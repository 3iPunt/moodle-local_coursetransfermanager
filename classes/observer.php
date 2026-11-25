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
use local_coursetransfermanager\manager\deletion_manager;
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

    /**
     * A coursetransfer request was deleted from its history.
     *
     * An execution still waiting for it would wait forever: it is marked as failed with the reason, and the removal of
     * the origin it scheduled is cancelled in the name of whoever deleted the request (CT LCT-126).
     *
     * @param base $event The request_deleted event (objectid = request id).
     * @return void
     */
    public static function request_deleted(base $event): void {
        global $DB;

        $requestid = (int) $event->objectid;
        if ($requestid <= 0) {
            return;
        }
        $execution = $DB->get_record('local_coursetransfermanager_executions', [
            'requestid' => $requestid,
            'status' => task_manager::STATUS_SUCCESS,
        ], '*', IGNORE_MULTIPLE);
        if (!$execution) {
            return;
        }

        $DB->update_record('local_coursetransfermanager_executions', (object) [
            'id' => $execution->id,
            'status' => task_manager::STATUS_ERROR,
            'errormessage' => get_string('exec_request_deleted', 'local_coursetransfermanager', $requestid),
            'timemodified' => time(),
        ]);
        if (in_array($execution->deletestatus, deletion_manager::CANCELLABLE, true)) {
            try {
                deletion_manager::cancel((int) $execution->id, (int) ($event->userid ?: get_admin()->id));
            } catch (\moodle_exception $e) {
                // The cron claimed it meanwhile: its own checks hold it, since the request is gone.
                debugging('Deletion of execution ' . $execution->id . ' not cancelled: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
