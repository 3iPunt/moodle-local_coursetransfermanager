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

namespace local_coursetransfermanager;

use curl;
use local_coursetransfer\coursetransfer_request;
use local_coursetransfermanager\manager\deletion_manager;
use local_coursetransfermanager\manager\prune_manager;
use local_coursetransfermanager\manager\rotation;
use local_coursetransfermanager\manager\simulation;
use local_coursetransfermanager\manager\task_manager;
use stdClass;

/**
 * Safeguards added in 2.2.2: same platform, user data, hidden content, recorded holds, read-only simulation,
 * claimed pruning, retried empty archives and account errors.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_coursetransfermanager
 * @covers     \local_coursetransfermanager\manager\deletion_manager
 * @covers     \local_coursetransfermanager\manager\task_manager
 * @covers     \local_coursetransfermanager\manager\prune_manager
 * @covers     \local_coursetransfermanager\manager\rotation
 * @covers     \local_coursetransfermanager\manager\simulation
 */
final class safeguards_v222_test extends \advanced_testcase {
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
     * Origin category detail answer with course 501 unchanged.
     *
     * @param array $extra Extra data keys (hiddencategories, hiddencourses).
     * @return array
     */
    private function detail(array $extra = []): array {
        return ['success' => true, 'errors' => [], 'data' => array_merge(['id' => 77, 'name' => 'Curs', 'idnumber' => 'X',
            'courses' => [['id' => 501, 'fullname' => 'Course 501']]], $extra)];
    }

    /**
     * A due, verifiable origin deletion: completed restore of course 501, intact copy here.
     *
     * @param array $taskoverrides Task columns.
     * @param array $manifest Manifest of the course request.
     * @return stdClass {task, execution, category, child, site}
     */
    private function seed(array $taskoverrides = [], array $manifest = []): stdClass {
        global $DB;
        $dg = $this->getDataGenerator();
        $ctgenerator = $dg->get_plugin_generator('local_coursetransfer');
        $site = $ctgenerator->create_site('origin', ['host' => 'https://origin.example.com']);
        $archive = $dg->create_category(['name' => 'Archive']);
        $category = $dg->create_category(['name' => 'Curs', 'parent' => $archive->id]);
        $course = $dg->create_course(['category' => $category->id]);
        $catrequest = $ctgenerator->create_request(['type' => coursetransfer_request::TYPE_CATEGORY, 'status' => 100]);
        $child = $ctgenerator->create_request([
            'type' => coursetransfer_request::TYPE_COURSE, 'status' => 100, 'target_course_id' => $course->id,
            'request_category_id' => $catrequest->id, 'origin_course_id' => 501, 'origin_course_fullname' => 'Course 501',
            'origin_manifest' => json_encode($manifest ?: ['verified' => time(), 'issues' => []]),
        ]);
        $children = json_encode([(int) $child->id]);
        $DB->set_field('local_coursetransfer_request', 'origin_category_requests', $children, ['id' => $catrequest->id]);
        $generator = $dg->get_plugin_generator('local_coursetransfermanager');
        $taskdata = array_merge(['originsiteid' => $site->id, 'targetcategoryid' => $archive->id], $taskoverrides);
        $task = $generator->create_task($taskdata);
        $execution = $generator->create_execution([
            'taskid' => $task->id, 'status' => 'completed', 'requestid' => $catrequest->id,
            'origincategoryid' => 77, 'origincategoryname' => 'Curs', 'destinationcategoryid' => $category->id,
            'scheduleddeleteat' => time() - 60, 'deletestatus' => deletion_manager::STATUS_WARNED,
            'timecreated' => time() - 10 * DAYSECS,
        ]);
        return (object) ['task' => $task, 'execution' => $execution, 'category' => $category, 'child' => $child,
            'site' => $site];
    }

    /**
     * Run the due deletions without printing.
     *
     * @return array
     */
    private function process(): array {
        ob_start();
        try {
            return deletion_manager::process_due(time());
        } finally {
            ob_end_clean();
        }
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
     * A task pointed to another platform after archiving never sends the deletion there (B3).
     */
    public function test_deletion_only_to_the_platform_of_the_copy(): void {
        global $DB;
        $seed = $this->seed(['enabled' => 1]);
        $other = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer')
            ->create_site('origin', ['host' => 'https://other.example.com']);
        $DB->set_field('local_coursetransfermanager_tasks', 'originsiteid', $other->id, ['id' => $seed->task->id]);

        $results = $this->process();

        $this->assertSame('held', $results[0]->outcome);
        $this->assertSame(0, $this->removals());
        $execution = $DB->get_record('local_coursetransfermanager_executions', ['id' => $seed->execution->id]);
        $hold = deletion_manager::hold_of($execution);
        $this->assertSame('site', $hold->rule);
        $this->assertStringContainsString('other.example.com', $hold->reason);
    }

    /**
     * The origin of a task cannot change while it has deletions pending (B3).
     */
    public function test_task_origin_locked_with_pending_deletions(): void {
        $seed = $this->seed();
        $other = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer')
            ->create_site('origin', ['host' => 'https://other.example.com']);
        $data = (array) $seed->task;
        $data['originsiteid'] = $other->id;

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('task_origin_locked', 'local_coursetransfermanager', 1));
        (new task_manager())->update_task((int) $seed->task->id, $data);
    }

