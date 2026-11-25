<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Task manager for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use coding_exception;
use core\lock\lock;
use core\lock\lock_config;
use core_course_category;
use core_user;
use dml_exception;
use dml_missing_record_exception;
use local_coursetransfer\api\request;
use local_coursetransfer\coursetransfer;
use local_coursetransfer\models\configuration_category;
use local_coursetransfermanager\notification\notifier;
use moodle_exception;
use stdClass;
use Throwable;

/**
 * Task Manager.
 *
 * Orchestrates the automated category restorations: task CRUD, the cron
 * pipeline (fire → resolve pattern → restore → relocate) and the deferred
 * cleanups, which are delegated to {@see deletion_manager} (origin-side
 * deletions with advance notice) and {@see prune_manager} (two-phase
 * archive pruning). Schedule math lives in {@see schedule}.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_manager {
    /** @var string Execution failed. */
    public const STATUS_ERROR = 'error';

    /** @var string Restoration launched and category relocated — not finished yet. */
    public const STATUS_SUCCESS = 'success';

    /** @var string Every course restored for real (set by the request_completed observer). */
    public const STATUS_COMPLETED = 'completed';

    /** @var string Error code: the origin could not be verified or listed (retried). */
    public const ERROR_ORIGIN = 'origin_unreachable';

    /** @var string Error code: the task creator cannot act in the origin anymore. */
    public const ERROR_CREATOR = 'creator_unavailable';

    /** @var string Error code: the restoration of one category failed. */
    public const ERROR_RESTORE = 'restore_failed';

    /** @var string Warning code: restored, but the created category could not be identified. */
    public const WARNING_RELOCATION = 'relocation_unresolved';

    /** @var int Retries per cycle when the origin cannot be read (one per hour). */
    public const ORIGIN_MAX_RETRIES = 3;

    /** @var int Failed attempts per origin category and cycle before giving up until the next one. */
    public const CATEGORY_MAX_ATTEMPTS = 3;

    /** @var int Wait before a retry or before continuing what the per-run cap left pending. */
    public const RETRY_DELAY = HOURSECS;

    /**
     * Status options usable for filtering executions.
     *
     * @return array
     * @throws coding_exception
     */
    public static function get_status_options(): array {
        return [
            '' => get_string('all'),
            self::STATUS_ERROR => get_string('status_error', 'local_coursetransfermanager'),
            self::STATUS_SUCCESS => get_string('status_success', 'local_coursetransfermanager'),
            self::STATUS_COMPLETED => get_string('status_completed', 'local_coursetransfermanager'),
        ];
    }

    /**
     * Get a label for a status code.
     *
     * @param string $status
     * @return string
     * @throws coding_exception
     */
    public static function get_status_label(string $status): string {
        return self::get_status_options()[$status] ?? $status;
    }

    /**
     * Tasks with their latest execution, resolved for the management panel.
     *
     * @param array $filters Optional filters: status (of the latest execution),
     *                       fromdate/todate (timestamps over timemodified).
     * @return stdClass[] Task rows enriched with laststatus, lastrun, lastmanual,
     *                    lasterror, originhost and targetcategoryname.
     * @throws dml_exception
     */
    public function get_tasks_overview(array $filters = []): array {
        global $DB;

        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'latest.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['fromdate'])) {
            $where[] = 't.timemodified >= :fromdate';
            $params['fromdate'] = (int)$filters['fromdate'];
        }
        if (!empty($filters['todate'])) {
            $where[] = 't.timemodified <= :todate';
            $params['todate'] = (int)$filters['todate'];
        }

        // Latest execution by id, not timecreated: one run launches several categories in the same
        // second, and a task must come back as a single row.
        $sql = "SELECT t.*, latest.status AS laststatus, latest.timecreated AS lastrun,
                       latest.manualrun AS lastmanual, latest.errormessage AS lasterror
                  FROM {local_coursetransfermanager_tasks} t
             LEFT JOIN (
                    SELECT e.taskid, e.status, e.timecreated, e.manualrun, e.errormessage
                      FROM {local_coursetransfermanager_executions} e
                      JOIN (
                            SELECT taskid, MAX(id) AS maxid
                              FROM {local_coursetransfermanager_executions}
                          GROUP BY taskid
                      ) m ON m.taskid = e.taskid AND m.maxid = e.id
             ) latest ON latest.taskid = t.id
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY t.timemodified DESC";

        $tasks = $DB->get_records_sql($sql, $params);

        $hosts = [];
        foreach ($tasks as $task) {
            if (!array_key_exists((int)$task->originsiteid, $hosts)) {
                try {
                    $hosts[(int)$task->originsiteid] = origin::site((int)$task->originsiteid)->host;
                } catch (Throwable $e) {
                    $hosts[(int)$task->originsiteid] = null;
                }
            }
            $task->originhost = $hosts[(int)$task->originsiteid];

            // The academic year this task will archive next, resolved through its mask.
            $mask = rotation::mask($task);
            $task->targetidnumber = null;
            if ($mask) {
                $reference = !empty($task->nextruntime) ? (int)$task->nextruntime : time();
                $task->targetidnumber = academic_year::example_for(
                    $mask,
                    rotation::archive_threshold($task, $reference)
                );
            }

            $task->targetcategoryname = null;
            if (!empty($task->targetcategoryid)) {
                $category = core_course_category::get((int)$task->targetcategoryid, IGNORE_MISSING);
                $task->targetcategoryname = $category ? $category->get_formatted_name() : null;
            }
        }

        return array_values($tasks);
    }

    /**
     * Create a new task.
     *
     * @param array $data Form data.
     * @return int Task ID.
     * @throws dml_exception
     */
    public function create_task(array $data): int {
        global $DB, $USER;

        $now = time();
        $cronexpression = trim($data['cronexpression'] ?? '0 2 1 9 *');

        $record = (object)[
            'type' => 'restore_category',
            'name' => $data['name'],
            'originsiteid' => (int)($data['originsiteid'] ?? 0),
            'categorypattern' => $data['categorypattern'],
            'targetcategoryid' => (int)$data['targetcategoryid'],
            'cronexpression' => $cronexpression,
            'retentiondays' => (int)($data['retentiondays'] ?? 30),
            'originkeepyears' => (int)($data['originkeepyears'] ?? 2),
            'destinationkeepyears' => (int)($data['destinationkeepyears'] ?? 4),
            'restoreuserdata' => !empty($data['restoreuserdata']) ? 1 : 0,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'usercreated' => (int)($USER->id ?? 0),
            'notifylevel' => $data['notifylevel'] ?? 'full',
            'notifyrecipients' => $data['notifyrecipients'] ?? null,
            'lastruntime' => null,
            'nextruntime' => schedule::next($cronexpression, $now),
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        return $DB->insert_record('local_coursetransfermanager_tasks', $record);
    }

    /**
     * Update an existing task.
     *
     * @param int $id Task ID.
     * @param array $data Form data.
     * @return bool
     * @throws dml_exception
     * @throws moodle_exception When the origin changes while deletions in the old one are pending.
     */
    public function update_task(int $id, array $data): bool {
        global $DB, $USER;

        $record = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $id], '*', MUST_EXIST);
        $cronexpression = trim($data['cronexpression'] ?? $record->cronexpression);

        $originsiteid = (int)($data['originsiteid'] ?? $record->originsiteid);
        $open = deletion_manager::count_open($id);
        if ($originsiteid !== (int)$record->originsiteid && $open > 0) {
            // Its pending deletions would be requested to the new platform with the ids of the old one (B3).
            throw new moodle_exception('task_origin_locked', 'local_coursetransfermanager', '', $open);
        }
        $record->name = $data['name'];
        $record->originsiteid = $originsiteid;
        $record->categorypattern = $data['categorypattern'];
        $record->targetcategoryid = (int)$data['targetcategoryid'];
        $record->cronexpression = $cronexpression;
        $record->retentiondays = (int)($data['retentiondays'] ?? 30);
        // Both retention windows must survive an edit: dropping one silently
        // would change what the task deletes without anybody asking for it.
        $record->originkeepyears = (int)($data['originkeepyears'] ?? $record->originkeepyears);
        $record->destinationkeepyears = (int)($data['destinationkeepyears'] ?? 4);
        $record->restoreuserdata = !empty($data['restoreuserdata']) ? 1 : 0;
        $record->enabled = !empty($data['enabled']) ? 1 : 0;
        $record->notifylevel = $data['notifylevel'] ?? $record->notifylevel;
        $record->notifyrecipients = array_key_exists('notifyrecipients', $data)
            ? $data['notifyrecipients'] : $record->notifyrecipients;
        $record->nextruntime = schedule::next($cronexpression, time());
        $record->timemodified = time();
        // The task acts in the origin as its creator: whoever saves an orphan task takes it over.
        if (!self::task_user($record) && !empty($USER->id)) {
            $record->usercreated = (int)$USER->id;
        }

        return $DB->update_record('local_coursetransfermanager_tasks', $record);
    }

    /**
     * Get a task by ID.
     *
     * @param int $id Task ID.
     * @return object
     * @throws dml_missing_record_exception
     * @throws dml_exception
     */
    public function get_task(int $id): object {
        global $DB;
        return $DB->get_record('local_coursetransfermanager_tasks', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Delete a task and its executions and pruning candidates.
     *
     * @param int $id Task ID.
     * @return bool
     * @throws dml_exception
     */
    public function delete_task(int $id): bool {
        global $DB;
        $DB->delete_records('local_coursetransfermanager_executions', ['taskid' => $id]);
        $DB->delete_records('local_coursetransfermanager_prune', ['taskid' => $id]);
        $DB->delete_records('local_coursetransfermanager_adopted', ['taskid' => $id]);
        unset_config(deletion_manager::approval_key($id), 'local_coursetransfermanager');
        return $DB->delete_records('local_coursetransfermanager_tasks', ['id' => $id]);
    }

    /**
     * Enable or disable a task.
     *
     * @param int $id Task ID.
     * @param bool $enabled New state.
     * @return object The updated task.
     * @throws dml_exception
     */
    public function set_enabled(int $id, bool $enabled): object {
        global $DB;

        $task = $this->get_task($id);
        $task->enabled = $enabled ? 1 : 0;
        if ($enabled && empty($task->nextruntime)) {
            $task->nextruntime = schedule::next($task->cronexpression, time());
        }
        $task->timemodified = time();
        $DB->update_record('local_coursetransfermanager_tasks', $task);

        return $task;
    }

    /**
     * Identity a task presents to the origin: its creator, while that user can still act.
     *
     * @param object $task Task (or row) with usercreated.
     * @return stdClass|null Null when the creator was deleted or suspended.
     */
    public static function task_user(object $task): ?stdClass {
        $user = empty($task->usercreated) ? false : core_user::get_user((int)$task->usercreated);
        if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
            return null;
        }
        return $user;
    }

    /**
     * Run all enabled tasks whose nextruntime has elapsed.
     *
     * @return void
     * @throws dml_exception
     */
    public function process_scheduled_tasks(): void {
        global $DB;

        $now = time();
        $this->schedule_missing($now);

        $tasks = $DB->get_records_select(
            'local_coursetransfermanager_tasks',
            'enabled = 1 AND nextruntime IS NOT NULL AND nextruntime <= :now',
            ['now' => $now]
        );

        foreach ($tasks as $task) {
            $lock = $this->lock_task((int)$task->id);
            if (!$lock) {
                mtrace('Skipping task "' . $task->name . '": another process is running it.');
                continue;
            }
            try {
                // Re-read under the lock: a manual run may have just moved it on.
                $task = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $task->id]);
                if (!$task || empty($task->enabled) || (int)$task->nextruntime > $now) {
                    continue;
                }
                $this->run_scheduled($task, $now);
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * One scheduled run of a task, holding its lock.
     *
     * @param object $task Task record.
     * @param int $now Current timestamp.
     * @return void
     * @throws dml_exception
     */
    private function run_scheduled(object $task, int $now): void {
        $user = self::task_user($task);
        if (!$user) {
            $error = get_string('creatorunavailable', 'local_coursetransfermanager', (int)$task->usercreated);
            $this->create_execution($task->id, self::STATUS_ERROR, ['error' => $error, 'errorcode' => self::ERROR_CREATOR]);
            notifier::execution_error($task, $error);
            mtrace('Task failed: ' . $error);
            $this->advance_schedule($task, $now);
            return;
        }

        try {
            $summary = $this->process_task($task, $user);
            // What the per-run cap (or a failed category) left pending continues soon, not next cycle.
            $this->advance_schedule($task, $now, $summary->remaining > 0 ? $now + self::RETRY_DELAY : null);
        } catch (Throwable $e) {
            $origin = $e instanceof moodle_exception && in_array($e->errorcode, ['originsyncfailed', 'originunreadable'], true);
            $account = $e instanceof moodle_exception && $e->errorcode === 'originaccountunusable';
            $error = $e->getMessage();
            $this->create_execution($task->id, self::STATUS_ERROR, [
                'error' => $error,
                'errorcode' => $origin ? self::ERROR_ORIGIN : ($account ? self::ERROR_CREATOR : null),
            ]);
            // An origin that does not answer is usually transient: retry a few times within the cycle.
            $retryat = ($origin && $this->origin_failures($task, $now) <= self::ORIGIN_MAX_RETRIES)
                ? $now + self::RETRY_DELAY : null;
            if ($retryat) {
                $error .= ' ' . get_string('retry_at', 'local_coursetransfermanager', userdate($retryat));
            }
            notifier::execution_error($task, $error);
            mtrace('Task failed: ' . $error);
            $this->advance_schedule($task, $now, $retryat);
        }
    }

    /**
     * Give a schedule to enabled tasks that lost it, without running them.
     *
     * A missing nextruntime used to mean "due now" (a cron that never fires ran every tick):
     * now it is recomputed, and a task whose expression never fires waits for an edit.
     *
     * @param int $now Current timestamp.
     * @return void
     * @throws dml_exception
     */
    private function schedule_missing(int $now): void {
        global $DB;

        $tasks = $DB->get_records_select(
            'local_coursetransfermanager_tasks',
            'enabled = 1 AND nextruntime IS NULL',
            [],
            '',
            'id, name, cronexpression'
        );
        foreach ($tasks as $task) {
            $next = schedule::next((string)$task->cronexpression, $now);
            if ($next === null) {
                mtrace('Task "' . $task->name . '" has a schedule that never fires: edit it.');
                continue;
            }
            $DB->set_field('local_coursetransfermanager_tasks', 'nextruntime', $next, ['id' => $task->id]);
        }
    }

    /**
     * Origin failures of the scheduled runs in the current cycle.
     *
     * @param object $task Task record.
     * @param int $now Current timestamp.
     * @return int
     * @throws dml_exception
     */
    private function origin_failures(object $task, int $now): int {
        global $DB;
        return $DB->count_records_select(
            'local_coursetransfermanager_executions',
            'taskid = :taskid AND status = :status AND errorcode = :code AND manualrun = 0 AND timecreated >= :since',
            ['taskid' => $task->id, 'status' => self::STATUS_ERROR, 'code' => self::ERROR_ORIGIN,
                'since' => $this->cycle_start($task, $now)]
        );
    }

    /**
     * Start of the task's current cycle: its most recent scheduled fire.
     *
     * @param object $task Task record.
     * @param int $now Current timestamp.
     * @return int
     */
    private function cycle_start(object $task, int $now): int {
        return schedule::previous((string)$task->cronexpression, $now + 60) ?? 0;
    }

    /**
     * Try to take the per-task lock shared by the cron and "run now".
     *
     * @param int $taskid Task id.
     * @return lock|null Null when another process holds it.
     */
    private function lock_task(int $taskid): ?lock {
        $factory = lock_config::get_lock_factory('local_coursetransfermanager');
        return $factory->get_lock('task_' . $taskid, 0) ?: null;
    }

    /**
     * Check, without running anything, whether a manual launch would be blocked.
     *
     * @param object $task Task record.
     * @return stdClass {blocked: bool, lastsuccess: ?int}
     * @throws dml_exception
     */
    public function run_now_precheck(object $task): stdClass {
        global $USER;
        if ($this->is_blocked($task, $USER)) {
            return (object)['blocked' => true, 'lastsuccess' => $this->get_last_success_time($task)];
        }
        return (object)['blocked' => false, 'lastsuccess' => null];
    }

    /**
     * Launch a task right now, outside its schedule, as the current user.
     *
     * Already archived categories are never brought twice; what is still pending
     * in this cycle can always be launched.
     *
     * @param int $taskid Task ID.
     * @return stdClass {launched, blocked, busy, lastsuccess, error, succeeded, failed}
     * @throws dml_exception
     */
    public function run_now(int $taskid): stdClass {
        global $USER;

        $task = $this->get_task($taskid);
        $result = (object)['launched' => false, 'blocked' => false, 'busy' => false, 'lastsuccess' => null,
            'error' => null, 'succeeded' => 0, 'failed' => 0];

        $lock = $this->lock_task((int)$task->id);
        if (!$lock) {
            $result->busy = true;
            $result->error = get_string('runnow_busy', 'local_coursetransfermanager');
            return $result;
        }

        try {
            if ($this->is_blocked($task, $USER)) {
                $result->blocked = true;
                $result->lastsuccess = $this->get_last_success_time($task);
                return $result;
            }

            // The progress lines of process_task() (mtrace) are meant for the cron log: on this web request they
            // would go before the JSON of the answer and the browser could not read it (CTM-015).
            ob_start();
            try {
                $summary = $this->process_task($task, $USER, true);
            } finally {
                ob_end_clean();
            }
            $result->succeeded = $summary->succeeded;
            $result->failed = $summary->failed;
            $result->launched = $summary->succeeded > 0;
            if (!$result->launched) {
                $result->error = $summary->failed > 0
                    ? get_string('runnow_allfailed', 'local_coursetransfermanager', $summary->failed)
                    : get_string('runnow_nothing', 'local_coursetransfermanager');
            }
            return $result;
        } catch (Throwable $e) {
            $origin = $e instanceof moodle_exception && in_array($e->errorcode, ['originsyncfailed', 'originunreadable'], true);
            $account = $e instanceof moodle_exception && $e->errorcode === 'originaccountunusable';
            $this->create_execution($task->id, self::STATUS_ERROR, [
                'error' => $e->getMessage(),
                'errorcode' => $origin ? self::ERROR_ORIGIN : ($account ? self::ERROR_CREATOR : null),
                'manualrun' => 1,
            ]);
            notifier::execution_error($task, $e->getMessage());
            $result->error = $e->getMessage();
            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether a manual launch has nothing left to do in this cycle.
     *
     * Only after a success in the cycle, and then only if nothing is pending. When the
     * origin cannot be read it stays blocked, as before: no proof that anything is left.
     *
     * @param object $task Task record.
     * @param stdClass $user Identity presented to the origin.
     * @return bool
     * @throws dml_exception
     */
    private function is_blocked(object $task, stdClass $user): bool {
        if (!$this->has_recent_success($task, time())) {
            return false;
        }
        try {
            return empty(rotation::to_archive($task, null, $user));
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * Returns true if a successful execution already exists for the current cycle.
     *
     * The "current cycle" starts at the most recent cron-scheduled fire at or before $now.
     *
     * @param object $task
     * @param int $now
     * @return bool
     * @throws dml_exception
     */
    private function has_recent_success(object $task, int $now): bool {
        global $DB;

        // Anchor at the most recent scheduled fire <= $now.
        $anchor = schedule::previous($task->cronexpression, $now + 60);
        if ($anchor === null) {
            return false;
        }

        [$insql, $inparams] = $DB->get_in_or_equal([self::STATUS_SUCCESS, self::STATUS_COMPLETED], SQL_PARAMS_NAMED);

        return $DB->record_exists_select(
            'local_coursetransfermanager_executions',
            "taskid = :taskid AND status $insql AND timecreated >= :timefrom",
            array_merge($inparams, [
                'taskid' => $task->id,
                'timefrom' => $anchor,
            ])
        );
    }

    /**
     * Timestamp of the latest successful execution of a task, if any.
     *
     * @param object $task
     * @return int|null
     * @throws dml_exception
     */
    private function get_last_success_time(object $task): ?int {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal([self::STATUS_SUCCESS, self::STATUS_COMPLETED], SQL_PARAMS_NAMED);
        $last = $DB->get_field_select(
            'local_coursetransfermanager_executions',
            'MAX(timecreated)',
            "taskid = :taskid AND status $insql",
            array_merge($inparams, ['taskid' => $task->id])
        );
        return $last ? (int)$last : null;
    }

    /**
     * Persist lastruntime/nextruntime so the next cron tick does not re-fire the task.
     *
     * @param object $task
     * @param int $now
     * @param int|null $nextrun Explicit next run (retry or continuation); the schedule's when null.
     * @return void
     * @throws dml_exception
     */
    private function advance_schedule(object $task, int $now, ?int $nextrun = null): void {
        global $DB;
        $DB->update_record('local_coursetransfermanager_tasks', (object)[
            'id' => $task->id,
            'lastruntime' => $now,
            'nextruntime' => $nextrun ?? schedule::next($task->cronexpression, $now),
            'timemodified' => $now,
        ]);
    }

    /**
     * Archive whatever the task has to archive on this run.
     *
     * Every origin category that has outstayed its years in production is archived,
     * oldest first and capped per execution so a catch-up does not saturate both platforms.
     *
     * @param object $task
     * @param stdClass $user Identity presented to the origin (creator, or who pressed "run now").
     * @param bool $manualrun True when launched via "run now".
     * @return stdClass {succeeded, failed, remaining}: remaining = what this cycle still has to retry.
     * @throws moodle_exception
     * @throws dml_exception
     */
    private function process_task(object $task, stdClass $user, bool $manualrun = false): stdClass {
        mtrace('Processing task: ' . $task->name . ($manualrun ? ' (manual run)' : ''));
        $summary = (object)['succeeded' => 0, 'failed' => 0, 'remaining' => 0];

        $site = origin::site((int)$task->originsiteid);
        $this->verify_origin_sync($site, $user);

        if (!rotation::mask($task)) {
            throw new moodle_exception('maskinvalid', 'local_coursetransfermanager');
        }

        $pending = rotation::to_archive($task, null, $user);
        if (empty($pending)) {
            mtrace('Nothing to archive: no category in the origin has outstayed its '
                . (int)$task->originkeepyears . ' year(s) in production.');
            return $summary;
        }

        // A category that keeps failing waits for the next cycle; a human pressing "run now" may insist.
        $attempts = $this->failed_attempts($task);
        $eligible = $manualrun ? $pending : array_values(array_filter(
            $pending,
            fn(stdClass $category): bool => ($attempts[(int)$category->id] ?? 0) < self::CATEGORY_MAX_ATTEMPTS
        ));
        if (count($eligible) < count($pending)) {
            mtrace((count($pending) - count($eligible)) . ' category(ies) failed '
                . self::CATEGORY_MAX_ATTEMPTS . ' times in this cycle: left for the next one.');
        }

        $batch = array_slice($eligible, 0, rotation::max_per_tick());
        mtrace('Policy: archive year <= ' . rotation::archive_threshold($task) . '. '
            . count($pending) . ' pending, processing ' . count($batch) . ' this run.');

        $summary->remaining = count($eligible) - count($batch);
        foreach ($batch as $category) {
            $archived = $this->archive_category(
                $task,
                $site,
                $user,
                (int)$category->id,
                (string)$category->name,
                (string)$category->idnumber,
                $manualrun
            );
            if ($archived) {
                $summary->succeeded++;
            } else {
                $summary->failed++;
                if (($attempts[(int)$category->id] ?? 0) + 1 < self::CATEGORY_MAX_ATTEMPTS) {
                    $summary->remaining++;
                }
            }
        }

        if ($summary->remaining > 0) {
            mtrace('Remaining ' . $summary->remaining . ' category(ies) will be retried in this cycle.');
        }
        return $summary;
    }

    /**
     * Failed scheduled attempts per origin category in the current cycle.
     *
     * @param object $task Task record.
     * @return int[] Origin category id => failures.
     * @throws dml_exception
     */
    private function failed_attempts(object $task): array {
        global $DB;
        return array_map('intval', $DB->get_records_sql_menu(
            "SELECT origincategoryid, COUNT(1)
               FROM {local_coursetransfermanager_executions}
              WHERE taskid = :taskid AND status = :status AND manualrun = 0
                AND origincategoryid IS NOT NULL AND timecreated >= :since
           GROUP BY origincategoryid",
            ['taskid' => $task->id, 'status' => self::STATUS_ERROR, 'since' => $this->cycle_start($task, time())]
        ));
    }

    /**
     * How every category restore is requested to local_coursetransfer.
     *
     * New courses in the destination, nothing removed in the origin by the restore itself
     * (the deletion is a separate, verified step) and a safety copy of every course kept
     * in the destination until that deletion and its retention are over.
     *
     * @param object $task Task record (restoreuserdata).
     * @return configuration_category
     */
    public static function restore_configuration(object $task): configuration_category {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        // A new course: only then the restore brings the course settings too (summary, image, format, dates,
        // idnumber, custom fields, completion). TARGET_CURRENT_ADDING (1) left them behind and, once the
        // origin is deleted, they were lost (B1).
        return new configuration_category(
            \backup::TARGET_NEW_COURSE,
            false,
            false,
            !empty($task->restoreuserdata),
            false,
            null,
            true
        );
    }

    /**
     * Bring one origin category into the archive and schedule its removal.
     *
     * @param object $task Task record.
     * @param stdClass $site Origin site.
     * @param stdClass $user Identity presented to the origin.
     * @param int $origincategoryid Category id in the origin.
     * @param string $origincategoryname Category name in the origin.
     * @param string $origincategoryidnumber Category idnumber in the origin.
     * @param bool $manualrun True when launched via "run now".
     * @return bool True when the restoration was launched.
     * @throws dml_exception
     */
    private function archive_category(
        object $task,
        stdClass $site,
        stdClass $user,
        int $origincategoryid,
        string $origincategoryname,
        string $origincategoryidnumber,
        bool $manualrun
    ): bool {
        mtrace('Archiving origin category ID ' . $origincategoryid
            . ' (' . $origincategoryidnumber . ')');

        $configuration = self::restore_configuration($task);

        $result = coursetransfer::restore_category(
            $user,
            $site,
            0,
            $origincategoryid,
            $configuration
        );

        $requestid = (int)($result['data']['requestid'] ?? 0);
        mtrace('Restore ' . (empty($result['success']) ? 'failed' : 'launched') . '. Request ID: ' . $requestid);

        $origin = [
            'origincategoryid' => $origincategoryid,
            'origincategoryname' => $origincategoryname,
            'origincategoryidnumber' => $origincategoryidnumber,
            'manualrun' => $manualrun ? 1 : 0,
        ];

        if (empty($result['success'])) {
            $error = 'Restoration failed (' . origin::format_errors($result['errors'] ?? []) . ')';
            // Half a restore leaves courses here: keep the trail so nobody hunts for orphans.
            $this->create_execution($task->id, self::STATUS_ERROR, $origin + [
                'requestid' => $requestid ?: null,
                'destinationcategoryid' => $this->resolve_created_category($requestid, $origincategoryidnumber),
                'error' => $error,
                'errorcode' => self::ERROR_RESTORE,
            ]);
            notifier::execution_error($task, $error);

            mtrace('Task failed: ' . $error);
            return false;
        }

        $destinationcategoryid = $this->relocate_restored_category(
            $requestid,
            $origincategoryidnumber,
            (int)$task->targetcategoryid
        );

        $this->create_execution($task->id, self::STATUS_SUCCESS, $origin + [
            'requestid' => $requestid ?: null,
            'destinationcategoryid' => $destinationcategoryid,
            'scheduleddeleteat' => time() + ((int)$task->retentiondays * DAYSECS),
            'deletestatus' => deletion_manager::STATUS_SCHEDULED,
            // Not an error: the restore runs, but the category is neither moved nor managed.
            'error' => $destinationcategoryid ? null
                : get_string('relocationunresolved', 'local_coursetransfermanager', $origincategoryidnumber),
            'errorcode' => $destinationcategoryid ? null : self::WARNING_RELOCATION,
        ]);

        notifier::execution_launched($task, $origincategoryname, (string)$site->host);

        mtrace('Task completed successfully. Request ID: ' . $requestid);
        return true;
    }

    /**
     * Relocate the restored category under the destination parent and return its id.
     *
     * A relocation problem never fails the execution: the restore already happened.
     *
     * @param int $requestid Coursetransfer request id returned by the restore.
     * @param string $idnumber Origin category idnumber.
     * @param int $destinationparentid Destination parent category ID.
     * @return int|null Id of the restored local category, when resolvable with certainty.
     */
    private function relocate_restored_category(int $requestid, string $idnumber, int $destinationparentid): ?int {
        try {
            $categoryid = $this->resolve_created_category($requestid, $idnumber);
            if (!$categoryid) {
                mtrace('Relocation skipped: the created category could not be identified with certainty.');
                return null;
            }

            if ($destinationparentid > 0 && $categoryid !== $destinationparentid) {
                $category = core_course_category::get($categoryid, IGNORE_MISSING, true);
                $parent = core_course_category::get($destinationparentid, IGNORE_MISSING, true);
                if ($category && $parent && (int)$category->parent !== $destinationparentid) {
                    $category->change_parent($parent);
                    mtrace('Moved restored category id=' . $categoryid . ' under destination parent id='
                        . $destinationparentid);
                }
            }

            return $categoryid;
        } catch (Throwable $e) {
            mtrace('Relocation failed (restore itself succeeded): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The local category a coursetransfer category request created, or null if not certain.
     *
     * Resolved from the request itself (its target category, or the courses of its child
     * requests), never by idnumber: idnumbers collide and a foreign category must never be taken.
     *
     * @param int $requestid Coursetransfer category request id.
     * @param string $idnumber Origin category idnumber, to double-check the result.
     * @return int|null
     */
    private function resolve_created_category(int $requestid, string $idnumber): ?int {
        global $DB;

        if ($requestid <= 0) {
            return null;
        }
        $request = $DB->get_record(
            'local_coursetransfer_request',
            ['id' => $requestid],
            'id, target_category_id, origin_category_requests'
        );
        if (!$request) {
            return null;
        }

        $candidates = [];
        if (!empty($request->target_category_id)) {
            $candidates[] = (int)$request->target_category_id;
        } else {
            $children = array_filter(array_map('intval', (array)json_decode((string)$request->origin_category_requests, true)));
            if (empty($children)) {
                return null;
            }
            [$insql, $params] = $DB->get_in_or_equal($children, SQL_PARAMS_NAMED);
            $paths = $DB->get_fieldset_sql(
                "SELECT DISTINCT cc.path
                   FROM {local_coursetransfer_request} r
                   JOIN {course} c ON c.id = r.target_course_id
                   JOIN {course_categories} cc ON cc.id = c.category
                  WHERE r.id $insql",
                $params
            );
            // The created category is the top of the restored tree (coursetransfer creates it at the root).
            foreach ($paths as $path) {
                $candidates[] = (int)explode('/', trim((string)$path, '/'))[0];
            }
            $candidates = array_values(array_unique($candidates));
        }
        if (count($candidates) !== 1) {
            return null;
        }

        $category = $DB->get_record('course_categories', ['id' => $candidates[0]], 'id, idnumber');
        if (!$category) {
            return null;
        }
        // Same idnumber, or the one coursetransfer derives when it collides (idnumber_uniqid).
        if (
            $idnumber !== '' && $category->idnumber !== $idnumber
                && !str_starts_with((string)$category->idnumber, $idnumber . '_')
        ) {
            return null;
        }
        return (int)$category->id;
    }

    /**
     * Exercise the origin sync pipeline before attempting a restore.
     *
     * Fails fast (and traceably) when token, target verification or connectivity are off.
     *
     * @param stdClass $site
     * @param stdClass $user Identity presented to the origin.
     * @return void
     * @throws moodle_exception originsyncfailed, with the cause in the message.
     */
    private function verify_origin_sync(stdClass $site, stdClass $user): void {
        mtrace('Verifying origin sync against ' . $site->host . ' ...');

        try {
            $response = (new request($site))->site_origin_test($user);
        } catch (Throwable $e) {
            mtrace('Origin sync verification threw: ' . $e->getMessage());
            throw new moodle_exception('originsyncfailed', 'local_coursetransfermanager', '', $e->getMessage());
        }

        if (!empty($response->success)) {
            mtrace('Origin sync verification OK.');
            return;
        }

        $detail = origin::format_errors($response->errors ?? []);
        mtrace('Origin sync verification failed: ' . $detail);
        $codes = array_map(static fn($error): string => (string) ($error->code ?? ''), (array) ($response->errors ?? []));
        if (array_intersect($codes, ['17002', '17004', '17005'])) {
            // The origin answers but refuses this account (not found, duplicated, suspended): not transient,
            // retrying would only repeat the error; the account has to be fixed in the origin (A4).
            throw new moodle_exception('originaccountunusable', 'local_coursetransfermanager', '', $detail);
        }
        // The cause goes in $a: debuginfo is dropped from the message in production.
        throw new moodle_exception('originsyncfailed', 'local_coursetransfermanager', '', $detail);
    }

    /**
     * Persist an execution row.
     *
     * @param int $taskid
     * @param string $status
     * @param array $extra Optional fields: requestid, origincategoryid, origincategoryname,
     *                     origincategoryidnumber, destinationcategoryid, scheduleddeleteat,
     *                     deletestatus, manualrun, error, errorcode.
     * @return int The execution id.
     * @throws dml_exception
     */
    private function create_execution(int $taskid, string $status, array $extra = []): int {
        global $DB;

        $record = (object)[
            'taskid' => $taskid,
            'status' => $status,
            'manualrun' => (int)($extra['manualrun'] ?? 0),
            'requestid' => $extra['requestid'] ?? null,
            'origincategoryid' => $extra['origincategoryid'] ?? null,
            'origincategoryname' => $extra['origincategoryname'] ?? null,
            'origincategoryidnumber' => $extra['origincategoryidnumber'] ?? null,
            'destinationcategoryid' => $extra['destinationcategoryid'] ?? null,
            'scheduleddeleteat' => $extra['scheduleddeleteat'] ?? null,
            'deletestatus' => $extra['deletestatus'] ?? null,
            'errorcode' => $extra['errorcode'] ?? (!empty($extra['error']) ? 'processing_error' : null),
            'errormessage' => $extra['error'] ?? null,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        return $DB->insert_record('local_coursetransfermanager_executions', $record);
    }

    /**
     * Run deferred cleanups: scheduled remote deletions + two-phase archive pruning.
     *
     * Advance notices (N3/N5) are dispatched by the notifier (F4) before the
     * deletions run, in the same cron pass.
     *
     * @return void
     * @throws dml_exception
     */
    public function cleanup_tasks(): void {
        global $DB;

        mtrace('Starting cleanup tasks...');

        $now = time();

        // R3 — emergency switch: while active NOTHING destructive runs and the
        // due dates keep sliding forward, so lifting the pause never fires an
        // immediate backlog of deletions.
        if (get_config('local_coursetransfermanager', 'deletionspaused')) {
            $postponed = deletion_manager::postpone_due($now)
                + prune_manager::postpone_due($now, deletion_manager::HOLD_POSTPONE_SECONDS);
            mtrace("Destructive lifecycle PAUSED by the emergency switch ({$postponed} due item(s) postponed).");
            mtrace('Cleanup tasks completed.');
            return;
        }
        $taskcache = [];
        $gettask = static function (int $taskid) use (&$taskcache, $DB): ?object {
            if (!array_key_exists($taskid, $taskcache)) {
                $taskcache[$taskid] = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $taskid]) ?: null;
            }
            return $taskcache[$taskid];
        };

        // N3 — advance notices go out BEFORE any deletion runs. A late notice (cron down,
        // short retention) moves the deletion so the notice always comes first.
        $lead = notifier::warning_lead_seconds();
        foreach (deletion_manager::get_due_warnings($now, $lead) as $warning) {
            if (!deletion_manager::mark_warned((int)$warning->id, $now + $lead)) {
                continue;
            }
            $warning->scheduleddeleteat = max((int)$warning->scheduleddeleteat, $now + $lead);
            if ($task = $gettask((int)$warning->taskid)) {
                notifier::deletion_warning($task, $warning);
            }
        }

        // Due deletions in the origin platform (N4 on success, N7 on rejection).
        foreach (deletion_manager::process_due($now) as $result) {
            $task = $gettask((int)$result->execution->taskid);
            if (!$task) {
                continue;
            }
            if ($result->outcome === deletion_manager::STATUS_DONE) {
                notifier::deletion_done($task, (string)$result->execution->origincategoryname);
            } else if ($result->outcome === 'held') {
                // Nothing failed: a safety lock refused to delete without a verified copy.
                notifier::deletion_held($task, (string)$result->execution->origincategoryname, $result->detail);
            } else if ($result->outcome === deletion_manager::STATUS_FAILED) {
                notifier::execution_error($task, $result->detail);
            } else if ($result->outcome === deletion_manager::STATUS_AWAITING_APPROVAL) {
                notifier::deletion_approval($task, (string)$result->execution->origincategoryname);
            }
            // Transient (retry) and skipped (cancelled meanwhile) outcomes stay silent.
        }

        // Two-phase pruning: announce candidates (N5), then prune the ones out of grace (N6).
        foreach (prune_manager::detect($now, lifecycle_config::grace_days()) as $candidate) {
            if ($task = $gettask((int)$candidate->taskid)) {
                notifier::prune_warning($task, $candidate);
            }
        }

        foreach (prune_manager::execute_due($now) as $result) {
            $task = $gettask((int)$result->candidate->taskid);
            if (!$task) {
                continue;
            }
            if ($result->outcome === prune_manager::STATUS_DONE) {
                notifier::prune_done($task, (string)$result->candidate->categoryname);
            } else if ($result->outcome === 'invalid') {
                notifier::execution_error($task, $result->detail);
            }
        }

        mtrace('Cleanup tasks completed.');
    }
}
