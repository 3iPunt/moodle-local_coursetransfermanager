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
 * Executions read model for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use stdClass;

/**
 * Assembles the data of the tracking screen: live executions, the unified
 * history (restores, origin deletions and prunings) and the tab counters.
 *
 * The DISPLAY status of an execution merges its own state with the deletion
 * lifecycle: error > deleted > cancelled > completed > launched.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class executions {

    /** @var string[] Display statuses selectable in the history filter. */
    public const DISPLAY_STATUSES = ['success', 'completed', 'error', 'cancelled', 'deleted'];

    /**
     * Display status of an execution row.
     *
     * @param stdClass $execution Execution row (status + deletestatus).
     * @return string One of DISPLAY_STATUSES.
     */
    public static function display_status(stdClass $execution): string {
        if ($execution->status === task_manager::STATUS_ERROR
                || $execution->deletestatus === deletion_manager::STATUS_FAILED) {
            return 'error';
        }
        if ($execution->deletestatus === deletion_manager::STATUS_DONE) {
            return 'deleted';
        }
        if ($execution->deletestatus === deletion_manager::STATUS_CANCELLED) {
            return 'cancelled';
        }
        return $execution->status === task_manager::STATUS_COMPLETED ? 'completed' : 'success';
    }

    /**
     * Live executions: launched and still restoring in the background.
     *
     * @param int|null $taskid Restrict to one task.
     * @return stdClass[] Execution rows with taskname and originsiteid.
     */
    public static function get_live(?int $taskid = null): array {
        global $DB;

        $params = [task_manager::STATUS_SUCCESS];
        $where = 'e.status = ?';
        if ($taskid !== null) {
            $where .= ' AND e.taskid = ?';
            $params[] = $taskid;
        }

        $sql = "SELECT e.id, e.taskid, t.name AS taskname, t.originsiteid,
                       e.origincategoryname, e.origincategoryidnumber, e.requestid, e.timecreated
                  FROM {local_ctm_executions} e
                  JOIN {local_ctm_tasks} t ON t.id = e.taskid
                 WHERE $where
              ORDER BY e.timecreated DESC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Unified history page: restores (with their deletion sub-state) plus
     * executed/excluded prunings, newest first.
     *
     * @param array $filters taskid, status (display status), fromdate, todate.
     * @param int $page Zero-based page.
     * @param int $perpage Page size.
     * @return stdClass {rows: stdClass[], total: int}
     */
    public static function get_history(array $filters, int $page, int $perpage): stdClass {
        global $DB;

        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['taskid'])) {
            $where[] = 'e.taskid = :taskid';
            $params['taskid'] = (int)$filters['taskid'];
        }
        if (!empty($filters['fromdate'])) {
            $where[] = 'e.timecreated >= :fromdate';
            $params['fromdate'] = (int)$filters['fromdate'];
        }
        if (!empty($filters['todate'])) {
            $where[] = 'e.timecreated <= :todate';
            $params['todate'] = (int)$filters['todate'];
        }
        switch ($filters['status'] ?? '') {
            case 'error':
                $where[] = "(e.status = 'error' OR e.deletestatus = 'failed')";
                break;
            case 'deleted':
                $where[] = "e.deletestatus = 'done'";
                break;
            case 'cancelled':
                $where[] = "e.deletestatus = 'cancelled'";
                break;
            case 'completed':
                $where[] = "e.status = 'completed' AND (e.deletestatus IS NULL OR e.deletestatus IN ('scheduled', 'warned'))";
                break;
            case 'success':
                $where[] = "e.status = 'success' AND (e.deletestatus IS NULL OR e.deletestatus IN ('scheduled', 'warned'))";
                break;
        }

        $wheresql = implode(' AND ', $where);
        $base = "FROM {local_ctm_executions} e JOIN {local_ctm_tasks} t ON t.id = e.taskid WHERE $wheresql";

        $total = $DB->count_records_sql("SELECT COUNT(1) $base", $params);
        $rows = $DB->get_records_sql(
            "SELECT e.*, t.name AS taskname, t.originsiteid $base ORDER BY e.timecreated DESC",
            $params, $page * $perpage, $perpage
        );

        foreach ($rows as $row) {
            $row->kind = 'restore';
            $row->displaystatus = self::display_status($row);
        }

        // Pruning history joins the same page only when no restore-only filter excludes it.
        $prunerows = [];
        $statusfilter = $filters['status'] ?? '';
        if ($statusfilter === '' || $statusfilter === 'deleted') {
            $pwhere = ["p.status IN ('done', 'excluded')"];
            $pparams = [];
            if (!empty($filters['taskid'])) {
                $pwhere[] = 'p.taskid = :taskid';
                $pparams['taskid'] = (int)$filters['taskid'];
            }
            if (!empty($filters['fromdate'])) {
                $pwhere[] = 'p.timemodified >= :fromdate';
                $pparams['fromdate'] = (int)$filters['fromdate'];
            }
            if (!empty($filters['todate'])) {
                $pwhere[] = 'p.timemodified <= :todate';
                $pparams['todate'] = (int)$filters['todate'];
            }
            if ($statusfilter === 'deleted') {
                $pwhere[] = "p.status = 'done'";
            }
            $prunerows = $DB->get_records_sql(
                "SELECT p.id, p.taskid, t.name AS taskname, p.categoryname, p.status, p.timemodified
                   FROM {local_ctm_prune} p
                   JOIN {local_ctm_tasks} t ON t.id = p.taskid
                  WHERE " . implode(' AND ', $pwhere) . "
               ORDER BY p.timemodified DESC",
                $pparams, 0, $perpage
            );
            foreach ($prunerows as $prune) {
                $prune->kind = 'prune';
                $prune->displaystatus = $prune->status === prune_manager::STATUS_DONE ? 'deleted' : 'cancelled';
                $prune->timecreated = (int)$prune->timemodified;
            }
            $total += count($prunerows);
        }

        $merged = array_merge(array_values($rows), array_values($prunerows));
        usort($merged, static fn(stdClass $a, stdClass $b): int => $b->timecreated <=> $a->timecreated);
        $merged = array_slice($merged, 0, $perpage);

        return (object)['rows' => $merged, 'total' => $total];
    }

    /**
     * Counters for the tab badges.
     *
     * @param int|null $taskid Restrict to one task.
     * @return stdClass {live: int, deletions: int}
     */
    public static function get_counts(?int $taskid = null): stdClass {
        return (object)[
            'live' => count(self::get_live($taskid)),
            'deletions' => count(deletion_manager::get_pending($taskid)) + count(prune_manager::get_pending($taskid)),
        ];
    }
}
