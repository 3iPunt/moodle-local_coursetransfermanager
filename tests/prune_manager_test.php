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

use local_coursetransfermanager\manager\prune_manager;

/**
 * Tests for the two-phase archive pruning (CTM-001 safety).
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\prune_manager
 */
final class prune_manager_test extends \advanced_testcase {

    /**
     * Seed a task, its archive parent and children categories.
     *
     * @param int $keepyears Years kept in the archive.
     * @return \stdClass {task, parent, managedold, managednew, foreign}
     */
    private function seed(int $keepyears = 4): \stdClass {
        global $DB;

        $generator = $this->getDataGenerator();
        $parent = $generator->create_category(['name' => 'Archivo']);
        $currentyear = (int)date('Y');

        // Two manager-created children (tracked via destinationcategoryid) and a foreign one.
        $managedold = $generator->create_category([
            'name' => 'Viejo', 'parent' => $parent->id, 'idnumber' => 'SJD' . ($currentyear - $keepyears - 1),
        ]);
        $managednew = $generator->create_category([
            'name' => 'Nuevo', 'parent' => $parent->id, 'idnumber' => 'SJD' . $currentyear,
        ]);
        // CTM-001 trap: a code that LOOKS old ("1042") in a category the manager never created.
        $foreign = $generator->create_category([
            'name' => 'Ajena', 'parent' => $parent->id, 'idnumber' => 'MED1042',
        ]);

        $now = time();
        $taskid = $DB->insert_record('local_ctm_tasks', (object)[
            'type' => 'restore_category', 'name' => 'Tarea', 'originsiteid' => 1,
            'categorypattern' => 'SJD{YEAR}', 'targetcategoryid' => $parent->id,
            'cronexpression' => '0 2 1 9 *', 'retentiondays' => 30,
            'destinationkeepyears' => $keepyears, 'restoreuserdata' => 0, 'enabled' => 1,
            'usercreated' => 2, 'notifylevel' => 'full',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        foreach ([$managedold, $managednew] as $category) {
            $DB->insert_record('local_ctm_executions', (object)[
                'taskid' => $taskid, 'status' => 'completed', 'manualrun' => 0,
                'destinationcategoryid' => $category->id,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }

        return (object)[
            'task' => $DB->get_record('local_ctm_tasks', ['id' => $taskid]),
            'parent' => $parent, 'managedold' => $managedold,
            'managednew' => $managednew, 'foreign' => $foreign,
        ];
    }

    /**
     * The strict year extraction never mistakes arbitrary codes for years.
     */
    public function test_extract_year_is_strict(): void {
        $this->assertNull(prune_manager::extract_year('MED1042'));
        $this->assertNull(prune_manager::extract_year('20256'));
        $this->assertNull(prune_manager::extract_year('GINF'));
        $this->assertSame(2026, prune_manager::extract_year('SJD2026'));
        $this->assertSame(2026, prune_manager::extract_year('2025-2026'));
        $this->assertSame(2026, prune_manager::extract_year('A2026B2024'));
    }

    /**
     * Detection announces ONLY manager-created categories older than the cutoff.
     */
    public function test_detect_announces_only_managed_and_old(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/candidate announced/');

        $seed = $this->seed();
        $announced = prune_manager::detect(time(), 7);

        $this->assertCount(1, $announced);
        $this->assertSame((int)$seed->managedold->id, (int)$announced[0]->categoryid);
        // Neither the recent managed one nor the foreign MED1042 are candidates.
        $this->assertSame(1, $DB->count_records('local_ctm_prune'));

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

        // The foreign category and the recent one are untouched (CTM-001).
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $seed->foreign->id]));
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $seed->managednew->id]));
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
        $DB->set_field('local_ctm_tasks', 'enabled', 0, ['id' => $seed->task->id]);

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
