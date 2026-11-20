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
 * Simulation screen of a task for local_coursetransfermanager.
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
 * What a task would do right now, shown without doing it.
 *
 * Pure presentation: the simulation result arrives through the constructor.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class simulation_page implements renderable, templatable {
    /** @var stdClass The task. */
    private stdClass $task;

    /** @var stdClass Result of simulation::run(). */
    private stdClass $result;

    /** @var int Reference timestamp. */
    private int $now;

    /**
     * Constructor.
     *
     * @param stdClass $task The task.
     * @param stdClass $result Result of {@see \local_coursetransfermanager\manager\simulation::run()}.
     * @param int $now Reference timestamp.
     */
    public function __construct(stdClass $task, stdClass $result, int $now) {
        $this->task = $task;
        $this->result = $result;
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
        $data->headertitle = get_string('sim_title', 'local_coursetransfermanager', format_string($this->task->name));
        $data->headerdesc = get_string('sim_desc', 'local_coursetransfermanager');
        $data->taskurl = (new moodle_url('/local/coursetransfermanager/task.php', ['id' => $this->task->id]))->out(false);

        $data->archive = array_map(static fn(stdClass $row): array => [
            'name' => format_string($row->name),
            'idnumber' => $row->idnumber,
            'year' => $row->year . '/' . substr((string) ($row->year + 1), -2),
            'thisrun' => $row->thisrun,
        ], $this->result->archive);
        $data->hasarchive = !empty($data->archive);
        $data->archiveerror = $this->result->archiveerror;
        $data->identity = $this->result->identity === '' ? ''
            : get_string('sim_identity', 'local_coursetransfermanager', $this->result->identity);

        $data->deletions = array_map(fn(stdClass $row): array => [
            'name' => format_string($row->name),
            'date' => $row->date ? userdate($row->date) : '-',
            'status' => get_string('sim_del_' . $row->status, 'local_coursetransfermanager'),
            'held' => $row->status === 'held',
            'delete' => $row->status === 'delete',
            'rule' => $row->rule,
            'reason' => $row->reason,
            'passed' => $row->passed,
            'notes' => implode(' ', $row->notes),
        ], $this->result->deletions);
        $data->hasdeletions = !empty($data->deletions);

        $data->prunings = array_map(fn(stdClass $row): array => [
            'name' => $row->name,
            'idnumber' => $row->idnumber,
            'year' => $row->year . '/' . substr((string) ($row->year + 1), -2),
            'status' => get_string('sim_prune_' . $row->status, 'local_coursetransfermanager'),
            'date' => $row->date ? userdate($row->date) : '-',
            'prune' => $row->status === 'prune',
        ], $this->result->prunings);
        $data->hasprunings = !empty($data->prunings);

        $data->generated = userdate($this->now);
        return $data;
    }
}
