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
 * Task lifecycle view: adopting or releasing an archive category.
 *
 * Adoption changes who may delete what, so it always goes through an explicit
 * confirmation that says out loud what will change (Nielsen 3 and 5).
 *
 * @module     local_coursetransfermanager/task_view
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/ajax',
    'core/notification',
    'core/str',
], function(Ajax, Notification, Str) {

    var root = null;
    var taskid = 0;

    /**
     * Send the adoption change and reload the page.
     *
     * @param {Number} categoryid Category id.
     * @param {Boolean} adopt True to adopt, false to stop managing.
     */
    var applyAdoption = function(categoryid, adopt) {
        Ajax.call([{
            methodname: 'local_coursetransfermanager_set_adoption',
            args: {taskid: taskid, categoryid: categoryid, adopt: adopt},
        }])[0].then(function() {
            // The lists, the projection and the pruning all change at once:
            // a reload is the honest way to show the new state.
            window.location.reload();
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Adopt or release one category, after confirming it.
     *
     * @param {Number} categoryid Category id.
     * @param {String} categoryname Category name, for the confirmation.
     * @param {Boolean} adopt True to adopt, false to stop managing.
     */
    var setAdoption = function(categoryid, categoryname, adopt) {
        Str.get_strings([
            {key: adopt ? 'view_adopt' : 'view_unadopt', component: 'local_coursetransfermanager'},
            {key: adopt ? 'view_adopt_confirm' : 'view_unadopt_confirm',
                component: 'local_coursetransfermanager', param: categoryname},
            {key: adopt ? 'view_adopt' : 'view_unadopt', component: 'local_coursetransfermanager'},
            {key: 'cancel', component: 'moodle'},
        ]).then(function(strings) {
            return Notification.confirm(strings[0], strings[1], strings[2], strings[3], function() {
                applyAdoption(categoryid, adopt);
            });
        }).catch(Notification.exception);
    };

    return {
        /**
         * Boot the view.
         *
         * @param {String} rootid Root node id.
         */
        init: function(rootid) {
            root = document.getElementById(rootid);
            if (!root) {
                return;
            }
            taskid = parseInt(root.dataset.taskid, 10) || 0;

            root.addEventListener('click', function(event) {
                var node = event.target.closest('[data-action]');
                if (!node || !root.contains(node)) {
                    return;
                }
                var action = node.dataset.action;
                if (action !== 'adopt' && action !== 'unadopt') {
                    return;
                }
                event.preventDefault();
                setAdoption(parseInt(node.dataset.categoryid, 10),
                    node.dataset.categoryname, action === 'adopt');
            });
        },
    };
});
