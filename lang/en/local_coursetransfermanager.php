<?php
// This file is part of Moodle - http://moodle.org/
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
 * Language strings for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Course Transfer Manager';
$string['coursetransfermanager:managetasks'] = 'Manage scheduled course transfers';
$string['managetasks'] = 'Scheduled transfers';
$string['managetasks_desc'] = 'Automatic yearly archiving of course categories between Moodle platforms. See what will run and what will be deleted next.';
$string['id'] = 'ID';
$string['type'] = 'Type';
$string['status'] = 'Status';
$string['fromdate'] = 'From';
$string['todate'] = 'To';

$string['status_error'] = 'Error';
$string['status_completed'] = 'Completed';
$string['status_success'] = 'Success';


$string['createtask'] = 'New task';
$string['edittask'] = 'Edit task';

$string['taskname'] = 'Name';
$string['categorypattern'] = 'Category pattern';
$string['targetcategory'] = 'Destination category';
$string['cronexpression'] = 'Cron expression';
$string['retentiondays'] = 'Retention days';
$string['destinationkeepyears'] = 'Years to keep at destination';
$string['restoreuserdata'] = 'Restore user data';
$string['enabled'] = 'Enabled';

$string['invalidcron'] = 'The cron expression must have 5 parts';

$string['actions'] = 'Actions';
$string['executions'] = 'Executions';

$string['deletetask'] = 'Delete task';
$string['taskdeleted'] = 'Task deleted successfully';
$string['confirmdeletetask'] = 'Are you sure you want to delete the task "{$a}"? Its execution history will also be deleted.';

$string['requestid'] = 'Request ID';
$string['origincategoryid'] = 'Origin category ID';
$string['origincategoryname'] = 'Origin category';
$string['scheduleddeleteat'] = 'Scheduled remote deletion';
$string['error'] = 'Error';
$string['process_tasks'] = 'Process course transfer tasks';
$string['categorynotfound'] = 'No category was found matching that idnumber pattern';


// Form sections.
$string['retention'] = 'Retention';



// Delete options.

$string['emptyresponse'] = 'Empty response from remote Moodle';
$string['invalidresponse'] = 'Invalid response from remote Moodle';
$string['remotecategoryerror'] = 'Remote category resolution failed';



$string['originsyncfailed'] = 'Could not verify synchronization with the origin site';

// Origin site selector.
$string['originsite'] = 'Origin site';
$string['originsitenotset'] = 'No origin site has been configured for this task.';
$string['originsitenotfound'] = 'The configured origin site no longer exists in local_coursetransfer.';

// Scheduling.
$string['lastruntime'] = 'Last execution';
$string['nextruntime'] = 'Next execution';

// Lifecycle notifications (N1-N7).
$string['notif_launched_subject'] = 'Task "{$a->taskname}": restoration launched';
$string['notif_launched_body'] = 'The task "{$a->taskname}" has launched the restoration of the category "{$a->categoryname}" from {$a->host}. It runs in the background and can take hours; you will be notified when it completes. Follow it at {$a->url}';
$string['notif_launched_small'] = 'Restoration launched.';
$string['notif_completed_subject'] = 'Task "{$a->taskname}": restoration completed';
$string['notif_completed_body'] = 'The restoration of the category "{$a->categoryname}" has finished: all its courses are now in this platform. Reminder: it will be deleted from the origin platform on {$a->deletedate} unless someone cancels it at {$a->url}';
$string['notif_completed_small'] = 'Restoration completed.';
$string['notif_delwarn_subject'] = 'Advance notice: "{$a->categoryname}" will be deleted from the origin platform on {$a->deletedate}';
$string['notif_delwarn_body'] = 'On {$a->deletedate} the category "{$a->categoryname}" will be deleted from {$a->host}. It stays archived in this platform. If it must remain in the origin, cancel the deletion before that date at {$a->url}';
$string['notif_delwarn_small'] = 'Deletion scheduled — cancellable.';
$string['notif_deldone_subject'] = '"{$a->categoryname}" has been deleted from the origin platform';
$string['notif_deldone_body'] = 'The category "{$a->categoryname}" has been deleted from {$a->host}, as scheduled by the task "{$a->taskname}". It remains archived in this platform. Record at {$a->url}';
$string['notif_deldone_small'] = 'Origin deletion executed.';
$string['notif_prunewarn_subject'] = 'Archive pruning announced: "{$a->categoryname}"';
$string['notif_prunewarn_body'] = 'The category "{$a->categoryname}" exceeds the years kept in the archive and will be pruned on {$a->gracedate}. To keep it, exclude it from pruning before that date at {$a->url}';
$string['notif_prunewarn_small'] = 'Pruning candidate announced — excludable.';
$string['notif_prunedone_subject'] = 'Archive pruning executed: "{$a->categoryname}"';
$string['notif_prunedone_body'] = 'The category "{$a->categoryname}" has been removed from the archive, as configured in the task "{$a->taskname}". Record at {$a->url}';
$string['notif_prunedone_small'] = 'Pruning executed.';
$string['notif_error_subject'] = 'Task "{$a->taskname}" failed';
$string['notif_error_body'] = 'The task "{$a->taskname}" failed: {$a->detail} — Review the task or the origin platform and check the record at {$a->url}';
$string['notif_error_small'] = 'Task execution failed.';

