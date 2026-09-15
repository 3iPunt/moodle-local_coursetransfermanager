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
 * Lifecycle notifier for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\notification;

use core\message\message;
use core_user;
use moodle_url;
use stdClass;

/**
 * Sends the N1-N7 lifecycle notifications of a task.
 *
 * Recipients: the task creator plus the additional recipients configured in
 * the task. The task notification level gates the informative notices
 * («essential» mutes N1/N2); the critical ones — advance notices of
 * deletions and errors — are ALWAYS sent, whatever the level.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class notifier {
    /** @var string Notify the full lifecycle (N1-N7). */
    public const LEVEL_FULL = 'full';

    /** @var string Notify only deletions and errors (N3-N7). */
    public const LEVEL_ESSENTIAL = 'essential';

    /** @var string[] Providers included in the essential level (N3-N8). */
    private const ESSENTIAL_PROVIDERS = [
        'deletion_warning', 'deletion_done', 'prune_warning', 'prune_done', 'execution_error',
        'deletion_held',
    ];

    /** @var int Default days of advance notice before an origin deletion. */
    public const DEFAULT_WARNING_DAYS = 7;

    /**
     * Advance-notice lead time for origin deletions, in seconds.
     *
     * @return int
     */
    public static function warning_lead_seconds(): int {
        $days = get_config('local_coursetransfermanager', 'deletionwarningdays');
        $days = ($days === false || $days === '') ? self::DEFAULT_WARNING_DAYS : (int) $days;
        return max(0, $days) * DAYSECS;
    }

    /**
     * N1 — The restoration was launched (runs in the background).
     *
     * @param stdClass $task Task record.
     * @param string $categoryname Origin category being restored.
     * @param string $host Origin host.
     * @return void
     */
    public static function execution_launched(stdClass $task, string $categoryname, string $host): void {
        $url = self::executions_url((int) $task->id);
        self::send(
            $task,
            'execution_launched',
            get_string('notif_launched_subject', 'local_coursetransfermanager', self::a([
                'taskname' => $task->name,
            ])),
            get_string('notif_launched_body', 'local_coursetransfermanager', self::a([
                'taskname' => $task->name,
                'categoryname' => $categoryname,
                'host' => $host,
                'url' => $url->out(false),
            ])),
            get_string('notif_launched_small', 'local_coursetransfermanager'),
            $url
        );
    }

    /**
     * N2 — The restoration completed for real (every course restored).
     *
     * @param stdClass $task Task record.
     * @param stdClass $execution Execution record.
     * @return void
     */
    public static function restore_completed(stdClass $task, stdClass $execution): void {
        $url = self::executions_url((int) $task->id);
        self::send(
            $task,
            'restore_completed',
            get_string('notif_completed_subject', 'local_coursetransfermanager', self::a([
                'taskname' => $task->name,
            ])),
            get_string('notif_completed_body', 'local_coursetransfermanager', self::a([
                'taskname' => $task->name,
                'categoryname' => (string) $execution->origincategoryname,
                'deletedate' => !empty($execution->scheduleddeleteat)
                    ? userdate((int) $execution->scheduleddeleteat) : '-',
                'url' => $url->out(false),
            ])),
            get_string('notif_completed_small', 'local_coursetransfermanager'),
            $url
        );
    }

    /**
     * N3 — Advance notice: the category will be deleted from the origin (cancellable).
     *
     * @param stdClass $task Task record.
     * @param stdClass $execution Execution record with the pending deletion.
     * @return void
     */
    public static function deletion_warning(stdClass $task, stdClass $execution): void {
        $url = self::executions_url((int) $task->id);
        self::send(
            $task,
            'deletion_warning',
            get_string('notif_delwarn_subject', 'local_coursetransfermanager', self::a([
                'categoryname' => (string) $execution->origincategoryname,
                'deletedate' => userdate((int) $execution->scheduleddeleteat),
            ])),
            get_string('notif_delwarn_body', 'local_coursetransfermanager', self::a([
                'categoryname' => (string) $execution->origincategoryname,
                'host' => self::origin_host($task),
                'deletedate' => userdate((int) $execution->scheduleddeleteat),
                'url' => $url->out(false),
            ])),
            get_string('notif_delwarn_small', 'local_coursetransfermanager'),
            $url,
            [
                get_string(
                    'agenda_category',
                    'local_coursetransfermanager',
                    (string) $execution->origincategoryname
                ) => (string) $execution->origincategoryidnumber,
                get_string('card_origin', 'local_coursetransfermanager') => self::origin_host($task),
                get_string('exec_del_date', 'local_coursetransfermanager') =>
                    userdate((int) $execution->scheduleddeleteat),
            ]
        );
    }

    /**
     * N4 — The deletion in the origin platform was executed.
     *
     * @param stdClass $task Task record.
     * @param string $categoryname Deleted category name.
     * @return void
     */
    public static function deletion_done(stdClass $task, string $categoryname): void {
        $url = self::executions_url((int) $task->id);
        self::send(
            $task,
            'deletion_done',
            get_string('notif_deldone_subject', 'local_coursetransfermanager', self::a([
                'categoryname' => $categoryname,
            ])),
            get_string('notif_deldone_body', 'local_coursetransfermanager', self::a([
                'categoryname' => $categoryname,
                'host' => self::origin_host($task),
                'taskname' => $task->name,
                'url' => $url->out(false),
            ])),
            get_string('notif_deldone_small', 'local_coursetransfermanager'),
            $url
        );
    }

    /**
     * N5 — Advance notice: archive pruning candidate announced (excludable).
     *
     * @param stdClass $task Task record.
     * @param stdClass $candidate Pruning candidate record.
     * @return void
     */
    public static function prune_warning(stdClass $task, stdClass $candidate): void {
        $url = self::executions_url((int) $task->id);
        self::send(
            $task,
            'prune_warning',
            get_string('notif_prunewarn_subject', 'local_coursetransfermanager', self::a([
                'categoryname' => (string) $candidate->categoryname,
            ])),
            get_string('notif_prunewarn_body', 'local_coursetransfermanager', self::a([
                'categoryname' => (string) $candidate->categoryname,
                'gracedate' => userdate((int) $candidate->graceuntil),
                'url' => $url->out(false),
            ])),
            get_string('notif_prunewarn_small', 'local_coursetransfermanager'),
            $url
        );
    }

    /**
     * N6 — The archive pruning was executed.
     *
     * @param stdClass $task Task record.
     * @param string $categoryname Pruned category name.
     * @return void
     */
    public static function prune_done(stdClass $task, string $categoryname): void {
        $url = self::executions_url((int) $task->id);
        self::send(
            $task,
            'prune_done',
            get_string('notif_prunedone_subject', 'local_coursetransfermanager', self::a([
                'categoryname' => $categoryname,
            ])),
            get_string('notif_prunedone_body', 'local_coursetransfermanager', self::a([
                'categoryname' => $categoryname,
                'taskname' => $task->name,
                'url' => $url->out(false),
            ])),
            get_string('notif_prunedone_small', 'local_coursetransfermanager'),
            $url
        );
    }

    /**
     * N7 — An execution (or a scheduled deletion) failed.
     *
     * @param stdClass $task Task record.
     * @param string $detail Human-readable failure detail.
     * @return void
     */
    public static function execution_error(stdClass $task, string $detail): void {
        $url = self::executions_url((int) $task->id);
        self::send(
            $task,
            'execution_error',
            get_string('notif_error_subject', 'local_coursetransfermanager', self::a([
                'taskname' => $task->name,
            ])),
            get_string('notif_error_body', 'local_coursetransfermanager', self::a([
                'taskname' => $task->name,
                'detail' => $detail,
                'url' => $url->out(false),
            ])),
            get_string('notif_error_small', 'local_coursetransfermanager'),
            $url
        );
    }

    /**
     * N8 — A deletion was HELD by a safety lock.
     *
     * Nothing failed: the plugin refused to delete the original because the
     * copy is not verified. Deliberately NOT worded as an error.
     *
     * @param stdClass $task Task record.
     * @param string $categoryname Category whose deletion was held.
     * @param string $reason Human reason (cause + what to do).
     * @return void
     */
    public static function deletion_held(stdClass $task, string $categoryname, string $reason): void {
        $url = self::executions_url((int) $task->id);
        self::send(
            $task,
            'deletion_held',
            get_string('notif_held_subject', 'local_coursetransfermanager', self::a([
                'categoryname' => $categoryname,
            ])),
            get_string('notif_held_body', 'local_coursetransfermanager', self::a([
                'categoryname' => $categoryname,
                'taskname' => $task->name,
                'reason' => $reason,
                'url' => $url->out(false),
            ])),
            get_string('notif_held_small', 'local_coursetransfermanager'),
            $url,
            [
                get_string('card_brings', 'local_coursetransfermanager') => $categoryname,
                get_string('exec_del_origin', 'local_coursetransfermanager') => $task->name,
            ]
        );
    }

    /**
     * Send a notification to every recipient of the task, honouring its level.
     *
     * @param stdClass $task Task record.
     * @param string $provider Message provider name.
     * @param string $subject Subject line.
     * @param string $body Plain-text body (contains the URL).
     * @param string $small Small message.
     * @param moodle_url $contexturl Context URL.
     * @param array $rows Extra label/value rows rendered in the message body.
     * @return void
     */
    private static function send(
        stdClass $task,
        string $provider,
        string $subject,
        string $body,
        string $small,
        moodle_url $contexturl,
        array $rows = []
    ): void {

        $level = $task->notifylevel ?? self::LEVEL_FULL;
        if ($level === self::LEVEL_ESSENTIAL && !in_array($provider, self::ESSENTIAL_PROVIDERS, true)) {
            return;
        }

        $url = $contexturl->out(false);
        $bodyhtml = self::render_html($provider, $subject, $body, $url, $rows);
        // Plain text carries no branding of its own: sign who sent it and from where.
        $bodytext = $body . "\n\n-- \n" . self::signature();

        foreach (self::recipients($task) as $userto) {
            $message = new message();
            $message->component = 'local_coursetransfermanager';
            $message->name = $provider;
            $message->userfrom = core_user::get_noreply_user();
            $message->userto = $userto;
            $message->subject = $subject;
            $message->fullmessage = $bodytext;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $bodyhtml;
            $message->smallmessage = $small;
            $message->notification = 1;
            $message->contexturl = $url;
            $message->contexturlname = get_string('executions', 'local_coursetransfermanager');

            message_send($message);
        }
    }

    /**
     * Render the HTML body of a notification from the shared email template.
     *
     * The plain-text body already carries the URL; in HTML it becomes the
     * call-to-action button instead, so the sentence reads clean.
     *
     * @param string $provider Message provider name (drives the accent tone).
     * @param string $subject Title.
     * @param string $body Plain-text body (contains the URL).
     * @param string $url Context URL.
     * @param array $rows Key facts as [label => value].
     * @return string HTML, or the escaped plain body if rendering is unavailable.
     */
    private static function render_html(
        string $provider,
        string $subject,
        string $body,
        string $url,
        array $rows
    ): string {
        global $OUTPUT;

        // Accent per provider: informative, good news, warning, destructive.
        $tones = [
            'execution_launched' => ['#2d7ff9', '#e8f1fe'],
            'restore_completed' => ['#1f9d57', '#e7f6ee'],
            'deletion_warning' => ['#e8a317', '#fcf3df'],
            'deletion_done' => ['#cd1405', '#ffe3e0'],
            'prune_warning' => ['#e8a317', '#fcf3df'],
            'prune_done' => ['#cd1405', '#ffe3e0'],
            'execution_error' => ['#cd1405', '#ffe3e0'],
            'deletion_held' => ['#e8a317', '#fcf3df'],
        ];
        [$tone, $tonesoft] = $tones[$provider] ?? ['#6f6f73', '#f4f4f5'];

        $exported = [];
        foreach ($rows as $label => $value) {
            $exported[] = ['label' => $label, 'value' => $value];
        }

        $data = [
            'tone' => $tone,
            'tonesoft' => $tonesoft,
            'logourl' => self::logo_url(),
            'title' => $subject,
            // The URL lives in the button, not in the sentence.
            'intro' => trim(str_replace($url, '', $body)),
            'rows' => $exported,
            'hasrows' => !empty($exported),
            'ctatext' => get_string('notif_cta', 'local_coursetransfermanager'),
            'ctaurl' => $url,
            'footer' => get_string('pluginname', 'local_coursetransfermanager'),
            'signature' => self::signature(),
        ];

        try {
            return $OUTPUT->render_from_template('local_coursetransfermanager/notification_email', $data);
        } catch (\Throwable $e) {
            // Never lose a notification over a rendering problem.
            return \html_writer::tag('p', s($body));
        }
    }

    /**
     * Who sent this and from where — the recipient may not know the plugin.
     *
     * @return string One-line signature: plugin (component) + site name and host.
     */
    private static function signature(): string {
        global $CFG, $SITE;

        return get_string('notif_signature', 'local_coursetransfermanager', (object) [
            'plugin' => get_string('pluginname', 'local_coursetransfermanager'),
            'component' => 'local_coursetransfermanager',
            'site' => format_string($SITE->fullname ?? ''),
            'host' => preg_replace('#^https?://#', '', $CFG->wwwroot),
        ]);
    }

    /**
     * Absolute URL of the plugin logo for emails, when a raster one exists.
     *
     * Mail clients do not render SVG and often block remote images, so the
     * template falls back to a typographic badge: a PNG is used only when the
     * plugin actually ships one (pix/emaillogo.png or pix/logo.png).
     *
     * @return string|null Absolute URL, or null to use the typographic badge.
     */
    private static function logo_url(): ?string {
        global $CFG, $OUTPUT;

        foreach (['emaillogo', 'logo'] as $name) {
            if (file_exists($CFG->dirroot . '/local/coursetransfermanager/pix/' . $name . '.png')) {
                return $OUTPUT->image_url($name, 'local_coursetransfermanager')->out(false);
            }
        }
        return null;
    }

    /**
     * Resolve the recipients of a task: creator + configured additional users.
     *
     * @param stdClass $task Task record.
     * @return stdClass[] User records, deduplicated. Falls back to the main admin.
     */
    private static function recipients(stdClass $task): array {
        $ids = [];
        if (!empty($task->usercreated)) {
            $ids[] = (int) $task->usercreated;
        }
        foreach (explode(',', (string) ($task->notifyrecipients ?? '')) as $id) {
            if ((int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        $users = [];
        foreach (array_unique($ids) as $id) {
            $user = core_user::get_user($id, '*', IGNORE_MISSING);
            if ($user && empty($user->deleted)) {
                $users[$user->id] = $user;
            }
        }

        if (empty($users)) {
            $admin = get_admin();
            if ($admin) {
                $users[$admin->id] = $admin;
            }
        }

        return array_values($users);
    }

    /**
     * URL of the follow-up screen for a task.
     *
     * @param int $taskid Task id.
     * @return moodle_url
     */
    private static function executions_url(int $taskid): moodle_url {
        return new moodle_url('/local/coursetransfermanager/executions.php', ['taskid' => $taskid]);
    }

    /**
     * Origin host of a task, for the message texts.
     *
     * @param stdClass $task Task record.
     * @return string
     */
    private static function origin_host(stdClass $task): string {
        try {
            return (string) \local_coursetransfermanager\manager\origin::site((int) $task->originsiteid)->host;
        } catch (\Throwable $e) {
            return '-';
        }
    }

    /**
     * Cast an associative array to the object get_string() expects.
     *
     * @param array $values Placeholder values.
     * @return stdClass
     */
    private static function a(array $values): stdClass {
        return (object) $values;
    }
}
