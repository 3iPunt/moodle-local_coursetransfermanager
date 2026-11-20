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
 * Tests for the panel prerequisite checks.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager;

use advanced_testcase;
use local_coursetransfermanager\manager\health;
use local_coursetransfermanager\task\process_tasks;

/**
 * Tests for the panel prerequisite checks.
 *
 * None of the checks reaches the origin: the sites check only reads the last
 * connection test local_coursetransfer persisted.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\health
 */
final class health_test extends advanced_testcase {
    /**
     * Checks keyed by their key.
     *
     * @return \stdClass[]
     */
    private function checks(): array {
        $checks = [];
        foreach (health::get_checks() as $check) {
            $checks[$check->key] = $check;
        }
        return $checks;
    }

    /**
     * Set the last run of every scheduled task, and of the plugin task in particular.
     *
     * @param int|null $all Last run of every task (null leaves them untouched).
     * @param int|null $mine Last run of the plugin task (null leaves it untouched).
     * @param int $disabled Whether the plugin task is disabled.
     */
    private function set_runs(?int $all, ?int $mine = null, int $disabled = 0): void {
        global $DB;
        if ($all !== null) {
            $DB->set_field('task_scheduled', 'lastruntime', $all);
        }
        $classname = '\\' . process_tasks::class;
        if ($mine !== null) {
            $DB->set_field('task_scheduled', 'lastruntime', $mine, ['classname' => $classname]);
        }
        $DB->set_field('task_scheduled', 'disabled', $disabled, ['classname' => $classname]);
    }

    /**
     * The four checks always come back, in the panel order.
     */
    public function test_checks_shape(): void {
        $this->resetAfterTest();

        $keys = array_column(health::get_checks(), 'key');
        $this->assertSame(['coursetransfer', 'cron', 'managertask', 'sites'], $keys);
    }

    /**
     * The coursetransfer dependency is installed in the test site: OK with its version.
     */
    public function test_coursetransfer_installed(): void {
        $this->resetAfterTest();

        $check = $this->checks()['coursetransfer'];
        $this->assertSame(health::OK, $check->status);
        $this->assertEquals(get_config('local_coursetransfer', 'version'), $check->data->version);
    }

    /**
     * Cron: never run is an error; recent is OK; within a day a warning; older an error.
     */
    public function test_cron_freshness(): void {
        $this->resetAfterTest();

        $this->set_runs(0);
        $this->assertSame(health::ERROR, $this->checks()['cron']->status);
        $this->assertNull($this->checks()['cron']->data->lastrun);

        $this->set_runs(time() - 60);
        $this->assertSame(health::OK, $this->checks()['cron']->status);

        $this->set_runs(time() - 3 * HOURSECS);
        $this->assertSame(health::WARNING, $this->checks()['cron']->status);

        $this->set_runs(time() - 2 * DAYSECS);
        $this->assertSame(health::ERROR, $this->checks()['cron']->status);
    }

    /**
     * The plugin scheduled task: disabled is an error, stale a warning, ticking OK.
     */
    public function test_manager_task_state(): void {
        $this->resetAfterTest();

        $this->set_runs(null, time() - 60, 1);
        $check = $this->checks()['managertask'];
        $this->assertSame(health::ERROR, $check->status);
        $this->assertTrue($check->data->disabled);

        $this->set_runs(null, 0);
        $check = $this->checks()['managertask'];
        $this->assertSame(health::WARNING, $check->status);
        $this->assertNull($check->data->lastrun);

        $this->set_runs(null, time() - 60);
        $check = $this->checks()['managertask'];
        $this->assertSame(health::OK, $check->status);
        $this->assertFalse($check->data->disabled);
    }

    /**
     * Without enabled tasks there is no origin to check: OK and an empty list.
     */
    public function test_sites_without_tasks(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        $site = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer')->create_site('origin', [
            'lastteststatus' => 0,
        ]);
        // A disabled task does not count, even pointing to a failing site.
        $generator->create_task(['originsiteid' => $site->id, 'enabled' => 0]);

        $check = $this->checks()['sites'];
        $this->assertSame(health::OK, $check->status);
        $this->assertSame([], $check->data->sites);
    }

    /**
     * The worst persisted test of the sites used by enabled tasks wins.
     */
    public function test_sites_last_test(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        $ctgenerator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfer');
        $now = time();

        $ok = $ctgenerator->create_site('origin', ['lasttest' => $now, 'lastteststatus' => 1]);
        $generator->create_task(['originsiteid' => $ok->id]);
        $check = $this->checks()['sites'];
        $this->assertSame(health::OK, $check->status);
        $this->assertCount(1, $check->data->sites);
        $this->assertSame((int)$ok->id, $check->data->sites[0]->id);
        $this->assertSame(1, $check->data->sites[0]->lastteststatus);

        // A site never tested is a warning, not an error.
        $untested = $ctgenerator->create_site('origin');
        $generator->create_task(['originsiteid' => $untested->id]);
        $this->assertSame(health::WARNING, $this->checks()['sites']->status);

        // A failed test makes it an error.
        $failed = $ctgenerator->create_site('origin', ['lasttest' => $now, 'lastteststatus' => 0]);
        $generator->create_task(['originsiteid' => $failed->id]);
        $check = $this->checks()['sites'];
        $this->assertSame(health::ERROR, $check->status);
        $this->assertCount(3, $check->data->sites);
    }

    /**
     * A task pointing to an origin site that no longer exists is an error, reported with its id.
     */
    public function test_sites_missing_origin(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursetransfermanager');
        $generator->create_task(['originsiteid' => 999999]);

        $check = $this->checks()['sites'];
        $this->assertSame(health::ERROR, $check->status);
        $this->assertSame(999999, $check->data->sites[0]->id);
        $this->assertNull($check->data->sites[0]->host);
        $this->assertSame(0, $check->data->sites[0]->lastteststatus);
    }
}
