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
 * Approve a task's first origin deletion (confirmation page) for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_coursetransfermanager\manager\deletion_manager;

global $PAGE, $OUTPUT, $DB, $USER;

require_login();
$context = context_system::instance();
require_capability('local/coursetransfermanager:managetasks', $context);

$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$execution = $DB->get_record('local_coursetransfermanager_executions', ['id' => $id], '*', MUST_EXIST);
$returnurl = new moodle_url('/local/coursetransfermanager/executions.php', [
    'taskid' => $execution->taskid,
    'tab' => 'deletions',
]);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursetransfermanager/approve.php', ['id' => $id]));
$PAGE->set_title(get_string('approval_title', 'local_coursetransfermanager'));
$PAGE->set_heading(get_string('approval_title', 'local_coursetransfermanager'));
$PAGE->set_pagelayout('standard');

if ($confirm) {
    require_sesskey();
    deletion_manager::approve($id, (int)$USER->id);
    redirect(
        $returnurl,
        get_string('approval_done', 'local_coursetransfermanager'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($execution->deletestatus !== deletion_manager::STATUS_AWAITING_APPROVAL) {
    redirect(
        $returnurl,
        get_string('deletionnotapprovable', 'local_coursetransfermanager'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$task = $DB->get_record('local_coursetransfermanager_tasks', ['id' => $execution->taskid], '*', MUST_EXIST);
echo $OUTPUT->header();
echo $OUTPUT->confirm(
    get_string('approval_confirm', 'local_coursetransfermanager', (object)[
        'category' => format_string((string)$execution->origincategoryname),
        'task' => format_string($task->name),
    ]),
    new single_button(
        new moodle_url('/local/coursetransfermanager/approve.php', ['id' => $id, 'confirm' => 1]),
        get_string('approval_approve', 'local_coursetransfermanager'),
        'post',
        single_button::BUTTON_DANGER
    ),
    $returnurl
);
echo $OUTPUT->footer();
