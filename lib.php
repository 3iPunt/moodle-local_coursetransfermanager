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
 * Navigation hooks for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @author     3IPUNT <contacte@tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add the manager shortcuts to Site administration > Courses.
 *
 * The manager is an extension of CourseTransfer: its links join the
 * "coursetransfer_shortcuts" container that local_coursetransfer creates
 * there, so the whole family appears together. Falls back to the Courses
 * node when the container is not present.
 *
 * @param settings_navigation $navigation The settings navigation tree.
 * @param context $context The current context.
 * @return void
 */
function local_coursetransfermanager_extend_settings_navigation(settings_navigation $navigation, context $context): void {
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return;
    }
    if (!has_capability('local/coursetransfermanager:managetasks', context_system::instance())) {
        return;
    }

    $label = get_string('managetasks', 'local_coursetransfermanager');
    $url = new moodle_url('/local/coursetransfermanager/manage.php');
    $icon = new pix_icon('i/calendar', $label);

    // Join the CourseTransfer shortcuts container (local plugins run alphabetically,
    // so coursetransfer has already created it when this hook runs).
    $container = $navigation->find('coursetransfer_shortcuts', navigation_node::TYPE_CONTAINER);
    if ($container) {
        $container->add($label, $url, navigation_node::TYPE_SETTING, null, 'coursetransfermanager_manage', $icon);
        return;
    }

    $coursesnode = $navigation->find('courses', navigation_node::TYPE_SETTING);
    if ($coursesnode) {
        $coursesnode->add($label, $url, navigation_node::TYPE_SETTING, null, 'coursetransfermanager_manage', $icon);
    }
}
