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
 * Web services for local_coursetransfermanager.
 *
 * FRONTEND (AJAX) ONLY: internal JS→PHP endpoints of the manager screens,
 * free to change between versions. The plugin exposes NO backend contract:
 * remote data always goes through the local_coursetransfer client (its
 * backend WS are the inter-platform contract — the category-by-idnumber WS
 * that once lived here belongs there since it owns the data).
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_coursetransfermanager\external\task_external;
use local_coursetransfermanager\external\wizard_external;

$functions = [
    'local_coursetransfermanager_test_pattern' => [
        'classname' => wizard_external::class,
        'methodname' => 'test_pattern',
        'description' => 'Test the category pattern against the origin platform, live.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/coursetransfermanager:managetasks',
    ],
    'local_coursetransfermanager_schedule_preview' => [
        'classname' => wizard_external::class,
        'methodname' => 'schedule_preview',
        'description' => 'Preview the next runs of a cron expression.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/coursetransfermanager:managetasks',
    ],
    'local_coursetransfermanager_category_search' => [
        'classname' => wizard_external::class,
        'methodname' => 'category_search',
        'description' => 'Search local categories by name (server-side, capped).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/coursetransfermanager:managetasks',
    ],
    'local_coursetransfermanager_user_search' => [
        'classname' => wizard_external::class,
        'methodname' => 'user_search',
        'description' => 'Search users to add as notification recipients.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/coursetransfermanager:managetasks',
    ],
    'local_coursetransfermanager_task_save' => [
        'classname' => wizard_external::class,
        'methodname' => 'task_save',
        'description' => 'Create or update a transfer task (wizard save).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/coursetransfermanager:managetasks',
    ],
    'local_coursetransfermanager_task_toggle' => [
        'classname' => task_external::class,
        'methodname' => 'toggle',
        'description' => 'Enable or disable a transfer task (panel switch).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/coursetransfermanager:managetasks',
    ],
    'local_coursetransfermanager_task_run_now' => [
        'classname' => task_external::class,
        'methodname' => 'run_now',
        'description' => 'Launch a transfer task immediately (guarded against duplicates).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/coursetransfermanager:managetasks',
    ],
    'local_coursetransfermanager_deletion_cancel' => [
        'classname' => task_external::class,
        'methodname' => 'cancel_deletion',
        'description' => 'Cancel a pending scheduled deletion in the origin platform.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/coursetransfermanager:managetasks',
    ],
    'local_coursetransfermanager_prune_exclude' => [
        'classname' => task_external::class,
        'methodname' => 'exclude_prune',
        'description' => 'Exclude a category from the announced archive pruning.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/coursetransfermanager:managetasks',
    ],
];

$services = [];
