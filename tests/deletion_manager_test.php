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
        $taskid = $DB->insert_record('local_coursetransfermanager_tasks', (object)[
            'type' => 'restore_category', 'name' => 'Tarea', 'originsiteid' => 1,
            'categorypattern' => 'SJD{YEAR}', 'targetcategoryid' => 1,
            'cronexpression' => '0 2 1 9 *', 'retentiondays' => 30,
            'destinationkeepyears' => 4, 'restoreuserdata' => 0,
            'enabled' => $overrides['enabled'] ?? 1,
            'usercreated' => 2, 'notifylevel' => 'full',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $executionid = $DB->insert_record('local_coursetransfermanager_executions', (object)[
            'taskid' => $taskid, 'status' => $overrides['status'] ?? 'success', 'manualrun' => 0,
            'origincategoryid' => 77, 'origincategoryname' => 'Curs 2025-2026',
            'destinationcategoryid' => $overrides['destinationcategoryid'] ?? null,
            'scheduleddeleteat' => $deleteat,
            'deletestatus' => deletion_manager::STATUS_SCHEDULED,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        return $DB->get_record('local_coursetransfermanager_executions', ['id' => $executionid]);
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

        $row = $DB->get_record('local_coursetransfermanager_executions', ['id' => $execution->id]);
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
            $row = $DB->get_record('local_coursetransfermanager_executions', ['id' => $execution->id]);
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
        $this->assertSame(
            deletion_manager::STATUS_CANCELLED,
            $DB->get_field('local_coursetransfermanager_executions', 'deletestatus', ['id' => $execution->id])
        );

        // And it cannot be cancelled again.
        $this->expectException(\moodle_exception::class);
        deletion_manager::cancel((int)$execution->id, (int)$user->id);
    }

    /**
     * Drop any origin answer a test left unused, so it never leaks into the next test.
     */
    protected function tearDown(): void {
        $mocks = new \ReflectionProperty(\curl::class, 'mockresponses');
        $mocks->setValue(null, []);
        parent::tearDown();
    }

    /**
     * Seed a due deletion whose archived copy is verifiable: a completed execution, its
     * category request and the restored course under the destination category.
     *
     * @param array $taskoverrides Task columns (usercreated, originsiteid...).
     * @return \stdClass {execution, course, category}
     */
    private function seed_verified(array $taskoverrides = []): \stdClass {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $ctgenerator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer');
        $catrequest = $ctgenerator->create_request([
            'type' => \local_coursetransfer\coursetransfer_request::TYPE_CATEGORY, 'status' => 100,
        ]);
        $child = $ctgenerator->create_request([
            'type' => 0, 'status' => 100, 'target_course_id' => $course->id, 'request_category_id' => $catrequest->id,
            'origin_course_id' => 501, 'origin_course_fullname' => 'Course 501',
            // Already checked against the origin and matching: what local_coursetransfer leaves behind.
            'origin_manifest' => json_encode(['verified' => time(), 'issues' => []]),
        ]);
        $DB->set_field(
            'local_coursetransfer_request',
            'origin_category_requests',
            json_encode([(int)$child->id]),
            ['id' => $catrequest->id]
        );

        $task = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager')->create_task(array_merge([
            'originsiteid' => 1, 'targetcategoryid' => $category->parent,
        ], $taskoverrides));
        $execution = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager')->create_execution([
            'taskid' => $task->id, 'status' => 'completed', 'requestid' => $catrequest->id,
            'origincategoryid' => 77, 'origincategoryname' => 'Curs 2019-2020',
            'destinationcategoryid' => $category->id,
            'scheduleddeleteat' => time() - 60, 'deletestatus' => deletion_manager::STATUS_WARNED,
        ]);
        return (object)['execution' => $execution, 'course' => $course, 'category' => $category];
    }

    /**
     * Run a callable capturing its output.
     *
     * @param callable $callback Callback.
     * @return mixed Its result.
     */
    private function quietly(callable $callback) {
        ob_start();
        try {
            return $callback();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * H12 — marking the notice as sent never brings a cancelled deletion back.
     */
    public function test_mark_warned_never_resurrects_a_cancelled_deletion(): void {
        global $DB;
        $this->resetAfterTest();
        $execution = $this->seed(time() + 10 * DAYSECS);

        // The cron read the due warning, then somebody cancelled before it was marked.
        deletion_manager::cancel((int)$execution->id, 2);
        deletion_manager::mark_warned((int)$execution->id);

        $this->assertSame(
            deletion_manager::STATUS_CANCELLED,
            $DB->get_field('local_coursetransfermanager_executions', 'deletestatus', ['id' => $execution->id])
        );
    }

    /**
     * R2 — the archive check looks at the courses of THAT request, not at whatever the
     * category happens to hold.
     */
    public function test_archive_check_uses_the_request_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $seed = $this->seed_verified();
        // The restored course is gone; an unrelated course sits in the category.
        $this->getDataGenerator()->create_course(['category' => $seed->category->id]);
        delete_course($seed->course, false);

        $results = $this->quietly(fn() => deletion_manager::process_due(time()));

        $this->assertCount(1, $results);
        $this->assertSame('held', $results[0]->outcome);
    }

    /**
     * R2v — the courses are in place but local_coursetransfer cannot vouch for the restore
     * (a child request did not finish): the deletion is held, audited with R2v and its issues.
     */
    public function test_unverified_restore_is_held(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $seed = $this->seed_verified();
        $children = json_decode((string)$DB->get_field(
            'local_coursetransfer_request',
            'origin_category_requests',
            ['id' => $seed->execution->requestid]
        ), true);
        $DB->set_field(
            'local_coursetransfer_request',
            'status',
            \local_coursetransfer\coursetransfer_request::STATUS_INCOMPLETED,
            ['id' => reset($children)]
        );
        $sink = $this->redirectEvents();

        $results = $this->quietly(fn() => deletion_manager::process_due(time()));

        $this->assertSame('held', $results[0]->outcome);
        $this->assertSame(0, $DB->count_records(
            'local_coursetransfer_request',
            ['type' => \local_coursetransfer\coursetransfer_request::TYPE_REMOVE_CATEGORY]
        ));
        $held = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \local_coursetransfermanager\event\deletion_held
        ));
        $this->assertCount(1, $held);
        $this->assertSame('R2v', $held[0]->other['rule']);
    }

    /**
     * S1 — the deletion is requested in the origin as the task creator.
     */
    public function test_deletion_uses_the_creator_identity(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('curlsecurityblockedhosts', '*.example.com');
        $site = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer')->create_site('origin', [
            'host' => 'https://origin.example.com',
        ]);
        $creator = $this->getDataGenerator()->create_user();
        $seed = $this->seed_verified(['originsiteid' => $site->id, 'usercreated' => $creator->id]);
        set_config('requirefirstdeletionapproval', 0, 'local_coursetransfermanager');
        // Served last in, first out: R3 reads the category detail first, then the removal answers.
        \curl::mock_response(json_encode(['success' => true, 'errors' => [], 'data' => [
            'course_category_name' => 'Curs 2019-2020', 'course_category_idnumber' => '2019-2020',
        ]]));
        \curl::mock_response(json_encode(['success' => true, 'errors' => [], 'data' => [
            'id' => 77, 'name' => 'Curs 2019-2020', 'idnumber' => '2019-2020',
            'courses' => [['id' => 501, 'fullname' => 'Course 501']],
        ]]));

        $results = $this->quietly(fn() => deletion_manager::process_due(time()));

        $this->assertSame(deletion_manager::STATUS_DONE, $results[0]->outcome);
        $remove = $DB->get_record(
            'local_coursetransfer_request',
            ['type' => \local_coursetransfer\coursetransfer_request::TYPE_REMOVE_CATEGORY],
            '*',
            MUST_EXIST
        );
        $this->assertEquals($creator->id, $remove->userid);
    }

    /**
     * S1 — without a usable creator the deletion is held, never sent as somebody else.
     */
    public function test_deletion_without_creator_is_held(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $creator = $this->getDataGenerator()->create_user();
        $seed = $this->seed_verified(['usercreated' => $creator->id]);
        delete_user($creator);

        $results = $this->quietly(fn() => deletion_manager::process_due(time()));

        $this->assertSame('held', $results[0]->outcome);
        $this->assertSame(0, $DB->count_records(
            'local_coursetransfer_request',
            ['type' => \local_coursetransfer\coursetransfer_request::TYPE_REMOVE_CATEGORY]
        ));
        $this->assertGreaterThan(time(), (int)$DB->get_field(
            'local_coursetransfermanager_executions',
            'scheduleddeleteat',
            ['id' => $seed->execution->id]
        ));
    }

    /**
     * H13 — with zero days of notice the warning and the deletion never share a pass:
     * a late notice moves the deletion to at least one day after it.
     */
    public function test_late_notice_postpones_the_deletion(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('deletionwarningdays', 0, 'local_coursetransfermanager');
        $seed = $this->seed_verified();
        $DB->set_field(
            'local_coursetransfermanager_executions',
            'deletestatus',
            deletion_manager::STATUS_SCHEDULED,
            ['id' => $seed->execution->id]
        );
        $sink = $this->redirectMessages();

        $this->quietly(fn() => (new \local_coursetransfermanager\manager\task_manager())->cleanup_tasks());

        $row = $DB->get_record('local_coursetransfermanager_executions', ['id' => $seed->execution->id]);
        $this->assertSame(deletion_manager::STATUS_WARNED, $row->deletestatus);
        $this->assertGreaterThanOrEqual(time() + DAYSECS - 5, (int)$row->scheduleddeleteat);
        $this->assertSame(['deletion_warning'], array_column($sink->get_messages(), 'eventtype'));
    }
}
