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
 * Upgrade steps for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Run upgrade steps for this plugin.
 *
 * @param int $oldversion The previously installed version number.
 * @return bool
 * @throws ddl_exception
 * @throws ddl_field_missing_exception
 * @throws ddl_table_missing_exception
 * @throws upgrade_exception
 */
function xmldb_local_coursetransfermanager_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026050401) {
        $table = new xmldb_table('local_coursetransfermanager_tasks');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('siteurl', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('categorypattern', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('targetcategoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('cronexpression', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('retentiondays', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '30');
        $table->add_field('remotekeepyears', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '2');
        $table->add_field('destinationkeepyears', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '4');
        $table->add_field('restoreuserdata', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_coursetransfermanager_executions');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('taskid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $table->add_field('requestid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('origincategoryid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('origincategoryname', XMLDB_TYPE_CHAR, '255');
        $table->add_field('origincategoryidnumber', XMLDB_TYPE_CHAR, '255');
        $table->add_field('scheduleddeleteat', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('errorcode', XMLDB_TYPE_CHAR, '50');
        $table->add_field('errormessage', XMLDB_TYPE_TEXT);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('task_fk', XMLDB_KEY_FOREIGN, ['taskid'], 'local_coursetransfermanager_tasks', ['id']);

        $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026050401, 'local', 'coursetransfermanager');
    }

    if ($oldversion < 2026050402) {
        $table = new xmldb_table('local_coursetransfermanager_tasks');

        $field = new xmldb_field('type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'restore_category');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('coursepattern', XMLDB_TYPE_CHAR, '255', null, null);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('restoreenrolments', XMLDB_TYPE_INTEGER, '1', null, null, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('restoregroups', XMLDB_TYPE_INTEGER, '1', null, null, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('createbackup', XMLDB_TYPE_INTEGER, '1', null, null, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('backupretentiondays', XMLDB_TYPE_INTEGER, '10', null, null, null, '90');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('targetcategoryid', XMLDB_TYPE_INTEGER, '10', null, null);
        $dbman->change_field_notnull($table, $field);

        $field = new xmldb_field('categorypattern', XMLDB_TYPE_CHAR, '255', null, null);
        $dbman->change_field_notnull($table, $field);

        upgrade_plugin_savepoint(true, 2026050402, 'local', 'coursetransfermanager');
    }

    if ($oldversion < 2026050805) {
        $table = new xmldb_table('local_coursetransfermanager_tasks');

        $field = new xmldb_field('categorytoken', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('transfertoken', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026050805, 'local', 'coursetransfermanager');
    }

    // Switch to origin-site reference, drop legacy site/token fields, add scheduling timestamps.
    if ($oldversion < 2026051301) {
        $table = new xmldb_table('local_coursetransfermanager_tasks');

        // Add new fields.
        $field = new xmldb_field('originsiteid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'name');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lastruntime', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('nextruntime', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Best-effort migration: try to match existing siteurl values to origin sites.
        if ($dbman->field_exists($table, new xmldb_field('siteurl'))) {
            $records = $DB->get_records('local_coursetransfermanager_tasks');
            foreach ($records as $record) {
                $host = !empty($record->siteurl) ? rtrim($record->siteurl, '/') : '';
                if ($host === '') {
                    continue;
                }
                $origin = $DB->get_record_select(
                    'local_coursetransfer_origin',
                    $DB->sql_compare_text('host') . ' = ' . $DB->sql_compare_text(':host'),
                    ['host' => $host],
                    'id',
                    IGNORE_MULTIPLE
                );
                if ($origin) {
                    $DB->set_field('local_coursetransfermanager_tasks', 'originsiteid', $origin->id, ['id' => $record->id]);
                }
            }
        }

        // Drop legacy fields.
        $legacy = [
            'siteurl', 'apitoken', 'categorytoken', 'transfertoken',
            'coursepattern', 'restoreenrolments', 'restoregroups',
            'createbackup', 'backupretentiondays',
        ];
        foreach ($legacy as $name) {
            $field = new xmldb_field($name);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }

        // Add scheduling index.
        $index = new xmldb_index('nextruntime_idx', XMLDB_INDEX_NOTUNIQUE, ['nextruntime']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Foreign key for originsiteid.
        $key = new xmldb_key('originsite_fk', XMLDB_KEY_FOREIGN, ['originsiteid'], 'local_coursetransfer_origin', ['id']);
        $dbman->add_key($table, $key);

        // Executions: index scheduleddeleteat to speed up cleanup sweep.
        $extable = new xmldb_table('local_coursetransfermanager_executions');
        $index = new xmldb_index('scheduleddeleteat_idx', XMLDB_INDEX_NOTUNIQUE, ['scheduleddeleteat']);
        if (!$dbman->index_exists($extable, $index)) {
            $dbman->add_index($extable, $index);
        }

        upgrade_plugin_savepoint(true, 2026051301, 'local', 'coursetransfermanager');
    }

    // 2.0.0 data model: task creator + per-task notifications, remote deletion lifecycle,
    // destination category tracking and two-phase pruning. Drops the dead remotekeepyears field.
    if ($oldversion < 2026072800) {
        // Tasks: creator and per-task notification settings.
        $table = new xmldb_table('local_coursetransfermanager_tasks');

        $field = new xmldb_field('usercreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('notifylevel', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'full', 'usercreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('notifyrecipients', XMLDB_TYPE_TEXT, null, null, null, null, null, 'notifylevel');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Backfill the creator with the main admin: existing tasks were configured by an admin
        // and someone must receive their lifecycle notifications from now on.
        $admin = get_admin();
        if ($admin) {
            $DB->set_field_select('local_coursetransfermanager_tasks', 'usercreated', $admin->id, 'usercreated = 0');
        }

        // The add_key call has no exists-guard: probe the backing index so a re-run does not duplicate it.
        $keyindex = new xmldb_index('usercreated_fk', XMLDB_INDEX_NOTUNIQUE, ['usercreated']);
        if (!$dbman->index_exists($table, $keyindex)) {
            $key = new xmldb_key('usercreated_fk', XMLDB_KEY_FOREIGN, ['usercreated'], 'user', ['id']);
            $dbman->add_key($table, $key);
        }

        // Drop the dead setting: nothing ever read remotekeepyears.
        $field = new xmldb_field('remotekeepyears');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Executions: manual launches, created category and remote deletion lifecycle.
        $table = new xmldb_table('local_coursetransfermanager_executions');

        $field = new xmldb_field('manualrun', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field(
            'destinationcategoryid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'origincategoryidnumber'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('deletestatus', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'scheduleddeleteat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('deletecancelledby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'deletestatus');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('deletecancelledat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'deletecancelledby');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('deletestatus_idx', XMLDB_INDEX_NOTUNIQUE, ['deletestatus']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('requestid_idx', XMLDB_INDEX_NOTUNIQUE, ['requestid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Backfill: successful executions with a pending scheduled deletion keep their schedule.
        // Executions with a null scheduleddeleteat stay null (already deleted or not applicable).
        $DB->set_field_select(
            'local_coursetransfermanager_executions',
            'deletestatus',
            'scheduled',
            "scheduleddeleteat IS NOT NULL AND status = 'success'"
        );

        // Two-phase pruning: candidates are announced with a grace period before deletion.
        $table = new xmldb_table('local_coursetransfermanager_prune');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('taskid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('categoryname', XMLDB_TYPE_CHAR, '255');
        $table->add_field('announcedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('graceuntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'announced');
        $table->add_field('excludedby', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('excludedat', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('task_fk', XMLDB_KEY_FOREIGN, ['taskid'], 'local_coursetransfermanager_tasks', ['id']);

        $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('categoryid_idx', XMLDB_INDEX_NOTUNIQUE, ['categoryid']);
        $table->add_index('graceuntil_idx', XMLDB_INDEX_NOTUNIQUE, ['graceuntil']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072800, 'local', 'coursetransfermanager');
    }

    // 2.1 rotation model: the pattern becomes a naming mask that recognises every
    // yearly category, and a conservation policy (years kept in the origin and in
    // the archive) decides what to archive and what to prune. Tasks no longer need
    // editing every course.
    if ($oldversion < 2026073100) {
        $table = new xmldb_table('local_coursetransfermanager_tasks');

        // P — academic years kept in the origin platform.
        $field = new xmldb_field(
            'originkeepyears',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '2',
            'retentiondays'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The pattern becomes a naming MASK: it no longer resolves to one year, it
        // recognises every yearly category. Existing patterns are normalised:
        // - regex anchors (^ $) were meaningful for the old exact match; as a mask
        // they would be literal characters, so they go.
        // - {PREVYEAR}-{YEAR} described "course starting the previous year"; as a
        // mask the starting year must be the first one, hence {YEAR}-{NEXTYEAR}.
        // Without this the whole policy would sit one year off.
        foreach ($DB->get_records('local_coursetransfermanager_tasks', null, '', 'id, categorypattern') as $task) {
            $mask = (string) $task->categorypattern;
            $mask = trim($mask);
            $mask = preg_replace('/^\^/', '', $mask);
            $mask = preg_replace('/\$$/', '', $mask);
            $mask = str_replace('{PREVYEAR}-{YEAR}', '{YEAR}-{NEXTYEAR}', $mask);
            $mask = str_replace('{PREVYEAR}/{YEAR}', '{YEAR}/{NEXTYEAR}', $mask);
            if ($mask !== (string) $task->categorypattern) {
                $DB->set_field('local_coursetransfermanager_tasks', 'categorypattern', $mask, ['id' => $task->id]);
            }
        }

        upgrade_plugin_savepoint(true, 2026073100, 'local', 'coursetransfermanager');
    }

    // The legacy single-year path was dropped: every task rotates by policy, so the
    // mode column no longer means anything.
    if ($oldversion < 2026073101) {
        $table = new xmldb_table('local_coursetransfermanager_tasks');
        $field = new xmldb_field('patternmode');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026073101, 'local', 'coursetransfermanager');
    }

    // Adoption: categories that reached the archive before the plugin existed are
    // invisible to the pruning by design (the safety rule that protects foreign
    // content). Adopting one is an explicit, audited decision.
    if ($oldversion < 2026073102) {
        $table = new xmldb_table('local_coursetransfermanager_adopted');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('taskid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('categoryname', XMLDB_TYPE_CHAR, '255');
        $table->add_field('adoptedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('task_fk', XMLDB_KEY_FOREIGN, ['taskid'], 'local_coursetransfermanager_tasks', ['id']);

        $table->add_index('categoryid_idx', XMLDB_INDEX_NOTUNIQUE, ['categoryid']);
        $table->add_index('taskcategory_idx', XMLDB_INDEX_UNIQUE, ['taskid', 'categoryid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026073102, 'local', 'coursetransfermanager');
    }

    if ($oldversion < 2026091500) {
        // The tables were named local_ctm_*, which does not carry the component
        // prefix Moodle expects. Renamed, keeping the data.
        $renames = [
            'local_ctm_tasks' => 'local_coursetransfermanager_tasks',
            'local_ctm_executions' => 'local_coursetransfermanager_executions',
            'local_ctm_adopted' => 'local_coursetransfermanager_adopted',
            'local_ctm_prune' => 'local_coursetransfermanager_prune',
        ];

        foreach ($renames as $oldname => $newname) {
            $oldtable = new xmldb_table($oldname);
            $newtable = new xmldb_table($newname);
            // Guarded both ways: a re-run, or a fresh install that never had the
            // old names, must be a no-op.
            if ($dbman->table_exists($oldtable) && !$dbman->table_exists($newtable)) {
                $dbman->rename_table($oldtable, $newname);
            }
        }

        upgrade_plugin_savepoint(true, 2026091500, 'local', 'coursetransfermanager');
    }

    return true;
}
