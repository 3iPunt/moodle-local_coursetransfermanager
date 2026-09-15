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
 * Task wizard renderable for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\output;

use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Use case: the admin configures (or edits) an archive task step by step,
 * verifying every decision live — pattern against the origin, schedule
 * preview, explicit deletion consequences — and reviews the whole lifecycle
 * before saving. Nothing destructive is configured without seeing it.
 *
 * Pure presentation: all data arrives through the constructor.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_wizard_page implements renderable, templatable {
    /** @var stdClass|null Task being edited, null when creating. */
    private ?stdClass $task;

    /** @var stdClass[] Origin sites: {id, name, host, lastteststatus, lasttest}. */
    private array $sites;

    /** @var stdClass Wizard context: creator name, recipients, settings, target. */
    private stdClass $context;

    /**
     * Constructor.
     *
     * @param stdClass|null $task Task record when editing.
     * @param stdClass[] $sites Available origin sites.
     * @param stdClass $context Extra context: creatorname, recipients (id/fullname/email),
     *                          target (id/name), warningdays, gracedays.
     */
    public function __construct(?stdClass $task, array $sites, stdClass $context) {
        $this->task = $task;
        $this->sites = $sites;
        $this->context = $context;
    }

    /**
     * Export the wizard data for the Mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();

        $isedit = $this->task !== null;
        $data->isedit = $isedit;
        $data->headertitle = $isedit
            ? get_string('edittask', 'local_coursetransfermanager')
            : get_string('wizard_title', 'local_coursetransfermanager');
        $data->headerdesc = get_string('wizard_desc', 'local_coursetransfermanager');
        $data->panelurl = (new moodle_url('/local/coursetransfermanager/manage.php'))->out(false);
        $data->panel = $data->panelurl;

        $now = time();
        $data->sites = array_map(function (stdClass $site) use ($now): array {
            return [
                'id' => (int)$site->id,
                'name' => format_string((string)$site->name),
                'host' => s((string)$site->host),
                'selected' => $this->task && (int)$this->task->originsiteid === (int)$site->id,
                'testok' => isset($site->lastteststatus) && (int)$site->lastteststatus === 1,
                'testko' => isset($site->lastteststatus) && $site->lastteststatus !== null
                    && (int)$site->lastteststatus === 0,
                'testrelative' => !empty($site->lasttest)
                    ? format_time(max(0, $now - (int)$site->lasttest)) : null,
            ];
        }, array_values($this->sites));

        $data->coursetransfersites = (new moodle_url('/local/coursetransfer/sites.php'))->out(false);

        // Everything the AMD module needs to boot, as one JSON blob.
        $config = [
            'taskid' => $isedit ? (int)$this->task->id : 0,
            'name' => $isedit ? $this->task->name : '',
            'originsiteid' => $isedit ? (int)$this->task->originsiteid : 0,
            'categorypattern' => $isedit ? $this->task->categorypattern : '{YEAR}-{NEXTYEAR}',
            'targetcategory' => $this->context->target ?? null,
            'cronexpression' => $isedit ? $this->task->cronexpression : '0 2 1 9 *',
            'retentiondays' => $isedit ? (int)$this->task->retentiondays : 30,
            'originkeepyears' => $isedit ? (int)$this->task->originkeepyears : 2,
            'destinationkeepyears' => $isedit ? (int)$this->task->destinationkeepyears : 4,
            'restoreuserdata' => $isedit ? (bool)$this->task->restoreuserdata : true,
            'notifylevel' => $isedit ? ($this->task->notifylevel ?? 'full') : 'full',
            'recipients' => $this->context->recipients ?? [],
            'creatorname' => $this->context->creatorname ?? '',
            'warningdays' => (int)($this->context->warningdays ?? 7),
            'gracedays' => (int)($this->context->gracedays ?? 7),
            'startmonth' => (int)($this->context->startmonth ?? 9),
            'currentyear' => (int)($this->context->currentyear ?? (int)date('Y')),
        ];
        $data->configjson = json_encode($config);

        return $data;
    }
}