// Administration settings.
$string['settings_link'] = 'Manager settings';
$string['setting_deletionwarningdays'] = 'Days of notice before deleting in the origin';
$string['setting_deletionwarningdays_desc'] = 'How many days before a scheduled deletion in the origin platform the cancellable advance notice is sent (bell and email).';
$string['setting_prunegracedays'] = 'Grace days before pruning the archive';
$string['setting_prunegracedays_desc'] = 'Days between announcing the pruning candidates and deleting them, leaving time to exclude what must be kept.';
$string['setting_deletionspaused'] = 'Pause every deletion (emergency switch)';
$string['setting_deletionspaused_desc'] = 'While active, NO deletion or pruning runs and their due dates keep sliding forward, so lifting the pause never triggers a backlog. Restorations are not affected.';
$string['panel_paused'] = 'Emergency switch active: every deletion and pruning is paused.';
$string['deletion_held_notcompleted'] = 'the restoration of "{$a}" is not registered as completed, so there is no verified complete copy in this platform.';
$string['deletion_held_archivemissing'] = 'the archived copy of "{$a}" no longer exists (or has no courses) in this platform.';

// Messages registered in db/messages.php.
$string['messageprovider:execution_launched'] = 'Restoration launched (task fired)';
$string['messageprovider:restore_completed'] = 'Restoration completed';
$string['messageprovider:deletion_warning'] = 'Advance notice of deletion in the origin platform';
$string['messageprovider:deletion_done'] = 'Deletion in the origin platform executed';
$string['messageprovider:prune_warning'] = 'Advance notice of archive pruning';
$string['messageprovider:prune_done'] = 'Archive pruning executed';
$string['messageprovider:execution_error'] = 'Transfer task failed';
$string['messageprovider:deletion_held'] = 'Deletion held by a safety lock';

// N8 — deletion held by a safety lock (nothing failed).
$string['notif_held_subject'] = 'Deletion held for safety: "{$a->categoryname}"';
$string['notif_held_body'] = 'Nothing was deleted. The task "{$a->taskname}" was due to delete "{$a->categoryname}" from the origin platform, but the safety check did not pass, so the deletion was postponed 7 days. Reason: {$a->reason} Review it and, if the deletion should not happen, cancel it at {$a->url}';
$string['notif_held_small'] = 'Deletion held: unverified copy.';
$string['notif_cta'] = 'Open the tracking screen';
$string['notif_signature'] = 'Automatic notice sent by {$a->plugin} ({$a->component}) from {$a->site} — {$a->host}';