    /**
     * User data asked for but missing in the copy holds the deletion (B2), with its reason recorded (A2).
     */
    public function test_copy_without_user_data_holds(): void {
        global $DB;
        $seed = $this->seed(['restoreuserdata' => 1], [
            'verified' => time(), 'issues' => [],
            'backup' => ['sections' => 1, 'activities' => [], 'enrolments' => null, 'users' => false],
        ]);

        $results = $this->process();

        $this->assertSame('held', $results[0]->outcome);
        $this->assertSame(0, $this->removals());
        $execution = $DB->get_record('local_coursetransfermanager_executions', ['id' => $seed->execution->id]);
        $this->assertSame(deletion_manager::HOLD_CODE_PREFIX . 'R2u', $execution->errorcode);
        $this->assertStringContainsString('Course 501', $execution->errormessage);
    }

    /**
     * Content of the origin category the creator cannot see holds the deletion (B5).
     */
    public function test_hidden_content_in_origin_holds(): void {
        $this->seed();
        $this->origin_answers([$this->detail(['hiddencategories' => 0, 'hiddencourses' => 2])]);

        $results = $this->process();

        $this->assertSame('held', $results[0]->outcome);
        $this->assertSame(0, $this->removals());
        $this->assertStringContainsString('2', $results[0]->detail);
    }

    /**
     * A recorded hold is cleared once every rule passes again (A2).
     */
    public function test_hold_cleared_when_rules_pass(): void {
        global $DB;
        $seed = $this->seed();
        $DB->update_record('local_coursetransfermanager_executions', (object) ['id' => $seed->execution->id,
            'errorcode' => deletion_manager::HOLD_CODE_PREFIX . 'R3', 'errormessage' => 'Origin did not answer']);
        set_config('requirefirstdeletionapproval', 0, 'local_coursetransfermanager');
        $this->origin_answers([$this->detail(), ['success' => true, 'errors' => [],
            'data' => ['course_category_name' => 'Curs', 'course_category_idnumber' => 'X']]]);

        $this->process();

        $execution = $DB->get_record('local_coursetransfermanager_executions', ['id' => $seed->execution->id]);
        $this->assertNull(deletion_manager::hold_of($execution));
    }

    /**
     * The simulation does not verify manifests: it reports them pending and writes nothing (A1).
     */
    public function test_simulation_reports_unverified_as_pending(): void {
        global $DB;
        $seed = $this->seed([], ['backup' => ['sections' => 1, 'activities' => [], 'enrolments' => null]]);
        $this->origin_answers([
            ['success' => true, 'errors' => [], 'data' => []],
            $this->detail(),
        ]);
        $before = $DB->get_field('local_coursetransfer_request', 'origin_manifest', ['id' => $seed->child->id]);

        $result = simulation::run($seed->task);

        $this->assertSame('pending', $result->deletions[0]->status);
        $this->assertSame($before, $DB->get_field('local_coursetransfer_request', 'origin_manifest', ['id' => $seed->child->id]));
    }

    /**
     * A candidate is claimed before pruning: an excluded one is not taken (B4).
     */
    public function test_prune_claim(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        $task = $generator->create_task();
        $announced = $generator->create_prune(['taskid' => $task->id, 'categoryid' => 11,
            'status' => prune_manager::STATUS_ANNOUNCED]);
        $excluded = $generator->create_prune(['taskid' => $task->id, 'categoryid' => 12,
            'status' => prune_manager::STATUS_EXCLUDED]);

        $this->assertTrue(prune_manager::claim((int) $announced->id));
        $this->assertFalse(prune_manager::claim((int) $announced->id), 'Taken only once');
        $this->assertFalse(prune_manager::claim((int) $excluded->id));
    }

    /**
     * A failed archive that created nothing here can be tried again; one that created something cannot (B6).
     */
    public function test_failed_archive_dedupe(): void {
        $ctgenerator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer');
        $empty = $ctgenerator->create_request(['type' => coursetransfer_request::TYPE_CATEGORY, 'status' => 0]);
        $launched = $ctgenerator->create_request(['type' => coursetransfer_request::TYPE_CATEGORY, 'status' => 0,
            'origin_category_requests' => json_encode([9])]);
        $method = new \ReflectionMethod(rotation::class, 'launched_something');

        $this->assertFalse($method->invoke(null, (object) ['requestid' => $empty->id, 'destinationcategoryid' => null]));
        $this->assertTrue($method->invoke(null, (object) ['requestid' => $launched->id, 'destinationcategoryid' => null]));
        $this->assertTrue($method->invoke(null, (object) ['requestid' => 999999, 'destinationcategoryid' => null]));
        $this->assertTrue($method->invoke(null, (object) ['requestid' => $empty->id, 'destinationcategoryid' => 5]));
    }

    /**
     * An origin that refuses the creator's account is an account to fix, not an unreachable origin (A4).
     */
    public function test_refused_account_is_not_an_unreachable_origin(): void {
        $site = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer')
            ->create_site('origin', ['host' => 'https://origin.example.com']);
        $this->origin_answers([['success' => false, 'errors' => [['code' => '17005', 'msg' => 'Suspended']]]]);
        $method = new \ReflectionMethod(task_manager::class, 'verify_origin_sync');

        ob_start();
        try {
            $method->invoke(new task_manager(), $site, get_admin());
            $this->fail('A refused account must stop the run');
        } catch (\moodle_exception $e) {
            $this->assertSame('originaccountunusable', $e->errorcode);
        } finally {
            ob_end_clean();
        }
    }
}
