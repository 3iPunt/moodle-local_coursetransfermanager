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
use moodle_exception;
use stdClass;

/**
 * Prunes the local archive safely and in two phases.
 *
 * Phase 1 (detect): categories older than the retention are ANNOUNCED as
 * candidates with a grace period, never deleted on the spot. Only categories
 * the manager itself created (tracked via destinationcategoryid on the
 * executions) are ever considered, and the year is extracted strictly — a
 * code like MED1042 is not a year (CTM-001).
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

    /**
     * Extract the academic year of an idnumber, strictly.
     *
     * Only standalone 20xx groups count; when a range like 2025-2026 is
     * present the most recent year wins (safer: prunes later, never earlier).
     *
     * @param string $idnumber Category idnumber.
     * @return int|null Year, or null when the idnumber carries no year.
     */
    public static function extract_year(string $idnumber): ?int {
        if (!preg_match_all('/(?<!\d)(20\d{2})(?!\d)/', $idnumber, $matches)) {
            return null;
        }
        return max(array_map('intval', $matches[1]));
    }

    /**
     * Phase 1: announce new pruning candidates with a grace period.
     *
     * @param int $now Current timestamp.
     * @param int $gracedays Days between the announcement and the deletion.
     * @return stdClass[] Newly announced candidate rows (for notification).
     */
    public static function detect(int $now, int $gracedays): array {
        global $DB;

        // Only categories the manager created are ever pruning candidates (CTM-001).
        $managed = $DB->get_fieldset_select(
            'local_ctm_executions',
            'DISTINCT destinationcategoryid',
            'destinationcategoryid IS NOT NULL'
        );
        if (empty($managed)) {
            return [];
        }
        $managed = array_map('intval', $managed);

        $tasks = $DB->get_records_select(
            'local_ctm_tasks',
            'enabled = 1 AND targetcategoryid IS NOT NULL AND targetcategoryid > 0 AND destinationkeepyears > 0'
        );

        $announced = [];
        $currentyear = (int) date('Y', $now);

        foreach ($tasks as $task) {
            try {
                $parent = core_course_category::get((int) $task->targetcategoryid, IGNORE_MISSING, true);
            } catch (\Throwable $e) {
                $parent = null;
            }
            if (!$parent) {
                continue;
            }

            $cutoff = $currentyear - (int) $task->destinationkeepyears;

            foreach ($parent->get_children() as $child) {
                if (!in_array((int) $child->id, $managed, true)) {
                    continue;
                }
                $year = self::extract_year((string) $child->idnumber);
                if ($year === null || $year > $cutoff) {
                    continue;
                }
                // Never re-announce a live candidate nor one an admin excluded.
                $exists = $DB->record_exists_select(
                    'local_ctm_prune',
                    'categoryid = ? AND status IN (?, ?)',
                    [(int) $child->id, self::STATUS_ANNOUNCED, self::STATUS_EXCLUDED]
                );
                if ($exists) {
                    continue;
                }

                $candidate = (object) [
                    'taskid' => (int) $task->id,
                    'categoryid' => (int) $child->id,
                    'categoryname' => $child->get_formatted_name(),
                    'announcedat' => $now,
                    'graceuntil' => $now + ($gracedays * DAYSECS),
                    'status' => self::STATUS_ANNOUNCED,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $candidate->id = $DB->insert_record('local_ctm_prune', $candidate);
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
     * @param int $now Current timestamp.
     * @return stdClass[] Results: {candidate, outcome: done|missing|retry, detail}.
     */
    public static function execute_due(int $now): array {
        global $DB;

        // Candidates of disabled tasks are frozen (pausing a task pauses its deletions).
        $due = $DB->get_records_sql(
            "SELECT p.*
               FROM {local_ctm_prune} p
               JOIN {local_ctm_tasks} t ON t.id = p.taskid
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
            try {
                mtrace('Pruning archived category id=' . $category->id . ' "' . $candidate->categoryname . '"');
                $category->delete_full(false);
                self::mark($candidate->id, self::STATUS_DONE);
                $results[] = (object) ['candidate' => $candidate, 'outcome' => self::STATUS_DONE, 'detail' => ''];
            } catch (\Throwable $e) {
                // Leave it announced so the next tick retries.
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

        $due = $DB->get_records_select('local_ctm_prune',
            'status = ? AND graceuntil <= ?', [self::STATUS_ANNOUNCED, $now], '', 'id');
        foreach ($due as $candidate) {
            $DB->update_record('local_ctm_prune', (object) [
                'id' => $candidate->id,
                'graceuntil' => $now + $seconds,
                'timemodified' => $now,
            ]);
        }
        return count($due);
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

        $candidate = $DB->get_record('local_ctm_prune', ['id' => $pruneid], '*', MUST_EXIST);
        if ($candidate->status !== self::STATUS_ANNOUNCED) {
            throw new moodle_exception('prunenotexcludable', 'local_coursetransfermanager');
        }

        $candidate->status = self::STATUS_EXCLUDED;
        $candidate->excludedby = $userid;
        $candidate->excludedat = time();
        $candidate->timemodified = time();
        $DB->update_record('local_ctm_prune', $candidate);

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
                  FROM {local_ctm_prune} p
                  JOIN {local_ctm_tasks} t ON t.id = p.taskid
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
        $DB->update_record('local_ctm_prune', (object) [
            'id' => $pruneid,
            'status' => $status,
            'timemodified' => time(),
        ]);
    }
}
