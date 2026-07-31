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
 * Frontend (AJAX) endpoints of the task wizard for local_coursetransfermanager.
 *
 * Internal JS→PHP only; free to change between versions. Remote data always
 * flows through the coursetransfer backend WS (the inter-platform contract).
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\external;

use context_system;
use core_course_category;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_text;
use invalid_parameter_exception;
use local_coursetransfermanager\manager\academic_year;
use local_coursetransfermanager\manager\origin;
use local_coursetransfermanager\manager\rotation;
use local_coursetransfermanager\manager\schedule;
use local_coursetransfermanager\manager\task_manager;

/**
 * Wizard endpoints: live pattern test, schedule preview, category and user
 * search, and the guarded save with every parity validation server-side.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wizard_external extends external_api {

    /**
     * Common security gate for every wizard endpoint.
     *
     * @return void
     */
    private static function require_manager(): void {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursetransfermanager:managetasks', $context);
    }

    /**
     * Parameters of test_pattern().
     *
     * @return external_function_parameters
     */
    public static function test_pattern_parameters(): external_function_parameters {
        return new external_function_parameters([
            'siteid' => new external_value(PARAM_INT, 'Origin site id'),
            'pattern' => new external_value(PARAM_RAW_TRIMMED, 'Naming mask ({YEAR}, {NEXTYEAR}, {YY}, {NEXTYY}…)'),
        ]);
    }

    /**
     * Test the naming mask against the origin, live.
     *
     * The mask no longer points at one year: it recognises every yearly
     * category. So the test lists what it recognises in the origin (with the
     * academic year of each one) — that is what tells the admin whether the
     * mask describes their naming, before trusting a policy that deletes.
     *
     * @param int $siteid Origin site id.
     * @param string $pattern Naming mask.
     * @return array {status: ok|none|down|invalid, matchcount, categories, example, message}
     */
    public static function test_pattern(int $siteid, string $pattern): array {
        $params = self::validate_parameters(self::test_pattern_parameters(), [
            'siteid' => $siteid,
            'pattern' => $pattern,
        ]);
        self::require_manager();

        $result = [
            'status' => 'invalid',
            'matchcount' => 0,
            'categories' => [],
            'example' => '',
            'message' => '',
        ];

        if (!academic_year::is_valid_mask($params['pattern'])) {
            return $result;
        }

        $mask = new academic_year($params['pattern']);
        $result['example'] = academic_year::example($params['pattern'], academic_year::current_year());

        $remote = origin::categories_result($params['siteid']);
        if ($remote->error !== '') {
            // The origin refused or could not be reached: never guess, and say why.
            $result['status'] = 'down';
            $result['message'] = $remote->error;
            return $result;
        }
        $categories = $remote->categories;

        $recognised = [];
        foreach ($categories as $category) {
            if (empty($category->idnumber)) {
                continue;
            }
            $year = $mask->year_of((string) $category->idnumber);
            if ($year === null) {
                continue;
            }
            $recognised[] = [
                'year' => $year,
                'label' => $year . '/' . substr((string) ($year + 1), -2),
                'name' => (string) $category->name,
                'idnumber' => (string) $category->idnumber,
                'courses' => (int) $category->totalcourses,
            ];
        }

        usort($recognised, static fn(array $a, array $b): int => $b['year'] <=> $a['year']);

        $result['status'] = empty($recognised) ? 'none' : 'ok';
        $result['matchcount'] = count($recognised);
        $result['categories'] = array_slice($recognised, 0, 12);

        return $result;
    }

    /**
     * Return structure of test_pattern().
     *
     * @return external_single_structure
     */
    public static function test_pattern_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok | none | down | invalid'),
            'matchcount' => new external_value(PARAM_INT, 'Yearly categories the mask recognises in the origin'),
            'example' => new external_value(PARAM_TEXT, 'Idnumber this mask produces for the current academic year'),
            'categories' => new external_multiple_structure(new external_single_structure([
                'year' => new external_value(PARAM_INT, 'Starting academic year'),
                'label' => new external_value(PARAM_TEXT, 'Academic year, human readable'),
                'name' => new external_value(PARAM_TEXT, 'Category name'),
                'idnumber' => new external_value(PARAM_TEXT, 'Category idnumber'),
                'courses' => new external_value(PARAM_INT, 'Courses in the category'),
            ])),
            'message' => new external_value(PARAM_RAW, 'Raw failure detail when the origin did not answer'),
        ]);
    }

    /**
     * Parameters of policy_preview().
     *
     * @return external_function_parameters
     */
    public static function policy_preview_parameters(): external_function_parameters {
        return new external_function_parameters([
            'siteid' => new external_value(PARAM_INT, 'Origin site id'),
            'pattern' => new external_value(PARAM_RAW_TRIMMED, 'Naming mask'),
            'originkeepyears' => new external_value(PARAM_INT, 'Academic years kept in the origin'),
            'destinationkeepyears' => new external_value(PARAM_INT, 'Academic years kept in the archive'),
            'targetcategoryid' => new external_value(PARAM_INT, 'Archive category, 0 when not chosen yet',
                VALUE_DEFAULT, 0),
            'withorigin' => new external_value(PARAM_BOOL,
                'Ask the origin which years exist there (one HTTP round trip)', VALUE_DEFAULT, false),
            'taskid' => new external_value(PARAM_INT,
                'Existing task, so the projection can tell facts from projections', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Year by year projection of the retention policy, for the wizard table.
     *
     * The whole point of this screen is that nobody has to imagine what the task
     * will delete in four years: the projection says it out loud, run by run.
     *
     * @param int $siteid Origin site id.
     * @param string $pattern Naming mask.
     * @param int $originkeepyears Academic years kept in the origin.
     * @param int $destinationkeepyears Academic years kept in the archive.
     * @param int $targetcategoryid Archive category, 0 when not chosen yet.
     * @param bool $withorigin Ask the origin which years exist there.
     * @param int $taskid Existing task id, 0 while creating one.
     * @return array Projection rows plus the thresholds behind them.
     */
    public static function policy_preview(int $siteid, string $pattern, int $originkeepyears,
            int $destinationkeepyears, int $targetcategoryid = 0, bool $withorigin = false,
            int $taskid = 0): array {
        $params = self::validate_parameters(self::policy_preview_parameters(), [
            'siteid' => $siteid,
            'pattern' => $pattern,
            'originkeepyears' => $originkeepyears,
            'destinationkeepyears' => $destinationkeepyears,
            'targetcategoryid' => $targetcategoryid,
            'withorigin' => $withorigin,
            'taskid' => $taskid,
        ]);
        self::require_manager();

        $result = [
            'valid' => false,
            'currentyear' => academic_year::current_year(),
            'currentlabel' => '',
            'archiveyear' => 0,
            'pruneyear' => 0,
            'totalyears' => 0,
            'rows' => [],
        ];

        if (!academic_year::is_valid_mask($params['pattern'])
                || $params['originkeepyears'] < 1 || $params['destinationkeepyears'] < 1) {
            return $result;
        }

        // A throwaway task object: the projection is pure policy arithmetic, so it
        // works before anything is saved. That is what makes the table a preview.
        // The id, when there is one, is what lets it tell an archived year that
        // really exists from one that is only projected.
        $draft = (object) [
            'id' => $params['taskid'],
            'originsiteid' => $params['siteid'],
            'categorypattern' => $params['pattern'],
            'originkeepyears' => $params['originkeepyears'],
            'destinationkeepyears' => $params['destinationkeepyears'],
            'targetcategoryid' => $params['targetcategoryid'],
        ];

        $current = academic_year::current_year();
        $result['valid'] = true;
        $result['currentlabel'] = $current . '/' . substr((string) ($current + 1), -2);
        $result['archiveyear'] = rotation::archive_threshold($draft);
        $result['pruneyear'] = rotation::prune_threshold($draft);
        $result['totalyears'] = $params['originkeepyears'] + $params['destinationkeepyears'];

        foreach (rotation::project($draft, 6, null, (bool) $params['withorigin']) as $row) {
            $result['rows'][] = [
                'year' => $row->year,
                'label' => $row->label,
                'iscurrent' => $row->iscurrent,
                'production' => array_map([self::class, 'export_cell'], $row->production),
                'archive' => array_map([self::class, 'export_cell'], $row->archive),
                'deleted' => array_map([self::class, 'export_cell'], $row->deleted),
            ];
        }

        return $result;
    }

    /**
     * One projection cell as the template consumes it.
     *
     * @param \stdClass $cell Cell from rotation::project().
     * @return array
     */
    private static function export_cell(\stdClass $cell): array {
        return [
            'year' => (int) $cell->year,
            'label' => $cell->year . '/' . substr((string) ($cell->year + 1), -2),
            'idnumber' => (string) $cell->idnumber,
            'exists' => (bool) $cell->exists,
        ];
    }

    /**
     * Returns of policy_preview().
     *
     * @return external_single_structure
     */
    public static function policy_preview_returns(): external_single_structure {
        $cell = static fn(): external_single_structure => new external_single_structure([
            'year' => new external_value(PARAM_INT, 'Starting academic year'),
            'label' => new external_value(PARAM_TEXT, 'Academic year, human readable'),
            'idnumber' => new external_value(PARAM_TEXT, 'Idnumber, real or projected'),
            'exists' => new external_value(PARAM_BOOL, 'True when the category exists today'),
        ]);

        return new external_single_structure([
            'valid' => new external_value(PARAM_BOOL, 'False when the mask or the years are unusable'),
            'currentyear' => new external_value(PARAM_INT, 'Academic year running now'),
            'currentlabel' => new external_value(PARAM_TEXT, 'Academic year running now, human readable'),
            'archiveyear' => new external_value(PARAM_INT, 'Newest year this run would archive'),
            'pruneyear' => new external_value(PARAM_INT, 'Newest year this run would delete for good'),
            'totalyears' => new external_value(PARAM_INT, 'Years a course survives in total'),
            'rows' => new external_multiple_structure(new external_single_structure([
                'year' => new external_value(PARAM_INT, 'Academic year of the run'),
                'label' => new external_value(PARAM_TEXT, 'Academic year of the run, human readable'),
                'iscurrent' => new external_value(PARAM_BOOL, 'True for the run happening this year'),
                'production' => new external_multiple_structure($cell()),
                'archive' => new external_multiple_structure($cell()),
                'deleted' => new external_multiple_structure($cell()),
            ])),
        ]);
    }

    /**
     * Parameters of schedule_preview().
     *
     * @return external_function_parameters
     */
    public static function schedule_preview_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cronexpression' => new external_value(PARAM_RAW_TRIMMED, '5-field cron expression'),
        ]);
    }

    /**
     * Next runs of a cron expression, humanised, plus the summer warning.
     *
     * @param string $cronexpression Cron expression.
     * @return array
     */
    public static function schedule_preview(string $cronexpression): array {
        $params = self::validate_parameters(self::schedule_preview_parameters(), [
            'cronexpression' => $cronexpression,
        ]);
        self::require_manager();

        if (!schedule::is_valid($params['cronexpression'])) {
            return ['valid' => false, 'summer' => false, 'runs' => [], 'firstrun' => 0];
        }

        $now = time();
        $runs = [];
        $summer = false;
        $first = 0;
        foreach (schedule::upcoming($params['cronexpression'], $now, 3) as $index => $timestamp) {
            if ($index === 0) {
                $first = $timestamp;
            }
            $month = (int)date('n', $timestamp);
            $summer = $summer || in_array($month, [6, 7, 8], true);
            $runs[] = [
                'date' => userdate($timestamp),
                'relative' => get_string('relative_in', 'local_coursetransfermanager',
                    format_time(max(0, $timestamp - $now))),
            ];
        }

        return ['valid' => !empty($runs), 'summer' => $summer, 'runs' => $runs, 'firstrun' => $first];
    }

    /**
     * Return structure of schedule_preview().
     *
     * @return external_single_structure
     */
    public static function schedule_preview_returns(): external_single_structure {
        return new external_single_structure([
            'valid' => new external_value(PARAM_BOOL, 'Whether the expression parses and matches future dates'),
            'summer' => new external_value(PARAM_BOOL, 'True when a run falls in June-August'),
            'firstrun' => new external_value(PARAM_INT, 'Timestamp of the first run'),
            'runs' => new external_multiple_structure(new external_single_structure([
                'date' => new external_value(PARAM_TEXT, 'Human date'),
                'relative' => new external_value(PARAM_TEXT, 'Relative time'),
            ])),
        ]);
    }

    /**
     * Parameters of category_search().
     *
     * @return external_function_parameters
     */
    public static function category_search_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_RAW_TRIMMED, 'Search text'),
        ]);
    }

    /**
     * Search local categories by name (server-side, capped: never the full catalogue).
     *
     * @param string $query Search text.
     * @return array
     */
    public static function category_search(string $query): array {
        global $DB;

        $params = self::validate_parameters(self::category_search_parameters(), ['query' => $query]);
        self::require_manager();

        $results = [];
        if (core_text::strlen($params['query']) >= 2) {
            $like = $DB->sql_like('name', ':query', false, false);
            $records = $DB->get_records_select('course_categories', $like,
                ['query' => '%' . $DB->sql_like_escape($params['query']) . '%'],
                'depth ASC, sortorder ASC', 'id', 0, 30);
            foreach ($records as $record) {
                $category = core_course_category::get((int)$record->id, IGNORE_MISSING);
                if (!$category || !$category->has_manage_capability()) {
                    continue;
                }
                $results[] = [
                    'id' => (int)$category->id,
                    'name' => $category->get_formatted_name(),
                    'path' => $category->get_nested_name(false),
                    'coursecount' => (int)$category->coursecount,
                ];
            }
        }

        return ['categories' => $results];
    }

    /**
     * Return structure of category_search().
     *
     * @return external_single_structure
     */
    public static function category_search_returns(): external_single_structure {
        return new external_single_structure([
            'categories' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category id'),
                'name' => new external_value(PARAM_TEXT, 'Category name'),
                'path' => new external_value(PARAM_TEXT, 'Nested path'),
                'coursecount' => new external_value(PARAM_INT, 'Direct course count'),
            ])),
        ]);
    }

    /**
     * Parameters of user_search().
     *
     * @return external_function_parameters
     */
    public static function user_search_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_RAW_TRIMMED, 'Search text (name or email)'),
        ]);
    }

    /**
     * Search users to add as notification recipients (capped at 30).
     *
     * @param string $query Search text.
     * @return array
     */
    public static function user_search(string $query): array {
        global $DB;

        $params = self::validate_parameters(self::user_search_parameters(), ['query' => $query]);
        self::require_manager();

        $results = [];
        if (core_text::strlen($params['query']) >= 2) {
            $fullname = $DB->sql_fullname('firstname', 'lastname');
            $where = '(' . $DB->sql_like($fullname, ':q1', false, false)
                . ' OR ' . $DB->sql_like('email', ':q2', false, false) . ')'
                . ' AND deleted = 0 AND suspended = 0 AND confirmed = 1 AND id > 1';
            $escaped = '%' . $DB->sql_like_escape($params['query']) . '%';
            $users = $DB->get_records_select('user', $where, ['q1' => $escaped, 'q2' => $escaped],
                'lastname ASC, firstname ASC', 'id, firstname, lastname, email, firstnamephonetic,
                 lastnamephonetic, middlename, alternatename', 0, 30);
            foreach ($users as $user) {
                $results[] = [
                    'id' => (int)$user->id,
                    'fullname' => fullname($user),
                    'email' => $user->email,
                ];
            }
        }

        return ['users' => $results];
    }

    /**
     * Return structure of user_search().
     *
     * @return external_single_structure
     */
    public static function user_search_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'User id'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                'email' => new external_value(PARAM_TEXT, 'Email'),
            ])),
        ]);
    }

    /**
     * Parameters of task_save().
     *
     * @return external_function_parameters
     */
    public static function task_save_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Task id (0 to create)'),
            'name' => new external_value(PARAM_TEXT, 'Task name'),
            'originsiteid' => new external_value(PARAM_INT, 'Origin site id'),
            'categorypattern' => new external_value(PARAM_RAW_TRIMMED, 'Naming mask of the yearly categories'),
            'originkeepyears' => new external_value(PARAM_INT, 'Academic years kept in the origin'),
            'targetcategoryid' => new external_value(PARAM_INT, 'Destination parent category id'),
            'cronexpression' => new external_value(PARAM_RAW_TRIMMED, '5-field cron expression'),
            'retentiondays' => new external_value(PARAM_INT, 'Days before the origin deletion'),
            'destinationkeepyears' => new external_value(PARAM_INT, 'Years kept in the archive'),
            'restoreuserdata' => new external_value(PARAM_BOOL, 'Copy user data'),
            'notifylevel' => new external_value(PARAM_ALPHA, 'full or essential'),
            'notifyrecipients' => new external_multiple_structure(
                new external_value(PARAM_INT, 'User id'), 'Additional recipients', VALUE_DEFAULT, []
            ),
        ]);
    }

    /**
     * Create or update a task, with every parity validation server-side.
     *
     * @param int $id Task id (0 to create).
     * @param string $name Task name.
     * @param int $originsiteid Origin site id.
     * @param string $categorypattern Category pattern.
     * @param int $targetcategoryid Destination parent category id.
     * @param string $cronexpression Cron expression.
     * @param int $retentiondays Origin retention days.
     * @param int $destinationkeepyears Archive years.
     * @param bool $restoreuserdata Copy user data.
     * @param string $notifylevel Notification level.
     * @param int[] $notifyrecipients Additional recipient ids.
     * @return array
     * @throws invalid_parameter_exception On any validation failure.
     */
    public static function task_save(int $id, string $name, int $originsiteid, string $categorypattern,
            int $originkeepyears, int $targetcategoryid, string $cronexpression, int $retentiondays,
            int $destinationkeepyears,
            bool $restoreuserdata, string $notifylevel, array $notifyrecipients = []): array {
        global $DB;

        $params = self::validate_parameters(self::task_save_parameters(), [
            'id' => $id,
            'name' => $name,
            'originsiteid' => $originsiteid,
            'categorypattern' => $categorypattern,
            'originkeepyears' => $originkeepyears,
            'targetcategoryid' => $targetcategoryid,
            'cronexpression' => $cronexpression,
            'retentiondays' => $retentiondays,
            'destinationkeepyears' => $destinationkeepyears,
            'restoreuserdata' => $restoreuserdata,
            'notifylevel' => $notifylevel,
            'notifyrecipients' => $notifyrecipients,
        ]);
        self::require_manager();

        // Parity validations (formerly in the moodleform): everything server-side.
        if (trim($params['name']) === '') {
            throw new invalid_parameter_exception('name');
        }
        origin::site($params['originsiteid']); // Throws when unset or missing.
        // The mask must describe a yearly naming: without a year there is no policy.
        if (!academic_year::is_valid_mask($params['categorypattern'])) {
            throw new invalid_parameter_exception('categorypattern');
        }
        if (!core_course_category::get($params['targetcategoryid'], IGNORE_MISSING)) {
            throw new invalid_parameter_exception('targetcategoryid');
        }
        if (!schedule::is_valid($params['cronexpression'])) {
            throw new invalid_parameter_exception('cronexpression');
        }
        // Named one by one: an admin fixing a rejected save needs to know which
        // window is wrong, not that "the retention" is.
        if ($params['retentiondays'] < 1) {
            throw new invalid_parameter_exception('retentiondays');
        }
        if ($params['originkeepyears'] < 1) {
            throw new invalid_parameter_exception('originkeepyears');
        }
        if ($params['destinationkeepyears'] < 1) {
            throw new invalid_parameter_exception('destinationkeepyears');
        }
        if (!in_array($params['notifylevel'], ['full', 'essential'], true)) {
            throw new invalid_parameter_exception('notifylevel');
        }
        $recipients = [];
        foreach (array_unique(array_map('intval', $params['notifyrecipients'])) as $userid) {
            if ($userid > 0 && $DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
                $recipients[] = $userid;
            }
        }

        $data = [
            'name' => trim($params['name']),
            'originsiteid' => $params['originsiteid'],
            'categorypattern' => $params['categorypattern'],
            'originkeepyears' => $params['originkeepyears'],
            'targetcategoryid' => $params['targetcategoryid'],
            'cronexpression' => $params['cronexpression'],
            'retentiondays' => $params['retentiondays'],
            'destinationkeepyears' => $params['destinationkeepyears'],
            'restoreuserdata' => $params['restoreuserdata'] ? 1 : 0,
            'notifylevel' => $params['notifylevel'],
            'notifyrecipients' => $recipients ? implode(',', $recipients) : null,
        ];

        $manager = new task_manager();
        if ($params['id'] > 0) {
            $task = $manager->get_task($params['id']);
            $data['enabled'] = (int)$task->enabled;
            $manager->update_task($params['id'], $data);
            $taskid = $params['id'];
        } else {
            $data['enabled'] = 1;
            $taskid = $manager->create_task($data);
        }

        $task = $manager->get_task($taskid);
        return [
            'id' => $taskid,
            'firstrun' => !empty($task->nextruntime) ? userdate((int)$task->nextruntime) : '',
        ];
    }

    /**
     * Return structure of task_save().
     *
     * @return external_single_structure
     */
    public static function task_save_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Saved task id'),
            'firstrun' => new external_value(PARAM_TEXT, 'Human date of the next scheduled run'),
        ]);
    }
}
