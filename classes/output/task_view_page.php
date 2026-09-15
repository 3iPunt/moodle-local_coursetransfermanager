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
 * Read-only lifecycle view of one task for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\output;

use local_coursetransfermanager\manager\agenda;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Use case: the admin asks "what exactly is this task going to delete, and
 * when?" and gets the whole answer without editing anything — the policy in
 * force, the next run step by step, the year by year projection, what is
 * already scheduled, and which archive categories the task does not manage.
 *
 * This is the screen that makes an asynchronous, months-long deletion flow
 * legible (Nielsen 1 and 6: visibility, and recognising instead of recalling).
 *
 * Pure presentation: every piece of data arrives through the constructor.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_view_page implements renderable, templatable {
    /** @var stdClass The task. */
    private stdClass $task;

    /** @var stdClass Projection {valid, currentlabel, totalyears, rows...}. */
    private stdClass $projection;

    /** @var stdClass[] Agenda items already scheduled for this task. */
    private array $agendaitems;

    /** @var stdClass[] Yearly categories in the archive managed by this task. */
    private array $managed;

    /** @var stdClass[] Yearly categories in the archive the task does NOT manage. */
    private array $adoptable;

    /** @var stdClass[] Categories adopted by an explicit decision. */
    private array $adopted;

    /** @var string|null Origin host, null when the platform is gone. */
    private ?string $originhost;

    /** @var int Days of advance notice before an origin deletion. */
    private int $warningdays;

    /** @var int Days of grace to exclude a pruning candidate. */
    private int $gracedays;

    /** @var int Reference time. */
    private int $now;

    /**
     * Constructor.
     *
     * @param stdClass $task The task record.
     * @param stdClass $projection Policy projection, as policy_preview exports it.
     * @param stdClass[] $agendaitems Agenda items already scheduled.
     * @param stdClass[] $managed Yearly categories in the archive managed by the task.
     * @param stdClass[] $adoptable Yearly categories in the archive not managed by the task.
     * @param stdClass[] $adopted Categories adopted explicitly.
     * @param string|null $originhost Origin host, null when the platform is gone.
     * @param int $warningdays Days of advance notice before an origin deletion.
     * @param int $gracedays Days of grace to exclude a pruning candidate.
     * @param int $now Reference time.
     */
    public function __construct(
        stdClass $task,
        stdClass $projection,
        array $agendaitems,
        array $managed,
        array $adoptable,
        array $adopted,
        ?string $originhost,
        int $warningdays,
        int $gracedays,
        int $now
    ) {
        $this->task = $task;
        $this->projection = $projection;
        $this->agendaitems = $agendaitems;
        $this->managed = $managed;
        $this->adoptable = $adoptable;
        $this->adopted = $adopted;
        $this->originhost = $originhost;
        $this->warningdays = $warningdays;
        $this->gracedays = $gracedays;
        $this->now = $now;
    }

    /**
     * Export for the template.
     *
     * @param renderer_base $output Renderer.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();

        $data->taskid = (int) $this->task->id;
        $data->headertitle = format_string($this->task->name);
        $data->headerdesc = get_string('view_desc', 'local_coursetransfermanager');
        $data->panel = (new moodle_url('/local/coursetransfermanager/manage.php'))->out(false);
        $data->settings = (new moodle_url(
            '/admin/settings.php',
            ['section' => 'local_coursetransfermanager']
        ))->out(false);
        $data->editurl = (new moodle_url(
            '/local/coursetransfermanager/edit.php',
            ['id' => $this->task->id]
        ))->out(false);
        $data->executionsurl = (new moodle_url(
            '/local/coursetransfermanager/executions.php',
            ['taskid' => $this->task->id]
        ))->out(false);

        $data->enabled = !empty($this->task->enabled);
        $data->originhost = $this->originhost !== null ? s($this->originhost) : null;
        $data->target = !empty($this->task->targetcategoryname)
            ? format_string($this->task->targetcategoryname) : null;

        $origin = max(1, (int) $this->task->originkeepyears);
        $archive = max(1, (int) $this->task->destinationkeepyears);
        $data->policyline = get_string('view_policy_line', 'local_coursetransfermanager', (object) [
            'pattern' => s((string) $this->task->categorypattern),
            'origin' => $origin,
            'archive' => $archive,
            'total' => $origin + $archive,
        ]);
        $data->retentiondays = (int) $this->task->retentiondays;

        // The projection component consumes exactly what policy_preview returns,
        // so the wizard preview and this view can never drift apart.
        $data->projection = $this->projection;

        $items = $this->build_timeline();
        $data->timeline = ['items' => $items];
        $data->hastimeline = !empty($items);

        $data->agenda = array_map([$this, 'export_agenda_item'], array_values($this->agendaitems));
        $data->hasagenda = !empty($data->agenda);

        $data->managed = array_map([$this, 'export_category'], array_values($this->managed));
        $data->hasmanaged = !empty($data->managed);

        $data->adoptable = array_map([$this, 'export_category'], array_values($this->adoptable));
        $data->hasadoptable = !empty($data->adoptable);

        $adoptedids = [];
        foreach ($this->adopted as $record) {
            $adoptedids[(int) $record->categoryid] = true;
        }
        foreach ($data->managed as $index => $category) {
            $data->managed[$index]['adopted'] = isset($adoptedids[$category['id']]);
        }

        return $data;
    }

    /**
     * The nine milestones of the next run, with their real dates.
     *
     * Same nine steps the wizard previews before saving, so what the admin was
     * promised and what the task will do are told with the same words.
     *
     * @return array Items for the lifecycle_timeline component.
     */
    private function build_timeline(): array {
        if (empty($this->task->enabled) || empty($this->task->nextruntime)) {
            return [];
        }

        $firstrun = (int) $this->task->nextruntime;
        $deletion = $firstrun + (int) $this->task->retentiondays * DAYSECS;
        $warning = $deletion - $this->warningdays * DAYSECS;
        $cutoff = (int) $this->projection->pruneyear;

        $params = (object) [
            // The year this run archives is the first one out of the production
            // window, not the current one.
            'category' => s((string) ($this->projection->rows[0]['archive'][0]['idnumber']
                ?? $this->task->categorypattern)),
            'host' => $this->originhost !== null ? s($this->originhost) : '-',
            'days' => (int) $this->task->retentiondays,
            'warningdays' => $this->warningdays,
            'grace' => $this->gracedays,
            'cutoff' => $cutoff,
            'warning' => userdate($warning),
        ];

        $sameday = get_string('wz_tl_sameday', 'local_coursetransfermanager');
        $hours = get_string('wz_tl_hours', 'local_coursetransfermanager');
        $aftergrace = get_string('wz_tl_aftergrace', 'local_coursetransfermanager');

        $whens = [
            userdate($firstrun), $sameday, $hours, $sameday, userdate($firstrun + DAYSECS),
            userdate($warning), userdate($deletion), $aftergrace, $aftergrace,
        ];
        $tones = ['neutral', 'neutral', 'neutral', 'neutral', 'success',
            'warning', 'danger', 'warning', 'danger'];

        $items = [];
        for ($step = 1; $step <= 9; $step++) {
            $items[] = [
                'num' => $step,
                'title' => get_string('wz_tl' . $step . '_title', 'local_coursetransfermanager'),
                'desc' => get_string('wz_tl' . $step . '_desc', 'local_coursetransfermanager', $params),
                'when' => $whens[$step - 1],
                'tone' => $tones[$step - 1],
            ];
        }

        return $items;
    }

    /**
     * One archive category as the template consumes it.
     *
     * @param stdClass $category Category with id, name, idnumber, year and courses.
     * @return array
     */
    private function export_category(stdClass $category): array {
        $year = (int) ($category->year ?? 0);

        return [
            'id' => (int) $category->id,
            'name' => format_string((string) $category->name),
            'idnumber' => s((string) $category->idnumber),
            'label' => $year > 0 ? $year . '/' . substr((string) ($year + 1), -2) : '-',
            'courses' => get_string(
                'view_courses',
                'local_coursetransfermanager',
                (int) ($category->courses ?? 0)
            ),
            'url' => (new moodle_url('/course/index.php', ['categoryid' => $category->id]))->out(false),
            'adopted' => false,
        ];
    }

    /**
     * One already scheduled agenda item as the template consumes it.
     *
     * @param stdClass $item Agenda item.
     * @return array
     */
    private function export_agenda_item(stdClass $item): array {
        $labels = [
            agenda::TYPE_EXECUTION => 'agenda_exec_label',
            agenda::TYPE_DELETION => 'agenda_del_label',
            agenda::TYPE_PRUNE => 'agenda_prune_label',
        ];

        return [
            'type' => $item->type,
            'isexecution' => $item->type === agenda::TYPE_EXECUTION,
            'isdeletion' => $item->type === agenda::TYPE_DELETION,
            'isprune' => $item->type === agenda::TYPE_PRUNE,
            'label' => get_string(
                $labels[$item->type] ?? 'agenda_exec_label',
                'local_coursetransfermanager'
            ),
            'date' => userdate((int) $item->date),
            'relative' => format_time(max(0, (int) $item->date - $this->now)),
            'what' => s((string) ($item->resolvedpattern ?? $item->categoryname ?? '')),
        ];
    }
}
