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

use core_external\external_api;
use curl;
use local_coursetransfer\coursetransfer_request;
use local_coursetransfermanager\external\task_external;
use local_coursetransfermanager\manager\academic_year;
use local_coursetransfermanager\manager\deletion_manager;
use local_coursetransfermanager\manager\prune_manager;
use local_coursetransfermanager\manager\simulation;
use local_coursetransfermanager\output\simulation_page;
use stdClass;

/**
 * Safeguards against losing a course: origin unchanged (R3), first-deletion approval,
 * simulation and the audit events.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\deletion_manager
 * @covers     \local_coursetransfermanager\manager\simulation
 * @covers     \local_coursetransfermanager\output\simulation_page
 * @covers     \local_coursetransfermanager\event\lifecycle_event
 */
final class safeguards_test extends \advanced_testcase {
    /**
     * Admin as cron, network closed.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('curlsecurityblockedhosts', '*.example.com');
    }

    /**
     * Drop any origin answer a test left unused.
     */
    protected function tearDown(): void {
        $mocks = new \ReflectionProperty(curl::class, 'mockresponses');
        $mocks->setValue(null, []);
        parent::tearDown();
    }

    /**
     * Queue the origin answers in call order.
     *
     * @param array $answers Decoded answers.
     */
    private function origin_answers(array $answers): void {
        foreach (array_reverse($answers) as $answer) {
            curl::mock_response(json_encode($answer));
        }
    }

    /**
     * Origin category detail answer.
     *
     * @param array $courses Courses as [id => fullname] or [id => [fullname, timemodified]].
     * @return array
     */
    private function detail(array $courses): array {
        $list = [];
        foreach ($courses as $id => $course) {
            $row = ['id' => $id, 'fullname' => is_array($course) ? $course[0] : $course];
            if (is_array($course)) {
                $row['timemodified'] = $course[1];
            }
            $list[] = $row;
        }
        return ['success' => true, 'errors' => [], 'data' => ['id' => 77, 'name' => 'Curs', 'idnumber' => 'X',
            'courses' => $list]];
    }

    /**
     * Successful removal answer.
     *
     * @return array
     */
    private function removed(): array {
        return ['success' => true, 'errors' => [], 'data' => ['course_category_name' => 'Curs',
            'course_category_idnumber' => 'X']];
    }

    /**
     * A due, verifiable origin deletion: completed restore of course 501, intact copy here.
     *
     * @param array $taskoverrides Task columns.
     * @return stdClass {task, execution, category}
     */
    private function seed(array $taskoverrides = []): stdClass {
        global $DB;
        $dg = $this->getDataGenerator();
        $site = $dg->get_plugin_generator('local_coursetransfer')->create_site('origin', ['host' => 'https://origin.example.com']);
        $archive = $dg->create_category(['name' => 'Archive']);
        $category = $dg->create_category(['name' => 'Curs', 'parent' => $archive->id]);
        $course = $dg->create_course(['category' => $category->id]);
        $ctgenerator = $dg->get_plugin_generator('local_coursetransfer');
        $catrequest = $ctgenerator->create_request(['type' => coursetransfer_request::TYPE_CATEGORY, 'status' => 100]);
        $child = $ctgenerator->create_request([
            'type' => coursetransfer_request::TYPE_COURSE, 'status' => 100, 'target_course_id' => $course->id,
            'request_category_id' => $catrequest->id, 'origin_course_id' => 501, 'origin_course_fullname' => 'Course 501',
            // Already checked against the origin and matching: what local_coursetransfer leaves behind.
            'origin_manifest' => json_encode(['verified' => time(), 'issues' => []]),
        ]);
        $DB->set_field(
            'local_coursetransfer_request',
            'origin_category_requests',
            json_encode([(int)$child->id]),
            ['id' => $catrequest->id]
        );
        $generator = $dg->get_plugin_generator('local_coursetransfermanager');
        $task = $generator->create_task(array_merge(
            ['originsiteid' => $site->id, 'targetcategoryid' => $archive->id],
            $taskoverrides
        ));
        $execution = $generator->create_execution([
            'taskid' => $task->id, 'status' => 'completed', 'requestid' => $catrequest->id,
            'origincategoryid' => 77, 'origincategoryname' => 'Curs', 'destinationcategoryid' => $category->id,
            'scheduleddeleteat' => time() - 60, 'deletestatus' => deletion_manager::STATUS_WARNED,
            'timecreated' => time() - 10 * DAYSECS,
        ]);
        return (object)['task' => $task, 'execution' => $execution, 'category' => $category];
    }

