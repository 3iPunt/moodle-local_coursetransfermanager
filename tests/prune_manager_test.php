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

namespace local_coursetransfermanager;

use local_coursetransfermanager\manager\academic_year;
use local_coursetransfermanager\manager\prune_manager;

/**
 * Tests for the two-phase archive pruning and its safety rule.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\prune_manager
 */
final class prune_manager_test extends \advanced_testcase {
    /**
     * Seed a task, its archive parent and one category per lifecycle band.
     *
     * The years are derived from the policy, never hardcoded, so the fixture
     * follows the model: production keeps the P newest courses, the archive the
     * V before those, and anything older than A−P−V is a pruning candidate.
     *
     * @param int $originkeep Academic years kept in production (P).
     * @param int $keepyears Academic years kept in the archive (V).
     * @return \stdClass {task, parent, managedold, managedmid, managednew, foreign, years}
     */
    private function seed(int $originkeep = 2, int $keepyears = 4): \stdClass {
        global $DB;

        $generator = $this->getDataGenerator();
        $parent = $generator->create_category(['name' => 'Archivo']);

        $current = academic_year::current_year();
        // The first year that has outlived the whole policy: it must be pruned.
        $prunable = $current - $originkeep - $keepyears;
        // Still inside the archive window: out of production, but NOT prunable.
        $middle = $prunable + 1;

        $managedold = $generator->create_category([
            'name' => 'Fuera de política', 'parent' => $parent->id, 'idnumber' => 'SJD' . $prunable,
        ]);
        $managedmid = $generator->create_category([
            'name' => 'En archivo', 'parent' => $parent->id, 'idnumber' => 'SJD' . $middle,
        ]);
        $managednew = $generator->create_category([
            'name' => 'Curso actual', 'parent' => $parent->id, 'idnumber' => 'SJD' . $current,
        ]);
        // Trap: a code that LOOKS old ("1042") in a category the manager never created.
        $foreign = $generator->create_category([
            'name' => 'Ajena', 'parent' => $parent->id, 'idnumber' => 'MED1042',
        ]);

        $now = time();
        $taskid = $DB->insert_record('local_coursetransfermanager_tasks', (object)[
            'type' => 'restore_category', 'name' => 'Tarea', 'originsiteid' => 1,
            'categorypattern' => 'SJD{YEAR}', 'targetcategoryid' => $parent->id,
            'cronexpression' => '0 2 1 9 *', 'retentiondays' => 30,
            'originkeepyears' => $originkeep,
            'destinationkeepyears' => $keepyears, 'restoreuserdata' => 0, 'enabled' => 1,
            'usercreated' => 2, 'notifylevel' => 'full',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        // Manager-created is tracked via destinationcategoryid on the executions.
        foreach ([$managedold, $managedmid, $managednew] as $category) {
            $DB->insert_record('local_coursetransfermanager_executions', (object)[
                'taskid' => $taskid, 'status' => 'completed', 'manualrun' => 0,
                'destinationcategoryid' => $category->id,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        return (object)[
            'task' => $DB->get_record('local_coursetransfermanager_tasks', ['id' => $taskid]),
            'parent' => $parent, 'managedold' => $managedold, 'managedmid' => $managedmid,
            'managednew' => $managednew, 'foreign' => $foreign,
            'years' => (object)['current' => $current, 'middle' => $middle, 'prunable' => $prunable],
        ];
    }

    // Year recognition now lives in academic_year (see academic_year_test):
    // the mask decides, so a code like MED1042 is never read as a year.

    /**
     * Detection announces ONLY manager-created categories older than the cutoff.
     */
    public function test_detect_announces_only_managed_and_old(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/candidate announced/');

        $seed = $this->seed();
        $announced = prune_manager::detect(time(), 7);

        // Only the year that outlived the whole policy (A−P−V).
        $this->assertCount(1, $announced);
        $this->assertSame((int)$seed->managedold->id, (int)$announced[0]->categoryid);
        // And it is the year the policy says, not just "some old category".
        $this->assertSame(
            'SJD' . $seed->years->prunable,
            $DB->get_field('course_categories', 'idnumber', ['id' => $announced[0]->categoryid])
        );
        // The one still inside the archive window is NOT a candidate: being out
        // of production is not the same as being out of the archive.
        $this->assertSame(1, $DB->count_records('local_coursetransfermanager_prune'));
        $this->assertFalse($DB->record_exists(
            'local_coursetransfermanager_prune',
            ['categoryid' => $seed->managedmid->id]
        ));
        // Neither the current course nor the foreign MED1042 are candidates.
        $this->assertFalse($DB->record_exists(
            'local_coursetransfermanager_prune',
            ['categoryid' => $seed->managednew->id]
        ));
        $this->assertFalse($DB->record_exists(
            'local_coursetransfermanager_prune',
            ['categoryid' => $seed->foreign->id]
        ));

        // A second detection pass does not re-announce the live candidate.
        $this->assertCount(0, prune_manager::detect(time(), 7));
    }

    /**
     * Nothing is pruned before the grace period ends; after it, only announced
     * candidates are deleted and foreign categories survive.
     */
    public function test_two_phase_execution_respects_grace(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/Pruning/');

        $seed = $this->seed();

        // Phase 1 announced today: nothing due yet.
        prune_manager::detect(time(), 7);
        $this->assertCount(0, prune_manager::execute_due(time()));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $seed->managedold->id]));

        // Jump past the grace period: the candidate is pruned.
        $results = prune_manager::execute_due(time() + 8 * DAYSECS);
        $this->assertCount(1, $results);
        $this->assertSame('done', $results[0]->outcome);
        $this->assertFalse($DB->record_exists('course_categories', ['id' => $seed->managedold->id]));

        // The foreign category, the recent one and the one still inside the
        // archive window are untouched.
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $seed->foreign->id]));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $seed->managednew->id]));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $seed->managedmid->id]));
    }

    /**
     * Widening the archive window protects a year that was already prunable:
     * the policy decides on every pass, it is not frozen at creation time.
     */
    public function test_policy_decides_on_every_pass(): void {
        global $DB;
        $this->resetAfterTest();

        // With P=2 and V=4 the oldest year is prunable...
        $seed = $this->seed(2, 4);
        $this->expectOutputRegex('/candidate/');
        $this->assertCount(1, prune_manager::detect(time(), 7));

        // ...and one more year of archive takes it out of range again.
        $DB->delete_records('local_coursetransfermanager_prune', []);
        $DB->set_field('local_coursetransfermanager_tasks', 'destinationkeepyears', 5, ['id' => $seed->task->id]);
        $this->assertCount(0, prune_manager::detect(time(), 7));

        // Shrinking production has the same effect: fewer years in production
        // means the archive window starts later.
        $DB->set_field('local_coursetransfermanager_tasks', 'destinationkeepyears', 4, ['id' => $seed->task->id]);
        $DB->set_field('local_coursetransfermanager_tasks', 'originkeepyears', 3, ['id' => $seed->task->id]);
        $this->assertCount(0, prune_manager::detect(time(), 7));
    }

    /**
     * R3 — disabling the task freezes its announced pruning candidates.
     */
    public function test_disabled_task_freezes_pruning(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/candidate announced/');

        $seed = $this->seed();
        prune_manager::detect(time(), 7);
        $DB->set_field('local_coursetransfermanager_tasks', 'enabled', 0, ['id' => $seed->task->id]);

        $this->assertCount(0, prune_manager::execute_due(time() + 8 * DAYSECS));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $seed->managedold->id]));
    }

    /**
     * An excluded candidate is never pruned and cannot be excluded twice.
     */
    public function test_exclusion_protects_the_category(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/candidate announced/');

        $seed = $this->seed();
        $announced = prune_manager::detect(time(), 7);
        $candidate = $announced[0];

        $excluded = prune_manager::exclude((int)$candidate->id, 2);
        $this->assertSame(prune_manager::STATUS_EXCLUDED, $excluded->status);
        $this->assertSame(2, (int)$excluded->excludedby);

        // Past the grace period nothing runs and the category survives.
        $this->assertCount(0, prune_manager::execute_due(time() + 8 * DAYSECS));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $seed->managedold->id]));

        // Excluded candidates are not re-announced either.
        $this->assertCount(0, prune_manager::detect(time(), 7));

        // Excluding again is rejected.
        $this->expectException(\moodle_exception::class);
        prune_manager::exclude((int)$candidate->id, 2);
    }
}
