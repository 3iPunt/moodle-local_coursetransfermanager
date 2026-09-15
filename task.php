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
 * Read-only lifecycle view of one task for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursetransfermanager\external\wizard_external;
use local_coursetransfermanager\manager\agenda;
use local_coursetransfermanager\manager\origin;
use local_coursetransfermanager\manager\rotation;
use local_coursetransfermanager\manager\task_manager;
use local_coursetransfermanager\notification\notifier;
use local_coursetransfermanager\output\task_view_page;

global $PAGE, $OUTPUT, $DB;

// Security.
require_login();
$context = context_system::instance();
require_capability('local/coursetransfermanager:managetasks', $context);

// Parameters.
$id = required_param('id', PARAM_INT);

// Data.
$manager = new task_manager();
$task = $manager->get_task($id);
$now = time();

// The archive category name, for the header cells.
$task->targetcategoryname = null;
if (!empty($task->targetcategoryid)) {
    $task->targetcategoryname = $DB->get_field(
        'course_categories',
        'name',
        ['id' => $task->targetcategoryid]
    );
}

// The origin host, or null when the platform was unregistered.
$originhost = null;
try {
    $originhost = origin::site((int)$task->originsiteid)->host;
} catch (moodle_exception $e) {
    $originhost = null;
}

// The projection comes from the same endpoint the wizard preview uses, so the
// promise made while configuring and what is shown here cannot drift apart.
$projection = (object)wizard_external::policy_preview(
    (int)$task->originsiteid,
    (string)$task->categorypattern,
    (int)$task->originkeepyears,
    (int)$task->destinationkeepyears,
    (int)$task->targetcategoryid,
    // One page load, so the origin round trip is worth it: here it matters which
    // years really exist there and which are only projected.
    true,
    (int)$task->id
);

$warningdays = get_config('local_coursetransfermanager', 'deletionwarningdays');
$warningdays = ($warningdays === false || $warningdays === '')
    ? notifier::DEFAULT_WARNING_DAYS : (int)$warningdays;
$gracedays = get_config('local_coursetransfermanager', 'prunegracedays');
$gracedays = ($gracedays === false || $gracedays === '')
    ? task_manager::DEFAULT_PRUNE_GRACE_DAYS : (int)$gracedays;

$pageobj = new task_view_page(
    $task,
    $projection,
    agenda::get_items($now, (int)$task->id),
    rotation::managed_categories($task),
    rotation::adoptable($task),
    array_values($DB->get_records('local_coursetransfermanager_adopted', ['taskid' => $task->id])),
    $originhost,
    $warningdays,
    $gracedays,
    $now
);

// Page setup.
$url = new moodle_url('/local/coursetransfermanager/task.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('view_title', 'local_coursetransfermanager'));
// The h1 lives in the ct-hero header (family rule); the theme heading stays empty.
$PAGE->set_heading('');
// Family layout + wide container (dense screen), see styles.css token.
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('ctm-wide');
$PAGE->navbar->add(
    get_string('managetasks', 'local_coursetransfermanager'),
    new moodle_url('/local/coursetransfermanager/manage.php')
);
$PAGE->navbar->add(format_string($task->name), $url);

// Render.
$renderer = $PAGE->get_renderer('local_coursetransfermanager');

echo $OUTPUT->header();
echo $renderer->render_page($pageobj, 'local_coursetransfermanager/task_view_page');
echo $OUTPUT->footer();
