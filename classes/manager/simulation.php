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
 * Dry run of a task for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use stdClass;
use Throwable;

/**
 * What a task would archive, delete from the origin and prune, right now, without doing it.
 *
 * Only reads: the origin is asked for its categories and their details, nothing is written.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class simulation {
    /**
     * Simulate a task.
     *
     * The origin is asked as the task creator, the same identity the real run uses, so what
     * the creator can see in the origin is what the simulation shows, whoever simulates.
     *
     * @param stdClass $task Task record.
     * @param int|null $now Reference timestamp.
     * @return stdClass {archive: stdClass[], archiveerror: string, identity: string,
     *                   deletions: stdClass[], prunings: stdClass[]}
     */
    public static function run(stdClass $task, ?int $now = null): stdClass {
        $now = $now ?? time();
        $user = task_manager::task_user($task);
        if ($user) {
            $archive = self::archive($task, $user, $now, $error);
        } else {
            // Like the real run: without a usable creator nothing is asked under another identity.
            $archive = [];
            $error = get_string('creatorunavailable', 'local_coursetransfermanager', (int) $task->usercreated);
        }
        return (object) [
            'archive' => $archive,
            'archiveerror' => $error,
            'identity' => $user ? fullname($user) : '',
            'deletions' => self::deletions($task, $now),
            'prunings' => self::prunings($task, $now),
        ];
    }

    /**
     * Origin categories the next run would archive, flagging the ones the per-run cap leaves for later.
     *
     * @param stdClass $task Task record.
     * @param stdClass $user Identity presented to the origin.
     * @param int $now Reference timestamp.
     * @param string|null $error Receives why the origin could not be read ('' when it could).
     * @return stdClass[] {name, idnumber, year, thisrun}
     */
    private static function archive(stdClass $task, stdClass $user, int $now, ?string &$error): array {
        $error = '';
        try {
            $pending = rotation::to_archive($task, $now, $user);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            return [];
        }
        $max = rotation::max_per_tick();
        $rows = [];
        foreach (array_values($pending) as $index => $category) {
            $rows[] = (object) [
                'name' => (string) $category->name,
                'idnumber' => (string) $category->idnumber,
                'year' => (int) $category->year,
                'thisrun' => $index < $max,
            ];
        }
        return $rows;
    }

    /**
     * Pending origin deletions of the task with what every safety rule says today.
     *
     * @param stdClass $task Task record.
     * @param int $now Reference timestamp.
     * @return stdClass[] {name, date, due, status, rule, reason, passed, needsapproval, notes}
     */
    private static function deletions(stdClass $task, int $now): array {
        $rows = [];
        foreach (deletion_manager::get_checkable((int) $task->id) as $execution) {
            // Read only: manifests not verified yet are reported as pending, not checked (that would write).
            $check = deletion_manager::check($execution, true);
            if ($check->rule !== null) {
                $status = 'held';
            } else if ($check->pending !== null) {
                $status = 'pending';
            } else if ($execution->deletestatus === deletion_manager::STATUS_AWAITING_APPROVAL || $check->needsapproval) {
                $status = 'approval';
            } else {
                $status = ((int) $execution->scheduleddeleteat <= $now) ? 'delete' : 'scheduled';
            }
            $rows[] = (object) [
                'name' => (string) $execution->origincategoryname,
                'date' => (int) $execution->scheduleddeleteat,
                'status' => $status,
                'rule' => (string) $check->rule,
                'reason' => (string) ($check->reason ?? $check->pending),
                'passed' => $check->passed,
                'notes' => $check->notes,
            ];
        }
        return $rows;
    }

    /**
     * Archive categories the policy prunes today, with the state of their candidate.
     *
     * @param stdClass $task Task record.
     * @param int $now Reference timestamp.
     * @return stdClass[] {name, idnumber, year, status, date}
     */
    private static function prunings(stdClass $task, int $now): array {
        global $DB;

        $rows = [];
        foreach (rotation::to_prune($task, $now) as $category) {
            $candidate = $DB->get_record_select(
                'local_coursetransfermanager_prune',
                'categoryid = ? AND status IN (?, ?)',
                [(int) $category->id, prune_manager::STATUS_ANNOUNCED, prune_manager::STATUS_EXCLUDED],
                'id, status, graceuntil',
                IGNORE_MULTIPLE
            );
            if (!$candidate) {
                $status = 'announce';
                $date = $now + lifecycle_config::grace_days() * DAYSECS;
            } else if ($candidate->status === prune_manager::STATUS_EXCLUDED) {
                $status = 'excluded';
                $date = 0;
            } else {
                $status = ((int) $candidate->graceuntil <= $now) ? 'prune' : 'announced';
                $date = (int) $candidate->graceuntil;
            }
            $rows[] = (object) [
                'name' => format_string((string) $category->name),
                'idnumber' => (string) $category->idnumber,
                'year' => (int) $category->year,
                'status' => $status,
                'date' => $date,
            ];
        }
        return $rows;
    }
}
