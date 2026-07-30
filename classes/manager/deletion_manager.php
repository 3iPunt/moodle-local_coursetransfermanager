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
 * Scheduled remote deletion lifecycle for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use core_course_category;
use local_coursetransfer\coursetransfer;
use moodle_exception;
use stdClass;

/**
 * Manages the deferred deletions in the origin platform.
 *
 * Every deletion is announced ahead of time and can be cancelled while it is
 * still pending: nothing destructive happens silently. States:
 * scheduled → warned (advance notice sent) → done | cancelled | failed.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deletion_manager {

    /** @var string Deletion is scheduled, advance notice not sent yet. */
    public const STATUS_SCHEDULED = 'scheduled';

    /** @var string Advance notice sent; deletion still pending and cancellable. */
    public const STATUS_WARNED = 'warned';

    /** @var string An admin cancelled the deletion; the category stays in the origin. */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var string The category was removed from the origin platform. */
    public const STATUS_DONE = 'done';

    /** @var string The origin rejected the removal; it will not be retried. */
    public const STATUS_FAILED = 'failed';

    /** @var int How long a held or paused deletion is postponed each time. */
    public const HOLD_POSTPONE_SECONDS = 7 * DAYSECS;

    /**
     * Pending (cancellable) deletions, oldest first.
     *
     * @param int|null $taskid Restrict to one task, or null for all.
     * @return stdClass[] Execution rows with taskname and originsiteid.
     */
    public static function get_pending(?int $taskid = null): array {
        global $DB;

        $params = [self::STATUS_SCHEDULED, self::STATUS_WARNED];
        $taskwhere = '';
        if ($taskid !== null) {
            $taskwhere = ' AND e.taskid = ?';
            $params[] = $taskid;
        }

        $sql = "SELECT e.id, e.taskid, t.name AS taskname, t.originsiteid, t.usercreated,
                       e.origincategoryid, e.origincategoryname, e.origincategoryidnumber,
                       e.scheduleddeleteat, e.deletestatus
                  FROM {local_ctm_executions} e
                  JOIN {local_ctm_tasks} t ON t.id = e.taskid
                 WHERE e.deletestatus IN (?, ?)" . $taskwhere . "
              ORDER BY e.scheduleddeleteat ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Cancel a pending deletion, recording who and when.
     *
     * @param int $executionid Execution id.
     * @param int $userid User cancelling.
     * @return stdClass The updated execution row.
     * @throws moodle_exception When the deletion is not pending anymore.
     */
    public static function cancel(int $executionid, int $userid): stdClass {
        global $DB;

        $execution = $DB->get_record('local_ctm_executions', ['id' => $executionid], '*', MUST_EXIST);
        if (!in_array($execution->deletestatus, [self::STATUS_SCHEDULED, self::STATUS_WARNED], true)) {
            throw new moodle_exception('deletionnotcancellable', 'local_coursetransfermanager');
        }

        $execution->deletestatus = self::STATUS_CANCELLED;
        $execution->deletecancelledby = $userid;
        $execution->deletecancelledat = time();
        $execution->timemodified = time();
        $DB->update_record('local_ctm_executions', $execution);

        return $execution;
    }

    /**
     * Deletions whose advance notice is due and not sent yet.
     *
     * @param int $now Current timestamp.
     * @param int $leadseconds How long before the deletion the notice is due.
     * @return stdClass[] Execution rows with taskname, usercreated and originsiteid.
     */
    public static function get_due_warnings(int $now, int $leadseconds): array {
        global $DB;

        $sql = "SELECT e.id, e.taskid, t.name AS taskname, t.originsiteid, t.usercreated,
                       t.notifylevel, t.notifyrecipients,
                       e.origincategoryname, e.origincategoryidnumber, e.scheduleddeleteat
                  FROM {local_ctm_executions} e
                  JOIN {local_ctm_tasks} t ON t.id = e.taskid
                 WHERE e.deletestatus = ?
                   AND t.enabled = 1
                   AND e.scheduleddeleteat IS NOT NULL
                   AND e.scheduleddeleteat - ? <= ?
              ORDER BY e.scheduleddeleteat ASC";

        return $DB->get_records_sql($sql, [self::STATUS_SCHEDULED, $leadseconds, $now]);
    }

    /**
     * Mark that the advance notice of a deletion was sent.
     *
     * @param int $executionid Execution id.
     * @return void
     */
    public static function mark_warned(int $executionid): void {
        global $DB;
        $DB->update_record('local_ctm_executions', (object) [
            'id' => $executionid,
            'deletestatus' => self::STATUS_WARNED,
            'timemodified' => time(),
        ]);
    }

    /**
     * Execute the deletions that reached their scheduled date.
     *
     * SAFETY LOCKS (the golden rule: never delete the original without a
     * verified copy): a due deletion only runs when the execution is
     * COMPLETED for real and the archived copy still exists locally with
     * courses in it. Otherwise it is HELD — postponed and reported — until
     * a human cancels it or the condition heals. Deletions of disabled
     * tasks are frozen.
     *
     * Outcome per row:
     * - Removal accepted by the origin → done (also closes sibling pending
     *   rows for the same category, so it is not re-deleted).
     * - Origin rejected the removal → failed (no retry; needs human review).
     * - Safety lock not satisfied → held (postponed + notified).
     * - Connectivity error → left pending, retried on the next cron tick.
     *
     * @param int $now Current timestamp.
     * @return stdClass[] Results: {execution, outcome: done|failed|held|retry, detail}.
     */
    public static function process_due(int $now): array {
        global $DB, $USER;

        $sql = "SELECT e.id, e.taskid, t.name AS taskname, t.originsiteid, t.usercreated,
                       t.notifylevel, t.notifyrecipients, e.status, e.destinationcategoryid,
                       e.origincategoryid, e.origincategoryname, e.scheduleddeleteat, e.deletestatus
                  FROM {local_ctm_executions} e
                  JOIN {local_ctm_tasks} t ON t.id = e.taskid
                 WHERE e.deletestatus IN (?, ?)
                   AND t.enabled = 1
                   AND e.scheduleddeleteat IS NOT NULL
                   AND e.scheduleddeleteat <= ?
                   AND e.origincategoryid IS NOT NULL";

        $due = $DB->get_records_sql($sql, [self::STATUS_SCHEDULED, self::STATUS_WARNED, $now]);
        $results = [];

        foreach ($due as $execution) {
            // Safety locks BEFORE anything destructive.
            $reason = self::verification_failure($execution);
            if ($reason !== null) {
                self::postpone($execution->id, $now);
                mtrace('Remote deletion HELD for origin category id=' . $execution->origincategoryid
                    . ': ' . $reason);
                $results[] = (object) ['execution' => $execution, 'outcome' => 'held', 'detail' => $reason];
                continue;
            }
            try {
                $site = origin::site((int) $execution->originsiteid);
                $result = coursetransfer::remove_category($site, (int) $execution->origincategoryid, $USER);

                if (!empty($result['success'])) {
                    self::close_pending($execution->taskid, (int) $execution->origincategoryid, self::STATUS_DONE);
                    mtrace('Remote deletion sent for origin category id=' . $execution->origincategoryid);
                    $results[] = (object) ['execution' => $execution, 'outcome' => self::STATUS_DONE, 'detail' => ''];
                } else {
                    $detail = origin::format_errors($result['errors'] ?? []);
                    self::close_pending($execution->taskid, (int) $execution->origincategoryid, self::STATUS_FAILED);
                    mtrace('Remote deletion rejected for origin category id=' . $execution->origincategoryid
                        . ': ' . $detail);
                    $results[] = (object) ['execution' => $execution, 'outcome' => self::STATUS_FAILED, 'detail' => $detail];
                }
            } catch (\Throwable $e) {
                // Connectivity problems are transient: keep the row pending and retry next tick.
                mtrace('Remote deletion could not run (will retry): ' . $e->getMessage());
                $results[] = (object) ['execution' => $execution, 'outcome' => 'retry', 'detail' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Why a due deletion must NOT run yet, or null when it is safe.
     *
     * @param stdClass $execution Due execution row (status, destinationcategoryid...).
     * @return string|null Human reason, or null when verified.
     */
    private static function verification_failure(stdClass $execution): ?string {
        // R1 — completeness lock: "launched" is not "finished".
        if ($execution->status !== task_manager::STATUS_COMPLETED) {
            return get_string('deletion_held_notcompleted', 'local_coursetransfermanager',
                format_string((string) $execution->origincategoryname));
        }
        // R2 — the archived copy must still exist here, with courses in it.
        if (empty($execution->destinationcategoryid)) {
            return get_string('deletion_held_archivemissing', 'local_coursetransfermanager',
                format_string((string) $execution->origincategoryname));
        }
        $category = core_course_category::get((int) $execution->destinationcategoryid, IGNORE_MISSING, true);
        if (!$category || $category->get_courses_count(['recursive' => true]) === 0) {
            return get_string('deletion_held_archivemissing', 'local_coursetransfermanager',
                format_string((string) $execution->origincategoryname));
        }
        return null;
    }

    /**
     * Postpone one pending deletion by the hold window.
     *
     * @param int $executionid Execution id.
     * @param int $now Current timestamp.
     * @return void
     */
    private static function postpone(int $executionid, int $now): void {
        global $DB;
        $DB->update_record('local_ctm_executions', (object) [
            'id' => $executionid,
            'scheduleddeleteat' => $now + self::HOLD_POSTPONE_SECONDS,
            'timemodified' => $now,
        ]);
    }

    /**
     * Postpone EVERY due deletion (emergency pause): nothing stays overdue,
     * so lifting the pause never triggers an immediate mass deletion.
     *
     * @param int $now Current timestamp.
     * @return int How many deletions were postponed.
     */
    public static function postpone_due(int $now): int {
        global $DB;

        $due = $DB->get_records_select('local_ctm_executions',
            "deletestatus IN (?, ?) AND scheduleddeleteat IS NOT NULL AND scheduleddeleteat <= ?",
            [self::STATUS_SCHEDULED, self::STATUS_WARNED, $now], '', 'id');
        foreach ($due as $execution) {
            self::postpone((int) $execution->id, $now);
        }
        return count($due);
    }

    /**
     * Close every pending deletion row of the given category with a final status.
     *
     * @param int $taskid Task id.
     * @param int $origincategoryid Origin category id.
     * @param string $status Final status (done or failed).
     * @return void
     */
    private static function close_pending(int $taskid, int $origincategoryid, string $status): void {
        global $DB;

        $DB->execute(
            "UPDATE {local_ctm_executions}
                SET deletestatus = :status, timemodified = :now
              WHERE taskid = :taskid
                AND origincategoryid = :origincategoryid
                AND deletestatus IN (:pending1, :pending2)",
            [
                'status' => $status,
                'now' => time(),
                'taskid' => $taskid,
                'origincategoryid' => $origincategoryid,
                'pending1' => self::STATUS_SCHEDULED,
                'pending2' => self::STATUS_WARNED,
            ]
        );
    }
}