// Management panel.
$string['health_coursetransfer'] = 'CourseTransfer';
$string['health_coursetransfer_ok'] = 'Plugin up and running';
$string['health_coursetransfer_ko'] = 'Plugin not available — nothing can run';
$string['health_cron'] = 'Moodle cron';
$string['health_cron_ok'] = 'Last run {$a} ago';
$string['health_cron_ko'] = 'Last run {$a} ago — check the cron';
$string['health_cron_never'] = 'Never run — nothing will execute';
$string['health_managertask'] = 'Manager scheduled task';
$string['health_managertask_ok'] = 'Enabled · last run {$a} ago';
$string['health_managertask_disabled'] = 'Disabled — no task will fire';
$string['health_managertask_stale'] = 'Enabled · no recent runs';
$string['health_managertask_manage'] = 'Manage scheduled tasks';
$string['relative_in'] = 'in {$a}';
$string['agenda_title'] = 'Coming up';
$string['agenda_summary'] = '{$a->executions} execution(s) · {$a->deletions} pending deletion(s)';
$string['agenda_empty'] = 'No scheduled activity. When a task is active you will see here its next execution and the upcoming deletions.';
$string['agenda_empty_short'] = 'No scheduled activity';
$string['agenda_footer'] = 'Every deletion is announced in advance and can be cancelled until the moment it happens.';
$string['agenda_exec_label'] = 'Next execution';
$string['agenda_del_label'] = 'Deletion in the ORIGIN';
$string['agenda_prune_label'] = 'Archive pruning';
$string['agenda_cancelled_label'] = 'Cancelled';
$string['agenda_exec_detail'] = 'Will bring the category «{$a->category}» from {$a->host}';
$string['agenda_del_detail'] = 'The complete category will be removed from {$a}. It stays archived here.';
$string['agenda_prune_detail'] = 'Exceeds the years kept by the local archive. It will be removed from this platform unless you exclude it from pruning.';
$string['agenda_category'] = 'Category «{$a}»';
$string['agenda_cancel'] = 'Cancel deletion';
$string['agenda_exclude'] = 'Exclude from pruning';
$string['tasks_title'] = 'Configured tasks';
$string['taskcount'] = '{$a} task(s)';
$string['filters'] = 'Filters';
$string['filters_apply'] = 'Apply';
$string['filters_clear'] = 'Clear';
$string['tasks_empty_title'] = 'No archive tasks yet';
$string['tasks_empty_body'] = 'A task brings a complete category from another Moodle platform every year, stores it in your archive and, after a while, deletes it from the origin. All automatic and always announced. Create the first one to start.';
$string['card_origin'] = 'Origin';
$string['card_brings'] = 'What it brings';
$string['card_destination'] = 'Destination';
$string['card_next'] = 'Next execution';
$string['card_last'] = 'Last execution';
$string['card_paused'] = 'Paused — it will not run';
$string['card_next_on'] = 'Will run on {$a->date} · in {$a->relative}';
$string['card_next_unknown'] = 'Schedule could not be computed';
$string['card_type_auto'] = 'Yearly migration';
$string['card_type_manual'] = 'Manual run';
$string['card_viewdetail'] = 'View detail';
$string['card_active'] = 'Active';
$string['card_inactive'] = 'Inactive';
$string['card_origin_ko'] = 'Its origin platform ({$a}) is registered as down. The next execution will fail until the connection is restored in CourseTransfer.';
$string['card_last_error'] = 'The last execution failed:';
$string['runnow'] = 'Run now';
$string['runnow_confirm_title'] = 'Run «{$a}» now?';
$string['runnow_confirm_body'] = 'The migration will launch immediately, without waiting for the schedule. The restoration runs in the background and can take hours; follow it in the tracking screen.';
$string['runnow_blocked_title'] = 'It cannot run now';
$string['runnow_blocked_body'] = '«{$a->name}» already ran successfully in this cycle (on {$a->date}). Relaunching it now would duplicate the courses in the archive. Wait for the next cycle or edit the task.';
$string['runnow_launched'] = 'Restoration launched. It runs in the background — follow it in the tracking screen.';
$string['runnow_failed'] = 'The launch failed: {$a}';
$string['cancel_modal_title'] = 'Cancel this deletion?';
$string['cancel_modal_body'] = '{$a} will stay where it is. It will be recorded who cancelled it and when.';
$string['cancel_confirm'] = 'Yes, cancel the deletion';
$string['exclude_modal_title'] = 'Exclude from pruning?';
$string['exclude_modal_body'] = '{$a} will be kept in the archive. It will be recorded who excluded it and when.';
$string['exclude_confirm'] = 'Yes, keep it';
$string['cancelled_by'] = 'Cancelled by {$a->name} · {$a->date}';

