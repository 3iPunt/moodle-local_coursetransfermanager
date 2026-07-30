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
 * Scheduled task that drives the course transfer manager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\task;

use core\task\scheduled_task;
use local_coursetransfermanager\manager\task_manager;

/**
 * Runs pending course transfer manager tasks on each cron tick.
 *
 * Acts as a thin wrapper around {@see task_manager} so the heavy lifting is
 * unit-testable in isolation.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_tasks extends scheduled_task {
    /**
     * Localised display name used by the scheduled tasks admin page.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name(): string {
        return get_string('process_tasks', 'local_coursetransfermanager');
    }

    /**
     * Execute the scheduling + cleanup pipeline.
     *
     * @return void
     * @throws \dml_exception
     */
    public function execute(): void {
        mtrace('Processing course transfer manager tasks...');

        $manager = new task_manager();
        $manager->process_scheduled_tasks();
        $manager->cleanup_tasks();

        mtrace('Finished processing tasks.');
    }
}
