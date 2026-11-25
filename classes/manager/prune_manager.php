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
 * Two-phase archive pruning for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use core_course_category;
use local_coursetransfermanager\event\prune_announced;
use local_coursetransfermanager\event\prune_excluded;
use local_coursetransfermanager\event\prune_executed;
use moodle_exception;
use stdClass;

/**
 * Prunes the local archive safely and in two phases.
 *
 * Phase 1 (detect): categories older than the retention are ANNOUNCED as
 * candidates with a grace period, never deleted on the spot. Only categories
 * each task manages (brought by its executions or explicitly adopted) are
 * ever considered, and the year is extracted strictly — a code like MED1042
 * is not a year.
 *
 * Phase 2 (execute): once the grace period ends, candidates still announced
 * (not excluded by an admin) are deleted.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prune_manager {
    /** @var string Candidate announced, waiting out its grace period. */
    public const STATUS_ANNOUNCED = 'announced';

    /** @var string An admin excluded the category from pruning. */
    public const STATUS_EXCLUDED = 'excluded';

    /** @var string The category was pruned (or no longer existed). */
    public const STATUS_DONE = 'done';

    /** @var string Claimed by the cron right before deleting: no longer excludable (B4). */
    public const STATUS_RUNNING = 'running';

    /**
     * Phase 1: announce new pruning candidates with a grace period.
     *
     * @param int $now Current timestamp.
     * @param int $gracedays Days between the announcement and the deletion (at least one).
     * @return stdClass[] Newly announced candidate rows (for notification).
     */
    public static function detect(int $now, int $gracedays): array {
        global $DB;

        // Never zero: announcing and deleting in the same pass would void the notice.
        $gracedays = max(1, $gracedays);

        $tasks = $DB->get_records_select(
            'local_coursetransfermanager_tasks',
            'enabled = 1 AND targetcategoryid IS NOT NULL AND targetcategoryid > 0 AND destinationkeepyears > 0'
        );

        $announced = [];

        foreach ($tasks as $task) {
            // Each task only announces what IT manages (brought or adopted), under its own destination.
            foreach (rotation::to_prune($task, $now) as $category) {
                // Never re-announce a live candidate nor one an admin excluded.
                $exists = $DB->record_exists_select(
                    'local_coursetransfermanager_prune',
                    'categoryid = ? AND status IN (?, ?)',
                    [(int) $category->id, self::STATUS_ANNOUNCED, self::STATUS_EXCLUDED]
                );
                if ($exists) {
                    continue;
                }

                $candidate = (object) [
                    'taskid' => (int) $task->id,
                    'categoryid' => (int) $category->id,
                    'categoryname' => format_string((string) $category->name),
                    'announcedat' => $now,
                    'graceuntil' => $now + ($gracedays * DAYSECS),
                    'status' => self::STATUS_ANNOUNCED,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $candidate->id = $DB->insert_record('local_coursetransfermanager_prune', $candidate);
                prune_announced::log((int) $candidate->id, $candidate->categoryname, 'policy');
                mtrace('Pruning candidate announced: category id=' . $candidate->categoryid
                    . ' "' . $candidate->categoryname . '" (grace until ' . userdate($candidate->graceuntil) . ')');
                $announced[] = $candidate;
            }
        }

        return $announced;
    }

    /**
     * Phase 2: prune announced candidates whose grace period ended.
     *
     * Each candidate is checked again right before deleting: the policy may have
     * changed during the grace period (see {@see rotation::is_prunable()}).
     *
     * @param int $now Current timestamp.
     * @return stdClass[] Results: {candidate, outcome: done|missing|invalid|excluded|retry, detail}.
     */
    public static function execute_due(int $now): array {
        global $DB;

        // Candidates of disabled tasks are frozen (pausing a task pauses its deletions).
        $due = $DB->get_records_sql(
            "SELECT p.*
               FROM {local_coursetransfermanager_prune} p
               JOIN {local_coursetransfermanager_tasks} t ON t.id = p.taskid
              WHERE p.status = ? AND p.graceuntil <= ? AND t.enabled = 1
           ORDER BY p.graceuntil ASC",
            [self::STATUS_ANNOUNCED, $now]
        );

        $results = [];
        foreach ($due as $candidate) {
            $category = core_course_category::get((int) $candidate->categoryid, IGNORE_MISSING, true);
            if (!$category) {
                // Already gone (deleted by hand): close the candidate quietly.
                self::mark($candidate->id, self::STATUS_DONE);
                $results[] = (object) ['candidate' => $candidate, 'outcome' => 'missing', 'detail' => ''];
                continue;
            }

            // Not prunable anymore: excluded for good, since only a human should bring it back.
            $task = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $candidate->taskid]);
            if (!$task || !rotation::is_prunable($task, (int) $category->id, $now)) {
                self::mark($candidate->id, self::STATUS_EXCLUDED);
                $detail = get_string('prune_invalid', 'local_coursetransfermanager', (string) $candidate->categoryname);
                mtrace('Pruning skipped for category id=' . $category->id . ': no longer meets the policy.');
                prune_excluded::log((int) $candidate->id, (string) $candidate->categoryname, 'revalidation', null, $detail);
                $results[] = (object) ['candidate' => $candidate, 'outcome' => 'invalid', 'detail' => $detail];
                continue;
            }

            // Claimed with a conditional update: an exclusion made while the cron prunes the previous ones wins (B4).
            if (!self::claim((int) $candidate->id)) {
                // Excluded by a person meanwhile: that is the expected outcome, not an error to report.
                $results[] = (object) ['candidate' => $candidate, 'outcome' => self::STATUS_EXCLUDED, 'detail' => ''];
                continue;
            }
            try {
                mtrace('Pruning archived category id=' . $category->id . ' "' . $candidate->categoryname . '"');
                $category->delete_full(false);
                self::mark($candidate->id, self::STATUS_DONE);
                prune_executed::log((int) $candidate->id, (string) $candidate->categoryname, 'policy+revalidation');
                $results[] = (object) ['candidate' => $candidate, 'outcome' => self::STATUS_DONE, 'detail' => ''];
            } catch (\Throwable $e) {
                // Back to announced so the next tick retries (and it can still be excluded meanwhile).
                self::mark($candidate->id, self::STATUS_ANNOUNCED);
                mtrace('Pruning failed for category id=' . $candidate->categoryid . ': ' . $e->getMessage());
                $results[] = (object) ['candidate' => $candidate, 'outcome' => 'retry', 'detail' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Postpone the grace of EVERY due candidate (emergency pause), so
     * lifting the pause never triggers an immediate mass pruning.
     *
     * @param int $now Current timestamp.
     * @param int $seconds Postpone window.
     * @return int How many candidates were postponed.
     */
    public static function postpone_due(int $now, int $seconds): int {
        global $DB;

        $due = $DB->get_records_select(
            'local_coursetransfermanager_prune',
            'status = ? AND graceuntil <= ?',
            [self::STATUS_ANNOUNCED, $now],
            '',
            'id'
        );
        foreach ($due as $candidate) {
            $DB->update_record('local_coursetransfermanager_prune', (object) [
                'id' => $candidate->id,
                'graceuntil' => $now + $seconds,
                'timemodified' => $now,
            ]);
        }
        return count($due);
    }

    /**
     * Take an announced candidate for pruning, unless it was excluded or taken meanwhile (B4).
     *
     * @param int $pruneid
     * @return bool True when this run took it.
     */
    public static function claim(int $pruneid): bool {
        global $DB;
        if ($DB->get_field('local_coursetransfermanager_prune', 'status', ['id' => $pruneid]) !== self::STATUS_ANNOUNCED) {
            return false;
        }
        $DB->execute(
            "UPDATE {local_coursetransfermanager_prune} SET status = :running, timemodified = :now
              WHERE id = :id AND status = :announced",
            ['running' => self::STATUS_RUNNING, 'now' => time(), 'id' => $pruneid, 'announced' => self::STATUS_ANNOUNCED]
        );
        return $DB->get_field('local_coursetransfermanager_prune', 'status', ['id' => $pruneid]) === self::STATUS_RUNNING;
    }

    /**
     * Exclude a candidate from pruning, recording who and when.
     *
     * @param int $pruneid Candidate id.
     * @param int $userid User excluding.
     * @return stdClass The updated candidate row.
     * @throws moodle_exception When the candidate is not announced anymore.
     */
    public static function exclude(int $pruneid, int $userid): stdClass {
        global $DB;

        $current = $DB->get_record('local_coursetransfermanager_prune', ['id' => $pruneid], 'status', MUST_EXIST);
        if ($current->status !== self::STATUS_ANNOUNCED) {
            throw new moodle_exception('prunenotexcludable', 'local_coursetransfermanager');
        }

        // Conditional update: the cron may be closing the same candidate right now.
        $now = time();
        $DB->execute(
            "UPDATE {local_coursetransfermanager_prune}
                SET status = :excluded, excludedby = :userid, excludedat = :now1, timemodified = :now2
              WHERE id = :id AND status = :announced",
            ['excluded' => self::STATUS_EXCLUDED, 'userid' => $userid, 'now1' => $now, 'now2' => $now,
                'id' => $pruneid, 'announced' => self::STATUS_ANNOUNCED]
        );

        $candidate = $DB->get_record('local_coursetransfermanager_prune', ['id' => $pruneid], '*', MUST_EXIST);
        if ($candidate->status !== self::STATUS_EXCLUDED || (int) $candidate->excludedby !== $userid) {
            throw new moodle_exception('prunenotexcludable', 'local_coursetransfermanager');
        }
        prune_excluded::log($pruneid, (string) $candidate->categoryname, 'manual');
        return $candidate;
    }

    /**
     * Live candidates (announced, still cancellable), oldest grace first.
     *
     * @param int|null $taskid Restrict to one task, or null for all.
     * @return stdClass[] Candidate rows with taskname.
     */
    public static function get_pending(?int $taskid = null): array {
        global $DB;

        $params = [self::STATUS_ANNOUNCED];
        $taskwhere = '';
        if ($taskid !== null) {
            $taskwhere = ' AND p.taskid = ?';
            $params[] = $taskid;
        }

        $sql = "SELECT p.*, t.name AS taskname, t.usercreated, t.notifylevel, t.notifyrecipients
                  FROM {local_coursetransfermanager_prune} p
                  JOIN {local_coursetransfermanager_tasks} t ON t.id = p.taskid
                 WHERE p.status = ?" . $taskwhere . "
              ORDER BY p.graceuntil ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Set the final status of a candidate.
     *
     * @param int $pruneid Candidate id.
     * @param string $status New status.
     * @return void
     */
    private static function mark(int $pruneid, string $status): void {
        global $DB;
        // Only from announced or claimed: never overwrite an exclusion made in the meantime.
        $DB->execute(
            "UPDATE {local_coursetransfermanager_prune} SET status = :status, timemodified = :now
              WHERE id = :id AND status IN (:announced, :running)",
            ['status' => $status, 'now' => time(), 'id' => $pruneid, 'announced' => self::STATUS_ANNOUNCED,
                'running' => self::STATUS_RUNNING]
        );
    }
}
