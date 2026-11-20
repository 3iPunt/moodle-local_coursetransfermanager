<?php
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
 * Behat data generator for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Enables "the following "local_coursetransfermanager > ..." exist:" steps.
 *
 * Example:
 *   And the following "local_coursetransfermanager > origin sites" exist:
 *     | name   | host                       |
 *     | Origin | https://origin.example.com |
 *   And the following "local_coursetransfermanager > tasks" exist:
 *     | name     | originsite                 | targetcategory | nextruntime  |
 *     | Rotation | https://origin.example.com | ARCHIVE        | ##+1 month## |
 *   And the following "local_coursetransfermanager > executions" exist:
 *     | task     | origincategoryname | deletestatus | scheduleddeleteat |
 *     | Rotation | Year 2019          | scheduled    | ##+10 days##      |
 *   And the following "local_coursetransfermanager > pruning candidates" exist:
 *     | task     | category | categoryname |
 *     | Rotation | Y2012    | Year 2012    |
 *
 * Origin sites are rows of local_coursetransfer (the dependency owns them); they are
 * created through its data generator so the hosts stay fake and nothing is called.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3ipunt <https://www.tresipunt.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_coursetransfermanager_generator extends behat_generator_base {
    /**
     * Entities this generator can create.
     *
     * @return array[]
     */
    protected function get_creatable_entities(): array {
        return [
            'origin sites' => [
                'singular' => 'origin site',
                'datagenerator' => 'origin_site',
                'required' => ['host'],
            ],
            'tasks' => [
                'singular' => 'task',
                'datagenerator' => 'task',
                'required' => ['name'],
                'switchids' => ['originsite' => 'originsiteid', 'targetcategory' => 'targetcategoryid'],
            ],
            'executions' => [
                'singular' => 'execution',
                'datagenerator' => 'execution',
                'required' => ['task'],
                'switchids' => ['task' => 'taskid', 'destinationcategory' => 'destinationcategoryid'],
            ],
            'pruning candidates' => [
                'singular' => 'pruning candidate',
                'datagenerator' => 'prune',
                'required' => ['task', 'category'],
                'switchids' => ['task' => 'taskid', 'category' => 'categoryid'],
            ],
        ];
    }

    /**
     * Create an origin site through the local_coursetransfer generator.
     *
     * @param array $data Site columns (host is required).
     */
    protected function process_origin_site(array $data): void {
        $this->datagenerator->get_plugin_generator('local_coursetransfer')->create_site('origin', $data);
    }

    /**
     * Origin site id from its host.
     *
     * @param string $host Host as given in the table.
     * @return int
     */
    protected function get_originsite_id(string $host): int {
        global $DB;

        $host = \local_coursetransfer\coursetransfer_sites::clean_host($host);
        $sites = $DB->get_records_select(
            'local_coursetransfer_origin',
            $DB->sql_compare_text('host') . ' = ' . $DB->sql_compare_text(':host'),
            ['host' => $host],
            'id',
            'id'
        );
        if (!$sites) {
            throw new Exception('The specified origin site with host "' . $host . '" does not exist');
        }
        return (int) reset($sites)->id;
    }

    /**
     * Archive category id from its idnumber.
     *
     * @param string $idnumber Category idnumber.
     * @return int
     */
    protected function get_targetcategory_id(string $idnumber): int {
        return (int) $this->get_category_id($idnumber);
    }

    /**
     * Destination category id from its idnumber.
     *
     * @param string $idnumber Category idnumber.
     * @return int
     */
    protected function get_destinationcategory_id(string $idnumber): int {
        return (int) $this->get_category_id($idnumber);
    }

    /**
     * Task id from its name.
     *
     * @param string $name Task name.
     * @return int
     */
    protected function get_task_id(string $name): int {
        global $DB;

        $tasks = $DB->get_records('local_coursetransfermanager_tasks', ['name' => $name], 'id', 'id');
        if (!$tasks) {
            throw new Exception('The specified task with name "' . $name . '" does not exist');
        }
        return (int) reset($tasks)->id;
    }
}
