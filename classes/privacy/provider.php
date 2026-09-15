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
 * Privacy provider for local_coursetransfermanager.
 *
 * The plugin stores user references as part of its administrative audit
 * trail: who created each task, who receives its notifications, who
 * cancelled a scheduled remote deletion and who excluded a category from
 * pruning. Personal data is limited to those user ids; deleting a user's
 * data anonymises the references without removing the operational records.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\privacy;

use context;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider: declares stored user references and handles export/erasure.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the user data stored by the plugin.
     *
     * @param collection $collection Metadata collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_ctm_tasks', [
            'usercreated' => 'privacy:metadata:local_ctm_tasks:usercreated',
            'notifyrecipients' => 'privacy:metadata:local_ctm_tasks:notifyrecipients',
        ], 'privacy:metadata:local_ctm_tasks');

        $collection->add_database_table('local_ctm_executions', [
            'deletecancelledby' => 'privacy:metadata:local_ctm_executions:deletecancelledby',
            'deletecancelledat' => 'privacy:metadata:local_ctm_executions:deletecancelledat',
        ], 'privacy:metadata:local_ctm_executions');

        $collection->add_database_table('local_ctm_prune', [
            'excludedby' => 'privacy:metadata:local_ctm_prune:excludedby',
            'excludedat' => 'privacy:metadata:local_ctm_prune:excludedat',
        ], 'privacy:metadata:local_ctm_prune');

        return $collection;
    }

    /**
     * Get the contexts holding data for a user. Everything lives in system context.
     *
     * @param int $userid The user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        $params = ['userid' => $userid, 'pattern' => '%,' . $userid . ',%'];
        $sql = "SELECT COUNT(1)
                  FROM {local_ctm_tasks} t
                 WHERE t.usercreated = :userid
                    OR " . $DB->sql_like($DB->sql_concat("','", 't.notifyrecipients', "','"), ':pattern');

        $found = $DB->count_records_sql($sql, $params) > 0
            || $DB->record_exists('local_ctm_executions', ['deletecancelledby' => $userid])
            || $DB->record_exists('local_ctm_prune', ['excludedby' => $userid]);

        if ($found) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Get all users referenced in the given context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof context_system) {
            return;
        }

        $userlist->add_from_sql(
            'usercreated',
            'SELECT usercreated FROM {local_ctm_tasks} WHERE usercreated > 0',
            []
        );
        $userlist->add_from_sql(
            'deletecancelledby',
            'SELECT deletecancelledby FROM {local_ctm_executions} WHERE deletecancelledby IS NOT NULL',
            []
        );
        $userlist->add_from_sql(
            'excludedby',
            'SELECT excludedby FROM {local_ctm_prune} WHERE excludedby IS NOT NULL',
            []
        );

        // Recipients are stored as a comma-separated list; expand them in PHP.
        $lists = $DB->get_fieldset_select(
            'local_ctm_tasks',
            'notifyrecipients',
            $DB->sql_isnotempty('local_ctm_tasks', 'notifyrecipients', true, true)
        );
        $recipients = [];
        foreach ($lists as $list) {
            foreach (explode(',', (string) $list) as $id) {
                if ((int) $id > 0) {
                    $recipients[(int) $id] = true;
                }
            }
        }
        if ($recipients) {
            $userlist->add_users(array_keys($recipients));
        }
    }

    /**
     * Export the user references stored by the plugin.
     *
     * @param approved_contextlist $contextlist Approved contexts for the user.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        $systemcontext = null;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_system) {
                $systemcontext = $context;
                break;
            }
        }
        if (!$systemcontext) {
            return;
        }

        $subcontext = [get_string('pluginname', 'local_coursetransfermanager')];
        $data = new \stdClass();

        $data->taskscreated = array_values(array_map(static function (\stdClass $task): array {
            return [
                'name' => $task->name,
                'timecreated' => transform::datetime($task->timecreated),
            ];
        }, $DB->get_records('local_ctm_tasks', ['usercreated' => $userid], 'id', 'id, name, timecreated')));

        $params = ['pattern' => '%,' . $userid . ',%'];
        $data->notificationrecipientof = $DB->get_fieldset_select(
            'local_ctm_tasks',
            'name',
            $DB->sql_like($DB->sql_concat("','", 'notifyrecipients', "','"), ':pattern'),
            $params
        );

        $data->deletionscancelled = array_values(array_map(static function (\stdClass $execution): array {
            return [
                'category' => (string) $execution->origincategoryname,
                'cancelledat' => transform::datetime($execution->deletecancelledat),
            ];
        }, $DB->get_records(
            'local_ctm_executions',
            ['deletecancelledby' => $userid],
            'id',
            'id, origincategoryname, deletecancelledat'
        )));

        $data->pruningexclusions = array_values(array_map(static function (\stdClass $candidate): array {
            return [
                'category' => (string) $candidate->categoryname,
                'excludedat' => transform::datetime($candidate->excludedat),
            ];
        }, $DB->get_records('local_ctm_prune', ['excludedby' => $userid], 'id', 'id, categoryname, excludedat')));

        writer::with_context($systemcontext)->export_data($subcontext, $data);
    }

    /**
     * Anonymise all user references in the given context.
     *
     * Operational records (tasks, executions, pruning audit) are kept: they
     * are administrative data, not user content. Only the user references
     * are removed.
     *
     * @param context $context The context to purge.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof context_system) {
            return;
        }

        $DB->set_field_select('local_ctm_tasks', 'usercreated', 0, 'usercreated > 0');
        $DB->set_field_select('local_ctm_tasks', 'notifyrecipients', null, '1 = 1');
        $DB->set_field_select('local_ctm_executions', 'deletecancelledby', null, 'deletecancelledby IS NOT NULL');
        $DB->set_field_select('local_ctm_prune', 'excludedby', null, 'excludedby IS NOT NULL');
    }

    /**
     * Anonymise the references of the given user.
     *
     * @param approved_contextlist $contextlist Approved contexts for the user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_system) {
                self::anonymise_user((int) $contextlist->get_user()->id);
                return;
            }
        }
    }

    /**
     * Anonymise the references of the given users.
     *
     * @param approved_userlist $userlist Approved users in the context.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::anonymise_user((int) $userid);
        }
    }

    /**
     * Remove every reference to a user id from the plugin tables.
     *
     * @param int $userid The user id to anonymise.
     * @return void
     */
    private static function anonymise_user(int $userid): void {
        global $DB;

        $DB->set_field('local_ctm_tasks', 'usercreated', 0, ['usercreated' => $userid]);
        $DB->set_field('local_ctm_executions', 'deletecancelledby', null, ['deletecancelledby' => $userid]);
        $DB->set_field('local_ctm_prune', 'excludedby', null, ['excludedby' => $userid]);

        // Remove the user from every comma-separated recipient list.
        $params = ['pattern' => '%,' . $userid . ',%'];
        $tasks = $DB->get_records_select(
            'local_ctm_tasks',
            $DB->sql_like($DB->sql_concat("','", 'notifyrecipients', "','"), ':pattern'),
            $params,
            'id',
            'id, notifyrecipients'
        );
        foreach ($tasks as $task) {
            $ids = array_filter(explode(',', (string) $task->notifyrecipients), static function (string $id) use ($userid): bool {
                return (int) $id > 0 && (int) $id !== $userid;
            });
            $DB->set_field(
                'local_ctm_tasks',
                'notifyrecipients',
                $ids ? implode(',', $ids) : null,
                ['id' => $task->id]
            );
        }
    }
}