// Task wizard.
$string['wizard_title'] = 'New yearly archive task';
$string['wizard_desc'] = 'Configure where a category is brought from every year, where it is stored, and what will be deleted afterwards. Nothing is deleted without telling you first.';
$string['wz_step1'] = 'Origin';
$string['wz_step1_hint'] = 'Platform and category';
$string['wz_step2'] = 'Destination';
$string['wz_step2_hint'] = 'Where it is archived';
$string['wz_step3'] = 'Schedule';
$string['wz_step3_hint'] = 'When it runs';
$string['wz_step4'] = 'Retentions and notices';
$string['wz_step4_hint'] = 'What is deleted · who is told';
$string['wz_step5'] = 'Review';
$string['wz_step5_hint'] = 'Confirm and activate';
$string['wz_s1_title'] = '1. Where the content comes from';
$string['wz_s1_desc'] = 'Choose the platform and check that the category exists before saving.';
$string['wz_name_placeholder'] = 'e.g. Yearly SJD archive';
$string['wz_name_help'] = 'Only used to recognise it in the panel and in the notices.';
$string['wz_manage_sites'] = 'Manage platforms in CourseTransfer';
$string['wz_last_test'] = 'Last connection test: {$a} ago · registered in CourseTransfer';
$string['wz_no_test'] = 'No connection test registered in CourseTransfer';
$string['wz_test_ok'] = 'Connection OK';
$string['wz_test_ko'] = 'No connection';
$string['wz_pattern_help'] = 'In this year\'s run the idnumber «{$a}» will be searched.';
$string['wz_insert'] = 'Insert:';
$string['wz_token_year'] = 'current year ({$a})';
$string['wz_token_prevyear'] = 'previous year ({$a})';
$string['wz_test_pattern'] = 'Test the pattern in the origin';
$string['wz_pat_loading'] = 'Asking the origin platform…';
$string['wz_pat_ok'] = 'Matches exactly one category';
$string['wz_pat_ok_hint'] = 'This is the category the task will bring on every run.';
$string['wz_pat_none'] = 'No category matches this pattern';
$string['wz_pat_none_hint'] = 'Check the idnumber in the origin or adjust the pattern. Saved as is, the execution will fail.';
$string['wz_pat_many'] = 'The pattern matches {$a} categories';
$string['wz_pat_many_hint'] = 'The task needs an unambiguous pattern: refine it until it matches exactly one.';
$string['wz_pat_down'] = 'The origin does not answer';
$string['wz_pat_down_hint'] = 'The token may have expired or the site may be down. Review the platform in CourseTransfer and test again. Detail: {$a}';
$string['wz_pat_invalid'] = 'The pattern is not a valid expression';
$string['wz_s2_title'] = '2. Where it is stored in this platform';
$string['wz_s2_desc'] = 'The category brought will be placed under the archive category you choose.';
$string['wz_dest_placeholder'] = 'Search an archive category…';
$string['wz_dest_help'] = 'Server-side search: type to filter, the full catalogue is never dumped.';
$string['wz_clear_selection'] = 'Clear selection';
$string['wz_dest_preview'] = 'It will look like this';
$string['wz_dest_preview_line'] = 'Category «{$a}» (brought every year)';
$string['wz_searching'] = 'Searching on the server…';
$string['wz_noresults'] = 'No results for «{$a}». Try another term.';
$string['wz_copy_what'] = 'What do we copy';
$string['wz_copy_users'] = 'Courses and user data';
$string['wz_copy_users_desc'] = 'Enrolments, grades and submissions. Complete archive, heavier.';
$string['wz_copy_courses'] = 'Courses only';
$string['wz_copy_courses_desc'] = 'Contents and structure, without people or personal data.';
$string['wz_copy_users_note'] = 'Copying user data means processing personal data in this platform. Check that it fits your retention policy.';
$string['wz_s3_title'] = '3. When it runs';
$string['wz_s3_desc'] = 'Say it in plain words. Below you will see the real dates.';
$string['wz_sched_semantic'] = 'Date and time';
$string['wz_sched_cron'] = 'Advanced (cron)';
$string['wz_once_a_year'] = 'Once a year, on';
$string['wz_of'] = 'of';
$string['wz_at'] = 'at';
$string['wz_day'] = 'Day';
$string['wz_month'] = 'Month';
$string['wz_hour'] = 'Hour';
$string['wz_server_time'] = 'Server time.';
$string['wz_cron_help'] = 'minute · hour · day of month · month · day of week. For cases that do not fit "once a year".';
$string['wz_next_runs'] = 'Next runs';
$string['wz_summer_warning'] = 'It falls in summer. The course may not be closed yet: check that by that date there is final content to bring.';
$string['wz_s4_eyebrow'] = 'This step configures deletions';
$string['wz_s4_title'] = '4. Retentions and notices';
$string['wz_s4_desc'] = 'What is deleted and when, and who we tell. Every deletion is announced first and can be cancelled.';
$string['wz_ret_origin'] = 'Deletion in the origin platform';
$string['wz_ret_origin_desc'] = 'After the days you set, the category is DELETED from {$a}. It stays archived here.';
$string['wz_ret_origin_box'] = 'With the first run on {$a->first}, the origin deletion would happen on {$a->deletion}. You will be warned on {$a->warning} ({$a->days} days before) with a link to cancel it.';
$string['wz_ret_short'] = 'That is little margin: restoring can take hours and someone must review the archive before the original disappears. We recommend 30 days or more.';
$string['wz_ret_archive'] = 'Pruning of this platform\'s archive';
$string['wz_ret_archive_desc'] = 'How many years of archive we keep here. The oldest is announced as a candidate and, if nobody excludes it, deleted.';
$string['wz_ret_archive_box'] = 'The archive would keep the last {$a->years} years. Anything from {$a->cutoff} or earlier becomes a pruning candidate, with {$a->grace} days of grace to exclude it.';
$string['wz_notices'] = 'Notices of this task';
$string['wz_notices_desc'] = 'Who receives the lifecycle notifications, besides the task creator.';
$string['wz_recipients'] = 'Recipients';
$string['wz_recipients_placeholder'] = 'Add people by name or email…';
$string['wz_creator_fixed'] = '{$a} (creator) · fixed';
$string['wz_level'] = 'Notice level';
$string['wz_level_full'] = 'Full';
$string['wz_level_full_desc'] = 'The whole lifecycle: launched, completed, notices, deletions and pruning.';
$string['wz_level_essential'] = 'Essential';
$string['wz_level_essential_desc'] = 'Only the critical: notices before deletions, deletions and errors.';
$string['wz_level_note'] = 'The critical notices — those before a deletion, and errors — are ALWAYS sent, whatever the level.';
$string['wz_s5_title'] = '5. Review before activating';
$string['wz_s5_desc'] = 'This is what will happen, with real dates, if you save it today.';
$string['wz_rev_origin'] = 'Category with idnumber «{$a->idnumber}» · pattern {$a->pattern}';
$string['wz_rev_first'] = 'First time: {$a}';
$string['wz_rev_ret'] = '{$a->days} days in the origin · {$a->years} years of archive';
$string['wz_timeline'] = 'Lifecycle timeline';
$string['wz_tl1_title'] = 'The task fires';
$string['wz_tl1_desc'] = 'The process starts on its own. Nobody needs to be watching.';
$string['wz_tl2_title'] = 'The category is searched in the origin';
$string['wz_tl2_desc'] = 'The idnumber «{$a->category}» is searched in {$a->host}. If it does not match or connect, the run ends in error and you are told the cause.';
$string['wz_tl3_title'] = 'Restoration launched';
$string['wz_tl3_desc'] = '"Launched" is not "finished": the course-by-course progress is followed in the CourseTransfer record.';
$string['wz_tl4_title'] = 'The category is relocated';
$string['wz_tl4_desc'] = 'It appears under the chosen archive category, still restoring courses.';
$string['wz_tl5_title'] = 'Restoration completed for real';
$string['wz_tl5_desc'] = 'Every course restored. The retention countdown of {$a->days} days starts here.';
$string['wz_tl6_title'] = 'Advance notice of the origin deletion';
$string['wz_tl6_desc'] = 'Bell and email, {$a->warningdays} days before, with a direct link to cancel the deletion.';
$string['wz_tl7_title'] = 'It is deleted from the origin platform';
$string['wz_tl7_desc'] = 'If nobody cancels it, «{$a->category}» is deleted from {$a->host}. It stays archived here.';
$string['wz_tl8_title'] = 'Pruning candidates announced';
$string['wz_tl8_desc'] = 'Anything from {$a->cutoff} or earlier is announced with {$a->grace} days of grace to exclude what you want to keep.';
$string['wz_tl9_title'] = 'Archive pruning';
$string['wz_tl9_desc'] = 'The years no longer covered are removed from the archive.';
$string['wz_tl_sameday'] = 'same day';
$string['wz_tl_hours'] = 'can take hours';
$string['wz_tl_aftergrace'] = 'after the grace period';
$string['wz_confirm_title'] = 'This task will delete content in another platform';
$string['wz_confirm_summary'] = 'On {$a->deletion} the category «{$a->category}» will be deleted from {$a->host}. You will receive a cancellable notice on {$a->warning}.';
$string['wz_confirm_check'] = 'I understand that this task will automatically delete content from the origin platform and from this platform\'s archive.';
$string['wz_back'] = 'Back';
$string['wz_continue'] = 'Continue';
$string['wz_save_new'] = 'Save and activate the task';
$string['wz_save_edit'] = 'Save changes';
$string['wz_done_title'] = 'Task saved and active';
$string['wz_done_desc'] = 'It will run for the first time on {$a}. You can cancel the origin deletion from the panel while it has not happened.';
$string['wz_done_back'] = 'Go to the panel';

