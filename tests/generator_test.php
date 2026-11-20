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
 * Tests for the local_coursetransfermanager data generator.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager;

use advanced_testcase;
use coding_exception;
use local_coursetransfermanager\manager\academic_year;
use local_coursetransfermanager\manager\prune_manager;

/**
 * Tests for the local_coursetransfermanager data generator.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager_generator
 */
final class generator_test extends advanced_testcase {
    /**
     * Get the plugin generator.
     *
     * @return \local_coursetransfermanager_generator
     */
    private function generator(): \local_coursetransfermanager_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
    }

    /**
     * A task with defaults is a valid, enabled rotation task owned by the admin.
     */
    public function test_create_task(): void {
        $this->resetAfterTest();

        $task = $this->generator()->create_task();
        $this->assertSame('restore_category', $task->type);
        $this->assertTrue(academic_year::is_valid_mask($task->categorypattern));
        $this->assertEquals(1, $task->enabled);
        $this->assertEquals(get_admin()->id, $task->usercreated);

        $named = $this->generator()->create_task(['name' => 'Archive', 'enabled' => 0]);
        $this->assertSame('Archive', $named->name);
        $this->assertEquals(0, $named->enabled);
    }

    /**
     * Executions, pruning candidates and adoptions hang from a task.
     */
    public function test_create_children(): void {
        $this->resetAfterTest();
        $task = $this->generator()->create_task();
        $category = $this->getDataGenerator()->create_category();

        $execution = $this->generator()->create_execution(['taskid' => $task->id, 'status' => 'error']);
        $this->assertEquals($task->id, $execution->taskid);
        $this->assertSame('error', $execution->status);

        $prune = $this->generator()->create_prune(['taskid' => $task->id, 'categoryid' => $category->id]);
        $this->assertSame(prune_manager::STATUS_ANNOUNCED, $prune->status);
        $this->assertGreaterThan($prune->announcedat, $prune->graceuntil);
        $this->assertCount(1, prune_manager::get_pending($task->id));

        $adopted = $this->generator()->create_adopted(['taskid' => $task->id, 'categoryid' => $category->id]);
        $this->assertEquals($category->id, $adopted->categoryid);
    }

    /**
     * Children without their parent task are a programming error.
     */
    public function test_children_need_a_task(): void {
        $this->resetAfterTest();
        $this->expectException(coding_exception::class);
        $this->generator()->create_execution([]);
    }

    /**
     * Yearly categories follow the mask from the current academic year back and can be
     * created twice without duplicates.
     */
    public function test_create_yearly_categories(): void {
        global $DB;
        $this->resetAfterTest();

        $categories = $this->generator()->create_yearly_categories(['years' => 3, 'courses' => 2]);
        $current = academic_year::current_year();
        $this->assertSame([$current, $current - 1, $current - 2], array_keys($categories));

        $mask = new academic_year('{YEAR}-{NEXTYEAR}');
        foreach ($categories as $year => $category) {
            $this->assertSame($year, $mask->year_of($category->idnumber));
            $this->assertCount(2, $category->courses);
        }

        $before = $DB->count_records('course');
        $this->generator()->create_yearly_categories(['years' => 3, 'courses' => 2]);
        $this->assertEquals($before, $DB->count_records('course'));
    }
}
