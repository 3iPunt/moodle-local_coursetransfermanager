<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Data generator for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursetransfermanager\manager\academic_year;
use local_coursetransfermanager\manager\prune_manager;

/**
 * Creates the plugin's records (tasks, executions, pruning candidates, adoptions) and the
 * yearly category tree a rotation task works on.
 *
 * Shared by PHPUnit, Behat and the stack seeders, so a test never builds these rows by hand.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_coursetransfermanager_generator extends component_generator_base {
    /** @var int Sequence for default task names. */
    protected int $taskcount = 0;

    /**
     * Reset the generator state between tests.
     */
    public function reset(): void {
        $this->taskcount = 0;
    }

    /**
     * Create a rotation task.
     *
     * Defaults: enabled, yearly on 1 September at 02:00, mask {YEAR}-{NEXTYEAR}, two years
     * kept in the origin and four in the archive, created by the main admin.
     *
     * @param array|stdClass $record Any column of the tasks table; the rest get defaults
     * @return stdClass the inserted row
     */
    public function create_task($record = []): stdClass {
        global $DB;

        $record = (array) $record;
        $this->taskcount++;
        $now = time();

        $row = (object) array_merge([
            'type' => 'restore_category',
            'name' => "Task {$this->taskcount}",
            'originsiteid' => 0,
            'categorypattern' => '{YEAR}-{NEXTYEAR}',
            'targetcategoryid' => null,
            'cronexpression' => '0 2 1 9 *',
            'retentiondays' => 30,
            'originkeepyears' => 2,
            'destinationkeepyears' => 4,
            'restoreuserdata' => 0,
            'enabled' => 1,
            'usercreated' => get_admin()->id,
            'notifylevel' => 'full',
            'timecreated' => $now,
            'timemodified' => $now,
        ], $record);

        $row->id = $DB->insert_record('local_coursetransfermanager_tasks', $row);
        return $DB->get_record('local_coursetransfermanager_tasks', ['id' => $row->id], '*', MUST_EXIST);
    }

    /**
     * Create an execution of a task.
     *
     * @param array|stdClass $record Must contain taskid; any other column of the executions table
     * @return stdClass the inserted row
     */
    public function create_execution($record): stdClass {
        global $DB;

        $record = (array) $record;
        if (empty($record['taskid'])) {
            throw new coding_exception('An execution needs a taskid');
        }
        $now = time();

        $row = (object) array_merge([
            'status' => 'completed',
            'manualrun' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $record);

        $row->id = $DB->insert_record('local_coursetransfermanager_executions', $row);
        return $DB->get_record('local_coursetransfermanager_executions', ['id' => $row->id], '*', MUST_EXIST);
    }

    /**
     * Create a pruning candidate, announced now with a seven-day grace period by default.
     *
     * @param array|stdClass $record Must contain taskid and categoryid
     * @return stdClass the inserted row
     */
    public function create_prune($record): stdClass {
        global $DB;

        $record = (array) $record;
        if (empty($record['taskid']) || empty($record['categoryid'])) {
            throw new coding_exception('A pruning candidate needs a taskid and a categoryid');
        }
        $now = time();

        $row = (object) array_merge([
            'categoryname' => null,
            'announcedat' => $now,
            'graceuntil' => $now + 7 * DAYSECS,
            'status' => prune_manager::STATUS_ANNOUNCED,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $record);

        $row->id = $DB->insert_record('local_coursetransfermanager_prune', $row);
        return $DB->get_record('local_coursetransfermanager_prune', ['id' => $row->id], '*', MUST_EXIST);
    }

    /**
     * Put an archive category the plugin did not create under a task's management.
     *
     * @param array|stdClass $record Must contain taskid and categoryid
     * @return stdClass the inserted row
     */
    public function create_adopted($record): stdClass {
        global $DB;

        $record = (array) $record;
        if (empty($record['taskid']) || empty($record['categoryid'])) {
            throw new coding_exception('An adoption needs a taskid and a categoryid');
        }

        $row = (object) array_merge([
            'categoryname' => null,
            'adoptedby' => get_admin()->id,
            'timecreated' => time(),
        ], $record);

        $row->id = $DB->insert_record('local_coursetransfermanager_adopted', $row);
        return $DB->get_record('local_coursetransfermanager_adopted', ['id' => $row->id], '*', MUST_EXIST);
    }

    /**
     * Create one category per academic year, named and numbered with a mask, each with courses.
     *
     * Years run from the current academic year back, so the tree always has the same shape
     * relative to "now": what the rotation archives and prunes does not depend on the date.
     *
     * @param array $options mask ('{YEAR}-{NEXTYEAR}'), years (5), courses (2 per year),
     *                       parent (category id, 0 = top level), prefix (course shortname prefix)
     * @return stdClass[] categories keyed by starting year, newest first; each with ->courses
     */
    public function create_yearly_categories(array $options = []): array {
        global $DB;

        $options = array_merge([
            'mask' => '{YEAR}-{NEXTYEAR}',
            'years' => 5,
            'courses' => 2,
            'parent' => 0,
            'prefix' => 'CT',
        ], $options);

        $current = academic_year::current_year();
        $categories = [];
        for ($year = $current; $year > $current - $options['years']; $year--) {
            $idnumber = academic_year::example($options['mask'], $year);
            $category = $DB->get_record('course_categories', ['idnumber' => $idnumber]);
            if (!$category) {
                $created = $this->datagenerator->create_category([
                    'name' => $idnumber,
                    'idnumber' => $idnumber,
                    'parent' => $options['parent'],
                ]);
                // The generator returns a core_course_category; callers get the plain row.
                $category = $DB->get_record('course_categories', ['id' => $created->id], '*', MUST_EXIST);
            }
            $category->courses = [];
            for ($i = 1; $i <= $options['courses']; $i++) {
                $shortname = "{$options['prefix']}-{$idnumber}-{$i}";
                $course = $DB->get_record('course', ['shortname' => $shortname]);
                if (!$course) {
                    $course = $this->datagenerator->create_course([
                        'fullname' => "Course {$i} {$idnumber}",
                        'shortname' => $shortname,
                        'idnumber' => $shortname,
                        'category' => $category->id,
                    ]);
                }
                $category->courses[] = $course;
            }
            $categories[$year] = $category;
        }
        return $categories;
    }
}
