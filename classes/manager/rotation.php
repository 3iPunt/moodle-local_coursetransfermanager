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
 * Conservation policy (rotation) for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use core_course_category;
use stdClass;

/**
 * Decides what to archive and what to prune, by academic year.
 *
 * The whole policy is two thresholds. With A = current academic year,
 * P = years kept in the origin and V = years kept in the archive:
 *
 *     archive  →  year <= A - P
 *     prune    →  year <= A - P - V
 *
 * Deleting for good at P + V years is a consequence, not a third setting.
 * Because both thresholds are relative to the execution date, a task
 * configured once keeps working for ever: every course the rotation moves
 * one step down on its own. It is also idempotent — if a year is missed
 * (cron down, plugin disabled) the next run detects two years overdue and
 * catches up, with no manual repair.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rotation {
    /** @var int Default cap of categories archived in a single execution. */
    public const DEFAULT_MAX_PER_TICK = 3;

    /**
     * Newest academic year that must LEAVE the origin.
     *
     * @param stdClass $task Task record (originkeepyears).
     * @param int|null $time Reference timestamp.
     * @return int Every year <= this one is archived.
     */
    public static function archive_threshold(stdClass $task, ?int $time = null): int {
        return academic_year::current_year($time) - max(1, (int) $task->originkeepyears);
    }

    /**
     * Newest academic year that must LEAVE the archive.
     *
     * @param stdClass $task Task record (originkeepyears + destinationkeepyears).
     * @param int|null $time Reference timestamp.
     * @return int Every year <= this one is pruned.
     */
    public static function prune_threshold(stdClass $task, ?int $time = null): int {
        return self::archive_threshold($task, $time) - max(1, (int) $task->destinationkeepyears);
    }

    /**
     * Categories of the origin that have outstayed their time in production.
     *
     * Oldest first: if the cap kicks in, the most urgent go first. Categories
     * whose idnumber does not match the mask are never touched, and neither
     * are those the task already archived successfully in this cycle.
     *
     * @param stdClass $task Task record.
     * @param int|null $time Reference timestamp.
     * @return stdClass[] {id, name, idnumber, year} of the origin, oldest first.
     */
    public static function to_archive(stdClass $task, ?int $time = null): array {
        $mask = self::mask($task);
        if (!$mask) {
            return [];
        }

        $threshold = self::archive_threshold($task, $time);
        $archived = self::already_archived_years($task);

        $candidates = [];
        foreach (origin::categories((int) $task->originsiteid) as $category) {
            // A remote on an older release may not report the idnumber: skip it
            // instead of failing the whole run (the contract is only additive).
            if (empty($category->idnumber)) {
                continue;
            }
            $year = $mask->year_of((string) $category->idnumber);
            if ($year === null || $year > $threshold) {
                continue;
            }
            if (in_array($year, $archived, true)) {
                continue;
            }
            $category->year = $year;
            $candidates[] = $category;
        }

        usort($candidates, static fn(stdClass $a, stdClass $b): int => $a->year <=> $b->year);

        return $candidates;
    }

    /**
     * Local archive categories that have outstayed their time in the archive.
     *
     * Only categories this plugin manages are considered — the safety rule
     * that keeps foreign content untouched.
     *
     * @param stdClass $task Task record.
     * @param int|null $time Reference timestamp.
     * @return stdClass[] {id, name, idnumber, year} of the local archive, oldest first.
     */
    public static function to_prune(stdClass $task, ?int $time = null): array {
        $mask = self::mask($task);
        if (!$mask) {
            return [];
        }

        $threshold = self::prune_threshold($task, $time);
        $candidates = [];

        foreach (self::managed_archive_categories($task) as $category) {
            $year = $mask->year_of((string) $category->idnumber);
            if ($year === null || $year > $threshold) {
                continue;
            }
            $category->year = $year;
            $candidates[] = $category;
        }

        usort($candidates, static fn(stdClass $a, stdClass $b): int => $a->year <=> $b->year);

        return $candidates;
    }

    /**
     * Where every academic year sits over the coming courses.
     *
     * Feeds the lifecycle table: for each academic year it reports what stays
     * in the origin, what sits in the archive and what gets deleted, marking
     * which of those categories exist today and which are a projection.
     *
     * @param stdClass $task Task record.
     * @param int $years How many academic years to project.
     * @param int|null $time Reference timestamp.
     * @param bool $readorigin Whether the origin platform is queried for the real categories.
     * @return stdClass[] One row per academic year.
     */
    public static function project(
        stdClass $task,
        int $years = 6,
        ?int $time = null,
        bool $readorigin = true
    ): array {
        $mask = self::mask($task);
        if (!$mask) {
            return [];
        }

        $p = max(1, (int) $task->originkeepyears);
        $v = max(1, (int) $task->destinationkeepyears);
        $current = academic_year::current_year($time);

        // What exists today, to tell facts from projections. Reading the origin
        // costs an HTTP round trip, so a caller recalculating on every keystroke
        // asks for the arithmetic only; the projection is identical either way,
        // it just cannot mark which remote years already exist.
        $inorigin = [];
        if ($readorigin) {
            foreach (origin::categories((int) $task->originsiteid) as $category) {
                $year = empty($category->idnumber) ? null : $mask->year_of((string) $category->idnumber);
                if ($year !== null) {
                    $inorigin[$year] = (string) $category->idnumber;
                }
            }
        }
        $inarchive = [];
        foreach (self::managed_archive_categories($task) as $category) {
            $year = $mask->year_of((string) $category->idnumber);
            if ($year !== null) {
                $inarchive[$year] = (string) $category->idnumber;
            }
        }

        $rows = [];
        for ($offset = 0; $offset < $years; $offset++) {
            $a = $current + $offset;

            $production = [];
            for ($year = $a; $year > $a - $p; $year--) {
                $production[] = self::cell($mask, $year, $inorigin, $inarchive);
            }

            $archive = [];
            for ($year = $a - $p; $year > $a - $p - $v; $year--) {
                $archive[] = self::cell($mask, $year, $inorigin, $inarchive);
            }

            $rows[] = (object) [
                'year' => $a,
                'label' => $a . '/' . substr((string) ($a + 1), -2),
                'iscurrent' => ($offset === 0),
                'production' => $production,
                'archive' => $archive,
                'deleted' => [self::cell($mask, $a - $p - $v, $inorigin, $inarchive)],
            ];
        }

        return $rows;
    }

    /**
     * One cell of the projection.
     *
     * @param academic_year $mask Compiled mask.
     * @param int $year Academic year.
     * @param array $inorigin Years present in the origin.
     * @param array $inarchive Years present in the managed archive.
     * @return stdClass {year, idnumber, exists}
     */
    private static function cell(academic_year $mask, int $year, array $inorigin, array $inarchive): stdClass {
        return (object) [
            'year' => $year,
            'idnumber' => $inorigin[$year] ?? $inarchive[$year] ?? academic_year::example_for($mask, $year),
            'exists' => isset($inorigin[$year]) || isset($inarchive[$year]),
        ];
    }

    /**
     * Compiled mask of a task.
     *
     * @param stdClass $task Task record.
     * @return academic_year|null Null when the mask cannot be used (no year in it):
     *                            the caller must then do nothing, never guess.
     */
    public static function mask(stdClass $task): ?academic_year {
        if (!academic_year::is_valid_mask((string) $task->categorypattern)) {
            return null;
        }
        return new academic_year((string) $task->categorypattern);
    }

    /**
     * Cap of categories archived per execution.
     *
     * @return int
     */
    public static function max_per_tick(): int {
        $configured = get_config('local_coursetransfermanager', 'maxarchivepertick');
        $max = ($configured === false || $configured === '') ? self::DEFAULT_MAX_PER_TICK : (int) $configured;
        return max(1, $max);
    }

    /**
     * Academic years this task already archived successfully.
     *
     * Prevents bringing the same course twice (which would duplicate every
     * one of its courses in the archive).
     *
     * @param stdClass $task Task record.
     * @return int[] Academic years.
     */
    private static function already_archived_years(stdClass $task): array {
        global $DB;

        $mask = self::mask($task);
        if (!$mask) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(
            [task_manager::STATUS_SUCCESS, task_manager::STATUS_COMPLETED],
            SQL_PARAMS_NAMED
        );
        $params['taskid'] = $task->id;

        $idnumbers = $DB->get_fieldset_select(
            'local_ctm_executions',
            'DISTINCT origincategoryidnumber',
            "taskid = :taskid AND status $insql AND origincategoryidnumber IS NOT NULL",
            $params
        );

        $years = [];
        foreach ($idnumbers as $idnumber) {
            $year = $mask->year_of((string) $idnumber);
            if ($year !== null) {
                $years[] = $year;
            }
        }
        return $years;
    }

    /**
     * Categories of the archive that are candidates to be ADOPTED.
     *
     * Content that reached the archive before this plugin existed is invisible
     * to the pruning on purpose (the rule that protects foreign categories).
     * These are the ones that sit under the task destination, match its mask
     * and are not managed yet: the admin can put them under the policy
     * explicitly, knowing they will then be pruned.
     *
     * @param stdClass $task Task record.
     * @return stdClass[] {id, name, idnumber, year} oldest first.
     */
    public static function adoptable(stdClass $task): array {
        $mask = self::mask($task);
        if (!$mask || empty($task->targetcategoryid)) {
            return [];
        }

        $parent = core_course_category::get((int) $task->targetcategoryid, IGNORE_MISSING, true);
        if (!$parent) {
            return [];
        }

        $managed = self::managed_category_ids($task);
        $candidates = [];

        foreach ($parent->get_children() as $child) {
            if (in_array((int) $child->id, $managed, true)) {
                continue;
            }
            $year = $mask->year_of((string) $child->idnumber);
            if ($year === null) {
                continue;
            }
            $candidates[] = (object) [
                'id' => (int) $child->id,
                'name' => $child->get_formatted_name(),
                'idnumber' => (string) $child->idnumber,
                'year' => $year,
                'courses' => $child->get_courses_count(),
            ];
        }

        usort($candidates, static fn(stdClass $a, stdClass $b): int => $a->year <=> $b->year);

        return $candidates;
    }

    /**
     * Put an archive category under the conservation policy.
     *
     * @param int $taskid Task id.
     * @param int $categoryid Archive category id.
     * @param int $userid User adopting it.
     * @return void
     * @throws \moodle_exception When the category does not exist.
     */
    public static function adopt(int $taskid, int $categoryid, int $userid): void {
        global $DB;

        $category = core_course_category::get($categoryid, IGNORE_MISSING, true);
        if (!$category) {
            throw new \moodle_exception('invalidcategoryid', 'error');
        }

        if ($DB->record_exists('local_ctm_adopted', ['taskid' => $taskid, 'categoryid' => $categoryid])) {
            return;
        }

        $DB->insert_record('local_ctm_adopted', (object) [
            'taskid' => $taskid,
            'categoryid' => $categoryid,
            'categoryname' => $category->get_formatted_name(),
            'adoptedby' => $userid,
            'timecreated' => time(),
        ]);
    }

    /**
     * Take an archive category out of the conservation policy.
     *
     * @param int $taskid Task id.
     * @param int $categoryid Archive category id.
     * @return void
     */
    public static function unadopt(int $taskid, int $categoryid): void {
        global $DB;
        $DB->delete_records('local_ctm_adopted', ['taskid' => $taskid, 'categoryid' => $categoryid]);
    }

    /**
     * Ids of the archive categories this task manages.
     *
     * Managed = created by one of its executions, or explicitly adopted.
     *
     * @param stdClass $task Task record.
     * @return int[]
     */
    public static function managed_category_ids(stdClass $task): array {
        global $DB;

        $created = $DB->get_fieldset_select(
            'local_ctm_executions',
            'DISTINCT destinationcategoryid',
            'taskid = :taskid AND destinationcategoryid IS NOT NULL',
            ['taskid' => $task->id]
        );
        $adopted = $DB->get_fieldset_select(
            'local_ctm_adopted',
            'categoryid',
            'taskid = :taskid',
            ['taskid' => $task->id]
        );

        return array_values(array_unique(array_map('intval', array_merge($created, $adopted))));
    }

    /**
     * Archive categories under the task destination that this plugin manages.
     *
     * Managed = created by an execution of this task, or explicitly adopted.
     *
     * @param stdClass $task Task record.
     * @return stdClass[] {id, name, idnumber}
     */
    /**
     * Yearly categories in the archive this task manages, newest first.
     *
     * Managed means the pruning may delete it: either the task brought it, or
     * somebody adopted it on purpose.
     *
     * @param stdClass $task The task.
     * @return stdClass[] Categories with id, name, idnumber, year and courses.
     */
    public static function managed_categories(stdClass $task): array {
        $mask = self::mask($task);
        if (!$mask) {
            return [];
        }

        $categories = [];
        foreach (self::managed_archive_categories($task) as $category) {
            $year = $mask->year_of((string) $category->idnumber);
            if ($year === null) {
                continue;
            }
            $record = core_course_category::get((int) $category->id, IGNORE_MISSING, true);
            $categories[] = (object) [
                'id' => (int) $category->id,
                'name' => $category->name,
                'idnumber' => (string) $category->idnumber,
                'year' => $year,
                'courses' => $record ? $record->get_courses_count() : 0,
            ];
        }

        usort($categories, static fn(stdClass $a, stdClass $b): int => $b->year <=> $a->year);

        return $categories;
    }

    /**
     * Archive categories already managed by this task, with their academic year resolved.
     *
     * @param stdClass $task Rotation task record.
     * @return array Category records with id, name, idnumber, parent and year.
     */
    private static function managed_archive_categories(stdClass $task): array {
        global $DB;

        $managed = self::managed_category_ids($task);

        if (empty($managed)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($managed, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'course_categories',
            "id $insql",
            $params,
            'id',
            'id, name, idnumber, parent'
        );

        $categories = [];
        foreach ($records as $record) {
            if ((string) $record->idnumber === '') {
                continue;
            }
            $categories[] = (object) [
                'id' => (int) $record->id,
                'name' => $record->name,
                'idnumber' => $record->idnumber,
            ];
        }
        return $categories;
    }
}
