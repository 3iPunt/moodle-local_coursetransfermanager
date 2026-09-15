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
use core_course_category;
use curl;
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

    /** @var int Default days of grace between announcing a pruning and running it. */
    public const DEFAULT_PRUNE_GRACE_DAYS = 7;

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
     * CSS class for the status badge.
     *
     * @param string $status
     * @return string
     */
    public static function get_status_class(string $status): string {
        return match ($status) {
            self::STATUS_ERROR => 'badge rounded-pill bg-danger px-3 py-2',
            self::STATUS_SUCCESS => 'badge rounded-pill bg-info px-3 py-2',
            self::STATUS_COMPLETED => 'badge rounded-pill bg-success px-3 py-2',
            default => 'badge rounded-pill bg-secondary px-3 py-2',
        };
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

        $sql = "SELECT t.*, latest.status AS laststatus, latest.timecreated AS lastrun,
                       latest.manualrun AS lastmanual, latest.errormessage AS lasterror
                  FROM {local_ctm_tasks} t
             LEFT JOIN (
                    SELECT e.taskid, e.status, e.timecreated, e.manualrun, e.errormessage
                      FROM {local_ctm_executions} e
                      JOIN (
                            SELECT taskid, MAX(timecreated) AS maxt
                              FROM {local_ctm_executions}
                          GROUP BY taskid
                      ) m ON m.taskid = e.taskid AND m.maxt = e.timecreated
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

        return $DB->insert_record('local_ctm_tasks', $record);
    }

    /**
     * Update an existing task.
     *
     * @param int $id Task ID.
     * @param array $data Form data.
     * @return bool
     * @throws dml_exception
     */
    public function update_task(int $id, array $data): bool {
        global $DB;

        $record = $DB->get_record('local_ctm_tasks', ['id' => $id], '*', MUST_EXIST);
        $cronexpression = trim($data['cronexpression'] ?? $record->cronexpression);

        $record->name = $data['name'];
        $record->originsiteid = (int)($data['originsiteid'] ?? $record->originsiteid);
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

        return $DB->update_record('local_ctm_tasks', $record);
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
        return $DB->get_record('local_ctm_tasks', ['id' => $id], '*', MUST_EXIST);
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
        $DB->delete_records('local_ctm_executions', ['taskid' => $id]);
        $DB->delete_records('local_ctm_prune', ['taskid' => $id]);
        return $DB->delete_records('local_ctm_tasks', ['id' => $id]);
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
        $DB->update_record('local_ctm_tasks', $task);

        return $task;
    }

    /**
     * Compute the next scheduled run time from a 5-field cron expression.
     *
     * Kept for backwards compatibility with the legacy form; delegates to {@see schedule}.
     *
     * @param string $cronexpression A 5-field cron expression.
     * @param int $from Reference timestamp; the next run will be strictly after this point.
     * @return int|null Unix timestamp of the next run, or null if it cannot be computed.
     */
    public static function compute_next_run(string $cronexpression, int $from): ?int {
        return schedule::next($cronexpression, $from);
    }

    /**
     * Best-effort previous-run anchor for the current cycle.
     *
     * Kept for backwards compatibility; delegates to {@see schedule}.
     *
     * @param string $cronexpression
     * @param int $reference Timestamp used as the upper bound.
     * @return int|null
     */
    public static function compute_previous_run(string $cronexpression, int $reference): ?int {
        return schedule::previous($cronexpression, $reference);
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
        $tasks = $DB->get_records_select(
            'local_ctm_tasks',
            'enabled = 1 AND (nextruntime IS NULL OR nextruntime <= :now)',
            ['now' => $now]
        );

        foreach ($tasks as $task) {
            try {
                if ($this->has_recent_success($task, $now)) {
                    mtrace('Skipping task "' . $task->name . '" — already executed in current cycle.');
                    $this->advance_schedule($task, $now);
                    continue;
                }
                $this->process_task($task);
                $this->advance_schedule($task, $now);
            } catch (Throwable $e) {
                $this->create_execution($task->id, self::STATUS_ERROR, ['error' => $e->getMessage()]);
                notifier::execution_error($task, $e->getMessage());
                mtrace('Task failed: ' . $e->getMessage());
                // Move the schedule forward so we do not retry every cron tick.
                $this->advance_schedule($task, $now);
            }
        }
    }

    /**
     * Check, without running anything, whether a manual launch would be blocked.
     *
     * @param object $task Task record.
     * @return stdClass {blocked: bool, lastsuccess: ?int}
     * @throws dml_exception
     */
    public function run_now_precheck(object $task): stdClass {
        if ($this->has_recent_success($task, time())) {
            return (object)['blocked' => true, 'lastsuccess' => $this->get_last_success_time($task)];
        }
        return (object)['blocked' => false, 'lastsuccess' => null];
    }

    /**
     * Launch a task right now, outside its schedule.
     *
     * Guarded against duplicates: relaunching within the current cycle would
     * duplicate every course in the archive (coursetransfer always creates
     * new courses), so a recent success blocks the launch.
     *
     * @param int $taskid Task ID.
     * @return stdClass {launched: bool, blocked: bool, lastsuccess: ?int, error: ?string}
     * @throws dml_exception
     */
    public function run_now(int $taskid): stdClass {
        $task = $this->get_task($taskid);
        $now = time();

        if ($this->has_recent_success($task, $now)) {
            return (object)[
                'launched' => false,
                'blocked' => true,
                'lastsuccess' => $this->get_last_success_time($task),
                'error' => null,
            ];
        }

        try {
            $this->process_task($task, true);
            return (object)['launched' => true, 'blocked' => false, 'lastsuccess' => null, 'error' => null];
        } catch (Throwable $e) {
            $this->create_execution($task->id, self::STATUS_ERROR, [
                'error' => $e->getMessage(),
                'manualrun' => 1,
            ]);
            notifier::execution_error($task, $e->getMessage());
            return (object)['launched' => false, 'blocked' => false, 'lastsuccess' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Returns true if a successful execution already exists for the current cycle.
     *
     * The "current cycle" starts at the most recent cron-scheduled fire at or before $now.
     * If a success row was recorded after that anchor, the task is considered already done
     * for this period — protecting against double-runs when an admin re-launches the cron
     * or when nextruntime has slipped.
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
            'local_ctm_executions',
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
            'local_ctm_executions',
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
     * @return void
     * @throws dml_exception
     */
    private function advance_schedule(object $task, int $now): void {
        global $DB;
        $DB->update_record('local_ctm_tasks', (object)[
            'id' => $task->id,
            'lastruntime' => $now,
            'nextruntime' => schedule::next($task->cronexpression, $now),
            'timemodified' => $now,
        ]);
    }

    /**
     * Archive whatever the task has to archive on this run.
     *
     * In rotation mode the conservation policy decides: every category of the
     * origin that has outstayed its years in production is archived, oldest
     * first and capped per execution so a catch-up does not saturate both
     * platforms. In legacy mode (tasks created before 2.1) the single-year
     * pattern is resolved as before.
     *
     * @param object $task
     * @param bool $manualrun True when launched via "run now".
     * @return void
     * @throws moodle_exception
     * @throws dml_exception
     */
    private function process_task(object $task, bool $manualrun = false): void {
        mtrace('Processing task: ' . $task->name . ($manualrun ? ' (manual run)' : ''));

        $site = origin::site((int)$task->originsiteid);
        $this->verify_origin_sync($site);

        if (!rotation::mask($task)) {
            throw new moodle_exception('maskinvalid', 'local_coursetransfermanager');
        }

        $pending = rotation::to_archive($task);
        if (empty($pending)) {
            mtrace('Nothing to archive: no category in the origin has outstayed its '
                . (int)$task->originkeepyears . ' year(s) in production.');
            return;
        }

        $max = rotation::max_per_tick();
        $batch = array_slice($pending, 0, $max);
        mtrace('Policy: archive year <= ' . rotation::archive_threshold($task) . '. '
            . count($pending) . ' pending, processing ' . count($batch) . ' this run.');

        foreach ($batch as $category) {
            $this->archive_category(
                $task,
                $site,
                (int)$category->id,
                (string)$category->name,
                (string)$category->idnumber,
                $manualrun
            );
        }

        if (count($pending) > count($batch)) {
            mtrace('Remaining ' . (count($pending) - count($batch))
                . ' category(ies) will be archived on the next run.');
        }
    }

    /**
     * Bring one origin category into the archive and schedule its removal.
     *
     * @param object $task Task record.
     * @param stdClass $site Origin site.
     * @param int $origincategoryid Category id in the origin.
     * @param string $origincategoryname Category name in the origin.
     * @param string $origincategoryidnumber Category idnumber in the origin.
     * @param bool $manualrun True when launched via "run now".
     * @return void
     * @throws dml_exception
     */
    private function archive_category(
        object $task,
        stdClass $site,
        int $origincategoryid,
        string $origincategoryname,
        string $origincategoryidnumber,
        bool $manualrun
    ): void {
        global $USER;

        mtrace('Archiving origin category ID ' . $origincategoryid
            . ' (' . $origincategoryidnumber . ')');

        $configuration = new configuration_category(
            1,
            0,
            0,
            $task->restoreuserdata ? 1 : 0
        );

        $result = coursetransfer::restore_category(
            $USER,
            $site,
            0,
            $origincategoryid,
            $configuration
        );

        mtrace('Restore response: ' . json_encode($result));

        if (empty($result['success'])) {
            $error = 'Restoration failed (' . origin::format_errors($result['errors'] ?? []) . ')';

            $this->create_execution($task->id, self::STATUS_ERROR, [
                'origincategoryid' => $origincategoryid,
                'origincategoryname' => $origincategoryname,
                'origincategoryidnumber' => $origincategoryidnumber,
                'error' => $error,
                'manualrun' => $manualrun ? 1 : 0,
            ]);
            notifier::execution_error($task, $error);

            mtrace('Task failed: ' . $error);
            return;
        }

        $requestid = (int)($result['data']['requestid'] ?? 0);

        $destinationcategoryid = $this->relocate_restored_category(
            $requestid,
            $origincategoryidnumber,
            (int)$task->targetcategoryid
        );

        $scheduleddeleteat = time() + ((int)$task->retentiondays * DAYSECS);

        $this->create_execution($task->id, self::STATUS_SUCCESS, [
            'requestid' => $requestid ?: null,
            'origincategoryid' => $origincategoryid,
            'origincategoryname' => $origincategoryname,
            'origincategoryidnumber' => $origincategoryidnumber,
            'destinationcategoryid' => $destinationcategoryid,
            'scheduleddeleteat' => $scheduleddeleteat,
            'deletestatus' => deletion_manager::STATUS_SCHEDULED,
            'manualrun' => $manualrun ? 1 : 0,
        ]);

        notifier::execution_launched($task, $origincategoryname, (string)$site->host);

        mtrace('Task completed successfully. Request ID: ' . $requestid);
    }

    /**
     * Relocate the restored category under the destination parent and return its id.
     *
     * The created category is resolved from the coursetransfer request row,
     * because idnumbers are not unique and a global idnumber lookup breaks as
     * soon as one is duplicated. The idnumber fallback only applies when
     * it is unambiguous.
     * A relocation problem never fails the execution: the restore already
     * happened — it is logged and the category stays where it was created.
     *
     * @param int $requestid Coursetransfer request id returned by the restore.
     * @param string $idnumber Restored category idnumber (fallback lookup).
     * @param int $destinationparentid Destination parent category ID.
     * @return int|null Id of the restored local category, when resolvable.
     */
    private function relocate_restored_category(int $requestid, string $idnumber, int $destinationparentid): ?int {
        global $DB;

        try {
            $categoryid = null;

            if ($requestid > 0) {
                $fromrequest = $DB->get_field('local_coursetransfer_request', 'target_category_id', ['id' => $requestid]);
                $categoryid = $fromrequest ? (int)$fromrequest : null;
            }

            if (!$categoryid && $idnumber !== '') {
                $records = $DB->get_records('course_categories', ['idnumber' => $idnumber], 'id', 'id, parent');
                if (count($records) === 1) {
                    $categoryid = (int)reset($records)->id;
                } else if (count($records) > 1) {
                    mtrace('Relocation skipped: idnumber "' . $idnumber . '" is ambiguous ('
                        . count($records) . ' categories) and the request did not expose the created category.');
                    return null;
                }
            }

            if (!$categoryid) {
                mtrace('Relocation skipped: created category could not be resolved.');
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
     * Exercise the origin sync pipeline before attempting a restore.
     *
     * Calls local_coursetransfer_site_origin_test on the remote so we fail fast
     * (and traceably) when token, target verification, or connectivity are off
     * — instead of erroring deep inside the restore call.
     *
     * @param stdClass $site
     * @return void
     * @throws moodle_exception
     */
    private function verify_origin_sync(stdClass $site): void {
        global $USER;

        mtrace('Verifying origin sync against ' . $site->host . ' ...');

        try {
            $response = (new request($site))->site_origin_test($USER);
        } catch (Throwable $e) {
            mtrace('Origin sync verification threw: ' . $e->getMessage());
            throw new moodle_exception(
                'originsyncfailed',
                'local_coursetransfermanager',
                '',
                null,
                $e->getMessage()
            );
        }

        if (!empty($response->success)) {
            mtrace('Origin sync verification OK.');
            return;
        }

        $detail = origin::format_errors($response->errors ?? []);
        mtrace('Origin sync verification failed: ' . $detail);
        throw new moodle_exception(
            'originsyncfailed',
            'local_coursetransfermanager',
            '',
            null,
            $detail
        );
    }

    /**
     * Resolve a remote category by idnumber regex through the WS exposed by the origin.
     *
     * @param string $siteurl
     * @param string $token
     * @param string $pattern
     * @return array {id, name, idnumber}
     * @throws moodle_exception
     */
    public static function get_remote_category_by_idnumber(string $siteurl, string $token, string $pattern): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $siteurl = rtrim($siteurl, '/');

        $params = [
            'wstoken' => $token,
            'wsfunction' => 'local_coursetransfer_get_category_idnumber',
            'moodlewsrestformat' => 'json',
            'pattern' => $pattern,
        ];

        $curl = new curl();
        $response = $curl->post($siteurl . '/webservice/rest/server.php', $params);
        mtrace('Remote URL: ' . $siteurl . '/webservice/rest/server.php');
        mtrace('Remote response: ' . $response);
        if (empty($response)) {
            throw new moodle_exception('emptyresponse', 'local_coursetransfermanager');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new moodle_exception('invalidresponse', 'local_coursetransfermanager');
        }

        // Surface remote Moodle web service errors using their standard envelope.
        if (!empty($data['exception'])) {
            $detail = trim(($data['errorcode'] ?? '') . ' ' . ($data['message'] ?? ''));
            throw new moodle_exception(
                'remotecategoryerror',
                'local_coursetransfermanager',
                '',
                null,
                $detail !== '' ? $detail : $data['exception']
            );
        }

        if (empty($data['success'])) {
            throw new moodle_exception(
                'remotecategoryerror',
                'local_coursetransfermanager',
                '',
                null,
                $data['error'] ?? 'Unknown remote response'
            );
        }

        if (empty($data['category']['id'])) {
            throw new moodle_exception(
                'remotecategoryerror',
                'local_coursetransfermanager',
                '',
                null,
                'Remote category ID not found in response'
            );
        }

        return [
            'id' => (int)$data['category']['id'],
            'name' => (string)($data['category']['name'] ?? ''),
            'idnumber' => (string)($data['category']['idnumber'] ?? ''),
        ];
    }

    /**
     * Persist an execution row.
     *
     * @param int $taskid
     * @param string $status
     * @param array $extra Optional fields: requestid, origincategoryid, origincategoryname,
     *                     origincategoryidnumber, destinationcategoryid, scheduleddeleteat,
     *                     deletestatus, manualrun, error.
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
            'errorcode' => !empty($extra['error']) ? 'processing_error' : null,
            'errormessage' => $extra['error'] ?? null,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        return $DB->insert_record('local_ctm_executions', $record);
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
                $taskcache[$taskid] = $DB->get_record('local_ctm_tasks', ['id' => $taskid]) ?: null;
            }
            return $taskcache[$taskid];
        };

        // N3 — advance notices go out BEFORE any deletion runs.
        foreach (deletion_manager::get_due_warnings($now, notifier::warning_lead_seconds()) as $warning) {
            if ($task = $gettask((int)$warning->taskid)) {
                notifier::deletion_warning($task, $warning);
            }
            deletion_manager::mark_warned((int)$warning->id);
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
            }
            // Transient (retry) outcomes stay silent: the next tick retries.
        }

        // Two-phase pruning: announce candidates (N5), then prune the ones out of grace (N6).
        $gracedays = get_config('local_coursetransfermanager', 'prunegracedays');
        $gracedays = ($gracedays === false || $gracedays === '') ? self::DEFAULT_PRUNE_GRACE_DAYS : (int)$gracedays;

        foreach (prune_manager::detect($now, $gracedays) as $candidate) {
            if ($task = $gettask((int)$candidate->taskid)) {
                notifier::prune_warning($task, $candidate);
            }
        }

        foreach (prune_manager::execute_due($now) as $result) {
            if ($result->outcome === prune_manager::STATUS_DONE) {
                if ($task = $gettask((int)$result->candidate->taskid)) {
                    notifier::prune_done($task, (string)$result->candidate->categoryname);
                }
            }
        }

        mtrace('Cleanup tasks completed.');
    }
}
