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
 * Frontend (AJAX) task actions for local_coursetransfermanager.
 *
 * Internal JS→PHP endpoints of the manager screens. Nobody external
 * consumes them: they can change freely between versions.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursetransfermanager\manager\deletion_manager;
use local_coursetransfermanager\manager\prune_manager;
use local_coursetransfermanager\manager\rotation;
use local_coursetransfermanager\manager\task_manager;

/**
 * Panel actions: enable/disable, run now, cancel deletion, exclude pruning.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_external extends external_api {
    /**
     * Common security gate for every panel action.
     *
     * @return void
     */
    private static function require_manager(): void {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursetransfermanager:managetasks', $context);
    }

    /**
     * Parameters of toggle().
     *
     * @return external_function_parameters
     */
    public static function toggle_parameters(): external_function_parameters {
        return new external_function_parameters([
            'taskid' => new external_value(PARAM_INT, 'Task id'),
            'enabled' => new external_value(PARAM_BOOL, 'New enabled state'),
        ]);
    }

    /**
     * Enable or disable a task.
     *
     * @param int $taskid Task id.
     * @param bool $enabled New state.
     * @return array
     */
    public static function toggle(int $taskid, bool $enabled): array {
        $params = self::validate_parameters(self::toggle_parameters(), [
            'taskid' => $taskid,
            'enabled' => $enabled,
        ]);
        self::require_manager();

        $manager = new task_manager();
        $task = $manager->set_enabled($params['taskid'], $params['enabled']);

        if (!empty($task->enabled) && !empty($task->nextruntime)) {
            $nextrun = get_string('card_next_on', 'local_coursetransfermanager', (object)[
                'date' => userdate((int)$task->nextruntime),
                'relative' => format_time(max(0, (int)$task->nextruntime - time())),
            ]);
        } else if (!empty($task->enabled)) {
            $nextrun = get_string('card_next_unknown', 'local_coursetransfermanager');
        } else {
            $nextrun = get_string('card_paused', 'local_coursetransfermanager');
        }

        return [
            'enabled' => !empty($task->enabled),
            'nextrun' => $nextrun,
        ];
    }

    /**
     * Return structure of toggle().
     *
     * @return external_single_structure
     */
    public static function toggle_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_BOOL, 'Enabled state after the change'),
            'nextrun' => new external_value(PARAM_TEXT, 'Human next-run line'),
        ]);
    }

    /**
     * Parameters of run_now().
     *
     * @return external_function_parameters
     */
    public static function run_now_parameters(): external_function_parameters {
        return new external_function_parameters([
            'taskid' => new external_value(PARAM_INT, 'Task id'),
            'precheck' => new external_value(
                PARAM_BOOL,
                'Only check whether the launch would be blocked',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Launch a task now (or precheck whether the launch is blocked).
     *
     * @param int $taskid Task id.
     * @param bool $precheck When true, nothing runs: returns the block state for the dialog.
     * @return array
     */
    public static function run_now(int $taskid, bool $precheck = false): array {
        $params = self::validate_parameters(self::run_now_parameters(), [
            'taskid' => $taskid,
            'precheck' => $precheck,
        ]);
        self::require_manager();

        $manager = new task_manager();
        $task = $manager->get_task($params['taskid']);

        if ($params['precheck']) {
            $result = $manager->run_now_precheck($task);
            return [
                'launched' => false,
                'blocked' => $result->blocked,
                'lastsuccess' => $result->lastsuccess ? userdate($result->lastsuccess) : '',
                'nextrun' => !empty($task->nextruntime) ? userdate((int)$task->nextruntime) : '',
                'error' => '',
            ];
        }

        $result = $manager->run_now($params['taskid']);
        return [
            'launched' => $result->launched,
            'blocked' => $result->blocked,
            'lastsuccess' => $result->lastsuccess ? userdate($result->lastsuccess) : '',
            'nextrun' => '',
            'error' => (string)($result->error ?? ''),
        ];
    }

    /**
     * Return structure of run_now().
     *
     * @return external_single_structure
     */
    public static function run_now_returns(): external_single_structure {
        return new external_single_structure([
            'launched' => new external_value(PARAM_BOOL, 'True when the restoration was launched'),
            'blocked' => new external_value(PARAM_BOOL, 'True when blocked by a recent success (duplicates guard)'),
            'lastsuccess' => new external_value(PARAM_TEXT, 'Human date of the blocking success, if any'),
            'nextrun' => new external_value(PARAM_TEXT, 'Human date of the scheduled run (precheck only)'),
            'error' => new external_value(PARAM_RAW, 'Failure detail when the launch failed'),
        ]);
    }

    /**
     * Parameters of cancel_deletion().
     *
     * @return external_function_parameters
     */
    public static function cancel_deletion_parameters(): external_function_parameters {
        return new external_function_parameters([
            'executionid' => new external_value(PARAM_INT, 'Execution id with the pending deletion'),
        ]);
    }

    /**
     * Cancel a pending remote deletion.
     *
     * @param int $executionid Execution id.
     * @return array
     */
    public static function cancel_deletion(int $executionid): array {
        global $USER;

        $params = self::validate_parameters(self::cancel_deletion_parameters(), [
            'executionid' => $executionid,
        ]);
        self::require_manager();

        $execution = deletion_manager::cancel($params['executionid'], (int)$USER->id);

        return [
            'cancelled' => true,
            'audit' => get_string('cancelled_by', 'local_coursetransfermanager', (object)[
                'name' => fullname($USER),
                'date' => userdate((int)$execution->deletecancelledat),
            ]),
        ];
    }

    /**
     * Return structure of cancel_deletion().
     *
     * @return external_single_structure
     */
    public static function cancel_deletion_returns(): external_single_structure {
        return new external_single_structure([
            'cancelled' => new external_value(PARAM_BOOL, 'True when the deletion was cancelled'),
            'audit' => new external_value(PARAM_TEXT, 'Who cancelled and when, human readable'),
        ]);
    }

    /**
     * Parameters of exclude_prune().
     *
     * @return external_function_parameters
     */
    public static function exclude_prune_parameters(): external_function_parameters {
        return new external_function_parameters([
            'pruneid' => new external_value(PARAM_INT, 'Pruning candidate id'),
        ]);
    }

    /**
     * Exclude a category from the announced archive pruning.
     *
     * @param int $pruneid Candidate id.
     * @return array
     */
    public static function exclude_prune(int $pruneid): array {
        global $USER;

        $params = self::validate_parameters(self::exclude_prune_parameters(), [
            'pruneid' => $pruneid,
        ]);
        self::require_manager();

        $candidate = prune_manager::exclude($params['pruneid'], (int)$USER->id);

        return [
            'excluded' => true,
            'audit' => get_string('cancelled_by', 'local_coursetransfermanager', (object)[
                'name' => fullname($USER),
                'date' => userdate((int)$candidate->excludedat),
            ]),
        ];
    }

    /**
     * Return structure of exclude_prune().
     *
     * @return external_single_structure
     */
    public static function exclude_prune_returns(): external_single_structure {
        return new external_single_structure([
            'excluded' => new external_value(PARAM_BOOL, 'True when the candidate was excluded'),
            'audit' => new external_value(PARAM_TEXT, 'Who excluded and when, human readable'),
        ]);
    }

    /**
     * Parameters of set_adoption().
     *
     * @return external_function_parameters
     */
    public static function set_adoption_parameters(): external_function_parameters {
        return new external_function_parameters([
            'taskid' => new external_value(PARAM_INT, 'Task id'),
            'categoryid' => new external_value(PARAM_INT, 'Archive category id'),
            'adopt' => new external_value(PARAM_BOOL, 'True to adopt, false to stop managing'),
        ]);
    }

    /**
     * Put an archive category under this task's pruning, or take it out.
     *
     * Categories that reached the archive without the task are invisible to the
     * pruning by design — that is the rule protecting foreign content. Adopting
     * one is therefore an explicit, audited decision, never a side effect.
     *
     * @param int $taskid Task id.
     * @param int $categoryid Archive category id.
     * @param bool $adopt True to adopt, false to stop managing.
     * @return array
     */
    public static function set_adoption(int $taskid, int $categoryid, bool $adopt): array {
        global $USER;

        $params = self::validate_parameters(self::set_adoption_parameters(), [
            'taskid' => $taskid,
            'categoryid' => $categoryid,
            'adopt' => $adopt,
        ]);
        self::require_manager();

        // Only a category the task could legitimately manage: never an arbitrary id.
        $task = (new task_manager())->get_task($params['taskid']);
        $allowed = false;
        foreach (rotation::adoptable($task) as $candidate) {
            if ((int) $candidate->id === $params['categoryid']) {
                $allowed = true;
                break;
            }
        }
        foreach (rotation::managed_categories($task) as $managed) {
            if ((int) $managed->id === $params['categoryid']) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \moodle_exception('adoptnotallowed', 'local_coursetransfermanager');
        }

        if ($params['adopt']) {
            rotation::adopt($params['taskid'], $params['categoryid'], (int) $USER->id);
        } else {
            rotation::unadopt($params['taskid'], $params['categoryid']);
        }

        return [
            'adopted' => $params['adopt'],
            'audit' => get_string(
                $params['adopt'] ? 'adopted_by' : 'unadopted_by',
                'local_coursetransfermanager',
                (object) [
                    'name' => fullname($USER),
                    'date' => userdate(time()),
                ]
            ),
        ];
    }

    /**
     * Return structure of set_adoption().
     *
     * @return external_single_structure
     */
    public static function set_adoption_returns(): external_single_structure {
        return new external_single_structure([
            'adopted' => new external_value(PARAM_BOOL, 'True when the task now manages it'),
            'audit' => new external_value(PARAM_TEXT, 'Who decided and when, human readable'),
        ]);
    }
}
