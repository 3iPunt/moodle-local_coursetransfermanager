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

use local_coursetransfer\api\request;
use local_coursetransfer\coursetransfer;
use local_coursetransfermanager\event\deletion_approved;
use local_coursetransfermanager\event\deletion_cancelled;
use local_coursetransfermanager\event\deletion_executed;
use local_coursetransfermanager\event\deletion_held;
use local_coursetransfermanager\event\deletion_requested;
use moodle_exception;
use stdClass;

/**
 * Manages the deferred deletions in the origin platform.
 *
 * Every deletion is announced ahead of time and can be cancelled while it is
 * still pending: nothing destructive happens silently. States: scheduled →
 * warned (notice sent) → running (claimed) → done | failed; or cancelled.
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

    /** @var string The origin accepted the removal request (it deletes the category in its own cron). */
    public const STATUS_DONE = 'done';

    /** @var string The origin rejected the removal; it will not be retried. */
    public const STATUS_FAILED = 'failed';

    /** @var string Claimed by the cron: the removal is being requested to the origin right now. */
    public const STATUS_RUNNING = 'running';

    /** @var string Verified, waiting for a manager to approve the task's first deletion. */
    public const STATUS_AWAITING_APPROVAL = 'awaitingapproval';

    /** @var string[] Pending states a person can still cancel. */
    public const CANCELLABLE = [self::STATUS_SCHEDULED, self::STATUS_WARNED, self::STATUS_AWAITING_APPROVAL];

    /** @var string[] Every state of a deletion that may still be requested to the origin. */
    public const PENDING = [self::STATUS_SCHEDULED, self::STATUS_WARNED, self::STATUS_AWAITING_APPROVAL, self::STATUS_RUNNING];

    /** @var string Prefix of the execution errorcode that records the rule holding its deletion. */
    public const HOLD_CODE_PREFIX = 'held_';

    /** @var int After this long a claim is taken as abandoned (the cron died mid-call). */
    public const RUNNING_STALE_SECONDS = HOURSECS;

    /** @var string Pending deletions joined with what the safety rules need from their task. */
    private const DUE_SQL = "SELECT e.id, e.taskid, t.name AS taskname, t.originsiteid, t.usercreated,
                                    t.notifylevel, t.notifyrecipients, t.categorypattern, t.originkeepyears,
                                    t.destinationkeepyears, t.restoreuserdata, e.status, e.requestid,
                                    e.destinationcategoryid, e.origincategoryid, e.origincategoryname,
                                    e.origincategoryidnumber, e.scheduleddeleteat, e.deletestatus, e.errorcode,
                                    e.errormessage, e.timecreated
                               FROM {local_coursetransfermanager_executions} e
                               JOIN {local_coursetransfermanager_tasks} t ON t.id = e.taskid";

    /** @var int How long a held or paused deletion is postponed each time. */
    public const HOLD_POSTPONE_SECONDS = 7 * DAYSECS;

    /**
     * Pending (cancellable) deletions, oldest first.
     *
     * @param int|null $taskid Restrict to one task, or null for all.
     * @return stdClass[] Execution rows with taskname, originsiteid and the hold (errorcode, errormessage).
     */
    public static function get_pending(?int $taskid = null): array {
        global $DB;

        $params = self::CANCELLABLE;
        $taskwhere = '';
        if ($taskid !== null) {
            $taskwhere = ' AND e.taskid = ?';
            $params[] = $taskid;
        }

        $sql = "SELECT e.id, e.taskid, t.name AS taskname, t.originsiteid, t.usercreated,
                       e.origincategoryid, e.origincategoryname, e.origincategoryidnumber,
                       e.scheduleddeleteat, e.deletestatus, e.errorcode, e.errormessage
                  FROM {local_coursetransfermanager_executions} e
                  JOIN {local_coursetransfermanager_tasks} t ON t.id = e.taskid
                 WHERE e.deletestatus IN (?, ?, ?)" . $taskwhere . "
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

        $current = $DB->get_record('local_coursetransfermanager_executions', ['id' => $executionid], 'deletestatus', MUST_EXIST);
        if (!in_array($current->deletestatus, self::CANCELLABLE, true)) {
            throw new moodle_exception('deletionnotcancellable', 'local_coursetransfermanager');
        }

        // Conditional update: once the cron claimed the row (running) it is too late to cancel.
        $now = time();
        [$insql, $params] = $DB->get_in_or_equal(self::CANCELLABLE, SQL_PARAMS_NAMED, 'pending');
        $DB->execute(
            "UPDATE {local_coursetransfermanager_executions}
                SET deletestatus = :cancelled, deletecancelledby = :userid,
                    deletecancelledat = :now1, timemodified = :now2
              WHERE id = :id AND deletestatus $insql",
            $params + ['cancelled' => self::STATUS_CANCELLED, 'userid' => $userid, 'now1' => $now, 'now2' => $now,
                'id' => $executionid]
        );

        $execution = $DB->get_record('local_coursetransfermanager_executions', ['id' => $executionid], '*', MUST_EXIST);
        if ($execution->deletestatus !== self::STATUS_CANCELLED || (int) $execution->deletecancelledby !== $userid) {
            throw new moodle_exception('deletionnotcancellable', 'local_coursetransfermanager');
        }
        deletion_cancelled::log($executionid, (string) $execution->origincategoryname, 'manual');
        return $execution;
    }

    /**
     * Approve a deletion held for the first-deletion approval.
     *
     * Also approves the task's current policy (mask, P, V, origin): its next deletions
     * go ahead without asking until the policy changes. Every safety rule still runs again.
     *
     * @param int $executionid Execution id.
     * @param int $userid User approving.
     * @return stdClass The updated execution row.
     * @throws moodle_exception When the deletion is not awaiting approval.
     */
    public static function approve(int $executionid, int $userid): stdClass {
        global $DB;

        $current = $DB->get_record('local_coursetransfermanager_executions', ['id' => $executionid], '*', MUST_EXIST);
        if ($current->deletestatus !== self::STATUS_AWAITING_APPROVAL) {
            throw new moodle_exception('deletionnotapprovable', 'local_coursetransfermanager');
        }
        $task = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $current->taskid], '*', MUST_EXIST);

        $DB->execute(
            "UPDATE {local_coursetransfermanager_executions} SET deletestatus = :warned, timemodified = :now
              WHERE id = :id AND deletestatus = :awaiting",
            ['warned' => self::STATUS_WARNED, 'now' => time(), 'id' => $executionid,
                'awaiting' => self::STATUS_AWAITING_APPROVAL]
        );
        $execution = $DB->get_record('local_coursetransfermanager_executions', ['id' => $executionid], '*', MUST_EXIST);
        if ($execution->deletestatus !== self::STATUS_WARNED) {
            throw new moodle_exception('deletionnotapprovable', 'local_coursetransfermanager');
        }

        set_config(self::approval_key((int) $task->id), self::policy_hash($task), 'local_coursetransfermanager');
        deletion_approved::log(
            $executionid,
            (string) $execution->origincategoryname,
            'approval',
            null,
            'approved by user ' . $userid
        );
        self::release_approved_siblings((int) $task->id, $executionid, $userid);
        return $execution;
    }

    /**
     * Put the task's other deletions waiting for approval back in the queue, keeping their dates.
     *
     * The approval covers the task policy, so they no longer wait for a person. They go back to
     * scheduled: their notice is sent again and every safety rule runs again before deleting.
     *
     * @param int $taskid Task id.
     * @param int $approvedid Execution that was approved (already moved on).
     * @param int $userid User approving.
     * @return void
     */
    private static function release_approved_siblings(int $taskid, int $approvedid, int $userid): void {
        global $DB;

        $siblings = $DB->get_records_select(
            'local_coursetransfermanager_executions',
            'taskid = ? AND deletestatus = ? AND id <> ?',
            [$taskid, self::STATUS_AWAITING_APPROVAL, $approvedid],
            '',
            'id, origincategoryname'
        );
        foreach ($siblings as $sibling) {
            $DB->execute(
                "UPDATE {local_coursetransfermanager_executions} SET deletestatus = :scheduled, timemodified = :now
                  WHERE id = :id AND deletestatus = :awaiting",
                ['scheduled' => self::STATUS_SCHEDULED, 'now' => time(), 'id' => $sibling->id,
                    'awaiting' => self::STATUS_AWAITING_APPROVAL]
            );
            deletion_approved::log(
                (int) $sibling->id,
                (string) $sibling->origincategoryname,
                'approval',
                null,
                'task policy approved by user ' . $userid . ' on execution ' . $approvedid
            );
        }
    }

    /**
     * Fingerprint of what decides which categories a task deletes.
     *
     * @param stdClass $task Task (or row) with categorypattern, originkeepyears, destinationkeepyears, originsiteid.
     * @return string
     */
    public static function policy_hash(stdClass $task): string {
        return sha1(implode('|', [
            trim((string) $task->categorypattern),
            (int) $task->originkeepyears,
            (int) $task->destinationkeepyears,
            (int) $task->originsiteid,
        ]));
    }

    /**
     * Whether the task's next deletion must wait for a person (first one, or first since a policy change).
     *
     * @param stdClass $task Task (or row) with id and the policy fields.
     * @return bool
     */
    public static function needs_approval(stdClass $task): bool {
        if (!lifecycle_config::require_first_deletion_approval()) {
            return false;
        }
        return get_config('local_coursetransfermanager', self::approval_key((int) $task->id)) !== self::policy_hash($task);
    }

    /**
     * Config key holding the policy a person approved for a task.
     *
     * @param int $taskid Task id.
     * @return string
     */
    public static function approval_key(int $taskid): string {
        return 'approvedpolicy_' . $taskid;
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
                  FROM {local_coursetransfermanager_executions} e
                  JOIN {local_coursetransfermanager_tasks} t ON t.id = e.taskid
                 WHERE e.deletestatus = ?
                   AND t.enabled = 1
                   AND e.scheduleddeleteat IS NOT NULL
                   AND e.scheduleddeleteat - ? <= ?
              ORDER BY e.scheduleddeleteat ASC";

        return $DB->get_records_sql($sql, [self::STATUS_SCHEDULED, $leadseconds, $now]);
    }

    /**
     * Mark that the advance notice of a deletion is being sent.
     *
     * @param int $executionid Execution id.
     * @param int|null $earliestdelete A late notice moves the deletion to at least this time.
     * @return bool False when the deletion is no longer scheduled (cancelled meanwhile): send nothing.
     */
    public static function mark_warned(int $executionid, ?int $earliestdelete = null): bool {
        global $DB;

        $params = ['warned' => self::STATUS_WARNED, 'now' => time(), 'id' => $executionid,
            'scheduled' => self::STATUS_SCHEDULED];
        $postpone = '';
        if ($earliestdelete !== null) {
            $postpone = ', scheduleddeleteat = CASE WHEN scheduleddeleteat < :earliest1 THEN :earliest2
                                                    ELSE scheduleddeleteat END';
            $params['earliest1'] = $earliestdelete;
            $params['earliest2'] = $earliestdelete;
        }
        $DB->execute(
            "UPDATE {local_coursetransfermanager_executions}
                SET deletestatus = :warned, timemodified = :now $postpone
              WHERE id = :id AND deletestatus = :scheduled",
            $params
        );

        return $DB->get_field('local_coursetransfermanager_executions', 'deletestatus', ['id' => $executionid])
            === self::STATUS_WARNED;
    }

    /**
     * Execute the deletions that reached their scheduled date.
     *
     * SAFETY LOCKS (the golden rule: never delete the original without a
     * verified copy): a due deletion only runs when the execution is
     * COMPLETED for real and every course of ITS request is still here,
     * inside the archived category. Otherwise it is HELD — postponed and
     * reported — until a human cancels it or the condition heals. Deletions
     * of disabled tasks are frozen. The origin is asked as the task creator;
     * without a usable creator the deletion is held too.
     *
     * Outcome per row:
     * - Removal accepted by the origin → done (also closes sibling pending
     *   rows for the same category, so it is not re-deleted). The origin
     *   deletes the category in its own cron: done means requested and accepted.
     * - Origin rejected the removal → failed (no retry; needs human review).
     * - Safety lock not satisfied → held (postponed, rule and reason recorded
     *   on the execution, notified).
     * - Cancelled while being processed → skipped.
     * - Connectivity error → left pending, retried on the next cron tick.
     *
     * @param int $now Current timestamp.
     * @return stdClass[] Results: {execution, outcome: done|failed|held|skipped|retry, detail}.
     */
    public static function process_due(int $now): array {
        global $DB;

        // A claim left behind by a cron that died mid-call goes back to pending: asking
        // the origin again is safe, it rejects a category that is already gone.
        $DB->execute(
            "UPDATE {local_coursetransfermanager_executions} SET deletestatus = :warned, timemodified = :now
              WHERE deletestatus = :running AND timemodified < :stale",
            ['warned' => self::STATUS_WARNED, 'now' => $now, 'running' => self::STATUS_RUNNING,
                'stale' => $now - self::RUNNING_STALE_SECONDS]
        );

        $due = $DB->get_records_sql(self::DUE_SQL . " WHERE e.deletestatus IN (?, ?)
                   AND t.enabled = 1
                   AND e.scheduleddeleteat IS NOT NULL
                   AND e.scheduleddeleteat <= ?
                   AND e.origincategoryid IS NOT NULL", [self::STATUS_SCHEDULED, self::STATUS_WARNED, $now]);
        $results = [];

        foreach ($due as $execution) {
            $name = (string) $execution->origincategoryname;
            // Safety locks BEFORE anything destructive.
            $check = self::check($execution);
            if ($check->rule !== null) {
                self::postpone($execution->id, $now);
                self::record_hold($execution, $check->rule, $check->reason);
                mtrace('Remote deletion HELD (' . $check->rule . ') for origin category id='
                    . $execution->origincategoryid . ': ' . $check->reason);
                deletion_held::log((int) $execution->id, $name, $check->rule, (int) $execution->usercreated, $check->reason);
                $results[] = (object) ['execution' => $execution, 'outcome' => 'held', 'detail' => $check->reason];
                continue;
            }
            if ($check->needsapproval) {
                if (self::await_approval((int) $execution->id, $now)) {
                    self::clear_hold((int) $execution->id);
                    mtrace('Remote deletion AWAITING APPROVAL for origin category id=' . $execution->origincategoryid);
                    deletion_held::log((int) $execution->id, $name, 'approval', (int) $execution->usercreated);
                    $results[] = (object) ['execution' => $execution, 'outcome' => self::STATUS_AWAITING_APPROVAL,
                        'detail' => ''];
                }
                continue;
            }
            if (!self::claim((int) $execution->id, $now)) {
                mtrace('Remote deletion skipped for origin category id=' . $execution->origincategoryid
                    . ': no longer pending (cancelled meanwhile).');
                $results[] = (object) ['execution' => $execution, 'outcome' => 'skipped', 'detail' => ''];
                continue;
            }
            self::clear_hold((int) $execution->id);
            deletion_requested::log((int) $execution->id, $name, $check->passed, (int) $execution->usercreated);
            try {
                $site = origin::site((int) $execution->originsiteid);
                $result = coursetransfer::remove_category($site, (int) $execution->origincategoryid, $check->user);

                if (!empty($result['success'])) {
                    self::close_pending($execution->taskid, (int) $execution->origincategoryid, self::STATUS_DONE);
                    mtrace('Remote deletion requested and accepted by the origin for category id='
                        . $execution->origincategoryid);
                    deletion_executed::log((int) $execution->id, $name, $check->passed, (int) $execution->usercreated);
                    $results[] = (object) ['execution' => $execution, 'outcome' => self::STATUS_DONE, 'detail' => ''];
                } else {
                    $detail = origin::format_errors($result['errors'] ?? []);
                    self::close_pending($execution->taskid, (int) $execution->origincategoryid, self::STATUS_FAILED);
                    mtrace('Remote deletion rejected for origin category id=' . $execution->origincategoryid
                        . ': ' . $detail);
                    $results[] = (object) ['execution' => $execution, 'outcome' => self::STATUS_FAILED, 'detail' => $detail];
                }
            } catch (\Throwable $e) {
                // Connectivity problems are transient: release the claim and retry next tick.
                self::release((int) $execution->id);
                mtrace('Remote deletion could not run (will retry): ' . $e->getMessage());
                $results[] = (object) ['execution' => $execution, 'outcome' => 'retry', 'detail' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Pending deletions of one task, with the columns {@see self::check()} needs.
     *
     * @param int $taskid Task id.
     * @return stdClass[]
     */
    public static function get_checkable(int $taskid): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::CANCELLABLE);
        return $DB->get_records_sql(
            self::DUE_SQL . " WHERE e.deletestatus $insql AND e.taskid = ? ORDER BY e.scheduleddeleteat ASC",
            array_merge($params, [$taskid])
        );
    }

    /**
     * Run every safety rule on a pending deletion, without changing anything here.
     *
     * Local rules first (R1 restore completed, R2 archived copy intact, site the copy came from,
     * R2u user data asked for and received), then the creator, then R3 (the origin answers and its
     * category is unchanged, read only) and only then R2v (restore verified by local_coursetransfer),
     * which may ask the origin for the manifests not checked yet and record the result. Last,
     * whether a person must approve it.
     *
     * @param stdClass $execution Row from {@see self::DUE_SQL}.
     * @param bool $readonly True for the simulation: R2v is not run on manifests not verified yet
     *                       (that would write), they are reported as pending instead.
     * @return stdClass {rule: ?string, reason: ?string, passed: string, user: ?stdClass, needsapproval: bool,
     *                  notes: string[], pending: ?string}
     */
    public static function check(stdClass $execution, bool $readonly = false): stdClass {
        $name = format_string((string) $execution->origincategoryname);
        $check = (object) ['rule' => null, 'reason' => null, 'passed' => '', 'user' => null,
            'needsapproval' => false, 'notes' => [], 'pending' => null];
        $passed = [];

        // R1 — completeness lock: "launched" is not "finished".
        if ($execution->status !== task_manager::STATUS_COMPLETED) {
            return self::held($check, 'R1', get_string('deletion_held_notcompleted', 'local_coursetransfermanager', $name));
        }
        $passed[] = 'R1';
        // R2 — every course of THIS request must still be here, inside the archived category.
        if (empty($execution->destinationcategoryid) || !self::archived_copy_is_intact($execution)) {
            return self::held($check, 'R2', get_string('deletion_held_archivemissing', 'local_coursetransfermanager', $name));
        }
        $passed[] = 'R2';
        $request = self::category_request((int) $execution->requestid);
        // Site — the removal only goes to the platform the copy came from, even if the task now points elsewhere.
        $reason = self::origin_moved($execution, $request);
        if ($reason !== null) {
            return self::held($check, 'site', $reason);
        }
        $passed[] = 'site';
        // R2u — user data was asked for: a copy that arrived without it (or anonymised) loses the students' work.
        $missing = self::userdata_missing($execution, $request->children);
        if ($missing) {
            return self::held($check, 'R2u', get_string('deletion_held_nouserdata', 'local_coursetransfermanager', (object) [
                'name' => $name,
                'courses' => implode(', ', $missing),
            ]));
        }
        $passed[] = 'R2u';
        // The origin is asked as the task creator, never as whoever runs the cron.
        $check->user = task_manager::task_user($execution);
        if (!$check->user) {
            return self::held($check, 'creator', get_string('deletion_held_nocreator', 'local_coursetransfermanager', (object) [
                'name' => $name,
                'userid' => (int) $execution->usercreated,
            ]));
        }
        // R3 — the origin answers and its category still is what was archived.
        if (lifecycle_config::hold_if_origin_changed()) {
            $reason = self::origin_changed($execution, $check->user, $request->children, $check->notes);
            if ($reason !== null) {
                return self::held($check, 'R3', $reason);
            }
            $passed[] = 'R3';
        }
        // R2v asks the origin as whoever requested the copy: an account that cannot act there would
        // only produce a failed check, recorded as a mismatch by older local_coursetransfer releases.
        $requester = self::unusable_requester($request);
        if ($requester !== null) {
            return self::held($check, 'requester', get_string('deletion_held_requester', 'local_coursetransfermanager', (object) [
                'name' => $name,
                'userid' => $requester,
            ]));
        }
        // R2v — local_coursetransfer confirms every course of the restore finished (and its manifest, when enabled).
        $unverified = self::unverified_manifests($request->children);
        if ($readonly && $unverified > 0) {
            $check->pending = get_string('deletion_pending_verify', 'local_coursetransfermanager', (object) [
                'name' => $name,
                'count' => $unverified,
            ]);
            $check->passed = implode('+', $passed);
            return $check;
        }
        $issues = self::restore_issues((int) $execution->requestid);
        if ($issues) {
            return self::held($check, 'R2v', get_string(
                'deletion_held_restoreunverified',
                'local_coursetransfermanager',
                (object) ['name' => $name, 'issues' => implode('; ', $issues)]
            ));
        }
        $passed[] = 'R2v';
        $check->passed = implode('+', $passed);
        $check->needsapproval = self::needs_approval((object) [
            'id' => $execution->taskid,
            'categorypattern' => $execution->categorypattern,
            'originkeepyears' => $execution->originkeepyears,
            'destinationkeepyears' => $execution->destinationkeepyears,
            'originsiteid' => $execution->originsiteid,
        ]);
        return $check;
    }

    /**
     * Fill a check result with the rule that holds the deletion.
     *
     * @param stdClass $check Check result.
     * @param string $rule Rule code.
     * @param string $reason Human reason.
     * @return stdClass
     */
    private static function held(stdClass $check, string $rule, string $reason): stdClass {
        $check->rule = $rule;
        $check->reason = $reason;
        return $check;
    }

    /**
     * The category request of an execution with its course requests.
     *
     * @param int $requestid Category request id.
     * @return stdClass {siteurl: string, userid: int, children: stdClass[]} (empty values when it is gone).
     */
    private static function category_request(int $requestid): stdClass {
        global $DB;

        $result = (object) ['siteurl' => '', 'userid' => 0, 'children' => []];
        $request = $requestid > 0 ? $DB->get_record(
            'local_coursetransfer_request',
            ['id' => $requestid],
            'id, siteurl, userid, origin_category_requests'
        ) : false;
        if (!$request) {
            return $result;
        }
        $result->siteurl = (string) $request->siteurl;
        $result->userid = (int) $request->userid;
        $children = array_filter(array_map('intval', (array) json_decode((string) $request->origin_category_requests, true)));
        if ($children) {
            [$insql, $params] = $DB->get_in_or_equal($children);
            $result->children = $DB->get_records_select(
                'local_coursetransfer_request',
                "id $insql",
                $params,
                'id',
                'id, userid, origin_course_id, origin_course_fullname, origin_course_shortname, origin_enrolusers,
                 origin_manifest'
            );
        }
        return $result;
    }

    /**
     * Site rule: why the removal would not reach the platform the copy came from, or null when it would.
     *
     * The task's origin can be changed after archiving; the removal always goes to the task's
     * current origin, so it only runs while that is the platform the copy was brought from.
     *
     * @param stdClass $execution Due execution row.
     * @param stdClass $request {@see self::category_request()}.
     * @return string|null
     */
    private static function origin_moved(stdClass $execution, stdClass $request): ?string {
        $name = format_string((string) $execution->origincategoryname);
        try {
            $host = (string) origin::site((int) $execution->originsiteid)->host;
        } catch (\Throwable $e) {
            return get_string('deletion_held_originmissing', 'local_coursetransfermanager', (object) [
                'name' => $name,
                'detail' => $e->getMessage(),
            ]);
        }
        $from = self::normalise_host($request->siteurl);
        if ($from !== '' && $from === self::normalise_host($host)) {
            return null;
        }
        return get_string('deletion_held_originmoved', 'local_coursetransfermanager', (object) [
            'name' => $name,
            'from' => $request->siteurl !== '' ? $request->siteurl : '-',
            'to' => $host,
        ]);
    }

    /**
     * A host as Course Transfer stores it, compared case-insensitively.
     *
     * @param string $host Host URL.
     * @return string
     */
    private static function normalise_host(string $host): string {
        return strtolower(rtrim(trim($host), '/'));
    }

    /**
     * R2u: courses whose copy arrived without the user data that was asked for (or anonymised).
     *
     * Read from the backup manifest local_coursetransfer stores on each course request; a course
     * without that record is left to R2v.
     *
     * @param stdClass $execution Due execution row (restoreuserdata of its task).
     * @param stdClass[] $children Course requests of the category request.
     * @return string[] Course names; empty when nothing is missing.
     */
    private static function userdata_missing(stdClass $execution, array $children): array {
        $missing = [];
        foreach ($children as $child) {
            if (empty($execution->restoreuserdata) && empty($child->origin_enrolusers)) {
                continue;
            }
            $manifest = json_decode((string) ($child->origin_manifest ?? ''), true);
            if (!is_array($manifest) || !isset($manifest['backup']) || !is_array($manifest['backup'])) {
                continue;
            }
            $backup = $manifest['backup'];
            $nousers = array_key_exists('users', $backup) ? empty($backup['users']) : !isset($backup['enrolments']);
            if ($nousers || !empty($backup['anonymized'])) {
                $missing[] = format_string((string) ($child->origin_course_fullname ?: $child->origin_course_shortname));
            }
        }
        return $missing;
    }

    /**
     * Id of the first account behind the copy that can no longer act (deleted or suspended), or null.
     *
     * @param stdClass $request {@see self::category_request()}.
     * @return int|null
     */
    private static function unusable_requester(stdClass $request): ?int {
        $userids = array_unique(array_filter(array_merge(
            [(int) $request->userid],
            array_map(static fn(stdClass $child): int => (int) $child->userid, $request->children)
        )));
        foreach ($userids as $userid) {
            if (!task_manager::task_user((object) ['usercreated' => $userid])) {
                return $userid;
            }
        }
        return null;
    }

    /**
     * How many course requests still have a manifest that R2v would check against the origin now.
     *
     * @param stdClass[] $children Course requests of the category request.
     * @return int
     */
    private static function unverified_manifests(array $children): int {
        if (!self::manifest_check_enabled()) {
            return 0;
        }
        $count = 0;
        foreach ($children as $child) {
            $manifest = json_decode((string) ($child->origin_manifest ?? ''), true);
            if (!is_array($manifest) || empty($manifest['verified'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Whether local_coursetransfer checks each restored course against its origin before removing it.
     *
     * @return bool
     */
    private static function manifest_check_enabled(): bool {
        if (class_exists(\local_coursetransfer\restore_manifest::class)) {
            return \local_coursetransfer\restore_manifest::enabled();
        }
        $value = get_config('local_coursetransfer', 'verifymanifest');
        return $value === false || $value === '' || (bool) $value;
    }

    /**
     * R2v: what local_coursetransfer could not verify of the category restore (empty when all is well).
     *
     * A coursetransfer without the verification API cannot vouch for the copy: that holds too.
     *
     * @param int $requestid Category request id.
     * @return string[]
     */
    private static function restore_issues(int $requestid): array {
        if (!method_exists(coursetransfer::class, 'verify_category_restore')) {
            return [get_string('deletion_verify_unavailable', 'local_coursetransfermanager')];
        }
        try {
            $verification = coursetransfer::verify_category_restore($requestid);
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
        if (!empty($verification->ok)) {
            return [];
        }
        $issues = array_values(array_filter(array_map('strval', (array) ($verification->issues ?? []))));
        return $issues ?: [get_string('deletion_verify_unavailable', 'local_coursetransfermanager')];
    }

    /**
     * R3: why the origin category no longer matches what was archived, or null when it does.
     *
     * Compares the origin's current courses with the ones this execution's request brought, and
     * holds while the origin reports content the task creator cannot see there: it was not copied,
     * and the removal in the origin is recursive. An origin that does not answer is a reason too:
     * nothing is deleted without checking.
     *
     * @param stdClass $execution Due execution row.
     * @param stdClass $user Identity presented to the origin.
     * @param stdClass[] $children Course requests of the category request.
     * @param string[] $notes Receives what the check could not verify.
     * @return string|null
     */
    private static function origin_changed(stdClass $execution, stdClass $user, array $children, array &$notes): ?string {
        $name = format_string((string) $execution->origincategoryname);
        try {
            $site = origin::site((int) $execution->originsiteid);
            $response = (new request($site))->origin_get_category_detail((int) $execution->origincategoryid, $user);
        } catch (\Throwable $e) {
            $response = (object) ['success' => false, 'errors' => [$e->getMessage()]];
        }
        if (empty($response->success)) {
            $errors = (array) ($response->errors ?? []);
            $codes = array_map(static fn($error): string => (string) (((array) $error)['code'] ?? ''), $errors);
            // The origin answers but refuses the account: say so, not that it did not answer (A4, CTM-014).
            $key = array_intersect($codes, ['17002', '17004', '17005'])
                ? 'deletion_held_originaccount' : 'deletion_held_originunreachable';
            return get_string($key, 'local_coursetransfermanager', (object) [
                'name' => $name,
                'detail' => origin::format_errors($errors),
            ]);
        }

        $hiddencategories = (int) ($response->data->hiddencategories ?? 0);
        $hiddencourses = (int) ($response->data->hiddencourses ?? 0);
        if ($hiddencategories > 0 || $hiddencourses > 0) {
            return get_string('deletion_held_originhidden', 'local_coursetransfermanager', (object) [
                'name' => $name,
                'categories' => $hiddencategories,
                'courses' => $hiddencourses,
                'user' => fullname($user),
            ]);
        }

        $archived = [];
        foreach ($children as $child) {
            $archived[(int) $child->origin_course_id] = (string) $child->origin_course_fullname;
        }

        $current = [];
        $modified = [];
        $knowsdates = false;
        foreach ((array) ($response->data->courses ?? []) as $course) {
            $course = (object) $course;
            $current[(int) $course->id] = (string) ($course->fullname ?? $course->id);
            if (isset($course->timemodified)) {
                $knowsdates = true;
                if ((int) $course->timemodified > (int) $execution->timecreated && isset($archived[(int) $course->id])) {
                    $modified[] = $current[(int) $course->id];
                }
            }
        }
        if (!$knowsdates) {
            // The current protocol does not report course dates: only the course list can be compared.
            $notes[] = get_string('r3_nodates', 'local_coursetransfermanager');
        }

        $changes = [];
        $added = array_diff_key($current, $archived);
        $removed = array_diff_key($archived, $current);
        if ($added) {
            $changes[] = get_string('r3_added', 'local_coursetransfermanager', implode(', ', $added));
        }
        if ($removed) {
            $changes[] = get_string('r3_removed', 'local_coursetransfermanager', implode(', ', $removed));
        }
        if ($modified) {
            $changes[] = get_string('r3_modified', 'local_coursetransfermanager', implode(', ', $modified));
        }
        if (!$changes) {
            return null;
        }
        return get_string('deletion_held_originchanged', 'local_coursetransfermanager', (object) [
            'name' => $name,
            'changes' => implode('; ', $changes),
        ]);
    }

    /**
     * Record on the execution the rule that holds its deletion and why, for the panel and the history.
     *
     * A relocation warning is kept: it already says why the copy cannot be checked.
     *
     * @param stdClass $execution Due execution row (id, errorcode).
     * @param string $rule Rule code.
     * @param string $reason Human reason.
     * @return void
     */
    private static function record_hold(stdClass $execution, string $rule, string $reason): void {
        global $DB;
        if (($execution->errorcode ?? null) === task_manager::WARNING_RELOCATION) {
            return;
        }
        $DB->update_record('local_coursetransfermanager_executions', (object) [
            'id' => (int) $execution->id,
            'errorcode' => self::HOLD_CODE_PREFIX . $rule,
            'errormessage' => $reason,
        ]);
    }

    /**
     * Forget a recorded hold once every rule passes again.
     *
     * @param int $executionid Execution id.
     * @return void
     */
    private static function clear_hold(int $executionid): void {
        global $DB;
        $DB->execute(
            "UPDATE {local_coursetransfermanager_executions} SET errorcode = NULL, errormessage = NULL
              WHERE id = :id AND " . $DB->sql_like('errorcode', ':prefix'),
            ['id' => $executionid, 'prefix' => $DB->sql_like_escape(self::HOLD_CODE_PREFIX) . '%']
        );
    }

    /**
     * The rule that last held a pending deletion and why, as recorded on its execution.
     *
     * @param stdClass $execution Execution row with errorcode and errormessage.
     * @return stdClass|null {rule, reason}, or null when nothing holds it.
     */
    public static function hold_of(stdClass $execution): ?stdClass {
        $code = (string) ($execution->errorcode ?? '');
        if (str_starts_with($code, self::HOLD_CODE_PREFIX)) {
            return (object) ['rule' => substr($code, strlen(self::HOLD_CODE_PREFIX)),
                'reason' => (string) $execution->errormessage];
        }
        if ($code === task_manager::WARNING_RELOCATION) {
            // Without the created category R2 can never pass: the warning is the reason.
            return (object) ['rule' => 'R2', 'reason' => (string) $execution->errormessage];
        }
        return null;
    }

    /**
     * Whether a task has deletions in the origin that may still be requested (pending, held or running).
     *
     * Its origin and destination are fixed meanwhile: the removal goes to the task's current origin.
     *
     * @param int $taskid Task id.
     * @return int How many.
     */
    public static function count_open(int $taskid): int {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::PENDING);
        return $DB->count_records_select(
            'local_coursetransfermanager_executions',
            "taskid = ? AND deletestatus $insql",
            array_merge([$taskid], $params)
        );
    }

    /**
     * Move a verified deletion to the approval queue.
     *
     * @param int $executionid Execution id.
     * @param int $now Current timestamp.
     * @return bool True when it moved (false: cancelled meanwhile).
     */
    private static function await_approval(int $executionid, int $now): bool {
        global $DB;
        $DB->execute(
            "UPDATE {local_coursetransfermanager_executions} SET deletestatus = :awaiting, timemodified = :now
              WHERE id = :id AND deletestatus IN (:pending1, :pending2)",
            ['awaiting' => self::STATUS_AWAITING_APPROVAL, 'now' => $now, 'id' => $executionid,
                'pending1' => self::STATUS_SCHEDULED, 'pending2' => self::STATUS_WARNED]
        );
        return $DB->get_field('local_coursetransfermanager_executions', 'deletestatus', ['id' => $executionid])
            === self::STATUS_AWAITING_APPROVAL;
    }

    /**
     * Claim a pending deletion before calling the origin.
     *
     * @param int $executionid Execution id.
     * @param int $now Current timestamp.
     * @return bool True when this process owns it; false when it was cancelled meanwhile.
     */
    private static function claim(int $executionid, int $now): bool {
        global $DB;
        $DB->execute(
            "UPDATE {local_coursetransfermanager_executions} SET deletestatus = :running, timemodified = :now
              WHERE id = :id AND deletestatus IN (:pending1, :pending2)",
            ['running' => self::STATUS_RUNNING, 'now' => $now, 'id' => $executionid,
                'pending1' => self::STATUS_SCHEDULED, 'pending2' => self::STATUS_WARNED]
        );
        return $DB->get_field('local_coursetransfermanager_executions', 'deletestatus', ['id' => $executionid])
            === self::STATUS_RUNNING;
    }

    /**
     * Give a claimed deletion back to the pending queue (its notice was already sent).
     *
     * @param int $executionid Execution id.
     * @return void
     */
    private static function release(int $executionid): void {
        global $DB;
        $DB->execute(
            "UPDATE {local_coursetransfermanager_executions} SET deletestatus = :warned, timemodified = :now
              WHERE id = :id AND deletestatus = :running",
            ['warned' => self::STATUS_WARNED, 'now' => time(), 'id' => $executionid, 'running' => self::STATUS_RUNNING]
        );
    }

    /**
     * Whether every course restored by the execution's request is still inside its archived category.
     *
     * @param stdClass $execution Execution row with requestid and destinationcategoryid.
     * @return bool
     */
    private static function archived_copy_is_intact(stdClass $execution): bool {
        global $DB;

        if (empty($execution->requestid)) {
            return false;
        }
        $children = $DB->get_field('local_coursetransfer_request', 'origin_category_requests', ['id' => $execution->requestid]);
        $children = array_filter(array_map('intval', (array) json_decode((string) $children, true)));
        if (empty($children)) {
            return false;
        }

        [$insql, $params] = $DB->get_in_or_equal($children, SQL_PARAMS_NAMED);
        $courseids = $DB->get_fieldset_select('local_coursetransfer_request', 'target_course_id', "id $insql", $params);
        $courseids = array_unique(array_map('intval', $courseids));
        if (count($courseids) !== count($children) || in_array(0, $courseids, true)) {
            return false;
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $paths = $DB->get_records_sql(
            "SELECT c.id, cc.path
               FROM {course} c
               JOIN {course_categories} cc ON cc.id = c.category
              WHERE c.id $insql",
            $params
        );
        if (count($paths) !== count($courseids)) {
            return false;
        }
        $root = '/' . (int) $execution->destinationcategoryid;
        foreach ($paths as $path) {
            if (!str_contains($path->path . '/', $root . '/')) {
                return false;
            }
        }
        return true;
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
        $DB->update_record('local_coursetransfermanager_executions', (object) [
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

        $due = $DB->get_records_select(
            'local_coursetransfermanager_executions',
            "deletestatus IN (?, ?) AND scheduleddeleteat IS NOT NULL AND scheduleddeleteat <= ?",
            [self::STATUS_SCHEDULED, self::STATUS_WARNED, $now],
            '',
            'id'
        );
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
            "UPDATE {local_coursetransfermanager_executions}
                SET deletestatus = :status, timemodified = :now
              WHERE taskid = :taskid
                AND origincategoryid = :origincategoryid
                AND deletestatus IN (:pending1, :pending2, :running)",
            [
                'status' => $status,
                'now' => time(),
                'taskid' => $taskid,
                'origincategoryid' => $origincategoryid,
                'pending1' => self::STATUS_SCHEDULED,
                'pending2' => self::STATUS_WARNED,
                'running' => self::STATUS_RUNNING,
            ]
        );
    }
}
