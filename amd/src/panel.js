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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Management panel interactions: task switch, actions menu, run-now flow
 * and cancellation of pending deletions/prunings (always confirmed).
 *
 * @module     local_coursetransfermanager/panel
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/ajax',
    'core/notification',
    'core/str',
    'core/modal_save_cancel',
    'core/modal_cancel',
    'core/modal_events',
], function(Ajax, Notification, Str, ModalSaveCancel, ModalCancel, ModalEvents) {

    /**
     * Call one of the plugin AJAX web services.
     *
     * @param {String} method Function name suffix.
     * @param {Object} args Arguments.
     * @return {Promise}
     */
    const call = function(method, args) {
        return Ajax.call([{
            methodname: 'local_coursetransfermanager_' + method,
            args: args,
        }])[0];
    };

    /**
     * Toggle a task on/off and update its card.
     *
     * @param {HTMLElement} root Panel root.
     * @param {HTMLElement} button The switch.
     */
    const toggleTask = function(root, button) {
        const taskid = parseInt(button.dataset.taskid, 10);
        const enabled = button.getAttribute('aria-checked') !== 'true';
        button.disabled = true;

        call('task_toggle', {taskid: taskid, enabled: enabled}).then(function(response) {
            const card = button.closest('[data-region="task"]');
            button.setAttribute('aria-checked', response.enabled ? 'true' : 'false');
            card.classList.toggle('ct-taskcard--off', !response.enabled);
            const nextrun = card.querySelector('[data-region="task-nextrun"]');
            if (nextrun) {
                nextrun.textContent = response.nextrun;
                nextrun.classList.toggle('ct-text-muted', !response.enabled);
            }
            return Str.get_string(response.enabled ? 'card_active' : 'card_inactive',
                'local_coursetransfermanager');
        }).then(function(label) {
            const labelnode = button.querySelector('[data-region="switch-label"]');
            if (labelnode) {
                labelnode.textContent = label;
            }
            button.disabled = false;
            return null;
        }).catch(function(error) {
            button.disabled = false;
            Notification.exception(error);
        });
    };

    /**
     * Run-now flow: precheck (blocked?) → confirm → launch.
     *
     * @param {HTMLElement} button The menu item.
     */
    const runNow = function(button) {
        const taskid = parseInt(button.dataset.taskid, 10);
        const taskname = button.dataset.taskname;

        call('task_run_now', {taskid: taskid, precheck: true}).then(function(check) {
            if (check.blocked) {
                return Str.get_strings([
                    {key: 'runnow_blocked_title', component: 'local_coursetransfermanager'},
                    {
                        key: 'runnow_blocked_body',
                        component: 'local_coursetransfermanager',
                        param: {name: taskname, date: check.lastsuccess},
                    },
                ]).then(function(strings) {
                    return ModalCancel.create({
                        title: strings[0],
                        body: strings[1],
                        show: true,
                    });
                });
            }
            return Str.get_strings([
                {key: 'runnow_confirm_title', component: 'local_coursetransfermanager', param: taskname},
                {key: 'runnow_confirm_body', component: 'local_coursetransfermanager', param: check.nextrun},
                {key: 'runnow', component: 'local_coursetransfermanager'},
            ]).then(function(strings) {
                return ModalSaveCancel.create({
                    title: strings[0],
                    body: strings[1],
                    buttons: {save: strings[2]},
                    show: true,
                }).then(function(modal) {
                    modal.getRoot().on(ModalEvents.save, function() {
                        launch(taskid);
                    });
                    return modal;
                });
            });
        }).catch(Notification.exception);
    };

    /**
     * Actually launch the task and report the outcome.
     *
     * @param {Number} taskid Task id.
     */
    const launch = function(taskid) {
        call('task_run_now', {taskid: taskid, precheck: false}).then(function(result) {
            if (result.launched) {
                return Str.get_string('runnow_launched', 'local_coursetransfermanager').then(function(message) {
                    Notification.addNotification({message: message, type: 'success'});
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 1200);
                    return null;
                });
            }
            return Str.get_string('runnow_failed', 'local_coursetransfermanager', result.error)
                .then(function(message) {
                    Notification.addNotification({message: message, type: 'error'});
                    return null;
                });
        }).catch(Notification.exception);
    };

    /**
     * Confirm and cancel a pending deletion (or exclude a pruning candidate).
     *
     * @param {HTMLElement} button Action button in the agenda row.
     * @param {Boolean} isprune True for pruning exclusions.
     */
    const cancelDeletion = function(button, isprune) {
        const category = button.dataset.category;

        Str.get_strings([
            {
                key: isprune ? 'exclude_modal_title' : 'cancel_modal_title',
                component: 'local_coursetransfermanager',
            },
            {
                key: isprune ? 'exclude_modal_body' : 'cancel_modal_body',
                component: 'local_coursetransfermanager',
                param: category,
            },
            {
                key: isprune ? 'exclude_confirm' : 'cancel_confirm',
                component: 'local_coursetransfermanager',
            },
        ]).then(function(strings) {
            return ModalSaveCancel.create({
                title: strings[0],
                body: strings[1],
                buttons: {save: strings[2]},
                show: true,
            }).then(function(modal) {
                modal.getRoot().on(ModalEvents.save, function() {
                    const promise = isprune
                        ? call('prune_exclude', {pruneid: parseInt(button.dataset.pruneid, 10)})
                        : call('deletion_cancel', {executionid: parseInt(button.dataset.executionid, 10)});
                    promise.then(function(response) {
                        markCancelled(button, response.audit);
                        return null;
                    }).catch(Notification.exception);
                });
                return modal;
            });
        }).catch(Notification.exception);
    };

    /**
     * Replace the action button of a cancelled row with the audit trail.
     *
     * @param {HTMLElement} button The action button.
     * @param {String} audit "Cancelled by … · date".
     */
    const markCancelled = function(button, audit) {
        const row = button.closest('.ct-agenda-row');
        Str.get_string('agenda_cancelled_label', 'local_coursetransfermanager').then(function(label) {
            if (row) {
                row.classList.remove('ct-agenda-row--danger', 'ct-agenda-row--warning');
                row.classList.add('ct-agenda-row--cancelled');
                const badge = row.querySelector('.ct-badge');
                if (badge) {
                    badge.className = 'ct-badge ct-badge--cancelled';
                    badge.textContent = label;
                }
                const actions = row.querySelector('.ct-agenda-actions');
                if (actions) {
                    actions.innerHTML = '<span class="ct-agenda-audit"><i class="fa fa-check" aria-hidden="true"></i> '
                        + audit + '</span>';
                }
            }
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Wire every panel interaction from the root node.
     *
     * @param {String} rootid Panel root id.
     */
    const init = function(rootid) {
        const root = document.getElementById(rootid);
        if (!root) {
            return;
        }

        root.addEventListener('click', function(event) {
            const actionnode = event.target.closest('[data-action]');
            if (!actionnode || !root.contains(actionnode)) {
                return;
            }
            const action = actionnode.dataset.action;

            if (action === 'agenda-toggle') {
                const body = root.querySelector('[data-region="agenda-body"]');
                const expanded = actionnode.getAttribute('aria-expanded') === 'true';
                actionnode.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                if (body) {
                    body.hidden = expanded;
                }
            } else if (action === 'filters-toggle') {
                const filters = root.querySelector('[data-region="filters"]');
                const expanded = actionnode.getAttribute('aria-expanded') === 'true';
                actionnode.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                if (filters) {
                    filters.hidden = expanded;
                }
            } else if (action === 'kebab-toggle') {
                const menu = actionnode.parentNode.querySelector('[data-region="kebab-menu"]');
                const expanded = actionnode.getAttribute('aria-expanded') === 'true';
                closeMenus(root);
                if (!expanded && menu) {
                    actionnode.setAttribute('aria-expanded', 'true');
                    menu.hidden = false;
                }
            } else if (action === 'toggle-task') {
                toggleTask(root, actionnode);
            } else if (action === 'run-now') {
                closeMenus(root);
                runNow(actionnode);
            } else if (action === 'cancel-deletion') {
                cancelDeletion(actionnode, false);
            } else if (action === 'exclude-prune') {
                cancelDeletion(actionnode, true);
            }
        });

        // Close the actions menus when clicking anywhere else.
        document.addEventListener('click', function(event) {
            if (!event.target.closest('[data-region="kebab"]')) {
                closeMenus(root);
            }
        });

        // Open the agenda by default when it carries pending deletions.
        const firstpending = root.querySelector('.ct-agenda-row--danger, .ct-agenda-row--warning');
        if (firstpending) {
            const head = root.querySelector('[data-action="agenda-toggle"]');
            const body = root.querySelector('[data-region="agenda-body"]');
            if (head && body) {
                head.setAttribute('aria-expanded', 'true');
                body.hidden = false;
            }
        }
    };

    /**
     * Close every open actions menu.
     *
     * @param {HTMLElement} root Panel root.
     */
    const closeMenus = function(root) {
        root.querySelectorAll('[data-region="kebab-menu"]').forEach(function(menu) {
            menu.hidden = true;
        });
        root.querySelectorAll('[data-action="kebab-toggle"]').forEach(function(btn) {
            btn.setAttribute('aria-expanded', 'false');
        });
    };

    return {init: init};
});
