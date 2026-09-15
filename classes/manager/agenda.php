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
 * Upcoming activity agenda for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use stdClass;

/**
 * Builds the chronological "coming up" list: next executions and pending
 * deletions/prunings, so nothing destructive is ever invisible.
 *
 * Raw data only — labels, countdowns and actions are rendering concerns.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agenda {
    /** @var string A task will fire. */
    public const TYPE_EXECUTION = 'execution';

    /** @var string A category will be deleted in the ORIGIN platform. */
    public const TYPE_DELETION = 'deletion';

    /** @var string A category of the local archive will be pruned. */
    public const TYPE_PRUNE = 'prune';

    /**
     * All upcoming items, sorted by date.
     *
     * @param int $now Current timestamp.
     * @param int|null $taskid Restrict to one task, or null for all.
     * @return stdClass[] Items: {type, date, ...type-specific fields}.
     */
    public static function get_items(int $now, ?int $taskid = null): array {
        $items = array_merge(
            self::upcoming_executions($taskid),
            self::pending_deletions($taskid),
            self::pending_prunings($taskid)
        );

        usort($items, static function (stdClass $a, stdClass $b): int {
            return $a->date <=> $b->date;
        });

        return $items;
    }

    /**
     * Next scheduled fire of every enabled task.
     *
     * @param int|null $taskid Restrict to one task.
     * @return stdClass[]
     */
    private static function upcoming_executions(?int $taskid): array {
        global $DB;

        $select = 'enabled = 1 AND nextruntime IS NOT NULL';
        $params = [];
        if ($taskid !== null) {
            $select .= ' AND id = ?';
            $params[] = $taskid;
        }

        $items = [];
        foreach ($DB->get_records_select('local_ctm_tasks', $select, $params, 'nextruntime ASC') as $task) {
            // What the policy will actually bring on that date: the academic year
            // that will have outstayed its time in production by then, not the
            // current one. Nobody has to work that out in their head.
            $mask = rotation::mask($task);
            $targetyear = $mask ? rotation::archive_threshold($task, (int) $task->nextruntime) : null;

            $items[] = (object) [
                'type' => self::TYPE_EXECUTION,
                'date' => (int) $task->nextruntime,
                'taskid' => (int) $task->id,
                'taskname' => $task->name,
                'originsiteid' => (int) $task->originsiteid,
                'categorypattern' => (string) $task->categorypattern,
                'targetyear' => $targetyear,
                'resolvedpattern' => ($mask && $targetyear !== null)
                    ? academic_year::example_for($mask, $targetyear)
                    : (string) $task->categorypattern,
            ];
        }
        return $items;
    }

    /**
     * Pending (cancellable) deletions in the origin platform.
     *
     * @param int|null $taskid Restrict to one task.
     * @return stdClass[]
     */
    private static function pending_deletions(?int $taskid): array {
        $items = [];
        foreach (deletion_manager::get_pending($taskid) as $deletion) {
            $items[] = (object) [
                'type' => self::TYPE_DELETION,
                'date' => (int) $deletion->scheduleddeleteat,
                'executionid' => (int) $deletion->id,
                'taskid' => (int) $deletion->taskid,
                'taskname' => $deletion->taskname,
                'originsiteid' => (int) $deletion->originsiteid,
                'categoryname' => (string) $deletion->origincategoryname,
                'categoryidnumber' => (string) $deletion->origincategoryidnumber,
                'deletestatus' => $deletion->deletestatus,
            ];
        }
        return $items;
    }

    /**
     * Announced pruning candidates of the local archive.
     *
     * @param int|null $taskid Restrict to one task.
     * @return stdClass[]
     */
    private static function pending_prunings(?int $taskid): array {
        $items = [];
        foreach (prune_manager::get_pending($taskid) as $candidate) {
            $items[] = (object) [
                'type' => self::TYPE_PRUNE,
                'date' => (int) $candidate->graceuntil,
                'pruneid' => (int) $candidate->id,
                'taskid' => (int) $candidate->taskid,
                'taskname' => $candidate->taskname,
                'categoryid' => (int) $candidate->categoryid,
                'categoryname' => (string) $candidate->categoryname,
                'announcedat' => (int) $candidate->announcedat,
            ];
        }
        return $items;
    }
}
