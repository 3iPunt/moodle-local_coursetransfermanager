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
 * Tracking screen (executions and deletions) for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursetransfermanager\manager\agenda;
use local_coursetransfermanager\manager\executions;
use local_coursetransfermanager\manager\task_manager;
use local_coursetransfermanager\output\executions_page;

global $PAGE, $OUTPUT, $DB;

// Security.
require_login();
$context = context_system::instance();
require_capability('local/coursetransfermanager:managetasks', $context);

// Parameters. The task scope is OPTIONAL: the global view is the default.
$taskid = optional_param('taskid', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$fromdateraw = optional_param('fromdate', '', PARAM_RAW_TRIMMED);
$todateraw = optional_param('todate', '', PARAM_RAW_TRIMMED);
$page = optional_param('page', 0, PARAM_INT);

$filters = ['taskid' => $taskid, 'status' => $status, 'fromdate' => null, 'todate' => null];
if ($fromdateraw !== '' && ($fromts = strtotime($fromdateraw)) !== false) {
    $filters['fromdate'] = usergetmidnight($fromts);
}
if ($todateraw !== '' && ($tots = strtotime($todateraw)) !== false) {
    $filters['todate'] = usergetmidnight($tots) + DAYSECS - 1;
}

$perpage = 20;
$scopetaskid = $taskid > 0 ? $taskid : null;

// Data.
$manager = new task_manager();
$task = $scopetaskid ? $manager->get_task($scopetaskid) : null;
$now = time();

$pageobj = new executions_page(
    $task,
    executions::get_live($scopetaskid),
    agenda::get_items($now, $scopetaskid),
    executions::get_history($filters, $page, $perpage),
    executions::get_counts($scopetaskid),
    $filters,
    array_values($DB->get_records('local_ctm_tasks', null, 'name ASC', 'id, name')),
    $page,
    $perpage,
    $now
);

// Page setup.
$urlparams = array_filter([
    'taskid' => $taskid,
    'status' => $status,
    'fromdate' => $fromdateraw,
    'todate' => $todateraw,
    'page' => $page,
]);
$url = new moodle_url('/local/coursetransfermanager/executions.php', $urlparams);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('exec_title', 'local_coursetransfermanager'));
// The h1 lives in the ct-hero header (family rule); the theme heading stays empty.
$PAGE->set_heading('');
// Family layout + wide container (dense screen), see styles.css token.
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('ctm-wide');

// Render.
$renderer = $PAGE->get_renderer('local_coursetransfermanager');

echo $OUTPUT->header();
echo $renderer->render_page($pageobj, 'local_coursetransfermanager/executions_page');
echo $OUTPUT->footer();
