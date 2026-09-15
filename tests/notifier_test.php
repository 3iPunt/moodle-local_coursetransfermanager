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

use local_coursetransfermanager\manager\task_manager;
use local_coursetransfermanager\notification\notifier;

/**
 * Tests for the N1-N7 lifecycle notifier and the request_completed observer.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\notification\notifier
 * @covers     \local_coursetransfermanager\observer
 */
final class notifier_test extends \advanced_testcase {
    /**
     * Seed a task owned by a creator with one extra recipient.
     *
     * @param string $level Notification level.
     * @return \stdClass {task, creator, extra}
     */
    private function seed(string $level = 'full'): \stdClass {
        global $DB;

        $generator = $this->getDataGenerator();
        $creator = $generator->create_user();
        $extra = $generator->create_user();

        $now = time();
        $taskid = $DB->insert_record('local_ctm_tasks', (object)[
            'type' => 'restore_category', 'name' => 'Tarea', 'originsiteid' => 1,
            'categorypattern' => 'SJD{YEAR}', 'targetcategoryid' => 1,
            'cronexpression' => '0 2 1 9 *', 'retentiondays' => 30,
            'destinationkeepyears' => 4, 'restoreuserdata' => 0, 'enabled' => 1,
            'usercreated' => $creator->id, 'notifylevel' => $level,
            'notifyrecipients' => (string)$extra->id,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        return (object)[
            'task' => $DB->get_record('local_ctm_tasks', ['id' => $taskid]),
            'creator' => $creator,
            'extra' => $extra,
        ];
    }

    /**
     * Every notice reaches the creator AND the configured extra recipient.
     */
    public function test_recipients_creator_plus_extra(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $seed = $this->seed();

        $sink = $this->redirectMessages();
        notifier::execution_launched($seed->task, 'Curs 2025-2026', 'origen.test');
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(2, $messages);
        $recipients = array_map(static fn($message) => (int)$message->useridto, $messages);
        $this->assertEqualsCanonicalizing([(int)$seed->creator->id, (int)$seed->extra->id], $recipients);
        $this->assertSame('execution_launched', $messages[0]->eventtype);
    }

    /**
     * The essential level mutes the informative notices but NEVER the
     * critical ones (advance deletion notices and errors).
     */
    public function test_essential_level_never_mutes_critical(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $seed = $this->seed('essential');

        $sink = $this->redirectMessages();
        notifier::execution_launched($seed->task, 'X', 'origen.test'); // N1: muted.
        $this->assertCount(0, $sink->get_messages());

        notifier::execution_error($seed->task, 'fallo de prueba'); // N7: always sent.
        $this->assertCount(2, $sink->get_messages());
        $sink->close();
    }

    /**
     * N8 — a held deletion is reported as a safety hold, NOT as a failure,
     * and reaches the recipients even at the essential level.
     */
    public function test_deletion_held_is_not_an_error(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $seed = $this->seed('essential');

        $sink = $this->redirectMessages();
        notifier::deletion_held(
            $seed->task,
            'Curs 2025-2026',
            get_string('deletion_held_archivemissing', 'local_coursetransfermanager', 'Curs 2025-2026')
        );
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(2, $messages);
        $this->assertSame('deletion_held', $messages[0]->eventtype);
        // The wording must not read as a failure.
        $this->assertStringNotContainsStringIgnoringCase('ha fallado', $messages[0]->subject);
        $this->assertStringNotContainsStringIgnoringCase('failed', $messages[0]->subject);
    }

    /**
     * The observer flips a launched execution to completed and sends N2.
     */
    public function test_observer_marks_completed_and_notifies(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        $seed = $this->seed();
        $executionid = $DB->insert_record('local_ctm_executions', (object)[
            'taskid' => $seed->task->id, 'status' => task_manager::STATUS_SUCCESS,
            'manualrun' => 0, 'requestid' => 424242,
            'origincategoryname' => 'Curs 2025-2026',
            'scheduleddeleteat' => time() + 30 * DAYSECS, 'deletestatus' => 'scheduled',
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $event = \local_coursetransfer\event\request_completed::create([
            'objectid' => 424242,
            'context' => \context_system::instance(),
            'other' => ['type' => 1, 'direction' => 0],
        ]);

        $sink = $this->redirectMessages();
        observer::request_completed($event);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertSame(
            task_manager::STATUS_COMPLETED,
            $DB->get_field('local_ctm_executions', 'status', ['id' => $executionid])
        );
        $this->assertCount(2, $messages);
        $this->assertSame('restore_completed', $messages[0]->eventtype);

        // An unrelated request id changes nothing.
        $other = \local_coursetransfer\event\request_completed::create([
            'objectid' => 999999,
            'context' => \context_system::instance(),
            'other' => ['type' => 1, 'direction' => 0],
        ]);
        observer::request_completed($other);
        $this->assertSame(
            task_manager::STATUS_COMPLETED,
            $DB->get_field('local_ctm_executions', 'status', ['id' => $executionid])
        );
    }
}