// Tracking screen.
$string['exec_title'] = 'Executions and deletions';
$string['exec_desc'] = 'What is being brought now, what fires next and what is going to be deleted. Every execution tells where in the lifecycle it is.';
$string['exec_scope'] = 'Task: {$a}';
$string['exec_scope_clear'] = 'Remove the task filter';
$string['exec_tab_live'] = 'In progress';
$string['exec_tab_deletions'] = 'Pending deletions';
$string['exec_tab_history'] = 'History';
$string['exec_live_title'] = 'Executions in progress';
$string['exec_live_empty'] = 'Nothing is running right now.';
$string['exec_live_line'] = 'Bringing the category «{$a}»';
$string['exec_live_since'] = 'started {$a} ago';
$string['exec_fine_detail'] = 'View fine detail in CourseTransfer';
$string['exec_phase_launched'] = 'Launched';
$string['exec_phase_restoring'] = 'Restoring';
$string['exec_phase_completed'] = 'Completed';
$string['exec_stalled'] = 'This phase is taking longer than usual. Probable cause: the cron on either platform may be stopped.';
$string['exec_stalled_checks'] = 'Review the health checks';
$string['exec_refreshed'] = 'Updated {$a}s ago';
$string['exec_upcoming_title'] = 'Upcoming scheduled executions';
$string['exec_upcoming_empty'] = 'No upcoming executions scheduled.';
$string['exec_upcoming_line'] = 'Will bring the category «{$a}»';
$string['exec_deletions_intro'] = 'Everything this plugin is going to delete, in one place. You can cancel any deletion while it is still pending: the category will stay where it is.';
$string['exec_deletions_empty'] = 'No pending deletions. Nothing will be removed automatically for now.';
$string['exec_urgent'] = 'urgent';
$string['exec_del_date'] = 'Scheduled date';
$string['exec_del_origin'] = 'Scheduled by';
$string['exec_filter_task'] = 'Task';
$string['exec_history_empty'] = 'No executions recorded with these filters.';
$string['exec_col_date'] = 'Date';
$string['exec_col_what'] = 'Task and category';
$string['exec_col_type'] = 'Type';
$string['exec_col_request'] = 'Request';
$string['exec_type_restore'] = 'Restore category';
$string['exec_type_prune'] = 'Archive pruning';
$string['exec_status_cancelled'] = 'Deletion cancelled';
$string['exec_status_deleted'] = 'Deleted';
$string['exec_view_error'] = 'View error';
$string['exec_error_title'] = 'The execution failed';
$string['exec_showing'] = 'Showing {$a->shown} of {$a->total} entries';
$string['exec_prev'] = 'Previous';
$string['exec_next'] = 'Next';

