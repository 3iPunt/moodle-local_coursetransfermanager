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
 * Simulate a task (dry run, nothing is executed) for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursetransfermanager\manager\simulation;
use local_coursetransfermanager\manager\task_manager;
use local_coursetransfermanager\output\simulation_page;

global $PAGE, $OUTPUT;

require_login();
$context = context_system::instance();
require_capability('local/coursetransfermanager:managetasks', $context);

$id = required_param('id', PARAM_INT);
$task = (new task_manager())->get_task($id);
$now = time();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursetransfermanager/simulate.php', ['id' => $id]));
$PAGE->set_title(get_string('sim_title', 'local_coursetransfermanager', format_string($task->name)));
// The h1 lives in the ct-hero header (family rule); the theme heading stays empty.
$PAGE->set_heading('');
$PAGE->set_pagelayout('standard');

$pageobj = new simulation_page($task, simulation::run($task, $now), $now);
$renderer = $PAGE->get_renderer('local_coursetransfermanager');

echo $OUTPUT->header();
echo $renderer->render_page($pageobj, 'local_coursetransfermanager/simulation_page');
echo $OUTPUT->footer();
