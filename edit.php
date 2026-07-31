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
 * Task wizard (create/edit) for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursetransfer\coursetransfer_sites;
use local_coursetransfermanager\manager\academic_year;
use local_coursetransfermanager\manager\task_manager;
use local_coursetransfermanager\notification\notifier;
use local_coursetransfermanager\output\task_wizard_page;

global $PAGE, $OUTPUT, $USER;

// Security.
require_login();
$context = context_system::instance();
require_capability('local/coursetransfermanager:managetasks', $context);

// Parameters.
$id = optional_param('id', 0, PARAM_INT);

// Data.
$manager = new task_manager();
$task = null;
if ($id > 0) {
    $task = $manager->get_task($id);
}

$sites = array_values(coursetransfer_sites::list('origin'));

// Wizard context: creator, recipients resolved to names, settings.
$wizardcontext = new stdClass();
$creatorid = $task ? (int)$task->usercreated : (int)$USER->id;
$creator = core_user::get_user($creatorid, '*', IGNORE_MISSING);
$wizardcontext->creatorname = $creator ? fullname($creator) : '';

$wizardcontext->recipients = [];
if ($task && !empty($task->notifyrecipients)) {
    foreach (explode(',', (string)$task->notifyrecipients) as $userid) {
        $user = core_user::get_user((int)$userid, '*', IGNORE_MISSING);
        if ($user && empty($user->deleted)) {
            $wizardcontext->recipients[] = [
                'id' => (int)$user->id,
                'fullname' => fullname($user),
                'email' => $user->email,
            ];
        }
    }
}

$wizardcontext->target = null;
if ($task && !empty($task->targetcategoryid)) {
    $category = core_course_category::get((int)$task->targetcategoryid, IGNORE_MISSING);
    if ($category) {
        $wizardcontext->target = [
            'id' => (int)$category->id,
            'name' => $category->get_formatted_name(),
            'path' => $category->get_nested_name(false),
        ];
    }
}

$warningdays = get_config('local_coursetransfermanager', 'deletionwarningdays');
$wizardcontext->warningdays = ($warningdays === false || $warningdays === '')
    ? notifier::DEFAULT_WARNING_DAYS : (int)$warningdays;
$gracedays = get_config('local_coursetransfermanager', 'prunegracedays');
$wizardcontext->gracedays = ($gracedays === false || $gracedays === '')
    ? task_manager::DEFAULT_PRUNE_GRACE_DAYS : (int)$gracedays;
// The wizard previews idnumbers client side: it needs the same academic year the
// engine uses, or the examples on screen would not match what the task will do.
$wizardcontext->startmonth = academic_year::current_year_start_month();
$wizardcontext->currentyear = academic_year::current_year();

// Page setup.
$url = new moodle_url('/local/coursetransfermanager/edit.php', $id ? ['id' => $id] : []);
$title = $task
    ? get_string('edittask', 'local_coursetransfermanager')
    : get_string('createtask', 'local_coursetransfermanager');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title($title);
// The h1 lives in the ct-hero header (family rule); the theme heading stays empty.
$PAGE->set_heading('');
// Family layout: the theme centers and limits the content width; ctm-wide
// widens its container to the manager content width (see styles.css token).
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('ctm-wide');

// Render.
$page = new task_wizard_page($task, $sites, $wizardcontext);
$renderer = $PAGE->get_renderer('local_coursetransfermanager');

echo $OUTPUT->header();
echo $renderer->render_page($page, 'local_coursetransfermanager/task_wizard_page');
echo $OUTPUT->footer();