// Deletion / pruning lifecycle.
$string['deletionnotcancellable'] = 'This deletion cannot be cancelled: it is no longer pending.';
$string['prunenotexcludable'] = 'This category cannot be excluded: it is no longer a pruning candidate.';

// Privacy API.
$string['privacy:metadata:local_ctm_tasks'] = 'Transfer tasks configured in this site. User references are kept to know who created each task and who receives its notifications.';
$string['privacy:metadata:local_ctm_tasks:usercreated'] = 'The user who created the task. Receives its lifecycle notifications.';
$string['privacy:metadata:local_ctm_tasks:notifyrecipients'] = 'Additional users that receive the notifications of this task.';
$string['privacy:metadata:local_ctm_executions'] = 'Executions of the transfer tasks. A user reference is kept when someone cancels a scheduled remote deletion.';
$string['privacy:metadata:local_ctm_executions:deletecancelledby'] = 'The user who cancelled the scheduled deletion in the origin platform.';
$string['privacy:metadata:local_ctm_executions:deletecancelledat'] = 'When the scheduled deletion was cancelled.';
$string['privacy:metadata:local_ctm_prune'] = 'Archive pruning candidates. A user reference is kept when someone excludes a category from pruning.';
$string['privacy:metadata:local_ctm_prune:excludedby'] = 'The user who excluded the category from pruning.';
$string['privacy:metadata:local_ctm_prune:excludedat'] = 'When the category was excluded from pruning.';
