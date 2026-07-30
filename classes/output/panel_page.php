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
 * Management panel renderable for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\output;

use local_coursetransfermanager\manager\agenda;
use local_coursetransfermanager\manager\deletion_manager;
use local_coursetransfermanager\manager\health;
use local_coursetransfermanager\manager\task_manager;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Use case: the admin opens the panel and understands, at a glance, whether
 * the prerequisites work, what state every task is in, and what will run or
 * be deleted next — with the destructive part always visible and cancellable.
 *
 * Pure presentation: all data arrives through the constructor.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel_page implements renderable, templatable {

    /** @var stdClass[] Health checks from {@see health::get_checks()}. */
    private array $checks;

    /** @var stdClass[] Agenda items from {@see agenda::get_items()}. */
    private array $agendaitems;

    /** @var stdClass[] Task overview rows from {@see task_manager::get_tasks_overview()}. */
    private array $tasks;

    /** @var array Active filters (status, fromdate, todate). */
    private array $filters;

    /** @var int Reference time for countdowns. */
    private int $now;

    /** @var string[] Hosts whose last registered connection test failed. */
    private array $kohosts = [];

    /** @var bool Emergency switch: every deletion/pruning is paused. */
    private bool $deletionspaused;

    /**
     * Constructor.
     *
     * @param stdClass[] $checks Health checks.
     * @param stdClass[] $agendaitems Upcoming agenda items.
     * @param stdClass[] $tasks Task overview rows.
     * @param array $filters Active filters.
     * @param int $now Reference timestamp.
     * @param bool $deletionspaused Emergency switch state.
     */
    public function __construct(array $checks, array $agendaitems, array $tasks, array $filters, int $now,
            bool $deletionspaused = false) {
        $this->checks = $checks;
        $this->agendaitems = $agendaitems;
        $this->tasks = $tasks;
        $this->filters = $filters;
        $this->now = $now;
        $this->deletionspaused = $deletionspaused;
    }

    /**
     * Export the panel data for the Mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();

        $data->headertitle = get_string('managetasks', 'local_coursetransfermanager');
        $data->headerdesc = get_string('managetasks_desc', 'local_coursetransfermanager');
        $data->newtaskurl = (new moodle_url('/local/coursetransfermanager/edit.php'))->out(false);
        $data->clearurl = (new moodle_url('/local/coursetransfermanager/manage.php'))->out(false);
        $data->executionsurl = (new moodle_url('/local/coursetransfermanager/executions.php'))->out(false);
        // Footer navigation (page_nav component): only the settings link here.
        $data->settings = (new moodle_url('/admin/settings.php', ['section' => 'local_coursetransfermanager']))->out(false);

        foreach ($this->checks as $check) {
            if ($check->key === 'sites') {
                foreach ($check->data->sites ?? [] as $site) {
                    if ($site->lastteststatus === 0 && !empty($site->host)) {
                        $this->kohosts[] = (string)$site->host;
                    }
                }
            }
        }

        $data->deletionspaused = $this->deletionspaused;
        $data->checks = $this->export_checks();
        $data->agenda = $this->export_agenda();
        $data->tasks = array_map([$this, 'export_task'], $this->tasks);
        $data->hastasks = !empty($data->tasks);
        $data->taskcount = count($data->tasks);

        $data->filters = $this->export_filters();
        $data->sesskey = sesskey();

        return $data;
    }

    /**
     * Health checks with labels, tone and optional action link.
     *
     * @return array[]
     */
    private function export_checks(): array {
        $exported = [];
        foreach ($this->checks as $check) {
            // The origin platforms are per task, not a global prerequisite: their
            // state shows on each task card (origin-KO strip), not as a chip here.
            if ($check->key === 'sites') {
                continue;
            }
            $item = [
                'key' => $check->key,
                'status' => $check->status,
                'isok' => $check->status === health::OK,
                'iswarning' => $check->status === health::WARNING,
                'iserror' => $check->status === health::ERROR,
                'label' => get_string('health_' . $check->key, 'local_coursetransfermanager'),
                'detail' => $this->check_detail($check),
                'actionurl' => null,
                'actionlabel' => null,
            ];
            if ($check->key === 'managertask') {
                $item['actionurl'] = (new moodle_url('/admin/tool/task/scheduledtasks.php'))->out(false);
                $item['actionlabel'] = get_string('health_managertask_manage', 'local_coursetransfermanager');
            }
            $exported[] = $item;
        }
        return $exported;
    }

    /**
     * Human detail line of a health check.
     *
     * @param stdClass $check The raw check.
     * @return string
     */
    private function check_detail(stdClass $check): string {
        switch ($check->key) {
            case 'coursetransfer':
                return $check->status === health::OK
                    ? get_string('health_coursetransfer_ok', 'local_coursetransfermanager')
                    : get_string('health_coursetransfer_ko', 'local_coursetransfermanager');
            case 'cron':
                if (!empty($check->data->lastrun)) {
                    $ago = format_time($this->now - (int)$check->data->lastrun);
                    return $check->status === health::OK
                        ? get_string('health_cron_ok', 'local_coursetransfermanager', $ago)
                        : get_string('health_cron_ko', 'local_coursetransfermanager', $ago);
                }
                return get_string('health_cron_never', 'local_coursetransfermanager');
            case 'managertask':
                if (!empty($check->data->disabled)) {
                    return get_string('health_managertask_disabled', 'local_coursetransfermanager');
                }
                if (!empty($check->data->lastrun)) {
                    return get_string('health_managertask_ok', 'local_coursetransfermanager',
                        format_time($this->now - (int)$check->data->lastrun));
                }
                return get_string('health_managertask_stale', 'local_coursetransfermanager');
        }
        return '';
    }

    /**
     * Agenda ("coming up") items, chronological, with countdowns and actions.
     *
     * @return array
     */
    private function export_agenda(): array {
        $items = [];
        $executions = 0;
        $deletions = 0;

        foreach ($this->agendaitems as $item) {
            $exported = [
                'day' => userdate($item->date, '%d'),
                'month' => userdate($item->date, '%b'),
                'datefull' => userdate($item->date),
                'relative' => get_string('relative_in', 'local_coursetransfermanager',
                    format_time(max(0, $item->date - $this->now))),
                'isexecution' => $item->type === agenda::TYPE_EXECUTION,
                'isdeletion' => $item->type === agenda::TYPE_DELETION,
                'isprune' => $item->type === agenda::TYPE_PRUNE,
                'taskid' => $item->taskid,
                'taskname' => format_string($item->taskname),
            ];

            if ($item->type === agenda::TYPE_EXECUTION) {
                $executions++;
                $exported['title'] = format_string($item->taskname);
                $exported['detail'] = get_string('agenda_exec_detail', 'local_coursetransfermanager', (object)[
                    'category' => s($item->resolvedpattern),
                    'host' => s((string)$this->task_host($item->taskid)),
                ]);
            } else if ($item->type === agenda::TYPE_DELETION) {
                $deletions++;
                $exported['title'] = get_string('agenda_category', 'local_coursetransfermanager',
                    format_string($item->categoryname));
                $exported['detail'] = get_string('agenda_del_detail', 'local_coursetransfermanager',
                    s((string)$this->task_host($item->taskid)));
                $exported['executionid'] = $item->executionid;
            } else {
                $deletions++;
                $exported['title'] = get_string('agenda_category', 'local_coursetransfermanager',
                    format_string($item->categoryname));
                $exported['detail'] = get_string('agenda_prune_detail', 'local_coursetransfermanager');
                $exported['pruneid'] = $item->pruneid;
            }

            $items[] = $exported;
        }

        return [
            'items' => $items,
            'hasitems' => !empty($items),
            'summary' => get_string('agenda_summary', 'local_coursetransfermanager', (object)[
                'executions' => $executions,
                'deletions' => $deletions,
            ]),
        ];
    }

    /**
     * A task overview row, ready for the task card component.
     *
     * @param stdClass $task Task overview row.
     * @return array
     */
    private function export_task(stdClass $task): array {
        $enabled = !empty($task->enabled);

        $exported = [
            'id' => (int)$task->id,
            'name' => format_string($task->name),
            'enabled' => $enabled,
            'originhost' => $task->originhost !== null ? s($task->originhost) : null,
            'originmissing' => $task->originhost === null,
            'pattern' => s((string)$task->categorypattern),
            'resolvedpattern' => s(task_manager::resolve_pattern((string)$task->categorypattern)),
            'target' => $task->targetcategoryname !== null ? format_string($task->targetcategoryname) : null,
            'editurl' => (new moodle_url('/local/coursetransfermanager/edit.php', ['id' => $task->id]))->out(false),
            'deleteurl' => (new moodle_url('/local/coursetransfermanager/delete.php', ['id' => $task->id]))->out(false),
            'executionsurl' => (new moodle_url('/local/coursetransfermanager/executions.php',
                ['taskid' => $task->id]))->out(false),
        ];

        // Next run, in human words.
        if (!$enabled) {
            $exported['nextrun'] = get_string('card_paused', 'local_coursetransfermanager');
            $exported['nextrunpaused'] = true;
        } else if (!empty($task->nextruntime)) {
            $exported['nextrun'] = get_string('card_next_on', 'local_coursetransfermanager', (object)[
                'date' => userdate((int)$task->nextruntime),
                'relative' => format_time(max(0, (int)$task->nextruntime - $this->now)),
            ]);
            $exported['nextrunpaused'] = false;
        } else {
            $exported['nextrun'] = get_string('card_next_unknown', 'local_coursetransfermanager');
            $exported['nextrunpaused'] = false;
        }

        // Origin platform flagged as down by its last registered test.
        if ($task->originhost !== null && in_array($task->originhost, $this->kohosts, true)) {
            $exported['originko'] = get_string('card_origin_ko', 'local_coursetransfermanager',
                s($task->originhost));
        }

        // Latest execution.
        if (!empty($task->laststatus)) {
            $exported['last'] = [
                'label' => task_manager::get_status_label((string)$task->laststatus),
                'badge' => 'ct-badge--' . $task->laststatus,
                'date' => userdate((int)$task->lastrun),
                'type' => !empty($task->lastmanual)
                    ? get_string('card_type_manual', 'local_coursetransfermanager')
                    : get_string('card_type_auto', 'local_coursetransfermanager'),
                'iserror' => $task->laststatus === task_manager::STATUS_ERROR,
            ];
            if ($task->laststatus === task_manager::STATUS_ERROR && !empty($task->lasterror)) {
                $exported['lasterror'] = s($task->lasterror);
            }
        }

        return $exported;
    }

    /**
     * Filter form state.
     *
     * @return array
     */
    private function export_filters(): array {
        $options = [];
        foreach (task_manager::get_status_options() as $value => $label) {
            if ($value === '') {
                continue;
            }
            $options[] = [
                'value' => $value,
                'label' => $label,
                'selected' => ($this->filters['status'] ?? '') === $value,
            ];
        }
        $active = !empty($this->filters['status']) || !empty($this->filters['fromdate'])
            || !empty($this->filters['todate']);

        return [
            'statusoptions' => $options,
            'fromdate' => !empty($this->filters['fromdate'])
                ? userdate((int)$this->filters['fromdate'], '%Y-%m-%d') : '',
            'todate' => !empty($this->filters['todate'])
                ? userdate((int)$this->filters['todate'], '%Y-%m-%d') : '',
            'active' => $active,
        ];
    }

    /**
     * Origin host of a task in the overview set.
     *
     * @param int $taskid Task id.
     * @return string|null
     */
    private function task_host(int $taskid): ?string {
        foreach ($this->tasks as $task) {
            if ((int)$task->id === $taskid) {
                return $task->originhost;
            }
        }
        return null;
    }
}
