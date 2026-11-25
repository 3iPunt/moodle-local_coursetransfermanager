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

use local_coursetransfermanager\manager\deletion_manager;
use local_coursetransfermanager\manager\task_manager;

/**
 * Tests for the cleanup orchestration (emergency pause switch).
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\task_manager
 */
final class task_manager_test extends \advanced_testcase {
    /**
     * Every restore keeps a safety copy in the destination and never removes the origin by itself:
     * the origin goes only through the verified, announced deletion.
     */
    public function test_restore_keeps_a_safety_copy(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $configuration = task_manager::restore_configuration((object)['restoreuserdata' => 1]);

        $this->assertTrue(property_exists($configuration, 'keepsafetycopy'), 'local_coursetransfer lacks keepsafetycopy');
        $this->assertTrue($configuration->keepsafetycopy);
        $this->assertFalse($configuration->originremovecategory);
        $this->assertTrue($configuration->originenrolusers);
        // A new course, so the course settings come too (B1).
        $this->assertSame(\backup::TARGET_NEW_COURSE, $configuration->targettarget);
    }

    /**
     * R3 — while the emergency switch is on, nothing destructive runs and
     * every due date slides forward, so lifting the pause never triggers an
     * immediate backlog of deletions or prunings.
     */
    public function test_emergency_pause_postpones_everything(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/PAUSED/');

        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $old = $generator->create_category(['parent' => $parent->id, 'idnumber' => 'SJD2019']);
        $generator->create_course(['category' => $old->id]);

        $now = time();
        $taskid = $DB->insert_record('local_coursetransfermanager_tasks', (object)[
            'type' => 'restore_category', 'name' => 'Tarea', 'originsiteid' => 1,
            'categorypattern' => 'SJD{YEAR}', 'targetcategoryid' => $parent->id,
            'cronexpression' => '0 2 1 9 *', 'retentiondays' => 30,
            'destinationkeepyears' => 4, 'restoreuserdata' => 0, 'enabled' => 1,
            'usercreated' => 2, 'notifylevel' => 'full',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        // A deletion overdue since yesterday and an overdue pruning candidate.
        $executionid = $DB->insert_record('local_coursetransfermanager_executions', (object)[
            'taskid' => $taskid, 'status' => 'completed', 'manualrun' => 0,
            'origincategoryid' => 77, 'origincategoryname' => 'X',
            'destinationcategoryid' => $old->id,
            'scheduleddeleteat' => $now - DAYSECS,
            'deletestatus' => deletion_manager::STATUS_SCHEDULED,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $pruneid = $DB->insert_record('local_coursetransfermanager_prune', (object)[
            'taskid' => $taskid, 'categoryid' => $old->id, 'categoryname' => 'X',
            'announcedat' => $now - 10 * DAYSECS, 'graceuntil' => $now - DAYSECS,
            'status' => 'announced', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        set_config('deletionspaused', 1, 'local_coursetransfermanager');
        (new task_manager())->cleanup_tasks();

        // Nothing executed, everything postponed into the future.
        $execution = $DB->get_record('local_coursetransfermanager_executions', ['id' => $executionid]);
        $this->assertSame(deletion_manager::STATUS_SCHEDULED, $execution->deletestatus);
        $this->assertGreaterThan($now, (int)$execution->scheduleddeleteat);

        $candidate = $DB->get_record('local_coursetransfermanager_prune', ['id' => $pruneid]);
        $this->assertSame('announced', $candidate->status);
        $this->assertGreaterThan($now, (int)$candidate->graceuntil);

        $this->assertTrue($DB->record_exists('course_categories', ['id' => $old->id]));
    }

    /**
     * Executions created in the same second (one run, several categories) still give one row per task.
     */
    public function test_tasks_overview_is_one_row_per_task(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \local_coursetransfermanager_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        $task = $generator->create_task();
        $now = time();
        $generator->create_execution(['taskid' => $task->id, 'status' => 'success', 'timecreated' => $now]);
        $last = $generator->create_execution(['taskid' => $task->id, 'status' => 'error', 'timecreated' => $now]);

        $overview = (new task_manager())->get_tasks_overview();

        $this->assertDebuggingNotCalled();
        $this->assertCount(1, $overview);
        $this->assertSame($last->status, reset($overview)->laststatus);
    }
}
