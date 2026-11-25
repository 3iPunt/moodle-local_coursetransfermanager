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

/**
 * Tests for the archive pipeline (cron and run now) against a mocked origin.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\task;

use advanced_testcase;
use curl;
use local_coursetransfermanager\manager\academic_year;
use local_coursetransfermanager\manager\task_manager;
use stdClass;

/**
 * Archive pipeline: restore, relocation, scheduling, retries and identity.
 *
 * The origin never answers for real: every remote call is served by curl::mock_response()
 * and example.com is blocked as a safety net.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\task_manager
 * @covers     \local_coursetransfermanager\task\process_tasks
 */
final class archive_pipeline_test extends advanced_testcase {
    /** @var int Origin category id sequence for the mocked answers. */
    private int $remoteid = 100;

    /**
     * Run as cron does, with the network closed.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('curlsecurityblockedhosts', '*.example.com');
        set_config('ignorecurlsecurity', 0, 'local_coursetransfer');
    }

    /**
     * Drop any origin answer a test left unused, so it never leaks into the next test.
     */
    protected function tearDown(): void {
        $mocks = new \ReflectionProperty(curl::class, 'mockresponses');
        $mocks->setValue(null, []);
        parent::tearDown();
    }

    /**
     * Queue the origin answers in call order (curl mocks are served last in, first out).
     *
     * @param array $answers Decoded answers, in the order the code asks for them.
     */
    private function origin_answers(array $answers): void {
        foreach (array_reverse($answers) as $answer) {
            curl::mock_response(json_encode($answer));
        }
    }

    /**
     * A successful envelope.
     *
     * @param mixed $data Payload.
     * @return array
     */
    private function ok($data = null): array {
        return ['success' => true, 'errors' => [], 'data' => $data];
    }

    /**
     * A failed envelope.
     *
     * @param string $msg Error message.
     * @return array
     */
    private function ko(string $msg): array {
        return ['success' => false, 'errors' => [['code' => '999', 'msg' => $msg]], 'data' => null];
    }

    /**
     * Origin category descriptor for an academic year.
     *
     * @param string $idnumber Idnumber.
     * @return array {id, name, idnumber, parentid, totalcourses}
     */
    private function remote_category(string $idnumber): array {
        $this->remoteid++;
        return ['id' => $this->remoteid, 'name' => 'Course ' . $idnumber, 'idnumber' => $idnumber,
            'parentid' => 0, 'totalcourses' => 1];
    }

    /**
     * Category detail answer with the given courses.
     *
     * @param array $category Remote category descriptor.
     * @param int $courses Courses in it.
     * @return array
     */
    private function detail(array $category, int $courses = 0): array {
        $list = [];
        for ($i = 1; $i <= $courses; $i++) {
            $list[] = ['id' => $category['id'] * 10 + $i, 'fullname' => "Course $i", 'shortname' => "C{$category['id']}-$i"];
        }
        return $this->ok(['id' => $category['id'], 'name' => $category['name'],
            'idnumber' => $category['idnumber'], 'courses' => $list]);
    }

    /**
     * Backup answer for one course.
     *
     * @return array
     */
    private function backup_ok(): array {
        return $this->ok([
            'course_fullname' => 'Course', 'course_shortname' => 'C', 'course_idnumber' => '',
            'course_category_id' => 1, 'course_category_name' => 'X', 'course_category_idnumber' => '',
            'origin_backup_size_estimated' => 10,
        ]);
    }

