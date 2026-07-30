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
 * Prerequisite health checks for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use core\task\manager as core_task_manager;
use local_coursetransfer\coursetransfer;
use local_coursetransfer\coursetransfer_sites;
use local_coursetransfermanager\task\process_tasks;
use stdClass;

/**
 * Computes the state of the plugin prerequisites for the panel.
 *
 * Raw data only — labels and actions are rendering concerns. The origin
 * sites check does NOT run live connection tests: it reads the last test
 * result persisted by local_coursetransfer (testing connections is its
 * responsibility; runtime failures surface as execution errors).
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class health {

    /** @var string Check is fine. */
    public const OK = 'ok';

    /** @var string Check needs attention but is not blocking. */
    public const WARNING = 'warning';

    /** @var string Check is broken; executions will fail. */
    public const ERROR = 'error';

    /**
     * Compute all prerequisite checks.
     *
     * @return stdClass[] Checks: {key, status, data}.
     */
    public static function get_checks(): array {
        return [
            self::check_coursetransfer(),
            self::check_cron(),
            self::check_manager_task(),
            self::check_origin_sites(),
        ];
    }

    /**
     * The coursetransfer dependency is installed and its classes are loadable.
     *
     * @return stdClass
     */
    private static function check_coursetransfer(): stdClass {
        $check = self::make('coursetransfer');
        try {
            $version = get_config('local_coursetransfer', 'version');
            $check->data->version = $version ?: null;
            $check->status = ($version && class_exists(coursetransfer::class)) ? self::OK : self::ERROR;
        } catch (\Throwable $e) {
            $check->status = self::ERROR;
            $check->data->message = $e->getMessage();
        }
        return $check;
    }

    /**
     * Moodle cron ran recently (it drives everything asynchronous).
     *
     * @return stdClass
     */
    private static function check_cron(): stdClass {
        global $DB;

        $check = self::make('cron');
        try {
            $lastrun = (int) $DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}');
            $check->data->lastrun = $lastrun ?: null;
            if (!$lastrun) {
                $check->status = self::ERROR;
            } else if ($lastrun > time() - HOURSECS) {
                $check->status = self::OK;
            } else if ($lastrun > time() - DAYSECS) {
                $check->status = self::WARNING;
            } else {
                $check->status = self::ERROR;
            }
        } catch (\Throwable $e) {
            $check->status = self::ERROR;
            $check->data->message = $e->getMessage();
        }
        return $check;
    }

    /**
     * The plugin scheduled task is enabled and ticking.
     *
     * @return stdClass
     */
    private static function check_manager_task(): stdClass {
        $check = self::make('managertask');
        try {
            $task = core_task_manager::get_scheduled_task(process_tasks::class);
            if (!$task) {
                $check->status = self::ERROR;
                return $check;
            }
            $check->data->disabled = (bool) $task->get_disabled();
            $check->data->lastrun = ((int) $task->get_last_run_time()) ?: null;
            if ($check->data->disabled) {
                $check->status = self::ERROR;
            } else if ($check->data->lastrun && $check->data->lastrun > time() - HOURSECS) {
                $check->status = self::OK;
            } else {
                $check->status = self::WARNING;
            }
        } catch (\Throwable $e) {
            $check->status = self::ERROR;
            $check->data->message = $e->getMessage();
        }
        return $check;
    }

    /**
     * Last persisted connection test of every origin site used by the tasks.
     *
     * @return stdClass
     */
    private static function check_origin_sites(): stdClass {
        global $DB;

        $check = self::make('sites');
        $check->data->sites = [];
        try {
            $siteids = $DB->get_fieldset_select(
                'local_ctm_tasks',
                'DISTINCT originsiteid',
                'enabled = 1 AND originsiteid > 0'
            );

            if (empty($siteids)) {
                $check->status = self::OK;
                return $check;
            }

            $worst = self::OK;
            foreach ($siteids as $siteid) {
                try {
                    $record = coursetransfer_sites::get('origin', (int) $siteid);
                    $site = (object) [
                        'id' => (int) $record->id,
                        'host' => $record->host,
                        'lasttest' => $record->lasttest ?? null,
                        'lastteststatus' => isset($record->lastteststatus) && $record->lastteststatus !== null
                            ? (int) $record->lastteststatus : null,
                    ];
                    if ($site->lastteststatus === 0) {
                        $worst = self::ERROR;
                    } else if ($site->lastteststatus === null && $worst !== self::ERROR) {
                        $worst = self::WARNING;
                    }
                    $check->data->sites[] = $site;
                } catch (\Throwable $e) {
                    // The task points to an origin site that no longer exists.
                    $worst = self::ERROR;
                    $check->data->sites[] = (object) [
                        'id' => (int) $siteid,
                        'host' => null,
                        'lasttest' => null,
                        'lastteststatus' => 0,
                    ];
                }
            }
            $check->status = $worst;
        } catch (\Throwable $e) {
            $check->status = self::ERROR;
            $check->data->message = $e->getMessage();
        }
        return $check;
    }

    /**
     * Build an empty check shell.
     *
     * @param string $key Check key.
     * @return stdClass
     */
    private static function make(string $key): stdClass {
        return (object) [
            'key' => $key,
            'status' => self::OK,
            'data' => new stdClass(),
        ];
    }
}
