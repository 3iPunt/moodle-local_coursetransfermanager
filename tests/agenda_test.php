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
 * Tests for the upcoming activity agenda.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager;

use advanced_testcase;
use local_coursetransfermanager\manager\academic_year;
use local_coursetransfermanager\manager\agenda;
use local_coursetransfermanager\manager\deletion_manager;
use local_coursetransfermanager\manager\prune_manager;

/**
 * Tests for the upcoming activity agenda.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\agenda
 */
final class agenda_test extends advanced_testcase {
    /**
     * Get the plugin generator.
     *
     * @return \local_coursetransfermanager_generator
     */
    private function generator(): \local_coursetransfermanager_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
    }

    /**
     * Executions, deletions and prunings come out in one list, sorted by date.
     */
    public function test_items_are_merged_and_sorted_by_date(): void {
        $this->resetAfterTest();

        $now = time();
        $task = $this->generator()->create_task(['nextruntime' => $now + 20 * DAYSECS]);
        $this->generator()->create_execution([
            'taskid' => $task->id,
            'origincategoryid' => 10,
            'origincategoryname' => 'Old year',
            'origincategoryidnumber' => '2018-2019',
            'scheduleddeleteat' => $now + 5 * DAYSECS,
            'deletestatus' => deletion_manager::STATUS_SCHEDULED,
        ]);
        $category = $this->getDataGenerator()->create_category(['name' => 'Archive 2012']);
        $this->generator()->create_prune([
            'taskid' => $task->id,
            'categoryid' => $category->id,
            'categoryname' => 'Archive 2012',
            'graceuntil' => $now + 10 * DAYSECS,
        ]);

        $items = agenda::get_items($now);

        $this->assertSame(
            [agenda::TYPE_DELETION, agenda::TYPE_PRUNE, agenda::TYPE_EXECUTION],
            array_column($items, 'type')
        );
        $dates = array_column($items, 'date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);

        $this->assertSame('Old year', $items[0]->categoryname);
        $this->assertSame('2018-2019', $items[0]->categoryidnumber);
        $this->assertSame((int)$category->id, $items[1]->categoryid);
        $this->assertSame((int)$task->id, $items[2]->taskid);
    }

    /**
     * The execution item says which academic year the next run will archive,
     * resolved with the task mask on the date of that run.
     */
    public function test_execution_item_resolves_the_target_year(): void {
        $this->resetAfterTest();

        $nextrun = time() + 30 * DAYSECS;
        $task = $this->generator()->create_task([
            'categorypattern' => '{YEAR}-{NEXTYEAR}',
            'originkeepyears' => 2,
            'nextruntime' => $nextrun,
        ]);

        $items = agenda::get_items(time());

        $this->assertCount(1, $items);
        $expected = academic_year::current_year($nextrun) - 2;
        $this->assertSame($expected, $items[0]->targetyear);
        $this->assertSame($expected . '-' . ($expected + 1), $items[0]->resolvedpattern);
        $this->assertSame($task->name, $items[0]->taskname);
    }

    /**
     * An invalid mask keeps the raw pattern and no target year, instead of guessing one.
     */
    public function test_execution_item_with_invalid_mask(): void {
        $this->resetAfterTest();

        $this->generator()->create_task([
            'categorypattern' => 'NOYEAR',
            'nextruntime' => time() + DAYSECS,
        ]);

        $items = agenda::get_items(time());

        $this->assertCount(1, $items);
        $this->assertNull($items[0]->targetyear);
        $this->assertSame('NOYEAR', $items[0]->resolvedpattern);
    }

    /**
     * Disabled or never scheduled tasks, closed deletions and closed prunings stay out.
     */
    public function test_only_live_items_are_listed(): void {
        $this->resetAfterTest();

        $now = time();
        $this->generator()->create_task(['enabled' => 0, 'nextruntime' => $now + DAYSECS]);
        $task = $this->generator()->create_task(['nextruntime' => null]);
        foreach ([deletion_manager::STATUS_CANCELLED, deletion_manager::STATUS_DONE, deletion_manager::STATUS_FAILED] as $status) {
            $this->generator()->create_execution([
                'taskid' => $task->id,
                'scheduleddeleteat' => $now + DAYSECS,
                'deletestatus' => $status,
            ]);
        }
        $category = $this->getDataGenerator()->create_category();
        foreach ([prune_manager::STATUS_EXCLUDED, prune_manager::STATUS_DONE] as $status) {
            $this->generator()->create_prune([
                'taskid' => $task->id,
                'categoryid' => $category->id,
                'status' => $status,
            ]);
        }

        $this->assertSame([], agenda::get_items($now));

        // A warned deletion is still pending: it can be cancelled until it runs.
        $this->generator()->create_execution([
            'taskid' => $task->id,
            'scheduleddeleteat' => $now + DAYSECS,
            'deletestatus' => deletion_manager::STATUS_WARNED,
        ]);
        $items = agenda::get_items($now);
        $this->assertCount(1, $items);
        $this->assertSame(agenda::TYPE_DELETION, $items[0]->type);
        $this->assertSame(deletion_manager::STATUS_WARNED, $items[0]->deletestatus);
    }

    /**
     * Scoping to a task leaves the other tasks out.
     */
    public function test_items_scoped_to_a_task(): void {
        $this->resetAfterTest();

        $now = time();
        $mine = $this->generator()->create_task(['nextruntime' => $now + DAYSECS]);
        $other = $this->generator()->create_task(['nextruntime' => $now + DAYSECS]);
        foreach ([$mine, $other] as $task) {
            $this->generator()->create_execution([
                'taskid' => $task->id,
                'scheduleddeleteat' => $now + 2 * DAYSECS,
                'deletestatus' => deletion_manager::STATUS_SCHEDULED,
            ]);
            $this->generator()->create_prune([
                'taskid' => $task->id,
                'categoryid' => $this->getDataGenerator()->create_category()->id,
            ]);
        }

        $this->assertCount(6, agenda::get_items($now));

        $items = agenda::get_items($now, (int)$mine->id);
        $this->assertCount(3, $items);
        $this->assertSame([(int)$mine->id], array_values(array_unique(array_column($items, 'taskid'))));
    }
}
