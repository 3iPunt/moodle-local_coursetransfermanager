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
 * Tests for the local_coursetransfermanager privacy provider.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\privacy;

use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_coursetransfermanager\manager\prune_manager;

/**
 * Tests for the local_coursetransfermanager privacy provider.
 *
 * The plugin keeps user references in administrative records (who created a task,
 * who receives its notices, who cancelled a deletion or excluded a pruning). Erasure
 * anonymises them and keeps the records.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Seed one user in every role the plugin records, plus a stranger.
     *
     * @return \stdClass {creator, recipient, canceller, excluder, stranger, task, execution, prune}
     */
    private function seed(): \stdClass {
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_coursetransfermanager');

        $users = [];
        foreach (['creator', 'recipient', 'canceller', 'excluder', 'stranger', 'other'] as $role) {
            $users[$role] = $dg->create_user();
        }

        $task = $generator->create_task([
            'name' => 'Yearly rotation',
            'usercreated' => $users['creator']->id,
            'notifyrecipients' => $users['recipient']->id . ',' . $users['other']->id,
        ]);
        $execution = $generator->create_execution([
            'taskid' => $task->id,
            'origincategoryname' => 'Year 2019',
            'deletestatus' => 'cancelled',
            'deletecancelledby' => $users['canceller']->id,
            'deletecancelledat' => time(),
        ]);
        $prune = $generator->create_prune([
            'taskid' => $task->id,
            'categoryid' => $dg->create_category()->id,
            'categoryname' => 'Archive 2012',
            'status' => prune_manager::STATUS_EXCLUDED,
            'excludedby' => $users['excluder']->id,
            'excludedat' => time(),
        ]);

        return (object) array_merge($users, ['task' => $task, 'execution' => $execution, 'prune' => $prune]);
    }

    /**
     * The three tables and their user fields are declared.
     */
    public function test_get_metadata(): void {
        $items = provider::get_metadata(new collection('local_coursetransfermanager'))->get_collection();

        $tables = [];
        foreach ($items as $item) {
            $tables[$item->get_name()] = array_keys($item->get_privacy_fields());
        }
        $this->assertSame(['usercreated', 'notifyrecipients'], $tables['local_coursetransfermanager_tasks']);
        $this->assertSame(['deletecancelledby', 'deletecancelledat'], $tables['local_coursetransfermanager_executions']);
        $this->assertSame(['excludedby', 'excludedat'], $tables['local_coursetransfermanager_prune']);
    }

    /**
     * Every recorded role gets the system context; a stranger gets none.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $seed = $this->seed();

        foreach (['creator', 'recipient', 'other', 'canceller', 'excluder'] as $role) {
            $contexts = provider::get_contexts_for_userid((int) $seed->$role->id)->get_contextids();
            $this->assertEquals([context_system::instance()->id], $contexts, $role);
        }
        $this->assertSame([], provider::get_contexts_for_userid((int) $seed->stranger->id)->get_contextids());
    }

    /**
     * The system context lists every referenced user, recipients included; other contexts none.
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        $seed = $this->seed();

        $userlist = new userlist(context_system::instance(), 'local_coursetransfermanager');
        provider::get_users_in_context($userlist);
        $expected = [];
        foreach (['creator', 'recipient', 'other', 'canceller', 'excluder'] as $role) {
            $expected[] = (int) $seed->$role->id;
        }
        $actual = array_map('intval', $userlist->get_userids());
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);

        $usercontext = \context_user::instance($seed->creator->id);
        $userlist = new userlist($usercontext, 'local_coursetransfermanager');
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);
    }

    /**
     * Export carries what each user did, and only that.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        $seed = $this->seed();
        $context = context_system::instance();
        $subcontext = [get_string('pluginname', 'local_coursetransfermanager')];

        $this->export_context_data_for_user((int) $seed->creator->id, $context, 'local_coursetransfermanager');
        $data = writer::with_context($context)->get_data($subcontext);
        $this->assertCount(1, $data->taskscreated);
        $this->assertSame('Yearly rotation', $data->taskscreated[0]['name']);
        $this->assertSame([], $data->notificationrecipientof);
        $this->assertSame([], $data->deletionscancelled);
        $this->assertSame([], $data->pruningexclusions);

        writer::reset();
        $this->export_context_data_for_user((int) $seed->recipient->id, $context, 'local_coursetransfermanager');
        $data = writer::with_context($context)->get_data($subcontext);
        $this->assertSame(['Yearly rotation'], array_values($data->notificationrecipientof));
        $this->assertSame([], $data->taskscreated);

        writer::reset();
        $this->export_context_data_for_user((int) $seed->canceller->id, $context, 'local_coursetransfermanager');
        $data = writer::with_context($context)->get_data($subcontext);
        $this->assertSame('Year 2019', $data->deletionscancelled[0]['category']);

        writer::reset();
        $this->export_context_data_for_user((int) $seed->excluder->id, $context, 'local_coursetransfermanager');
        $data = writer::with_context($context)->get_data($subcontext);
        $this->assertSame('Archive 2012', $data->pruningexclusions[0]['category']);
    }

    /**
     * Erasing one user anonymises only their references and keeps the records.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $seed = $this->seed();
        $context = context_system::instance();

        $contextlist = new approved_contextlist($seed->recipient, 'local_coursetransfermanager', [$context->id]);
        provider::delete_data_for_user($contextlist);
        // The other recipient keeps receiving the notices.
        $this->assertSame(
            (string) $seed->other->id,
            $DB->get_field('local_coursetransfermanager_tasks', 'notifyrecipients', ['id' => $seed->task->id])
        );

        $contextlist = new approved_contextlist($seed->creator, 'local_coursetransfermanager', [$context->id]);
        provider::delete_data_for_user($contextlist);
        $contextlist = new approved_contextlist($seed->canceller, 'local_coursetransfermanager', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $task = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $seed->task->id], '*', MUST_EXIST);
        $this->assertEquals(0, $task->usercreated);
        $this->assertNull($DB->get_field(
            'local_coursetransfermanager_executions',
            'deletecancelledby',
            ['id' => $seed->execution->id],
            MUST_EXIST
        ));
        // The excluder was not erased.
        $this->assertEquals(
            $seed->excluder->id,
            $DB->get_field('local_coursetransfermanager_prune', 'excludedby', ['id' => $seed->prune->id])
        );
        $this->assertSame([], provider::get_contexts_for_userid((int) $seed->creator->id)->get_contextids());
    }

    /**
     * Erasing a list of users anonymises them all; a non-system context is ignored.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        $seed = $this->seed();

        $usercontext = \context_user::instance($seed->excluder->id);
        provider::delete_data_for_users(
            new approved_userlist($usercontext, 'local_coursetransfermanager', [$seed->excluder->id])
        );
        $this->assertEquals(
            $seed->excluder->id,
            $DB->get_field('local_coursetransfermanager_prune', 'excludedby', ['id' => $seed->prune->id])
        );

        provider::delete_data_for_users(new approved_userlist(
            context_system::instance(),
            'local_coursetransfermanager',
            [$seed->excluder->id, $seed->recipient->id, $seed->other->id]
        ));
        $this->assertNull($DB->get_field('local_coursetransfermanager_prune', 'excludedby', ['id' => $seed->prune->id]));
        $this->assertNull($DB->get_field('local_coursetransfermanager_tasks', 'notifyrecipients', ['id' => $seed->task->id]));
        // The creator was not in the list.
        $this->assertEquals(
            $seed->creator->id,
            $DB->get_field('local_coursetransfermanager_tasks', 'usercreated', ['id' => $seed->task->id])
        );
    }

    /**
     * Erasing the whole system context anonymises every reference and keeps every record.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        $seed = $this->seed();

        provider::delete_data_for_all_users_in_context(context_system::instance());

        $this->assertSame(1, $DB->count_records('local_coursetransfermanager_tasks'));
        $this->assertSame(1, $DB->count_records('local_coursetransfermanager_executions'));
        $this->assertSame(1, $DB->count_records('local_coursetransfermanager_prune'));
        foreach (['creator', 'recipient', 'other', 'canceller', 'excluder'] as $role) {
            $this->assertSame([], provider::get_contexts_for_userid((int) $seed->$role->id)->get_contextids(), $role);
        }
    }

    /**
     * Adoptions keep who adopted the category, and the notices go through core_message:
     * both are declared.
     */
    public function test_metadata_declares_adoptions_and_messages(): void {
        $items = provider::get_metadata(new collection('local_coursetransfermanager'))->get_collection();

        $tables = [];
        $subsystems = [];
        foreach ($items as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\subsystem_link) {
                $subsystems[] = $item->get_name();
            } else {
                $tables[$item->get_name()] = array_keys($item->get_privacy_fields());
            }
        }
        $this->assertSame(['adoptedby', 'timecreated'], $tables['local_coursetransfermanager_adopted']);
        $this->assertContains('core_message', $subsystems);
    }

    /**
     * Whoever adopted a category is found, exported and anonymised.
     */
    public function test_adopter_is_found_exported_and_anonymised(): void {
        global $DB;
        $this->resetAfterTest();
        $dg = $this->getDataGenerator();
        $adopter = $dg->create_user();
        $task = $dg->get_plugin_generator('local_coursetransfermanager')->create_task(['name' => 'Rotation']);
        $adoption = $dg->get_plugin_generator('local_coursetransfermanager')->create_adopted([
            'taskid' => $task->id,
            'categoryid' => $dg->create_category()->id,
            'categoryname' => 'Archive 2010',
            'adoptedby' => $adopter->id,
        ]);
        $context = context_system::instance();

        $this->assertEquals([$context->id], provider::get_contexts_for_userid((int) $adopter->id)->get_contextids());
        $userlist = new userlist($context, 'local_coursetransfermanager');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $adopter->id, array_map('intval', $userlist->get_userids()));

        $this->export_context_data_for_user((int) $adopter->id, $context, 'local_coursetransfermanager');
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_coursetransfermanager')]);
        $this->assertSame('Archive 2010', $data->adoptions[0]['category']);

        provider::delete_data_for_user(new approved_contextlist($adopter, 'local_coursetransfermanager', [$context->id]));
        $this->assertEquals(0, $DB->get_field('local_coursetransfermanager_adopted', 'adoptedby', ['id' => $adoption->id]));
        $this->assertSame(1, $DB->count_records('local_coursetransfermanager_adopted'));
    }
}
