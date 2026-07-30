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
 * Tracking screen interactions: tabs, live auto-refresh, error expansion
 * and cancellation of pending deletions/prunings (always confirmed).
 *
 * @module     local_coursetransfermanager/executions
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/ajax',
    'core/notification',
    'core/str',
    'core/modal_save_cancel',
    'core/modal_events',
], function(Ajax, Notification, Str, ModalSaveCancel, ModalEvents) {

    var root = null;
    var seconds = 0;

    var call = function(method, args) {
        return Ajax.call([{
            methodname: 'local_coursetransfermanager_' + method,
            args: args,
        }])[0];
    };

    /**
     * Show one tab, hide the rest, and keep it in the URL.
     *
     * @param {String} name Tab name: live | deletions | history.
     * @param {Boolean} push Whether to update the URL.
     */
    var showTab = function(name, push) {
        ['live', 'deletions', 'history'].forEach(function(tab) {
            var section = root.querySelector('[data-region="tab-' + tab + '"]');
            if (section) {
                section.hidden = (tab !== name);
            }
        });
        root.querySelectorAll('[data-action="tab"]').forEach(function(button) {
            button.setAttribute('aria-selected', button.dataset.tab === name ? 'true' : 'false');
        });
        if (push && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', name);
            window.history.replaceState(null, '', url.toString());
        }
    };

    /**
     * "Updated N seconds ago" ticker + gentle auto-refresh while there are
     * live executions and the live tab is visible.
     */
    var startTicker = function() {
        var label = root.querySelector('[data-region="refreshed"]');
        if (!label) {
            return;
        }
        var haslive = !!root.querySelector('.ct-livecard');
        window.setInterval(function() {
            seconds += 1;
            Str.get_string('exec_refreshed', 'local_coursetransfermanager', seconds)
                .then(function(text) {
                    label.textContent = text;
                    return null;
                }).catch(function() {
                    return null;
                });
            var livevisible = !root.querySelector('[data-region="tab-live"]').hidden;
            if (haslive && livevisible && seconds >= 60 && !document.hidden) {
                window.location.reload();
            }
        }, 1000);
    };

    /**
     * Confirm and cancel a pending deletion (or exclude a pruning candidate).
     *
     * @param {HTMLElement} button Action button.
     * @param {Boolean} isprune True for pruning exclusions.
     */
    var cancelDeletion = function(button, isprune) {
        var category = button.dataset.category;

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
                    var promise = isprune
                        ? call('prune_exclude', {pruneid: parseInt(button.dataset.pruneid, 10)})
                        : call('deletion_cancel', {executionid: parseInt(button.dataset.executionid, 10)});
                    promise.then(function(response) {
                        var card = button.closest('.ct-delcard');
                        var actions = card.querySelector('[data-region="delcard-actions"]');
                        card.classList.add('ct-delcard--cancelled');
                        actions.innerHTML = '<span class="ct-agenda-audit">'
                            + '<i class="fa fa-check" aria-hidden="true"></i> '
                            + response.audit + '</span>';
                        return null;
                    }).catch(Notification.exception);
                });
                return modal;
            });
        }).catch(Notification.exception);
    };

    /**
     * Boot the tracking screen.
     *
     * @param {String} rootid Root node id.
     */
    var init = function(rootid) {
        root = document.getElementById(rootid);
        if (!root) {
            return;
        }

        var params = new URLSearchParams(window.location.search);
        var initial = params.get('tab');
        if (['live', 'deletions', 'history'].indexOf(initial) === -1) {
            initial = 'live';
        }
        showTab(initial, false);

        root.addEventListener('click', function(event) {
            var node = event.target.closest('[data-action]');
            if (!node) {
                return;
            }
            var action = node.dataset.action;
            if (action === 'tab') {
                showTab(node.dataset.tab, true);
            } else if (action === 'cancel-deletion') {
                cancelDeletion(node, false);
            } else if (action === 'exclude-prune') {
                cancelDeletion(node, true);
            } else if (action === 'toggle-error') {
                var row = node.closest('[data-region="hist-row"]');
                var detail = row.querySelector('[data-region="hist-error"]');
                var expanded = node.getAttribute('aria-expanded') === 'true';
                node.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                if (detail) {
                    detail.hidden = expanded;
                }
            } else if (action === 'page') {
                event.preventDefault();
                var url = new URL(window.location.href);
                url.searchParams.set('page', node.dataset.page);
                url.searchParams.set('tab', 'history');
                window.location.assign(url.toString());
            }
        });

        startTicker();
    };

    return {init: init};
});
