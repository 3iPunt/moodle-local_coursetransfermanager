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
 * Event observer for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager;

use core\event\base;
use local_coursetransfermanager\manager\task_manager;
use local_coursetransfermanager\notification\notifier;

/**
 * Reacts to CourseTransfer events.
 *
 * A "success" execution only means the restoration was LAUNCHED; the moment
 * it truly finishes is signalled by coursetransfer's request_completed
 * event. This observer marks the execution as completed and sends N2.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A coursetransfer request reached COMPLETED.
     *
     * @param base $event The request_completed event (objectid = request id).
     * @return void
     */
    public static function request_completed(base $event): void {
        global $DB;

        $requestid = (int) $event->objectid;
        if ($requestid <= 0) {
            return;
        }

        // Only executions of this manager launched with that request matter.
        $execution = $DB->get_record('local_coursetransfermanager_executions', [
            'requestid' => $requestid,
            'status' => task_manager::STATUS_SUCCESS,
        ], '*', IGNORE_MULTIPLE);
        if (!$execution) {
            return;
        }

        $execution->status = task_manager::STATUS_COMPLETED;
        $execution->timemodified = time();
        $DB->update_record('local_coursetransfermanager_executions', $execution);

        $task = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $execution->taskid]);
        if ($task) {
            notifier::restore_completed($task, $execution);
        }
    }
}
