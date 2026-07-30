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
 * Page for deleting a task.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursetransfermanager\manager\task_manager;

global $PAGE, $OUTPUT;

require_login();

$context = context_system::instance();
require_capability('local/coursetransfermanager:managetasks', $context);

$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$manageurl = new moodle_url('/local/coursetransfermanager/manage.php');
$url = new moodle_url('/local/coursetransfermanager/delete.php', ['id' => $id]);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('deletetask', 'local_coursetransfermanager'));
$PAGE->set_heading(get_string('deletetask', 'local_coursetransfermanager'));

$manager = new task_manager();
$task = $manager->get_task($id);

if ($confirm) {
    require_sesskey();

    $manager->delete_task($id);

    redirect(
        $manageurl,
        get_string('taskdeleted', 'local_coursetransfermanager'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();

echo $OUTPUT->confirm(
    get_string('confirmdeletetask', 'local_coursetransfermanager', format_string($task->name)),
    new moodle_url('/local/coursetransfermanager/delete.php', [
        'id' => $id,
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]),
    $manageurl
);

echo $OUTPUT->footer();
