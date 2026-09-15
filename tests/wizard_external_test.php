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
            wizard_external::task_save(
                0,
                'X',
                $origin,
                'X{YEAR}',
                2,
                $category->id,
                'malo',
                30,
                4,
                true,
                'full',
                []
            );
            $this->fail('Expected invalid_parameter_exception (cron)');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('cronexpression', $e->debuginfo ?? $e->getMessage());
        }

        // Missing category.
        try {
            wizard_external::task_save(
                0,
                'X',
                $origin,
                'X{YEAR}',
                2,
                999999,
                '0 2 1 9 *',
                30,
                4,
                true,
                'full',
                []
            );
            $this->fail('Expected invalid_parameter_exception (category)');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('targetcategoryid', $e->debuginfo ?? $e->getMessage());
        }

        // A mask with no year placeholder cannot rotate: it must be refused.
        try {
            wizard_external::task_save(
                0,
                'X',
                $origin,
                'X-FIXED',
                2,
                $category->id,
                '0 2 1 9 *',
                30,
                4,
                true,
                'full',
                []
            );
            $this->fail('Expected invalid_parameter_exception (mask)');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('categorypattern', $e->debuginfo ?? $e->getMessage());
        }

        // Half a policy is no policy: zero years in production is refused.
        try {
            wizard_external::task_save(
                0,
                'X',
                $origin,
                'X{YEAR}',
                0,
                $category->id,
                '0 2 1 9 *',
                30,
                4,
                true,
                'full',
                []
            );
            $this->fail('Expected invalid_parameter_exception (originkeepyears)');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('originkeepyears', $e->debuginfo ?? $e->getMessage());
        }

        // Non-positive retention.
        $this->expectException(\invalid_parameter_exception::class);
        wizard_external::task_save(
            0,
            'X',
            $origin,
            'X{YEAR}',
            2,
            $category->id,
            '0 2 1 9 *',
            0,
            4,
            true,
            'full',
            []
        );
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

        $result = wizard_external::task_save(
            0,
            'Archivo anual',
            $origin,
            'SJD{YEAR}',
            2,
            $category->id,
            '0 2 1 9 *',
            30,
            4,
            false,
            'essential',
            [(int)$recipient->id, 999999]
        );

        $task = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $result['id']], '*', MUST_EXIST);
        $this->assertSame('Archivo anual', $task->name);
        $this->assertSame(1, (int)$task->enabled);
        $this->assertSame((int)$USER->id, (int)$task->usercreated);
        $this->assertSame('essential', $task->notifylevel);
        $this->assertSame((string)$recipient->id, $task->notifyrecipients);
        $this->assertSame(2, (int)$task->originkeepyears);
        $this->assertNotEmpty($task->nextruntime);
        $this->assertNotEmpty($result['firstrun']);

        // Updating keeps the enabled state and does not touch the creator.
        wizard_external::task_save(
            (int)$task->id,
            'Renombrada',
            $origin,
            'SJD{YEAR}',
            3,
            $category->id,
            '0 2 1 9 *',
            15,
            2,
            true,
            'full',
            []
        );
        $updated = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $task->id]);
        $this->assertSame('Renombrada', $updated->name);
        $this->assertSame(3, (int)$updated->originkeepyears);
        $this->assertSame((int)$task->usercreated, (int)$updated->usercreated);
        $this->assertNull($updated->notifyrecipients);
    }

    /**
     * An unusable mask never reaches the origin: it is refused up front, and
     * the projection says so instead of inventing years.
     */
    public function test_policy_preview_refuses_half_a_policy(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $origin = $this->seed_origin();

        foreach ([['CAT-FIXED', 2, 4], ['CAT-{YEAR}', 0, 4], ['CAT-{YEAR}', 2, 0]] as [$mask, $p, $v]) {
            $preview = wizard_external::policy_preview($origin, $mask, $p, $v, 0);
            $this->assertFalse($preview['valid'], $mask . " P={$p} V={$v} should be refused");
            $this->assertSame([], $preview['rows']);
        }
    }

    /**
     * The projection the wizard shows is the policy, year by year: it must say
     * exactly what the engine will delete and when.
     */
    public function test_policy_preview_projects_the_policy(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('academicyearstartmonth', 9, 'local_coursetransfermanager');
        $origin = $this->seed_origin();

        // Default mode: pure arithmetic, no HTTP round trip. This is what the
        // wizard calls on every keystroke.
        $preview = wizard_external::policy_preview($origin, 'CAT-{YEAR}-{NEXTYEAR}', 2, 4, 0);

        $this->assertTrue($preview['valid']);
        $this->assertSame(6, $preview['totalyears']);
        $this->assertSame($preview['currentyear'] - 2, $preview['archiveyear']);
        $this->assertSame($preview['currentyear'] - 6, $preview['pruneyear']);
        $this->assertCount(6, $preview['rows']);

        $first = $preview['rows'][0];
        $this->assertTrue($first['iscurrent']);
        $this->assertCount(2, $first['production']);
        $this->assertCount(4, $first['archive']);
        $this->assertCount(1, $first['deleted']);
        // Nothing exists locally in this test, so every cell is a projection.
        $this->assertFalse($first['archive'][0]['exists']);
        $this->assertStringStartsWith('CAT-', $first['archive'][0]['idnumber']);
    }

    /**
     * With the origin asked and unreachable, the projection is still the same
     * arithmetic: it just cannot mark which remote years exist. A policy that
     * stopped being legible because a platform is down would be useless.
     */
    public function test_policy_preview_survives_an_unreachable_origin(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $origin = $this->seed_origin();

        $offline = wizard_external::policy_preview($origin, 'CAT-{YEAR}-{NEXTYEAR}', 2, 4, 0, true);
        // The remote call logs its failure through coursetransfer; that is the
        // platform's own reporting, not a problem with the projection.
        $this->resetDebugging();

        $this->assertTrue($offline['valid']);
        $this->assertCount(6, $offline['rows']);
        $this->assertSame(6, $offline['totalyears']);
        foreach ($offline['rows'][0]['production'] as $cell) {
            $this->assertFalse($cell['exists']);
        }
    }

    /**
     * A category already in the archive is reported as existing, so the admin
     * tells apart what is there from what is only projected.
     */
    public function test_policy_preview_marks_what_already_exists(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $origin = $this->seed_origin();

        $generator = $this->getDataGenerator();
        $archive = $generator->create_category(['name' => 'Archivo']);
        $current = \local_coursetransfermanager\manager\academic_year::current_year();
        $existing = $generator->create_category(['name' => 'Anterior', 'parent' => $archive->id,
            'idnumber' => 'CAT-' . ($current - 2) . '-' . ($current - 1)]);

        // Managed means the task brought it: that is what makes it a fact.
        $taskid = $DB->insert_record('local_coursetransfermanager_tasks', (object)[
            'name' => 'T', 'originsiteid' => $origin, 'categorypattern' => 'CAT-{YEAR}-{NEXTYEAR}',
            'targetcategoryid' => $archive->id, 'cronexpression' => '0 2 1 9 *',
            'retentiondays' => 30, 'originkeepyears' => 2, 'destinationkeepyears' => 4,
            'restoreuserdata' => 0, 'enabled' => 1, 'usercreated' => 2, 'notifylevel' => 'full',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursetransfermanager_executions', (object)[
            'taskid' => $taskid, 'status' => 'completed', 'manualrun' => 0,
            'destinationcategoryid' => $existing->id,
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $preview = wizard_external::policy_preview(
            $origin,
            'CAT-{YEAR}-{NEXTYEAR}',
            2,
            4,
            (int)$archive->id,
            false,
            (int)$taskid
        );

        // First archive cell of the current run is A−2, the one just created.
        $cell = $preview['rows'][0]['archive'][0];
        $this->assertSame($current - 2, $cell['year']);
        $this->assertTrue($cell['exists']);
        // And a year nobody created is still only a projection.
        $this->assertFalse($preview['rows'][0]['archive'][1]['exists']);
    }

    /**
     * An origin that does not answer must produce "down" with its reason, never
     * a guess: a rotation that cannot see the origin archives nothing.
     */
    public function test_test_pattern_reports_a_silent_origin(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $origin = $this->seed_origin();

        $result = wizard_external::test_pattern($origin, 'CAT-{YEAR}-{NEXTYEAR}');
        $this->resetDebugging();
        $this->assertSame('down', $result['status']);
        $this->assertSame(0, $result['matchcount']);
        $this->assertSame([], $result['categories']);
        $this->assertNotSame('', $result['message']);
        // Even with no answer, the mask example is computed locally.
        $this->assertStringStartsWith('CAT-', $result['example']);
    }

    /**
     * A mask with no year placeholder is refused before any remote call.
     */
    public function test_test_pattern_refuses_an_unusable_mask(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = wizard_external::test_pattern($this->seed_origin(), 'CAT-FIXED');
        $this->assertSame('invalid', $result['status']);
        $this->assertSame('', $result['example']);
    }
}
