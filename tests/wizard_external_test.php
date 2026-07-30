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

use local_coursetransfermanager\external\wizard_external;

/**
 * Tests for the wizard AJAX endpoints (previews, searches and guarded save).
 *
 * test_pattern's happy path needs a paired origin platform and is covered by
 * the cross-site functional validation.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\external\wizard_external
 */
final class wizard_external_test extends \advanced_testcase {

    /**
     * Register an origin site in coursetransfer (hard dependency).
     *
     * @return int Origin site id.
     */
    private function seed_origin(): int {
        global $DB;
        return $DB->insert_record('local_coursetransfer_origin', (object)[
            'name' => 'Origen', 'host' => 'https://origen.test', 'token' => 'x',
            'userid' => 2, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Every endpoint requires the manage capability.
     */
    public function test_capability_gate(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        wizard_external::schedule_preview('0 2 1 9 *');
    }

    /**
     * The schedule preview returns future runs and flags summer months.
     */
    public function test_schedule_preview(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $preview = wizard_external::schedule_preview('0 2 1 9 *');
        $this->assertTrue($preview['valid']);
        $this->assertCount(3, $preview['runs']);
        $this->assertFalse($preview['summer']);
        $this->assertGreaterThan(time(), $preview['firstrun']);

        $this->assertTrue(wizard_external::schedule_preview('0 2 1 7 *')['summer']);
        $this->assertFalse(wizard_external::schedule_preview('not a cron')['valid']);
    }

    /**
     * Searches require at least 2 characters and stay capped.
     */
    public function test_searches_are_guarded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertCount(0, wizard_external::category_search('a')['categories']);
        $this->assertCount(0, wizard_external::user_search('a')['users']);

        $this->getDataGenerator()->create_category(['name' => 'Archivo histórico']);
        $found = wizard_external::category_search('Archivo')['categories'];
        $this->assertCount(1, $found);
        $this->assertSame('Archivo histórico', $found[0]['name']);

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Berta', 'lastname' => 'Buscable']);
        $users = wizard_external::user_search('Buscable')['users'];
        $this->assertCount(1, $users);
        $this->assertSame((int)$user->id, $users[0]['id']);
    }

    /**
     * The save validates everything server-side even if the JS is bypassed.
     */
    public function test_task_save_rejects_invalid_input(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $origin = $this->seed_origin();
        $category = $this->getDataGenerator()->create_category();

        // Invalid cron.
        try {
            wizard_external::task_save(0, 'X', $origin, '^X$', $category->id, 'malo', 30, 4, true, 'full', []);
            $this->fail('Expected invalid_parameter_exception (cron)');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('cronexpression', $e->debuginfo ?? $e->getMessage());
        }

        // Missing category.
        try {
            wizard_external::task_save(0, 'X', $origin, '^X$', 999999, '0 2 1 9 *', 30, 4, true, 'full', []);
            $this->fail('Expected invalid_parameter_exception (category)');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('targetcategoryid', $e->debuginfo ?? $e->getMessage());
        }

        // Non-positive retention.
        $this->expectException(\invalid_parameter_exception::class);
        wizard_external::task_save(0, 'X', $origin, '^X$', $category->id, '0 2 1 9 *', 0, 4, true, 'full', []);
    }

    /**
     * A valid save creates the task, computes the schedule, stores the
     * creator and filters non-existing recipients out.
     */
    public function test_task_save_creates_task(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $origin = $this->seed_origin();
        $category = $this->getDataGenerator()->create_category();
        $recipient = $this->getDataGenerator()->create_user();

        $result = wizard_external::task_save(0, 'Archivo anual', $origin, 'SJD{YEAR}',
            $category->id, '0 2 1 9 *', 30, 4, false, 'essential',
            [(int)$recipient->id, 999999]);

        $task = $DB->get_record('local_ctm_tasks', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertSame('Archivo anual', $task->name);
        $this->assertSame(1, (int)$task->enabled);
        $this->assertSame((int)$USER->id, (int)$task->usercreated);
        $this->assertSame('essential', $task->notifylevel);
        $this->assertSame((string)$recipient->id, $task->notifyrecipients);
        $this->assertNotEmpty($task->nextruntime);
        $this->assertNotEmpty($result['firstrun']);

        // Updating keeps the enabled state and does not touch the creator.
        wizard_external::task_save((int)$task->id, 'Renombrada', $origin, 'SJD{YEAR}',
            $category->id, '0 2 1 9 *', 15, 2, true, 'full', []);
        $updated = $DB->get_record('local_ctm_tasks', ['id' => $task->id]);
        $this->assertSame('Renombrada', $updated->name);
        $this->assertSame((int)$task->usercreated, (int)$updated->usercreated);
        $this->assertNull($updated->notifyrecipients);
    }
}