    /**
     * Seed an origin site, a destination and a due task.
     *
     * @param array $overrides Task columns.
     * @return stdClass {task, destination, threshold}
     */
    private function seed(array $overrides = []): stdClass {
        $site = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer')->create_site('origin', [
            'host' => 'https://origin.example.com',
        ]);
        $destination = $this->getDataGenerator()->create_category(['name' => 'Archive']);
        $task = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager')->create_task(array_merge([
            'originsiteid' => $site->id,
            'targetcategoryid' => $destination->id,
            'nextruntime' => time() - 60,
        ], $overrides));
        return (object) [
            'task' => $task,
            'destination' => $destination,
            'threshold' => academic_year::current_year() - (int) $task->originkeepyears,
        ];
    }

    /**
     * Idnumber of an academic year with the default mask.
     *
     * @param int $year Academic year.
     * @return string
     */
    private function idnumber(int $year): string {
        return academic_year::example('{YEAR}-{NEXTYEAR}', $year);
    }

    /**
     * Run the cron task, capturing its output.
     *
     * @return string
     */
    private function run_cron(): string {
        ob_start();
        (new process_tasks())->execute();
        return ob_get_clean();
    }

    /**
     * Run the manager's run_now, capturing its output.
     *
     * @param int $taskid Task id.
     * @return stdClass
     */
    private function run_now(int $taskid): stdClass {
        ob_start();
        $result = (new task_manager())->run_now($taskid);
        ob_end_clean();
        return $result;
    }

    /**
     * Executions of a task, oldest first.
     *
     * @param int $taskid Task id.
     * @return stdClass[]
     */
    private function executions(int $taskid): array {
        global $DB;
        return array_values($DB->get_records('local_coursetransfermanager_executions', ['taskid' => $taskid], 'id'));
    }

    /**
     * H2 — the restored category is resolved from the request's own courses, never by
     * idnumber: a foreign category with the same idnumber is not moved nor managed.
     */
    public function test_relocation_never_takes_a_foreign_category(): void {
        global $DB;
        $seed = $this->seed();
        $year = $seed->threshold;
        // Foreign category already holding the idnumber: coursetransfer creates the real one with a suffix.
        $elsewhere = $this->getDataGenerator()->create_category(['name' => 'Elsewhere']);
        $foreign = $this->getDataGenerator()->create_category([
            'name' => 'Foreign', 'idnumber' => $this->idnumber($year), 'parent' => $elsewhere->id,
        ]);
        $remote = $this->remote_category($this->idnumber($year));
        $this->origin_answers([$this->ok(), $this->ok([$remote]), $this->detail($remote, 1), $this->backup_ok()]);
        $this->redirectMessages();

        $this->run_cron();

        $executions = $this->executions((int) $seed->task->id);
        $this->assertCount(1, $executions);
        $this->assertSame(task_manager::STATUS_SUCCESS, $executions[0]->status);
        $this->assertNotEquals($foreign->id, $executions[0]->destinationcategoryid);
        $this->assertEquals($elsewhere->id, $DB->get_field('course_categories', 'parent', ['id' => $foreign->id]));
        // The managed category is the one holding the restored course, now under the destination.
        $created = $DB->get_record('course_categories', ['id' => $executions[0]->destinationcategoryid], '*', MUST_EXIST);
        $this->assertEquals($seed->destination->id, $created->parent);
        $this->assertStringStartsWith($this->idnumber($year) . '_', $created->idnumber);
        $this->assertSame(1, $DB->count_records('course', ['category' => $created->id]));
    }

    /**
     * H2 — an empty restored category cannot be told apart with certainty: it is not
     * relocated nor managed, and the execution says so.
     */
    public function test_unresolvable_category_is_not_managed(): void {
        $seed = $this->seed();
        $remote = $this->remote_category($this->idnumber($seed->threshold));
        $this->origin_answers([$this->ok(), $this->ok([$remote]), $this->detail($remote, 0)]);
        $this->redirectMessages();

        $output = $this->run_cron();

        $executions = $this->executions((int) $seed->task->id);
        $this->assertCount(1, $executions);
        $this->assertSame(task_manager::STATUS_SUCCESS, $executions[0]->status);
        $this->assertNull($executions[0]->destinationcategoryid);
        $this->assertSame('relocation_unresolved', $executions[0]->errorcode);
        $this->assertStringContainsString('Relocation skipped', $output);
    }

    /**
     * S1 — scheduled executions talk to the origin as the task creator, not as the
     * user cron runs as.
     */
    public function test_scheduled_run_uses_the_creator_identity(): void {
        global $DB;
        $creator = $this->getDataGenerator()->create_user(['username' => 'creator']);
        $seed = $this->seed(['usercreated' => $creator->id]);
        $remote = $this->remote_category($this->idnumber($seed->threshold));
        $this->origin_answers([$this->ok(), $this->ok([$remote]), $this->detail($remote, 1), $this->backup_ok()]);
        $this->redirectMessages();

        $this->run_cron();

        $requests = $DB->get_records('local_coursetransfer_request');
        $this->assertCount(2, $requests);
        foreach ($requests as $request) {
            $this->assertEquals($creator->id, $request->userid);
        }
    }

    /**
     * S1 — a creator that no longer exists (or is suspended) stops the run: nothing
     * reaches the origin under somebody else's identity.
     */
    public function test_unavailable_creator_stops_the_run(): void {
        global $DB;
        $creator = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $seed = $this->seed(['usercreated' => $creator->id]);
        $sink = $this->redirectMessages();

        $this->run_cron();

        $executions = $this->executions((int) $seed->task->id);
        $this->assertCount(1, $executions);
        $this->assertSame(task_manager::STATUS_ERROR, $executions[0]->status);
        $this->assertSame('creator_unavailable', $executions[0]->errorcode);
        $this->assertSame(0, $DB->count_records('local_coursetransfer_request'));
        $this->assertSame(['execution_error'], array_column($sink->get_messages(), 'eventtype'));
    }

    /**
     * H3 — with a wildcard mask several categories share a year: each one is archived,
     * and the one that failed before launching is retried.
     */
    public function test_deduplication_is_per_category(): void {
        $seed = $this->seed(['categorypattern' => '{ANY}-{YEAR}-{NEXTYEAR}']);
        $year = $seed->threshold;
        $ginf = $this->remote_category('GINF-' . $this->idnumber($year));
        $med = $this->remote_category('MED-' . $this->idnumber($year));
        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        $generator->create_execution(['taskid' => $seed->task->id, 'status' => 'completed',
            'origincategoryid' => $ginf['id'], 'origincategoryidnumber' => $ginf['idnumber'], 'requestid' => 1]);
        // MED failed before anything was launched (no request): it must be retried.
        $generator->create_execution(['taskid' => $seed->task->id, 'status' => 'error',
            'origincategoryid' => $med['id'], 'origincategoryidnumber' => $med['idnumber']]);
        $this->origin_answers([$this->ok([$ginf, $med])]);

        $pending = \local_coursetransfermanager\manager\rotation::to_archive($seed->task);

        $this->assertSame([$med['idnumber']], array_column($pending, 'idnumber'));
    }

    /**
     * H3 — a category whose restore was launched and then failed half way is not
     * relaunched automatically: that would duplicate the courses that did arrive.
     */
    public function test_partially_launched_category_is_not_relaunched(): void {
        $seed = $this->seed();
        $remote = $this->remote_category($this->idnumber($seed->threshold));
        $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager')->create_execution([
            'taskid' => $seed->task->id, 'status' => 'error', 'requestid' => 5,
            'origincategoryid' => $remote['id'], 'origincategoryidnumber' => $remote['idnumber'],
        ]);
        $this->origin_answers([$this->ok([$remote])]);

        $this->assertSame([], \local_coursetransfermanager\manager\rotation::to_archive($seed->task));
    }

    /**
     * H5 — what the per-run cap leaves pending continues soon, not next year.
     */
    public function test_capped_run_continues_within_the_cycle(): void {
        global $DB;
        set_config('maxarchivepertick', 1, 'local_coursetransfermanager');
        $seed = $this->seed();
        $old = $this->remote_category($this->idnumber($seed->threshold - 1));
        $new = $this->remote_category($this->idnumber($seed->threshold));
        $this->origin_answers([$this->ok(), $this->ok([$old, $new]), $this->detail($old, 1), $this->backup_ok()]);
        $this->redirectMessages();

        $this->run_cron();

        $this->assertCount(1, $this->executions((int) $seed->task->id));
        $nextrun = (int) $DB->get_field('local_coursetransfermanager_tasks', 'nextruntime', ['id' => $seed->task->id]);
        $this->assertGreaterThan(time(), $nextrun);
        $this->assertLessThanOrEqual(time() + 2 * HOURSECS, $nextrun);
    }

    /**
     * H5 — run now continues with what is pending even after a success in this cycle.
     */
    public function test_run_now_is_not_blocked_while_something_is_pending(): void {
        $seed = $this->seed();
        $done = $this->remote_category($this->idnumber($seed->threshold - 1));
        $pending = $this->remote_category($this->idnumber($seed->threshold));
        $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager')->create_execution([
            'taskid' => $seed->task->id, 'status' => 'success', 'requestid' => 9,
            'origincategoryid' => $done['id'], 'origincategoryidnumber' => $done['idnumber'],
        ]);
        $this->origin_answers([$this->ok([$done, $pending]), $this->ok(), $this->ok([$done, $pending]),
            $this->detail($pending, 1), $this->backup_ok()]);
        $this->redirectMessages();

        $result = $this->run_now((int) $seed->task->id);

        $this->assertFalse($result->blocked);
        $this->assertTrue($result->launched);
        $this->assertCount(2, $this->executions((int) $seed->task->id));
    }

    /**
     * A lock factory that behaves like another process holding the lock.
     *
     * Postgres advisory locks are re-entrant within one connection, so the test uses database records.
     *
     * @return \core\lock\lock_factory
     */
    private function other_process_locks(): \core\lock\lock_factory {
        global $CFG;
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        return \core\lock\lock_config::get_lock_factory('local_coursetransfermanager');
    }

    /**
     * H5 — run now refuses while another process holds the task.
     */
    public function test_run_now_respects_the_task_lock(): void {
        $seed = $this->seed(['originsiteid' => 0]);
        $factory = $this->other_process_locks();
        $lock = $factory->get_lock('task_' . $seed->task->id, 0);
        $this->assertNotEmpty($lock);

        try {
            $result = $this->run_now((int) $seed->task->id);
        } finally {
            $lock->release();
        }

        $this->assertFalse($result->launched);
        $this->assertTrue($result->busy);
        $this->assertSame([], $this->executions((int) $seed->task->id));
    }

    /**
     * H5 — cron skips a task another process holds, without moving its schedule.
     */
    public function test_cron_respects_the_task_lock(): void {
        global $DB;
        $seed = $this->seed(['originsiteid' => 0]);
        $factory = $this->other_process_locks();
        $lock = $factory->get_lock('task_' . $seed->task->id, 0);

        try {
            $this->run_cron();
        } finally {
            $lock->release();
        }

        $this->assertSame([], $this->executions((int) $seed->task->id));
        $this->assertEquals(
            $seed->task->nextruntime,
            $DB->get_field('local_coursetransfermanager_tasks', 'nextruntime', ['id' => $seed->task->id])
        );
    }

    /**
     * H8 — a restore that failed half way keeps its request and the created category.
     */
    public function test_partial_failure_keeps_the_request_and_category(): void {
        global $DB;
        $seed = $this->seed();
        $remote = $this->remote_category($this->idnumber($seed->threshold));
        $this->origin_answers([$this->ok(), $this->ok([$remote]), $this->detail($remote, 1), $this->ko('backup failed')]);
        $this->redirectMessages();

        $this->run_cron();

        $executions = $this->executions((int) $seed->task->id);
        $this->assertCount(1, $executions);
        $this->assertSame(task_manager::STATUS_ERROR, $executions[0]->status);
        $this->assertNotEmpty($executions[0]->requestid);
        $this->assertTrue($DB->record_exists('local_coursetransfer_request', ['id' => $executions[0]->requestid]));
        $this->assertNotEmpty($executions[0]->destinationcategoryid);
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $executions[0]->destinationcategoryid]));
    }

    /**
     * H9 — run now does not report a launch when every category failed.
     */
    public function test_run_now_reports_failures(): void {
        $seed = $this->seed();
        $remote = $this->remote_category($this->idnumber($seed->threshold));
        $this->origin_answers([$this->ok(), $this->ok([$remote]), $this->ko('detail refused')]);
        $this->redirectMessages();

        $result = $this->run_now((int) $seed->task->id);

        $this->assertFalse($result->launched);
        $this->assertSame(0, $result->succeeded);
        $this->assertSame(1, $result->failed);
        $this->assertNotEmpty($result->error);
    }

    /**
     * CTM-015 — run now answers a web request: the progress lines meant for the cron log must not reach the
     * output, or they would go before the JSON of the answer.
     */
    public function test_run_now_prints_nothing(): void {
        $seed = $this->seed();
        $remote = $this->remote_category($this->idnumber($seed->threshold));
        $this->origin_answers([$this->ok(), $this->ok([$remote]), $this->ko('detail refused')]);
        $this->redirectMessages();

        $this->expectOutputString('');
        (new task_manager())->run_now((int) $seed->task->id);
    }

    /**
     * H10 — a task without a next run time is not taken as overdue: its schedule is
     * recomputed and nothing runs outside it.
     */
    public function test_missing_next_run_is_not_due(): void {
        global $DB;
        $seed = $this->seed(['originsiteid' => 0, 'nextruntime' => null]);

        $this->run_cron();

        $this->assertSame([], $this->executions((int) $seed->task->id));
        $nextrun = $DB->get_field('local_coursetransfermanager_tasks', 'nextruntime', ['id' => $seed->task->id]);
        $this->assertGreaterThan(time(), (int) $nextrun);
    }

    /**
     * H11 — an origin whose category list cannot be read is an error with a retry,
     * never a silent "nothing to archive".
     */
    public function test_unreadable_origin_is_retried(): void {
        global $DB;
        $seed = $this->seed();
        $this->origin_answers([$this->ok(), $this->ko('listing refused')]);
        $sink = $this->redirectMessages();

        $output = $this->run_cron();

        $this->assertStringNotContainsString('Nothing to archive', $output);
        $executions = $this->executions((int) $seed->task->id);
        $this->assertCount(1, $executions);
        $this->assertSame(task_manager::STATUS_ERROR, $executions[0]->status);
        $this->assertSame('origin_unreachable', $executions[0]->errorcode);
        $this->assertStringContainsString('listing refused', $executions[0]->errormessage);
        $this->assertSame(['execution_error'], array_column($sink->get_messages(), 'eventtype'));
        $nextrun = (int) $DB->get_field('local_coursetransfermanager_tasks', 'nextruntime', ['id' => $seed->task->id]);
        $this->assertGreaterThan(time(), $nextrun);
        $this->assertLessThanOrEqual(time() + 2 * HOURSECS, $nextrun);
    }

    /**
     * H11 — the retries are capped: after the last one the task waits for its next cycle.
     */
    public function test_origin_retries_are_capped(): void {
        global $DB;
        $seed = $this->seed();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        for ($i = 0; $i < task_manager::ORIGIN_MAX_RETRIES; $i++) {
            $generator->create_execution(['taskid' => $seed->task->id, 'status' => 'error',
                'errorcode' => 'origin_unreachable', 'timecreated' => time() - 60]);
        }
        $this->origin_answers([$this->ok(), $this->ko('listing refused')]);
        $this->redirectMessages();

        $this->run_cron();

        $nextrun = (int) $DB->get_field('local_coursetransfermanager_tasks', 'nextruntime', ['id' => $seed->task->id]);
        $this->assertGreaterThan(time() + DAYSECS, $nextrun);
    }

    /**
     * The reason the origin sync failed is part of the message itself ($a), not debuginfo:
     * outside PHPUnit and developer debugging, moodle_exception drops debuginfo.
     */
    public function test_sync_failure_detail_is_in_the_message(): void {
        $seed = $this->seed();
        $this->origin_answers([$this->ko('Target site not registered in origin')]);
        $this->redirectMessages();

        $this->run_cron();

        $executions = $this->executions((int) $seed->task->id);
        $this->assertSame(
            get_string('originsyncfailed', 'local_coursetransfermanager', '999 Target site not registered in origin'),
            $executions[0]->errormessage
        );
    }
}