    /**
     * Run a callable capturing its output.
     *
     * @param callable $callback Callback.
     * @return mixed
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
     * Status of an execution's deletion.
     *
     * @param int $id Execution id.
     * @return string
     */
    private function deletestatus(int $id): string {
        global $DB;
        return $DB->get_field('local_coursetransfermanager_executions', 'deletestatus', ['id' => $id]);
    }

    /**
     * Removal requests sent to the origin.
     *
     * @return int
     */
    private function removals(): int {
        global $DB;
        return $DB->count_records('local_coursetransfer_request', ['type' => coursetransfer_request::TYPE_REMOVE_CATEGORY]);
    }

    /**
     * R3 — a course created in the origin after archiving holds the deletion, naming it.
     */
    public function test_new_course_in_origin_holds_the_deletion(): void {
        $seed = $this->seed();
        $this->origin_answers([$this->detail([501 => 'Course 501', 502 => 'Brand new course'])]);
        $sink = $this->redirectEvents();

        $results = $this->quietly(fn() => deletion_manager::process_due(time()));

        $this->assertSame('held', $results[0]->outcome);
        $this->assertStringContainsString('Brand new course', $results[0]->detail);
        $this->assertSame(0, $this->removals());
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof event\deletion_held);
        $this->assertCount(1, $events);
        $this->assertSame('R3', reset($events)->other['rule']);
    }

    /**
     * R3 — a course modified in the origin after archiving holds the deletion (when the origin says so).
     */
    public function test_modified_course_in_origin_holds_the_deletion(): void {
        $seed = $this->seed();
        $this->origin_answers([$this->detail([501 => ['Course 501', time() - HOURSECS]])]);

        $results = $this->quietly(fn() => deletion_manager::process_due(time()));

        $this->assertSame('held', $results[0]->outcome);
        $this->assertStringContainsString(
            get_string('r3_modified', 'local_coursetransfermanager', 'Course 501'),
            $results[0]->detail
        );
    }

    /**
     * R3 — an origin that does not answer the check holds the deletion.
     */
    public function test_silent_origin_holds_the_deletion(): void {
        $seed = $this->seed();
        $this->origin_answers([['success' => false, 'errors' => [['code' => '1', 'msg' => 'down']], 'data' => null]]);

        $results = $this->quietly(fn() => deletion_manager::process_due(time()));

        $this->assertSame('held', $results[0]->outcome);
        $this->assertSame(0, $this->removals());
    }

    /**
     * R3 can be turned off by the admin: the origin is then not asked.
     */
    public function test_r3_can_be_turned_off(): void {
        set_config('holdiforiginchanged', 0, 'local_coursetransfermanager');
        set_config('requirefirstdeletionapproval', 0, 'local_coursetransfermanager');
        $seed = $this->seed();
        $this->origin_answers([$this->removed()]);

        $results = $this->quietly(fn() => deletion_manager::process_due(time()));

        $this->assertSame(deletion_manager::STATUS_DONE, $results[0]->outcome);
    }

    /**
     * The first deletion of a task waits for approval; once approved it runs, the next ones
     * do not ask, and a policy change asks again.
     */
    public function test_first_deletion_waits_for_approval(): void {
        global $DB;
        $seed = $this->seed();
        $this->origin_answers([$this->detail([501 => 'Course 501'])]);
        $sink = $this->redirectMessages();

        $this->quietly(fn() => (new manager\task_manager())->cleanup_tasks());

        $this->assertSame(deletion_manager::STATUS_AWAITING_APPROVAL, $this->deletestatus((int)$seed->execution->id));
        $this->assertSame(0, $this->removals());
        $messages = $sink->get_messages();
        $this->assertSame(
            get_string('notif_approval_subject', 'local_coursetransfermanager', (object)['categoryname' => 'Curs']),
            end($messages)->subject
        );
        // Awaiting rows are not processed again on their own.
        $this->assertSame([], $this->quietly(fn() => deletion_manager::process_due(time())));

        $events = $this->redirectEvents();
        deletion_manager::approve((int)$seed->execution->id, (int)get_admin()->id);
        $this->assertInstanceOf(event\deletion_approved::class, $events->get_events()[0]);
        $events->close();

        // Every rule runs again before deleting.
        $this->origin_answers([$this->detail([501 => 'Course 501']), $this->removed()]);
        $results = $this->quietly(fn() => deletion_manager::process_due(time()));
        $this->assertSame(deletion_manager::STATUS_DONE, $results[0]->outcome);
        $this->assertSame(1, $this->removals());

        $task = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $seed->task->id]);
        $this->assertFalse(deletion_manager::needs_approval($task));
        $task->originkeepyears = 3;
        $this->assertTrue(deletion_manager::needs_approval($task));
    }

    /**
     * Approving one deletion approves the task policy: the task's other deletions waiting for
     * approval go back to the queue with their own dates; other tasks are not touched.
     */
    public function test_approval_releases_the_task_siblings(): void {
        global $DB;
        $seed = $this->seed();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        $later = time() + 5 * DAYSECS;
        $sibling = $generator->create_execution([
            'taskid' => $seed->task->id, 'status' => 'completed', 'origincategoryid' => 78,
            'origincategoryname' => 'Curs 2', 'scheduleddeleteat' => $later,
            'deletestatus' => deletion_manager::STATUS_AWAITING_APPROVAL,
        ]);
        $othertask = $generator->create_task(['originsiteid' => $seed->task->originsiteid]);
        $other = $generator->create_execution([
            'taskid' => $othertask->id, 'status' => 'completed', 'origincategoryid' => 79,
            'origincategoryname' => 'Curs 3', 'scheduleddeleteat' => $later,
            'deletestatus' => deletion_manager::STATUS_AWAITING_APPROVAL,
        ]);
        $this->origin_answers([$this->detail([501 => 'Course 501'])]);
        $this->quietly(fn() => deletion_manager::process_due(time()));
        $this->assertSame(deletion_manager::STATUS_AWAITING_APPROVAL, $this->deletestatus((int)$seed->execution->id));

        $events = $this->redirectEvents();
        deletion_manager::approve((int)$seed->execution->id, (int)get_admin()->id);

        $this->assertSame(deletion_manager::STATUS_WARNED, $this->deletestatus((int)$seed->execution->id));
        $this->assertSame(deletion_manager::STATUS_SCHEDULED, $this->deletestatus((int)$sibling->id));
        $this->assertEquals($later, $DB->get_field(
            'local_coursetransfermanager_executions',
            'scheduleddeleteat',
            ['id' => $sibling->id]
        ));
        $this->assertSame(deletion_manager::STATUS_AWAITING_APPROVAL, $this->deletestatus((int)$other->id));
        $approved = array_filter($events->get_events(), fn($e) => $e instanceof event\deletion_approved);
        $this->assertEqualsCanonicalizing(
            [(int)$seed->execution->id, (int)$sibling->id],
            array_map(fn($e) => (int)$e->objectid, $approved)
        );
    }

    /**
     * Approving needs the capability, and only a deletion awaiting approval can be approved.
     */
    public function test_approval_web_service(): void {
        $seed = $this->seed();
        $this->origin_answers([$this->detail([501 => 'Course 501'])]);
        $this->quietly(fn() => deletion_manager::process_due(time()));

        $this->setUser($this->getDataGenerator()->create_user());
        try {
            task_external::deletion_approve((int)$seed->execution->id);
            $this->fail('A user without the capability approved a deletion');
        } catch (\required_capability_exception $e) {
            $this->assertSame(deletion_manager::STATUS_AWAITING_APPROVAL, $this->deletestatus((int)$seed->execution->id));
        }

        $this->setAdminUser();
        $result = external_api::clean_returnvalue(
            task_external::deletion_approve_returns(),
            task_external::deletion_approve((int)$seed->execution->id)
        );
        $this->assertTrue($result['approved']);
        $this->assertSame(deletion_manager::STATUS_WARNED, $this->deletestatus((int)$seed->execution->id));

        $this->expectException(\moodle_exception::class);
        task_external::deletion_approve((int)$seed->execution->id);
    }

    /**
     * A deletion awaiting approval can still be cancelled.
     */
    public function test_awaiting_deletion_can_be_cancelled(): void {
        $seed = $this->seed();
        $this->origin_answers([$this->detail([501 => 'Course 501'])]);
        $this->quietly(fn() => deletion_manager::process_due(time()));

        deletion_manager::cancel((int)$seed->execution->id, (int)get_admin()->id);

        $this->assertSame(deletion_manager::STATUS_CANCELLED, $this->deletestatus((int)$seed->execution->id));
    }

    /**
     * The simulation reports what would happen and writes nothing anywhere.
     */
    public function test_simulation_writes_nothing(): void {
        global $DB, $PAGE;
        $seed = $this->seed();
        // A category of the archive past the whole policy, brought by the task.
        $year = academic_year::current_year() - 2 - 4;
        $old = $this->getDataGenerator()->create_category([
            'name' => 'Old', 'parent' => $seed->task->targetcategoryid, 'idnumber' => $year . '-' . ($year + 1),
        ]);
        $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager')->create_execution([
            'taskid' => $seed->task->id, 'destinationcategoryid' => $old->id,
        ]);
        $pending = academic_year::current_year() - 2;
        $this->origin_answers([
            ['success' => true, 'errors' => [], 'data' => [
                ['id' => 900, 'name' => 'Pending', 'idnumber' => $pending . '-' . ($pending + 1)],
            ]],
            $this->detail([501 => 'Course 501', 502 => 'Brand new course']),
        ]);
        $tables = ['local_coursetransfermanager_executions', 'local_coursetransfermanager_prune',
            'local_coursetransfer_request', 'course_categories', 'course', 'config_plugins'];
        $before = array_map(fn($t) => $DB->count_records($t), $tables);
        $sink = $this->redirectEvents();

        $result = simulation::run($seed->task);

        $this->assertSame($before, array_map(fn($t) => $DB->count_records($t), $tables));
        $this->assertSame([], $sink->get_events());
        $this->assertSame([$pending . '-' . ($pending + 1)], array_column($result->archive, 'idnumber'));
        $this->assertSame('held', $result->deletions[0]->status);
        $this->assertSame('R3', $result->deletions[0]->rule);
        $this->assertSame('announce', $result->prunings[0]->status);
        $this->assertSame(deletion_manager::STATUS_WARNED, $this->deletestatus((int)$seed->execution->id));

        // And the screen renders it.
        $page = new simulation_page($seed->task, $result, time());
        $renderer = $PAGE->get_renderer('local_coursetransfermanager');
        $html = $renderer->render_page($page, 'local_coursetransfermanager/simulation_page');
        $this->assertStringContainsString('Brand new course', $html);
    }

    /**
     * The simulation asks the origin as the task creator, like the real run, whoever simulates;
     * without a usable creator it reports it instead of asking under another identity.
     */
    public function test_simulation_uses_the_creator_identity(): void {
        global $DB, $PAGE;
        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Task', 'lastname' => 'Creator']);
        $seed = $this->seed(['usercreated' => $creator->id]);
        $this->origin_answers([
            ['success' => true, 'errors' => [], 'data' => []],
            $this->detail([501 => 'Course 501']),
        ]);

        $result = simulation::run($seed->task);

        $this->assertSame('', $result->archiveerror);
        $this->assertSame(fullname($creator), $result->identity);
        $html = $PAGE->get_renderer('local_coursetransfermanager')->render_page(
            new simulation_page($seed->task, $result, time()),
            'local_coursetransfermanager/simulation_page'
        );
        $this->assertStringContainsString(get_string('sim_identity', 'local_coursetransfermanager', fullname($creator)), $html);

        // The admin simulating never stands in for a suspended creator.
        $DB->set_field('user', 'suspended', 1, ['id' => $creator->id]);

        $result = simulation::run($seed->task);

        $this->assertSame(get_string('creatorunavailable', 'local_coursetransfermanager', $creator->id), $result->archiveerror);
        $this->assertSame('', $result->identity);
        $this->assertSame([], $result->archive);
    }

    /**
     * The simulation web service needs the capability and returns its structure.
     */
    public function test_simulation_web_service(): void {
        $seed = $this->seed(['originsiteid' => 0]);
        $result = external_api::clean_returnvalue(
            task_external::task_simulate_returns(),
            task_external::task_simulate((int)$seed->task->id)
        );
        $this->assertNotSame('', $result['archiveerror']);
        $this->assertCount(1, $result['deletions']);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        task_external::task_simulate((int)$seed->task->id);
    }

    /**
     * Every destructive step of the pruning leaves an event, with the rule behind it.
     */
    public function test_pruning_events(): void {
        $seed = $this->seed();
        $year = academic_year::current_year() - 2 - 4;
        $old = $this->getDataGenerator()->create_category([
            'name' => 'Old', 'parent' => $seed->task->targetcategoryid, 'idnumber' => $year . '-' . ($year + 1),
        ]);
        $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager')->create_execution([
            'taskid' => $seed->task->id, 'destinationcategoryid' => $old->id,
        ]);
        $sink = $this->redirectEvents();

        $announced = $this->quietly(fn() => prune_manager::detect(time(), 7));
        $this->quietly(fn() => prune_manager::execute_due(time() + 8 * DAYSECS));

        $classes = array_map(fn($e) => get_class($e), $sink->get_events());
        $this->assertContains(event\prune_announced::class, $classes);
        $this->assertContains(event\prune_executed::class, $classes);
        $executed = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof event\prune_executed))[0];
        $this->assertSame((int)$announced[0]->id, (int)$executed->objectid);
        $this->assertSame('Old', $executed->other['category']);
        $this->assertNotEmpty($executed->get_description());
    }

    /**
     * Cancelling a deletion and excluding a pruning leave who did it.
     */
    public function test_manual_decisions_are_audited(): void {
        $seed = $this->seed();
        $candidate = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager')->create_prune([
            'taskid' => $seed->task->id, 'categoryid' => $seed->category->id, 'categoryname' => 'Curs',
        ]);
        $sink = $this->redirectEvents();

        deletion_manager::cancel((int)$seed->execution->id, (int)get_admin()->id);
        prune_manager::exclude((int)$candidate->id, (int)get_admin()->id);

        $events = $sink->get_events();
        $this->assertInstanceOf(event\deletion_cancelled::class, $events[0]);
        $this->assertInstanceOf(event\prune_excluded::class, $events[1]);
        $this->assertEquals(get_admin()->id, $events[0]->userid);
        $this->assertSame('manual', $events[1]->other['rule']);
    }

    /**
     * A deletion that runs leaves the request and the execution, with the rules it passed.
     */
    public function test_executed_deletion_is_audited(): void {
        set_config('requirefirstdeletionapproval', 0, 'local_coursetransfermanager');
        $seed = $this->seed();
        $this->origin_answers([$this->detail([501 => 'Course 501']), $this->removed()]);
        $sink = $this->redirectEvents();

        $this->quietly(fn() => deletion_manager::process_due(time()));

        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof event\lifecycle_event));
        $this->assertInstanceOf(event\deletion_requested::class, $events[0]);
        $this->assertInstanceOf(event\deletion_executed::class, $events[1]);
        $this->assertSame('R1+R2+R2v+R3', $events[1]->other['rule']);
        $this->assertEquals($seed->task->usercreated, $events[1]->relateduserid);
    }
}
