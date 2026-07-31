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
 * Task wizard: step navigation, live pattern test, schedule preview,
 * retention consequences, recipients and the reviewed, confirmed save.
 *
 * @module     local_coursetransfermanager/task_wizard
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/ajax',
    'core/notification',
    'core/str',
    'core/templates',
], function(Ajax, Notification, Str, Templates) {

    var root = null;
    var cfg = null;
    var step = 1;
    var state = null;
    var preview = {firstrun: 0, valid: false};
    var lifecycletimer = null;

    var call = function(method, args) {
        return Ajax.call([{
            methodname: 'local_coursetransfermanager_' + method,
            args: args,
        }])[0];
    };

    var region = function(name) {
        return root.querySelector('[data-region="' + name + '"]');
    };

    var esc = function(text) {
        var div = document.createElement('div');
        div.textContent = text === null || text === undefined ? '' : String(text);
        return div.innerHTML;
    };

    var fmtDate = function(timestamp) {
        return new Date(timestamp * 1000).toLocaleDateString(document.documentElement.lang || 'es', {
            day: 'numeric', month: 'long', year: 'numeric',
        });
    };

    // ---- Steps -----------------------------------------------------------

    var showStep = function(target) {
        step = target;
        [1, 2, 3, 4, 5].forEach(function(number) {
            var panel = region('step-' + number);
            panel.hidden = (number !== target);
        });
        root.querySelectorAll('.ct-step').forEach(function(button) {
            var number = parseInt(button.dataset.step, 10);
            button.classList.toggle('ct-step--active', number === target);
            button.classList.toggle('ct-step--done', number < target);
            if (number === target) {
                button.setAttribute('aria-current', 'step');
            } else {
                button.removeAttribute('aria-current');
            }
        });
        root.querySelector('[data-action="step-back"]').hidden = (target === 1);
        root.querySelector('[data-action="step-next"]').hidden = (target === 5);
        root.querySelector('[data-action="save"]').hidden = (target !== 5);
        if (target === 3) {
            refreshSchedule();
        }
        if (target === 4) {
            refreshRetention();
        }
        if (target === 5) {
            renderReview();
        }
    };

    var stepValid = function(number) {
        if (number === 1) {
            if (state.name.trim() === '' || !state.originsiteid) {
                return false;
            }
            // A mask without a year placeholder cannot rotate anything.
            return /\{(YEAR|YY)\}/.test(state.categorypattern);
        }
        if (number === 2) {
            return !!state.targetcategory;
        }
        return true;
    };

    // ---- Step 1: origin --------------------------------------------------

    var refreshSiteCard = function() {
        var select = document.getElementById('ctm-site');
        var option = select.options[select.selectedIndex];
        var card = region('sitecard');
        if (!option || option.value === '0') {
            card.hidden = true;
            return;
        }
        card.hidden = false;
        region('sitecard-host').textContent = option.dataset.host;
        var strings = [];
        if (option.dataset.testrelative) {
            strings.push({
                key: 'wz_last_test', component: 'local_coursetransfermanager',
                param: option.dataset.testrelative,
            });
        } else {
            strings.push({key: 'wz_no_test', component: 'local_coursetransfermanager'});
        }
        strings.push({key: option.dataset.testko === '1' ? 'wz_test_ko' : 'wz_test_ok',
            component: 'local_coursetransfermanager'});
        Str.get_strings(strings).then(function(results) {
            region('sitecard-test').textContent = results[0];
            var known = option.dataset.testok === '1' || option.dataset.testko === '1';
            region('sitecard-badge').innerHTML = known
                ? '<span class="ct-badge ' + (option.dataset.testko === '1' ? 'ct-badge--error' : 'ct-badge--completed')
                    + '">' + esc(results[1]) + '</span>'
                : '';
            return null;
        }).catch(Notification.exception);
    };

    var refreshPatternHelp = function() {
        var help = region('pattern-help');
        var valid = /\{(YEAR|YY)\}/.test(state.categorypattern);
        var request = valid
            ? {key: 'wz_pattern_help', component: 'local_coursetransfermanager',
                param: {year: resolvePattern(state.categorypattern), previous:
                    resolvePattern(state.categorypattern, currentYear() - 1)}}
            : {key: 'wz_pattern_help_invalid', component: 'local_coursetransfermanager'};
        Str.get_string(request.key, request.component, request.param)
            .then(function(text) {
                help.textContent = text;
                help.classList.toggle('ct-text-danger', !valid);
                return null;
            }).catch(Notification.exception);
    };

    /**
     * Render the mask for one academic year, mirroring academic_year::example().
     *
     * @param {String} pattern Naming mask.
     * @param {Number} year Starting academic year.
     * @return {String} Idnumber the mask produces for that year.
     */
    var resolvePattern = function(pattern, year) {
        var start = year || currentYear();
        return pattern
            .split('{YEAR}').join(start)
            .split('{NEXTYEAR}').join(start + 1)
            .split('{YY}').join(String(start).slice(-2))
            .split('{NEXTYY}').join(String(start + 1).slice(-2))
            .split('{ANY}').join('')
            .split('{DIGITS}').join('');
    };

    /**
     * Academic year in progress: before the start month the year is still the previous one.
     *
     * @return {Number} Starting academic year.
     */
    var currentYear = function() {
        if (cfg && cfg.currentyear) {
            return cfg.currentyear;
        }
        var now = new Date();
        var startmonth = (cfg && cfg.startmonth) || 9;
        return now.getMonth() + 1 < startmonth ? now.getFullYear() - 1 : now.getFullYear();
    };

    /**
     * Ask the origin which yearly categories the mask recognises.
     *
     * The mask is not expected to match one category: it must recognise the whole
     * series, because the rotation decides year by year which one to archive.
     */
    var testPattern = function() {
        var result = region('pattern-result');
        Str.get_string('wz_pat_loading', 'local_coursetransfermanager').then(function(loading) {
            result.innerHTML = '<div class="ct-patbox"><i class="fa fa-spinner fa-spin" aria-hidden="true"></i> '
                + esc(loading) + '</div>';
            return call('test_pattern', {siteid: state.originsiteid, pattern: state.categorypattern});
        }).then(function(response) {
            var keys = {
                ok: ['wz_pat_ok', 'wz_pat_ok_hint'],
                none: ['wz_pat_none', 'wz_pat_none_hint'],
                down: ['wz_pat_down', 'wz_pat_down_hint'],
                invalid: ['wz_pat_invalid', 'wz_pat_invalid_hint'],
            }[response.status] || ['wz_pat_invalid', 'wz_pat_invalid_hint'];
            var params = {
                ok: [response.matchcount, null],
                none: [null, response.example],
                down: [null, response.message],
                invalid: [null, null],
            }[response.status] || [null, null];

            return Str.get_strings([
                {key: keys[0], component: 'local_coursetransfermanager', param: params[0]},
                {key: keys[1], component: 'local_coursetransfermanager', param: params[1]},
                {key: 'wz_pat_courses', component: 'local_coursetransfermanager'},
                {key: 'wz_pat_more', component: 'local_coursetransfermanager',
                    param: Math.max(0, response.matchcount - response.categories.length)},
            ]).then(function(strings) {
                var tone = response.status === 'ok' ? 'success'
                    : (response.status === 'none' ? 'warning' : 'danger');
                var detail = '';
                if (response.categories.length) {
                    detail = '<ul class="ct-patbox-list">'
                        + response.categories.map(function(category) {
                            return '<li><span class="ct-badge ct-badge--neutral">' + esc(category.label)
                                + '</span> <code>' + esc(category.idnumber) + '</code> '
                                + '<span class="ct-text-muted">' + esc(category.name) + ' · '
                                + category.courses + ' ' + esc(strings[2]) + '</span></li>';
                        }).join('')
                        + '</ul>';
                    if (response.matchcount > response.categories.length) {
                        detail += '<p class="ct-text-muted">' + esc(strings[3]) + '</p>';
                    }
                }
                result.innerHTML = '<div class="ct-patbox ct-patbox--' + tone + '"><strong>' + esc(strings[0])
                    + '</strong>' + detail + '<p>' + esc(strings[1]) + '</p></div>';
                return null;
            });
        }).catch(Notification.exception);
    };

    // ---- Step 2: destination --------------------------------------------

    var wireAutocomplete = function(inputid, dropregion, searcher, renderer, onpick) {
        var input = document.getElementById(inputid);
        var drop = region(dropregion);
        var timer = null;

        input.addEventListener('input', function() {
            window.clearTimeout(timer);
            var query = input.value.trim();
            if (query.length < 2) {
                drop.hidden = true;
                return;
            }
            timer = window.setTimeout(function() {
                Str.get_string('wz_searching', 'local_coursetransfermanager').then(function(text) {
                    drop.hidden = false;
                    drop.innerHTML = '<div class="ct-ac-note">' + esc(text) + '</div>';
                    return searcher(query);
                }).then(function(items) {
                    if (!items.length) {
                        return Str.get_string('wz_noresults', 'local_coursetransfermanager', query)
                            .then(function(empty) {
                                drop.innerHTML = '<div class="ct-ac-note">' + esc(empty) + '</div>';
                                return null;
                            });
                    }
                    drop.innerHTML = items.map(renderer).join('');
                    drop.querySelectorAll('[data-pick]').forEach(function(node) {
                        node.addEventListener('click', function() {
                            onpick(JSON.parse(node.dataset.pick));
                            drop.hidden = true;
                        });
                    });
                    return null;
                }).catch(Notification.exception);
            }, 350);
        });

        document.addEventListener('click', function(event) {
            if (!event.target.closest('#' + inputid) && !drop.contains(event.target)) {
                drop.hidden = true;
            }
        });
    };

    var refreshDestPreview = function() {
        var box = region('dest-preview');
        if (!state.targetcategory) {
            box.hidden = true;
            document.querySelector('[data-action="dest-clear"]').hidden = true;
            return;
        }
        box.hidden = false;
        document.querySelector('[data-action="dest-clear"]').hidden = false;
        Str.get_string('wz_dest_preview_line', 'local_coursetransfermanager',
            resolvePattern(state.categorypattern)).then(function(line) {
            region('dest-preview-body').innerHTML = '<strong>' + esc(state.targetcategory.path
                || state.targetcategory.name) + '</strong><br>└ ' + esc(line);
            return null;
        }).catch(Notification.exception);
    };

    var refreshOptButtons = function() {
        root.querySelectorAll('[data-action="set-userdata"]').forEach(function(button) {
            var active = (button.dataset.value === '1') === state.restoreuserdata;
            button.classList.toggle('ct-opt--active', active);
        });
        region('userdata-note').hidden = !state.restoreuserdata;
        root.querySelectorAll('[data-action="set-level"]').forEach(function(button) {
            button.classList.toggle('ct-opt--active', button.dataset.value === state.notifylevel);
        });
    };

    // ---- Step 3: schedule -------------------------------------------------

    var buildCron = function() {
        if (state.schedmode === 'cron') {
            return state.cronexpression;
        }
        return '0 ' + state.hour + ' ' + state.day + ' ' + state.month + ' *';
    };

    var refreshSchedule = function() {
        var cron = buildCron();
        call('schedule_preview', {cronexpression: cron}).then(function(response) {
            preview = response;
            var box = region('sched-preview');
            if (!response.valid) {
                box.hidden = true;
                region('summer-warning').hidden = true;
                return Str.get_string('invalidcron', 'local_coursetransfermanager').then(function(text) {
                    box.hidden = false;
                    region('sched-preview-body').innerHTML = '<div class="ct-patbox ct-patbox--danger">'
                        + esc(text) + '</div>';
                    return null;
                });
            }
            box.hidden = false;
            region('sched-preview-body').innerHTML = response.runs.map(function(run) {
                return '<div class="ct-runrow"><span class="ct-runrow-dot" aria-hidden="true"></span>'
                    + '<strong>' + esc(run.date) + '</strong> <span class="ct-text-muted">· '
                    + esc(run.relative) + '</span></div>';
            }).join('');
            region('summer-warning').hidden = !response.summer;
            return null;
        }).catch(Notification.exception);
    };

    // ---- Step 4: retentions ------------------------------------------------

    var refreshRetention = function() {
        var host = selectedHost();
        Str.get_strings([
            {key: 'wz_ret_origin_desc', component: 'local_coursetransfermanager', param: host},
        ]).then(function(strings) {
            region('ret-origin-desc').textContent = strings[0];
            return null;
        }).catch(Notification.exception);

        var originbox = region('ret-origin-box');
        var archivebox = region('ret-archive-box');
        var policybox = region('ret-policy-box');

        // Both years feed the same formula, so half a policy has no readable
        // meaning: say it instead of printing "keeps 0 years, survives 2".
        if (state.originkeepyears < 1 || state.destinationkeepyears < 1) {
            Str.get_string('wz_years_invalid', 'local_coursetransfermanager').then(function(text) {
                policybox.textContent = text;
                archivebox.textContent = text;
                policybox.classList.add('ct-text-danger');
                archivebox.classList.add('ct-text-danger');
                return null;
            }).catch(Notification.exception);
            region('ret-short').hidden = state.retentiondays >= 15;
            refreshLifecycle();
            return;
        }
        policybox.classList.remove('ct-text-danger');
        archivebox.classList.remove('ct-text-danger');

        // The policy is pure arithmetic on the academic year, not on the run date:
        // production keeps the P newest courses, the archive the V before those.
        var current = currentYear();
        var kept = [];
        for (var year = current; year > current - state.originkeepyears; year--) {
            kept.push(yearLabel(year));
        }
        var archiving = current - state.originkeepyears;
        var pruning = archiving - state.destinationkeepyears;

        var requests = [
            {
                key: 'wz_ret_policy_box', component: 'local_coursetransfermanager',
                param: {years: state.originkeepyears, kept: kept.join(', '),
                    archiving: yearLabel(archiving), idnumber: resolvePattern(state.categorypattern, archiving)},
            },
            {
                key: 'wz_ret_archive_box', component: 'local_coursetransfermanager',
                param: {years: state.destinationkeepyears, cutoff: yearLabel(pruning),
                    grace: cfg.gracedays, total: state.originkeepyears + state.destinationkeepyears},
            },
        ];
        if (preview.valid && preview.firstrun) {
            var deletion = preview.firstrun + state.retentiondays * 86400;
            requests.push({
                key: 'wz_ret_origin_box', component: 'local_coursetransfermanager',
                param: {first: fmtDate(preview.firstrun), deletion: fmtDate(deletion),
                    warning: fmtDate(deletion - cfg.warningdays * 86400), days: cfg.warningdays},
            });
        } else {
            originbox.textContent = '';
        }

        Str.get_strings(requests).then(function(strings) {
            policybox.textContent = strings[0];
            archivebox.textContent = strings[1];
            if (strings.length > 2) {
                originbox.textContent = strings[2];
            }
            return null;
        }).catch(Notification.exception);

        region('ret-short').hidden = state.retentiondays >= 15;
        refreshLifecycle();
    };

    /**
     * Academic year as the admin reads it: 2026 is "2026/27".
     *
     * @param {Number} year Starting academic year.
     * @return {String} Human readable label.
     */
    var yearLabel = function(year) {
        return year + '/' + String(year + 1).slice(-2);
    };

    /**
     * Year by year projection of the policy, straight from the engine.
     *
     * Recalculated server side on purpose: the table has to say what the task will
     * really do, not what the browser guesses it will do.
     */
    var refreshLifecycle = function() {
        var box = region('lifecycle-table');
        window.clearTimeout(lifecycletimer);
        lifecycletimer = window.setTimeout(function() {
            call('policy_preview', {
                siteid: state.originsiteid,
                pattern: state.categorypattern,
                originkeepyears: state.originkeepyears,
                destinationkeepyears: state.destinationkeepyears,
                targetcategoryid: state.targetcategory ? state.targetcategory.id : 0,
                // No remote call here: this runs on every keystroke, and the
                // origin is already inspected by "Test the mask in the origin".
                withorigin: false,
                // Editing an existing task: mark the years already archived.
                taskid: cfg.taskid,
            }).then(function(response) {
                return Templates.render('local_coursetransfermanager/components/lifecycle_table', response);
            }).then(function(html) {
                box.innerHTML = html;
                return null;
            }).catch(Notification.exception);
        }, 300);
    };

    var selectedHost = function() {
        var select = document.getElementById('ctm-site');
        var option = select.options[select.selectedIndex];
        return option && option.value !== '0' ? option.dataset.host : '-';
    };

    // ---- Recipients ---------------------------------------------------------

    var renderChips = function() {
        var box = region('recipient-chips');
        Str.get_string('wz_creator_fixed', 'local_coursetransfermanager', cfg.creatorname)
            .then(function(creator) {
                var html = '<span class="ct-chip ct-chip--fixed">' + esc(creator) + '</span>';
                state.recipients.forEach(function(user, index) {
                    html += '<span class="ct-chip">' + esc(user.fullname)
                        + ' <button type="button" class="ct-chip-x" data-removerecipient="' + index
                        + '" aria-label="' + esc(user.fullname) + '">&times;</button></span>';
                });
                box.innerHTML = html;
                box.querySelectorAll('[data-removerecipient]').forEach(function(button) {
                    button.addEventListener('click', function() {
                        state.recipients.splice(parseInt(button.dataset.removerecipient, 10), 1);
                        renderChips();
                    });
                });
                return null;
            }).catch(Notification.exception);
    };

    // ---- Step 5: review ------------------------------------------------------

    var renderReview = function() {
        var resolved = resolvePattern(state.categorypattern);
        var host = selectedHost();
        var deletion = preview.firstrun ? preview.firstrun + state.retentiondays * 86400 : 0;
        var warning = deletion ? deletion - cfg.warningdays * 86400 : 0;

        Str.get_strings([
            {key: 'card_origin', component: 'local_coursetransfermanager'},
            {key: 'card_destination', component: 'local_coursetransfermanager'},
            {key: 'wz_step3', component: 'local_coursetransfermanager'},
            {key: 'wz_step4', component: 'local_coursetransfermanager'},
            {key: 'wz_rev_origin', component: 'local_coursetransfermanager',
                param: {idnumber: resolved, pattern: state.categorypattern}},
            {key: state.restoreuserdata ? 'wz_copy_users' : 'wz_copy_courses',
                component: 'local_coursetransfermanager'},
            {key: 'wz_rev_first', component: 'local_coursetransfermanager',
                param: preview.firstrun ? fmtDate(preview.firstrun) : '-'},
            {key: 'wz_rev_ret', component: 'local_coursetransfermanager',
                param: {days: state.retentiondays, years: state.destinationkeepyears,
                    production: state.originkeepyears}},
            {key: state.notifylevel === 'full' ? 'wz_level_full' : 'wz_level_essential',
                component: 'local_coursetransfermanager'},
            {key: 'wz_confirm_summary', component: 'local_coursetransfermanager',
                param: {category: resolved, host: host,
                    deletion: deletion ? fmtDate(deletion) : '-',
                    warning: warning ? fmtDate(warning) : '-'}},
        ]).then(function(s) {
            var cards = [
                {label: s[0], main: esc(host), sub: esc(s[4]), gostep: 1},
                {label: s[1], main: esc(state.targetcategory ? state.targetcategory.name : '-'),
                    sub: esc(s[5]), gostep: 2},
                {label: s[2], main: esc(preview.runs && preview.runs.length ? preview.runs[0].date : '-'),
                    sub: esc(s[6]), gostep: 3},
                {label: s[3], main: esc(s[7]), sub: esc(s[8])
                    + ' · +' + state.recipients.length, gostep: 4},
            ];
            region('review-cards').innerHTML = cards.map(function(card) {
                return '<div class="ct-revcard"><span class="ct-eyebrow">' + esc(card.label) + '</span>'
                    + '<strong>' + card.main + '</strong><small>' + card.sub + '</small>'
                    + '<button type="button" class="ct-btn ct-btn--ghost ct-btn--sm" data-action="go-step" data-step="'
                    + card.gostep + '">✎</button></div>';
            }).join('');
            region('confirm-summary').textContent = s[9];
            return renderTimeline();
        }).catch(Notification.exception);
    };

    var renderTimeline = function() {
        var resolved = resolvePattern(state.categorypattern);
        var host = selectedHost();
        var deletion = preview.firstrun ? preview.firstrun + state.retentiondays * 86400 : 0;
        var warning = deletion ? deletion - cfg.warningdays * 86400 : 0;
        // Pruning cutoff is policy arithmetic on the academic year (A − P − V), not
        // on the run date: the run date only decides *when* the maths is applied.
        var cutoff = currentYear() - state.originkeepyears - state.destinationkeepyears;

        var requests = [];
        for (var i = 1; i <= 9; i++) {
            requests.push({key: 'wz_tl' + i + '_title', component: 'local_coursetransfermanager'});
            requests.push({
                key: 'wz_tl' + i + '_desc', component: 'local_coursetransfermanager',
                param: {category: resolved, host: host, days: state.retentiondays,
                    warningdays: cfg.warningdays, grace: cfg.gracedays, cutoff: cutoff,
                    warning: warning ? fmtDate(warning) : '-'},
            });
        }
        requests.push({key: 'wz_tl_sameday', component: 'local_coursetransfermanager'});
        requests.push({key: 'wz_tl_hours', component: 'local_coursetransfermanager'});
        requests.push({key: 'wz_tl_aftergrace', component: 'local_coursetransfermanager'});

        return Str.get_strings(requests).then(function(s) {
            var whens = [
                preview.firstrun ? fmtDate(preview.firstrun) : '-', s[18], s[19], s[18],
                preview.firstrun ? fmtDate(preview.firstrun + 86400) : '-',
                warning ? fmtDate(warning) : '-',
                deletion ? fmtDate(deletion) : '-', s[20], s[20],
            ];
            var tones = ['neutral', 'neutral', 'neutral', 'neutral', 'success',
                'warning', 'danger', 'warning', 'danger'];
            var items = [];
            for (var i = 0; i < 9; i++) {
                items.push({
                    num: i + 1,
                    title: s[i * 2],
                    desc: s[i * 2 + 1],
                    when: whens[i],
                    tone: tones[i],
                });
            }
            return Templates.render('local_coursetransfermanager/components/lifecycle_timeline', {items: items});
        }).then(function(html) {
            region('timeline').innerHTML = html;
            return null;
        });
    };

    // ---- Save -----------------------------------------------------------------

    var save = function() {
        var button = root.querySelector('[data-action="save"]');
        button.disabled = true;
        call('task_save', {
            id: cfg.taskid,
            name: state.name,
            originsiteid: state.originsiteid,
            categorypattern: state.categorypattern,
            originkeepyears: state.originkeepyears,
            targetcategoryid: state.targetcategory ? state.targetcategory.id : 0,
            cronexpression: buildCron(),
            retentiondays: state.retentiondays,
            destinationkeepyears: state.destinationkeepyears,
            restoreuserdata: state.restoreuserdata,
            notifylevel: state.notifylevel,
            notifyrecipients: state.recipients.map(function(user) {
                return user.id;
            }),
        }).then(function(response) {
            [1, 2, 3, 4, 5].forEach(function(number) {
                region('step-' + number).hidden = true;
            });
            var steps = region('steps');
            if (steps) {
                steps.hidden = true;
            }
            region('wizard-actions').hidden = true;
            region('step-done').hidden = false;
            return Str.get_string('wz_done_desc', 'local_coursetransfermanager', response.firstrun)
                .then(function(text) {
                    region('done-desc').textContent = text;
                    return null;
                });
        }).catch(function(error) {
            button.disabled = false;
            Notification.exception(error);
        });
    };

    // ---- Init -------------------------------------------------------------------

    var initSelects = function() {
        var lang = document.documentElement.lang || 'es';
        var day = document.getElementById('ctm-day');
        for (var d = 1; d <= 28; d++) {
            day.appendChild(new Option(d, d));
        }
        var month = document.getElementById('ctm-month');
        for (var m = 1; m <= 12; m++) {
            var name = new Date(2000, m - 1, 1).toLocaleDateString(lang, {month: 'long'});
            month.appendChild(new Option(name, m));
        }
        var hour = document.getElementById('ctm-hour');
        for (var h = 0; h <= 23; h++) {
            hour.appendChild(new Option((h < 10 ? '0' + h : h) + ':00', h));
        }
    };

    var loadState = function() {
        state = {
            name: cfg.name,
            originsiteid: cfg.originsiteid,
            categorypattern: cfg.categorypattern,
            targetcategory: cfg.targetcategory,
            retentiondays: cfg.retentiondays,
            originkeepyears: cfg.originkeepyears,
            destinationkeepyears: cfg.destinationkeepyears,
            restoreuserdata: cfg.restoreuserdata,
            notifylevel: cfg.notifylevel,
            recipients: cfg.recipients.slice(),
            schedmode: 'semantic',
            day: 1, month: 9, hour: 2,
            cronexpression: cfg.cronexpression,
        };
        var semantic = /^0 (\d{1,2}) (\d{1,2}) (\d{1,2}) \*$/.exec(cfg.cronexpression.trim());
        if (semantic) {
            state.hour = parseInt(semantic[1], 10);
            state.day = parseInt(semantic[2], 10);
            state.month = parseInt(semantic[3], 10);
        } else {
            state.schedmode = 'cron';
        }

        document.getElementById('ctm-name').value = state.name;
        document.getElementById('ctm-site').value = String(state.originsiteid || 0);
        document.getElementById('ctm-pattern').value = state.categorypattern;
        document.getElementById('ctm-day').value = String(state.day);
        document.getElementById('ctm-month').value = String(state.month);
        document.getElementById('ctm-hour').value = String(state.hour);
        document.getElementById('ctm-cron').value = state.cronexpression;
        document.getElementById('ctm-retention').value = String(state.retentiondays);
        document.getElementById('ctm-originkeep').value = String(state.originkeepyears);
        document.getElementById('ctm-keepyears').value = String(state.destinationkeepyears);
        if (state.targetcategory) {
            document.getElementById('ctm-dest').value = state.targetcategory.path || state.targetcategory.name;
        }
        setSchedMode(state.schedmode);
    };

    var setSchedMode = function(mode) {
        state.schedmode = mode;
        region('sched-semantic').hidden = (mode !== 'semantic');
        region('sched-cron').hidden = (mode !== 'cron');
        root.querySelectorAll('[data-action="sched-mode"]').forEach(function(tab) {
            tab.setAttribute('aria-selected', tab.dataset.mode === mode ? 'true' : 'false');
        });
        refreshSchedule();
    };

    var wireEvents = function() {
        root.addEventListener('click', function(event) {
            var node = event.target.closest('[data-action]');
            if (!node) {
                return;
            }
            var action = node.dataset.action;
            if (action === 'go-step') {
                var target = parseInt(node.dataset.step, 10);
                if (target <= step || stepValid(step)) {
                    showStep(target);
                }
            } else if (action === 'step-next') {
                if (stepValid(step)) {
                    showStep(step + 1);
                }
            } else if (action === 'step-back') {
                showStep(step - 1);
            } else if (action === 'insert-token') {
                var input = document.getElementById('ctm-pattern');
                input.value += node.dataset.token;
                state.categorypattern = input.value;
                refreshPatternHelp();
            } else if (action === 'test-pattern') {
                testPattern();
            } else if (action === 'dest-clear') {
                state.targetcategory = null;
                document.getElementById('ctm-dest').value = '';
                refreshDestPreview();
            } else if (action === 'set-userdata') {
                state.restoreuserdata = node.dataset.value === '1';
                refreshOptButtons();
            } else if (action === 'set-level') {
                state.notifylevel = node.dataset.value;
                refreshOptButtons();
            } else if (action === 'sched-mode') {
                setSchedMode(node.dataset.mode);
            } else if (action === 'preset-retention') {
                state.retentiondays = parseInt(node.dataset.days, 10);
                document.getElementById('ctm-retention').value = node.dataset.days;
                refreshRetention();
            } else if (action === 'preset-originkeep') {
                state.originkeepyears = parseInt(node.dataset.years, 10);
                document.getElementById('ctm-originkeep').value = node.dataset.years;
                refreshRetention();
            } else if (action === 'save') {
                save();
            }
        });

        root.addEventListener('input', function(event) {
            var field = event.target.dataset.field;
            if (!field) {
                return;
            }
            var value = event.target.value;
            if (field === 'name') {
                state.name = value;
            } else if (field === 'originsiteid') {
                state.originsiteid = parseInt(value, 10) || 0;
                refreshSiteCard();
            } else if (field === 'categorypattern') {
                state.categorypattern = value;
                refreshPatternHelp();
            } else if (field === 'day' || field === 'month' || field === 'hour') {
                state[field] = parseInt(value, 10);
                refreshSchedule();
            } else if (field === 'cronexpression') {
                state.cronexpression = value;
                refreshSchedule();
            } else if (field === 'retentiondays') {
                state.retentiondays = parseInt(value, 10) || 0;
                refreshRetention();
            } else if (field === 'destinationkeepyears') {
                state.destinationkeepyears = parseInt(value, 10) || 0;
                refreshRetention();
            } else if (field === 'originkeepyears') {
                state.originkeepyears = parseInt(value, 10) || 0;
                refreshRetention();
            } else if (field === 'confirm') {
                root.querySelector('[data-action="save"]').disabled = !event.target.checked;
            }
        });

        // Leaving a year field empty is a transient state while typing, not a
        // policy: put the minimum back so the screen never stays in limbo.
        root.addEventListener('change', function(event) {
            var field = event.target.dataset.field;
            if (field !== 'originkeepyears' && field !== 'destinationkeepyears') {
                return;
            }
            if (state[field] < 1) {
                state[field] = 1;
                event.target.value = '1';
                refreshRetention();
            }
        });

        wireAutocomplete('ctm-dest', 'category-drop', function(query) {
            return call('category_search', {query: query}).then(function(response) {
                return response.categories;
            });
        }, function(category) {
            return '<button type="button" class="ct-ac-item" data-pick=\'' + JSON.stringify(category)
                .replace(/'/g, '&#39;') + '\'><strong>' + esc(category.path) + '</strong>'
                + '<small>' + esc(category.coursecount) + '</small></button>';
        }, function(category) {
            state.targetcategory = category;
            document.getElementById('ctm-dest').value = category.path;
            refreshDestPreview();
        });

        wireAutocomplete('ctm-recipients', 'user-drop', function(query) {
            return call('user_search', {query: query}).then(function(response) {
                return response.users;
            });
        }, function(user) {
            return '<button type="button" class="ct-ac-item" data-pick=\'' + JSON.stringify(user)
                .replace(/'/g, '&#39;') + '\'><strong>' + esc(user.fullname) + '</strong>'
                + '<small>' + esc(user.email) + '</small></button>';
        }, function(user) {
            var exists = state.recipients.some(function(existing) {
                return existing.id === user.id;
            });
            if (!exists) {
                state.recipients.push(user);
                renderChips();
            }
            document.getElementById('ctm-recipients').value = '';
        });
    };

    var initTokens = function() {
        var year = currentYear();
        Str.get_strings([
            {key: 'wz_token_year', component: 'local_coursetransfermanager', param: year},
            {key: 'wz_token_nextyear', component: 'local_coursetransfermanager', param: year + 1},
            {key: 'wz_token_yy', component: 'local_coursetransfermanager', param: String(year).slice(-2)},
            {key: 'wz_token_nextyy', component: 'local_coursetransfermanager',
                param: String(year + 1).slice(-2)},
        ]).then(function(strings) {
            region('token-year').textContent = strings[0];
            region('token-nextyear').textContent = strings[1];
            region('token-yy').textContent = strings[2];
            region('token-nextyy').textContent = strings[3];
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Boot the wizard.
     *
     * @param {String} rootid Root node id.
     * @param {Object} config Server-exported wizard configuration.
     */
    var init = function(rootid, config) {
        root = document.getElementById(rootid);
        cfg = config;
        if (!root) {
            return;
        }
        initSelects();
        loadState();
        initTokens();
        wireEvents();
        refreshSiteCard();
        refreshPatternHelp();
        refreshOptButtons();
        renderChips();
        refreshDestPreview();
        showStep(1);
    };

    return {init: init};
});
