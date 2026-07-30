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
 * Management panel for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursetransfermanager\manager\agenda;
use local_coursetransfermanager\manager\health;
use local_coursetransfermanager\manager\task_manager;
use local_coursetransfermanager\output\panel_page;

global $PAGE, $OUTPUT;

// Security.
require_login();
$context = context_system::instance();
require_capability('local/coursetransfermanager:managetasks', $context);

// Parameters (GET filters).
$status = optional_param('status', '', PARAM_ALPHA);
$fromdateraw = optional_param('fromdate', '', PARAM_RAW_TRIMMED);
$todateraw = optional_param('todate', '', PARAM_RAW_TRIMMED);

$filters = ['status' => $status, 'fromdate' => null, 'todate' => null];
if ($fromdateraw !== '' && ($fromts = strtotime($fromdateraw)) !== false) {
    $filters['fromdate'] = usergetmidnight($fromts);
}
if ($todateraw !== '' && ($tots = strtotime($todateraw)) !== false) {
    $filters['todate'] = usergetmidnight($tots) + DAYSECS - 1;
}

// Page setup.
$url = new moodle_url('/local/coursetransfermanager/manage.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('managetasks', 'local_coursetransfermanager'));
// The h1 lives in the ct-hero header (family rule); the theme heading stays empty.
$PAGE->set_heading('');
// Family layout: the THEME centers and limits the content (no plugin max-width);
// the ctm-wide body class widens the theme container for this dense screen.
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('ctm-wide');

// Data.
$now = time();
$manager = new task_manager();
$page = new panel_page(
    health::get_checks(),
    agenda::get_items($now),
    $manager->get_tasks_overview($filters),
    $filters,
    $now,
    (bool)get_config('local_coursetransfermanager', 'deletionspaused')
);

// Render.
$renderer = $PAGE->get_renderer('local_coursetransfermanager');

echo $OUTPUT->header();
echo $renderer->render_page($page, 'local_coursetransfermanager/panel_page');
echo $OUTPUT->footer();
