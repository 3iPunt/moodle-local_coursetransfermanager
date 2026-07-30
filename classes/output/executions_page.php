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
 * Tracking screen renderable for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\output;

use local_coursetransfermanager\manager\agenda;
use local_coursetransfermanager\manager\task_manager;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Use case: the admin answers "what is being brought now, what will fire
 * next and what is going to be deleted" — globally or for one task — and
 * can cancel any pending deletion from here. The fine per-course progress
 * is linked to CourseTransfer, never duplicated.
 *
 * Pure presentation: all data arrives through the constructor.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class executions_page implements renderable, templatable {

    /** @var stdClass|null Scope task, null for the global view. */
    private ?stdClass $task;

    /** @var stdClass[] Live executions. */
    private array $live;

    /** @var stdClass[] Upcoming agenda items (executions + deletions + prunings). */
    private array $agendaitems;

    /** @var stdClass History page {rows, total}. */
    private stdClass $history;

    /** @var stdClass Tab counters {live, deletions}. */
    private stdClass $counts;

    /** @var array Active filters. */
    private array $filters;

    /** @var array Task options for the filter select: [{id, name}]. */
    private array $taskoptions;

    /** @var int Current zero-based page. */
    private int $page;

    /** @var int Page size. */
    private int $perpage;

    /** @var int Reference time. */
    private int $now;

    /**
     * Constructor.
     *
     * @param stdClass|null $task Scope task or null.
     * @param stdClass[] $live Live executions.
     * @param stdClass[] $agendaitems Upcoming items.
     * @param stdClass $history History page.
     * @param stdClass $counts Tab counters.
     * @param array $filters Active filters.
     * @param array $taskoptions Task filter options.
     * @param int $page Current page.
     * @param int $perpage Page size.
     * @param int $now Reference timestamp.
     */
    public function __construct(?stdClass $task, array $live, array $agendaitems, stdClass $history,
            stdClass $counts, array $filters, array $taskoptions, int $page, int $perpage, int $now) {
        $this->task = $task;
        $this->live = $live;
        $this->agendaitems = $agendaitems;
        $this->history = $history;
        $this->counts = $counts;
        $this->filters = $filters;
        $this->taskoptions = $taskoptions;
        $this->page = $page;
        $this->perpage = $perpage;
        $this->now = $now;
    }

    /**
     * Export the tracking data for the Mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();

        $data->headertitle = get_string('exec_title', 'local_coursetransfermanager');
        $data->headerdesc = get_string('exec_desc', 'local_coursetransfermanager');
        $data->panel = (new moodle_url('/local/coursetransfermanager/manage.php'))->out(false);
        $data->settings = (new moodle_url('/admin/settings.php', ['section' => 'local_coursetransfermanager']))->out(false);
        $data->baseurl = (new moodle_url('/local/coursetransfermanager/executions.php'))->out(false);

        $data->scoped = $this->task !== null;
        if ($this->task) {
            $data->scopename = format_string($this->task->name);
            $data->taskid = (int)$this->task->id;
        }

        $data->counts = ['live' => $this->counts->live, 'deletions' => $this->counts->deletions];
        $data->live = array_map([$this, 'export_live'], array_values($this->live));
        $data->haslive = !empty($data->live);
        $data->upcoming = $this->export_upcoming();
        $data->hasupcoming = !empty($data->upcoming);
        $data->deletions = $this->export_deletions();
        $data->hasdeletions = !empty($data->deletions);
        $data->history = array_map([$this, 'export_history_row'], $this->history->rows);
        $data->hashistory = !empty($data->history);

        // Filters + pagination of the history tab.
        $options = [];
        foreach (self::status_options() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label,
                'selected' => ($this->filters['status'] ?? '') === $value];
        }
        $tasks = [];
        foreach ($this->taskoptions as $option) {
            $tasks[] = ['id' => (int)$option->id, 'name' => format_string($option->name),
                'selected' => (int)($this->filters['taskid'] ?? 0) === (int)$option->id];
        }
        $data->filters = [
            'statusoptions' => $options,
            'taskoptions' => $tasks,
            'fromdate' => !empty($this->filters['fromdate'])
                ? userdate((int)$this->filters['fromdate'], '%Y-%m-%d') : '',
            'todate' => !empty($this->filters['todate'])
                ? userdate((int)$this->filters['todate'], '%Y-%m-%d') : '',
        ];

        $total = (int)$this->history->total;
        $shown = $this->page * $this->perpage + count($this->history->rows);
        $data->paging = [
            'total' => $total,
            'showing' => get_string('exec_showing', 'local_coursetransfermanager', (object)[
                'shown' => min($shown, $total),
                'total' => $total,
            ]),
            'hasprev' => $this->page > 0,
            'hasnext' => $shown < $total,
            'prevpage' => max(0, $this->page - 1),
            'nextpage' => $this->page + 1,
        ];

        return $data;
    }

    /**
     * Display-status filter options.
     *
     * @return array value => label.
     */
    private static function status_options(): array {
        return [
            '' => get_string('all'),
            'success' => get_string('status_success', 'local_coursetransfermanager'),
            'completed' => get_string('status_completed', 'local_coursetransfermanager'),
            'error' => get_string('status_error', 'local_coursetransfermanager'),
            'cancelled' => get_string('exec_status_cancelled', 'local_coursetransfermanager'),
            'deleted' => get_string('exec_status_deleted', 'local_coursetransfermanager'),
        ];
    }

    /**
     * Badge class + label of a display status.
     *
     * @param string $displaystatus Display status.
     * @return array {badge, label}
     */
    public static function status_badge(string $displaystatus): array {
        $labels = [
            'success' => get_string('status_success', 'local_coursetransfermanager'),
            'completed' => get_string('status_completed', 'local_coursetransfermanager'),
            'error' => get_string('status_error', 'local_coursetransfermanager'),
            'cancelled' => get_string('exec_status_cancelled', 'local_coursetransfermanager'),
            'deleted' => get_string('exec_status_deleted', 'local_coursetransfermanager'),
        ];
        $badges = [
            'success' => 'ct-badge--success',
            'completed' => 'ct-badge--completed',
            'error' => 'ct-badge--error',
            'cancelled' => 'ct-badge--cancelled',
            'deleted' => 'ct-badge--deleted',
        ];
        return [
            'badge' => $badges[$displaystatus] ?? 'ct-badge--inactive',
            'label' => $labels[$displaystatus] ?? $displaystatus,
        ];
    }

    /**
     * A live execution card.
     *
     * @param stdClass $execution Live execution row.
     * @return array
     */
    private function export_live(stdClass $execution): array {
        $stalledafter = 3 * HOURSECS;
        return [
            'id' => (int)$execution->id,
            'taskname' => format_string($execution->taskname),
            'categoryname' => format_string((string)$execution->origincategoryname),
            'since' => format_time(max(0, $this->now - (int)$execution->timecreated)),
            'requesturl' => !empty($execution->requestid)
                ? (new moodle_url('/local/coursetransfer/log.php', ['id' => (int)$execution->requestid]))->out(false)
                : null,
            'stalled' => ($this->now - (int)$execution->timecreated) > $stalledafter,
        ];
    }

    /**
     * Upcoming scheduled executions (from the agenda).
     *
     * @return array
     */
    private function export_upcoming(): array {
        $items = [];
        foreach ($this->agendaitems as $item) {
            if ($item->type !== agenda::TYPE_EXECUTION) {
                continue;
            }
            $items[] = [
                'day' => userdate($item->date, '%d'),
                'month' => userdate($item->date, '%b'),
                'datefull' => userdate($item->date),
                'relative' => get_string('relative_in', 'local_coursetransfermanager',
                    format_time(max(0, $item->date - $this->now))),
                'taskname' => format_string($item->taskname),
                'resolvedpattern' => s($item->resolvedpattern),
            ];
        }
        return $items;
    }

    /**
     * Pending deletion/pruning cards (cancellable).
     *
     * @return array
     */
    private function export_deletions(): array {
        $cards = [];
        foreach ($this->agendaitems as $item) {
            if ($item->type === agenda::TYPE_EXECUTION) {
                continue;
            }
            $urgent = ($item->date - $this->now) <= 3 * DAYSECS;
            $cards[] = [
                'isdeletion' => $item->type === agenda::TYPE_DELETION,
                'isprune' => $item->type === agenda::TYPE_PRUNE,
                'executionid' => $item->executionid ?? null,
                'pruneid' => $item->pruneid ?? null,
                'categoryname' => format_string((string)$item->categoryname),
                'taskname' => format_string($item->taskname),
                'date' => userdate($item->date),
                'relative' => get_string('relative_in', 'local_coursetransfermanager',
                    format_time(max(0, $item->date - $this->now))),
                'urgent' => $urgent && $item->type === agenda::TYPE_DELETION,
            ];
        }
        return $cards;
    }

    /**
     * A history table row.
     *
     * @param stdClass $row History row (restore execution or prune record).
     * @return array
     */
    private function export_history_row(stdClass $row): array {
        $badge = self::status_badge($row->displaystatus);
        $exported = [
            'date' => userdate((int)$row->timecreated, '%d-%m-%Y %H:%M'),
            'taskname' => format_string($row->taskname),
            'isprune' => $row->kind === 'prune',
            'isrestore' => $row->kind === 'restore',
            'badge' => $badge['badge'],
            'statuslabel' => $badge['label'],
        ];

        if ($row->kind === 'prune') {
            $exported['categoryname'] = format_string((string)$row->categoryname);
            return $exported;
        }

        $exported['categoryname'] = format_string((string)$row->origincategoryname);
        $exported['requestid'] = !empty($row->requestid) ? (int)$row->requestid : null;
        $exported['requesturl'] = !empty($row->requestid)
            ? (new moodle_url('/local/coursetransfer/log.php', ['id' => (int)$row->requestid]))->out(false)
            : null;
        if (!empty($row->errormessage) || !empty($row->errorcode)) {
            $exported['error'] = s(trim(($row->errorcode ?? '') . ' ' . ($row->errormessage ?? '')));
        }
        return $exported;
    }
}