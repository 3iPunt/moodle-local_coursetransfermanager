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

/**
 * Tests for the origin deletion lifecycle (announced and cancellable).
 *
 * The actual remote removal (process_due happy path) needs a paired origin
 * platform and is covered by the cross-site functional validation, like the
 * coursetransfer submit flows.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\deletion_manager
 */
final class deletion_manager_test extends \advanced_testcase {

    /**
     * Seed a task and one execution with a scheduled origin deletion.
     *
     * @param int $deleteat Timestamp of the scheduled deletion.
     * @param array $overrides Optional: status, destinationcategoryid, enabled.
     * @return \stdClass The execution record.
     */
    private function seed(int $deleteat, array $overrides = []): \stdClass {
        global $DB;

        $now = time();
        $taskid = $DB->insert_record('local_ctm_tasks', (object)[
            'type' => 'restore_category', 'name' => 'Tarea', 'originsiteid' => 1,
            'categorypattern' => 'SJD{YEAR}', 'targetcategoryid' => 1,
            'cronexpression' => '0 2 1 9 *', 'retentiondays' => 30,
            'destinationkeepyears' => 4, 'restoreuserdata' => 0,
            'enabled' => $overrides['enabled'] ?? 1,
            'usercreated' => 2, 'notifylevel' => 'full',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $executionid = $DB->insert_record('local_ctm_executions', (object)[
            'taskid' => $taskid, 'status' => $overrides['status'] ?? 'success', 'manualrun' => 0,
            'origincategoryid' => 77, 'origincategoryname' => 'Curs 2025-2026',
            'destinationcategoryid' => $overrides['destinationcategoryid'] ?? null,
            'scheduleddeleteat' => $deleteat,
            'deletestatus' => deletion_manager::STATUS_SCHEDULED,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        return $DB->get_record('local_ctm_executions', ['id' => $executionid]);
    }

    /**
     * R1 — a due deletion whose restoration is not COMPLETED is held:
     * postponed and reported, never executed.
     */
    public function test_due_deletion_held_until_completed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/HELD/');

        $execution = $this->seed(time() - HOURSECS, ['status' => 'success']);
        $results = deletion_manager::process_due(time());

        $this->assertCount(1, $results);
        $this->assertSame('held', $results[0]->outcome);
        $this->assertStringContainsString('Curs 2025-2026', $results[0]->detail);

        $row = $DB->get_record('local_ctm_executions', ['id' => $execution->id]);
        $this->assertSame(deletion_manager::STATUS_SCHEDULED, $row->deletestatus);
        $this->assertGreaterThan(time(), (int)$row->scheduleddeleteat);
    }

    /**
     * R2 — a due deletion whose archived copy is missing or empty is held.
     */
    public function test_due_deletion_held_without_verified_archive(): void {
        global $DB;
        $this->resetAfterTest();
        $this->expectOutputRegex('/HELD/');

        // Completed but never tracked a destination category.
        $orphan = $this->seed(time() - HOURSECS, ['status' => 'completed']);
        // Completed but the archived category has no courses in it.
        $empty = $this->seed(time() - HOURSECS, [
            'status' => 'completed',
            'destinationcategoryid' => $this->getDataGenerator()->create_category()->id,
        ]);

        $results = deletion_manager::process_due(time());
        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame('held', $result->outcome);
        }
        foreach ([$orphan, $empty] as $execution) {
            $row = $DB->get_record('local_ctm_executions', ['id' => $execution->id]);
            $this->assertSame(deletion_manager::STATUS_SCHEDULED, $row->deletestatus);
            $this->assertGreaterThan(time(), (int)$row->scheduleddeleteat);
        }
    }

    /**
     * R3 — disabling a task freezes its warnings and deletions.
     */
    public function test_disabled_task_freezes_deletions(): void {
        $this->resetAfterTest();

        $this->seed(time() - HOURSECS, ['status' => 'completed', 'enabled' => 0]);
        $this->assertCount(0, deletion_manager::get_due_warnings(time(), 30 * DAYSECS));
        $this->assertCount(0, deletion_manager::process_due(time()));
    }

    /**
     * Pending deletions list only scheduled/warned rows.
     */
    public function test_get_pending(): void {
        $this->resetAfterTest();
        $execution = $this->seed(time() + 10 * DAYSECS);

        $pending = deletion_manager::get_pending();
        $this->assertCount(1, $pending);
        $this->assertSame((int)$execution->id, (int)reset($pending)->id);

        deletion_manager::cancel((int)$execution->id, 2);
        $this->assertCount(0, deletion_manager::get_pending());
    }

    /**
     * The advance notice becomes due exactly lead-time before the deletion,
     * and marking it warned removes it from the due list.
     */
    public function test_due_warnings_window(): void {
        $this->resetAfterTest();
        $execution = $this->seed(time() + 10 * DAYSECS);

        // 7 days of lead on a deletion 10 days away: not due yet.
        $this->assertCount(0, deletion_manager::get_due_warnings(time(), 7 * DAYSECS));
        // 12 days of lead: due.
        $due = deletion_manager::get_due_warnings(time(), 12 * DAYSECS);
        $this->assertCount(1, $due);

        deletion_manager::mark_warned((int)$execution->id);
        $this->assertCount(0, deletion_manager::get_due_warnings(time(), 12 * DAYSECS));
        // Warned rows are still pending (cancellable).
        $this->assertCount(1, deletion_manager::get_pending());
    }

    /**
     * Cancelling records who and when, and cannot happen twice.
     */
    public function test_cancel_audits_and_is_final(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $execution = $this->seed(time() + 10 * DAYSECS);

        $cancelled = deletion_manager::cancel((int)$execution->id, (int)$user->id);
        $this->assertSame(deletion_manager::STATUS_CANCELLED, $cancelled->deletestatus);
        $this->assertSame((int)$user->id, (int)$cancelled->deletecancelledby);
        $this->assertGreaterThan(0, (int)$cancelled->deletecancelledat);

        // A cancelled deletion is never due for execution.
        $this->assertCount(0, deletion_manager::process_due(time() + 30 * DAYSECS));
        $this->assertSame(deletion_manager::STATUS_CANCELLED,
            $DB->get_field('local_ctm_executions', 'deletestatus', ['id' => $execution->id]));

        // And it cannot be cancelled again.
        $this->expectException(\moodle_exception::class);
        deletion_manager::cancel((int)$execution->id, (int)$user->id);
    }
}
