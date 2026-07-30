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
 * Administration settings for local_coursetransfermanager.
 *
 * The manager is an extension of CourseTransfer: its pages hang from the
 * CourseTransfer admin category so the whole family lives together in the
 * administration tree. When the category is not available (it always is,
 * given the hard dependency) it falls back to "Local plugins".
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    global $ADMIN, $CFG;

    // Plugin settings page (standard location: Plugins > Local plugins).
    $settings = new admin_settingpage(
        'local_coursetransfermanager',
        get_string('pluginname', 'local_coursetransfermanager')
    );

    $settings->add(new admin_setting_configtext(
        'local_coursetransfermanager/deletionwarningdays',
        get_string('setting_deletionwarningdays', 'local_coursetransfermanager'),
        get_string('setting_deletionwarningdays_desc', 'local_coursetransfermanager'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_coursetransfermanager/prunegracedays',
        get_string('setting_prunegracedays', 'local_coursetransfermanager'),
        get_string('setting_prunegracedays_desc', 'local_coursetransfermanager'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_coursetransfermanager/deletionspaused',
        get_string('setting_deletionspaused', 'local_coursetransfermanager'),
        get_string('setting_deletionspaused_desc', 'local_coursetransfermanager'),
        0
    ));

    $ADMIN->add('localplugins', $settings);

    // Hang the manager pages inside the CourseTransfer admin category (family).
    // Local plugin settings load in displayname order (locale-dependent), so
    // whichever plugin of the family loads first creates the shared category;
    // coursetransfer guards against the duplicate on its side.
    if (!$ADMIN->locate('local_coursetransfer_category')) {
        $ADMIN->add('modules', new admin_category(
            'local_coursetransfer_category',
            new lang_string('pluginname', 'local_coursetransfer')
        ));
    }

    $ADMIN->add('local_coursetransfer_category', new admin_externalpage(
        'local_coursetransfermanager_manage',
        get_string('managetasks', 'local_coursetransfermanager'),
        new moodle_url('/local/coursetransfermanager/manage.php'),
        'local/coursetransfermanager:managetasks'
    ));

    $ADMIN->add('local_coursetransfer_category', new admin_externalpage(
        'local_coursetransfermanager_config',
        get_string('settings_link', 'local_coursetransfermanager'),
        $CFG->wwwroot . '/admin/settings.php?section=local_coursetransfermanager'
    ));
}
