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
 * Tests for the process_tasks scheduled task.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\task;

use advanced_testcase;
use curl;
use local_coursetransfermanager\manager\prune_manager;
use local_coursetransfermanager\manager\task_manager;

/**
 * Tests for the process_tasks scheduled task.
 *
 * No test reaches the network: origin sites live under example.com, the cURL security
 * helper blocks that domain, and the origin answers, when one is needed, are mocked
 * with curl::mock_response().
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\task\process_tasks
 * @covers     \local_coursetransfermanager\manager\task_manager
 */
final class process_tasks_test extends advanced_testcase {
    /**
     * Run every test as cron does, with the network closed.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Cron runs scheduled tasks as the admin (\core\cron::setup_user()).
        $this->setAdminUser();
        // Safety net: if a test ever forgot a mock, the request is refused before any DNS lookup.
        set_config('curlsecurityblockedhosts', '*.example.com');
        set_config('ignorecurlsecurity', 0, 'local_coursetransfer');
    }

    /**
     * Get the plugin generator.
     *
     * @return \local_coursetransfermanager_generator
     */
    private function generator(): \local_coursetransfermanager_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
    }

    /**
     * Run the scheduled task and return what it printed.
     *
     * @return string
     */
    private function run_task(): string {
        ob_start();
        (new process_tasks())->execute();
        return ob_get_clean();
    }

    /**
     * Executions of a task.
     *
     * @param int $taskid Task id.
     * @return \stdClass[]
     */
    private function executions(int $taskid): array {
        global $DB;
        return array_values($DB->get_records('local_coursetransfermanager_executions', ['taskid' => $taskid], 'id'));
    }

    /**
     * The task is registered with a name.
     */
    public function test_task_is_registered(): void {
        $task = \core\task\manager::get_scheduled_task(process_tasks::class);
        $this->assertInstanceOf(process_tasks::class, $task);
        $this->assertNotEmpty($task->get_name());
    }

    /**
     * A due task without an origin site fails before any remote call: the error is
     * recorded, the creator is told and the schedule moves on so it does not retry
     * on every cron tick.
     */
    public function test_due_task_without_origin_records_the_error(): void {
        global $DB;

        $task = $this->generator()->create_task(['originsiteid' => 0, 'nextruntime' => time() - 60]);
        $sink = $this->redirectMessages();

        $output = $this->run_task();

        $this->assertStringContainsString('Task failed', $output);
        $executions = $this->executions((int)$task->id);
        $this->assertCount(1, $executions);
        $this->assertSame(task_manager::STATUS_ERROR, $executions[0]->status);
        $this->assertSame(get_string('originsitenotset', 'local_coursetransfermanager'), $executions[0]->errormessage);
        $this->assertEquals(0, $executions[0]->manualrun);

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('execution_error', $messages[0]->eventtype);
        $this->assertEquals(get_admin()->id, $messages[0]->useridto);

        $row = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $task->id]);
        $this->assertGreaterThan(time(), (int)$row->nextruntime);
        $this->assertGreaterThan(0, (int)$row->lastruntime);
    }

    /**
     * The origin answers that the sync is broken (mocked answer): the execution
     * fails with the remote reason and nothing is restored.
     */
    public function test_origin_rejecting_the_sync_records_the_error(): void {
        global $DB;

        $site = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer')->create_site('origin', [
            'host' => 'https://origin.example.com',
        ]);
        $task = $this->generator()->create_task(['originsiteid' => $site->id, 'nextruntime' => time() - 60]);
        curl::mock_response(json_encode([
            'success' => false,
            'errors' => [['code' => '10010', 'msg' => 'Target site not registered in origin']],
            'data' => null,
        ]));
        $sink = $this->redirectMessages();

        $output = $this->run_task();

        $this->assertStringContainsString('Origin sync verification failed', $output);
        $executions = $this->executions((int)$task->id);
        $this->assertCount(1, $executions);
        $this->assertSame(task_manager::STATUS_ERROR, $executions[0]->status);
        $this->assertStringContainsString(
            get_string('originsyncfailed', 'local_coursetransfermanager', '10010 Target site not registered in origin'),
            $executions[0]->errormessage
        );
        $this->assertSame(0, $DB->count_records('local_coursetransfer_request'));
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * An origin the task cannot reach (here, blocked by the cURL security helper) is an
     * error too, and the transport failure is logged for the developer.
     */
    public function test_unreachable_origin_records_the_error(): void {
        $site = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer')->create_site('origin', [
            'host' => 'https://unreachable.example.com',
        ]);
        $task = $this->generator()->create_task(['originsiteid' => $site->id, 'nextruntime' => time() - 60]);
        $this->redirectMessages();

        $output = $this->run_task();

        // Core reports the blocked URL and local_coursetransfer logs the failed request,
        // both with debugging(); how many messages depends on the Moodle version.
        $this->assertNotEmpty($this->getDebuggingMessages());
        $this->resetDebugging();
        $this->assertStringContainsString('Task failed', $output);
        $executions = $this->executions((int)$task->id);
        $this->assertCount(1, $executions);
        $this->assertSame(task_manager::STATUS_ERROR, $executions[0]->status);
    }

    /**
     * A healthy origin with nothing old enough to archive: no execution, no error, and
     * the schedule moves on.
     */
    public function test_nothing_to_archive(): void {
        global $DB;

        $site = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer')->create_site('origin', [
            'host' => 'https://origin.example.com',
        ]);
        $task = $this->generator()->create_task(['originsiteid' => $site->id, 'nextruntime' => time() - 60]);
        // Mocks are served last in, first out: the categories answer goes first.
        curl::mock_response(json_encode(['success' => true, 'errors' => [], 'data' => []]));
        curl::mock_response(json_encode(['success' => true, 'errors' => [], 'data' => null]));
        $sink = $this->redirectMessages();

        $output = $this->run_task();

        $this->assertStringContainsString('Origin sync verification OK', $output);
        $this->assertStringContainsString('Nothing to archive', $output);
        $this->assertSame([], $this->executions((int)$task->id));
        $this->assertSame(0, $sink->count());
        $nextrun = (int)$DB->get_field('local_coursetransfermanager_tasks', 'nextruntime', ['id' => $task->id]);
        $this->assertGreaterThan(time(), $nextrun);
    }

    /**
     * Tasks not due yet and disabled tasks never reach the origin.
     *
     * A success earlier in the cycle no longer skips a due run: categories are deduplicated one
     * by one, so the run only brings what is still pending (see archive_pipeline_test).
     */
    public function test_tasks_that_must_not_run(): void {
        global $DB;

        $future = time() + DAYSECS;
        $notdue = $this->generator()->create_task(['originsiteid' => 0, 'nextruntime' => $future]);
        $disabled = $this->generator()->create_task(['originsiteid' => 0, 'enabled' => 0, 'nextruntime' => time() - 60]);

        $this->run_task();

        $this->assertSame([], $this->executions((int)$notdue->id));
        $this->assertSame([], $this->executions((int)$disabled->id));
        $this->assertEquals($future, $DB->get_field('local_coursetransfermanager_tasks', 'nextruntime', ['id' => $notdue->id]));
    }

    /**
     * The cleanup pass prunes the archive candidates whose grace period ended.
     */
    public function test_cleanup_prunes_candidates_out_of_grace(): void {
        global $DB;

        // A category the task brought, under its destination and past the whole policy.
        $archive = $this->getDataGenerator()->create_category(['name' => 'Archive']);
        $year = \local_coursetransfermanager\manager\academic_year::current_year() - 2 - 4;
        $category = $this->getDataGenerator()->create_category([
            'name' => 'Old archive', 'parent' => $archive->id, 'idnumber' => $year . '-' . ($year + 1),
        ]);
        $task = $this->generator()->create_task([
            'originsiteid' => 0, 'nextruntime' => time() + DAYSECS, 'targetcategoryid' => $archive->id,
        ]);
        $this->generator()->create_execution(['taskid' => $task->id, 'destinationcategoryid' => $category->id]);
        $candidate = $this->generator()->create_prune([
            'taskid' => $task->id,
            'categoryid' => $category->id,
            'categoryname' => 'Old archive',
            'graceuntil' => time() - 60,
        ]);
        $sink = $this->redirectMessages();

        $this->run_task();

        $this->assertFalse($DB->record_exists('course_categories', ['id' => $category->id]));
        $this->assertSame(
            prune_manager::STATUS_DONE,
            $DB->get_field('local_coursetransfermanager_prune', 'status', ['id' => $candidate->id])
        );
        $this->assertSame(['prune_done'], array_column($sink->get_messages(), 'eventtype'));
    }

    /**
     * With the emergency switch on, nothing is pruned and the due candidates slide forward.
     */
    public function test_emergency_switch_postpones_the_pruning(): void {
        global $DB;

        set_config('deletionspaused', 1, 'local_coursetransfermanager');
        $task = $this->generator()->create_task(['originsiteid' => 0, 'nextruntime' => time() + DAYSECS]);
        $category = $this->getDataGenerator()->create_category();
        $candidate = $this->generator()->create_prune([
            'taskid' => $task->id,
            'categoryid' => $category->id,
            'graceuntil' => time() - 60,
        ]);

        $output = $this->run_task();

        $this->assertStringContainsString('PAUSED', $output);
        $this->assertTrue($DB->record_exists('course_categories', ['id' => $category->id]));
        $row = $DB->get_record('local_coursetransfermanager_prune', ['id' => $candidate->id]);
        $this->assertSame(prune_manager::STATUS_ANNOUNCED, $row->status);
        $this->assertGreaterThan(time(), (int)$row->graceuntil);
    }
}
