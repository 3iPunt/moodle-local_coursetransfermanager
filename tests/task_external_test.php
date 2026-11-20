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

use local_coursetransfermanager\external\task_external;
use local_coursetransfermanager\manager\deletion_manager;
use local_coursetransfermanager\manager\prune_manager;

/**
 * Tests for the panel AJAX endpoints (toggle, run-now guard, cancellations).
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\external\task_external
 */
final class task_external_test extends \advanced_testcase {
    /**
     * Seed a task, optionally with a recent successful execution.
     *
     * @param bool $withsuccess Add a success execution in the current cycle.
     * @return int Task id.
     */
    private function seed(bool $withsuccess = false): int {
        global $DB;

        $now = time();
        $taskid = $DB->insert_record('local_coursetransfermanager_tasks', (object)[
            'type' => 'restore_category', 'name' => 'Tarea', 'originsiteid' => 1,
            'categorypattern' => 'SJD{YEAR}', 'targetcategoryid' => 1,
            'cronexpression' => '0 2 1 9 *', 'retentiondays' => 30,
            'destinationkeepyears' => 4, 'restoreuserdata' => 0, 'enabled' => 1,
            'usercreated' => 2, 'notifylevel' => 'full',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        if ($withsuccess) {
            $DB->insert_record('local_coursetransfermanager_executions', (object)[
                'taskid' => $taskid, 'status' => 'success', 'manualrun' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        return $taskid;
    }

    /**
     * Every endpoint requires the manage capability.
     */
    public function test_capability_gate(): void {
        $this->resetAfterTest();
        $taskid = $this->seed();

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        task_external::toggle($taskid, false);
    }

    /**
     * The switch flips the state and recomputes the next-run line.
     */
    public function test_toggle(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $taskid = $this->seed();

        $off = task_external::toggle($taskid, false);
        $this->assertFalse($off['enabled']);
        $this->assertSame(0, (int)$DB->get_field('local_coursetransfermanager_tasks', 'enabled', ['id' => $taskid]));

        $on = task_external::toggle($taskid, true);
        $this->assertTrue($on['enabled']);
        $this->assertNotEmpty($on['nextrun']);
    }

    /**
     * The run-now precheck blocks when a success already exists in the cycle
     * (relaunching would duplicate every course in the archive).
     */
    public function test_run_now_precheck_blocks_duplicates(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blocked = task_external::run_now($this->seed(true), true);
        $this->assertTrue($blocked['blocked']);
        $this->assertFalse($blocked['launched']);
        $this->assertNotEmpty($blocked['lastsuccess']);

        $free = task_external::run_now($this->seed(false), true);
        $this->assertFalse($free['blocked']);
        $this->assertFalse($free['launched']); // Precheck never launches.
    }

    /**
     * Cancelling a pending deletion through the WS records the audit trail.
     */
    public function test_cancel_deletion(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $taskid = $this->seed();
        $executionid = $DB->insert_record('local_coursetransfermanager_executions', (object)[
            'taskid' => $taskid, 'status' => 'success', 'manualrun' => 0,
            'origincategoryid' => 7, 'origincategoryname' => 'X',
            'scheduleddeleteat' => time() + 10 * DAYSECS,
            'deletestatus' => deletion_manager::STATUS_SCHEDULED,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $result = task_external::cancel_deletion($executionid);
        $this->assertTrue($result['cancelled']);
        $this->assertNotEmpty($result['audit']);
        $this->assertSame(
            deletion_manager::STATUS_CANCELLED,
            $DB->get_field('local_coursetransfermanager_executions', 'deletestatus', ['id' => $executionid])
        );
    }

    /**
     * Adopting an archive category is an explicit, audited and reversible
     * decision — and only for a category inside this task's archive.
     */
    public function test_set_adoption(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $archive = $generator->create_category(['name' => 'Archive']);
        $yearly = $generator->create_category(['name' => '2020/21',
            'idnumber' => 'SJD2020', 'parent' => $archive->id]);
        $elsewhere = $generator->create_category(['name' => 'Elsewhere', 'idnumber' => 'SJD2019']);

        $taskid = $this->seed();
        $DB->set_field('local_coursetransfermanager_tasks', 'targetcategoryid', $archive->id, ['id' => $taskid]);

        // A category outside this task's archive is refused, not adopted.
        try {
            task_external::set_adoption($taskid, (int)$elsewhere->id, true);
            $this->fail('Expected moodle_exception for a category outside the archive');
        } catch (\moodle_exception $e) {
            $this->assertFalse($DB->record_exists(
                'local_coursetransfermanager_adopted',
                ['taskid' => $taskid, 'categoryid' => $elsewhere->id]
            ));
        }

        $result = task_external::set_adoption($taskid, (int)$yearly->id, true);
        $this->assertTrue($result['adopted']);
        $this->assertNotEmpty($result['audit']);
        $this->assertTrue($DB->record_exists(
            'local_coursetransfermanager_adopted',
            ['taskid' => $taskid, 'categoryid' => $yearly->id]
        ));

        $released = task_external::set_adoption($taskid, (int)$yearly->id, false);
        $this->assertFalse($released['adopted']);
        $this->assertFalse($DB->record_exists(
            'local_coursetransfermanager_adopted',
            ['taskid' => $taskid, 'categoryid' => $yearly->id]
        ));
    }

    /**
     * Excluding an announced pruning candidate records who and when, and the
     * candidate leaves the pending list so the pruning never deletes it.
     */
    public function test_exclude_prune(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        $category = $this->getDataGenerator()->create_category(['idnumber' => 'SJD2015']);
        $task = $generator->create_task(['targetcategoryid' => $category->parent]);
        $candidate = $generator->create_prune(['taskid' => $task->id, 'categoryid' => $category->id]);

        $result = task_external::exclude_prune((int)$candidate->id);
        $result = \core_external\external_api::clean_returnvalue(task_external::exclude_prune_returns(), $result);

        $this->assertTrue($result['excluded']);
        $this->assertStringContainsString(fullname($USER), $result['audit']);
        $row = $DB->get_record('local_coursetransfermanager_prune', ['id' => $candidate->id], '*', MUST_EXIST);
        $this->assertSame(prune_manager::STATUS_EXCLUDED, $row->status);
        $this->assertEquals($USER->id, $row->excludedby);
        $this->assertNotEmpty($row->excludedat);
        $this->assertCount(0, prune_manager::get_pending((int)$task->id));
    }

    /**
     * A candidate that is no longer announced (already excluded or pruned) cannot be excluded.
     */
    public function test_exclude_prune_rejects_closed_candidates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        $category = $this->getDataGenerator()->create_category();
        $task = $generator->create_task();
        $candidate = $generator->create_prune([
            'taskid' => $task->id,
            'categoryid' => $category->id,
            'status' => prune_manager::STATUS_DONE,
        ]);

        try {
            task_external::exclude_prune((int)$candidate->id);
            $this->fail('A pruned candidate must not be excludable');
        } catch (\moodle_exception $e) {
            $this->assertSame('prunenotexcludable', $e->errorcode);
        }
        $this->assertSame(
            prune_manager::STATUS_DONE,
            $DB->get_field('local_coursetransfermanager_prune', 'status', ['id' => $candidate->id])
        );
    }

    /**
     * Excluding a candidate requires the manage capability.
     */
    public function test_exclude_prune_requires_capability(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        $category = $this->getDataGenerator()->create_category();
        $task = $generator->create_task();
        $candidate = $generator->create_prune(['taskid' => $task->id, 'categoryid' => $category->id]);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        task_external::exclude_prune((int)$candidate->id);
    }
}
