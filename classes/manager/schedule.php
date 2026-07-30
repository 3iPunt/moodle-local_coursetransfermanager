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
 * Cron schedule calculator for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use local_coursetransfermanager\task\process_tasks;
use Throwable;

/**
 * Direct (field-based) computation of cron schedules.
 *
 * Walks candidate days instead of scanning minute by minute, so a yearly
 * expression resolves in ~1.5k iterations at most instead of ~2.1M.
 * Field parsing is delegated to Moodle's own scheduled_task::eval_cron_field
 * so the accepted syntax matches core exactly.
 *
 * Note: day-of-month and day-of-week are combined with AND (both must
 * match), preserving the plugin's historical semantics.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule {

    /** @var int Search horizon in days (4 years + leap margin). */
    private const MAX_DAYS = 1466;

    /**
     * Whether the expression is a parseable 5-field cron.
     *
     * @param string $expression Cron expression.
     * @return bool
     */
    public static function is_valid(string $expression): bool {
        return self::parse($expression) !== null;
    }

    /**
     * Next run strictly after the reference timestamp.
     *
     * @param string $expression 5-field cron expression (minute hour dom month dow).
     * @param int $from Reference timestamp; the result is strictly after it.
     * @return int|null Unix timestamp or null when the expression cannot be parsed or never matches.
     */
    public static function next(string $expression, int $from): ?int {
        $fields = self::parse($expression);
        if ($fields === null) {
            return null;
        }

        // Start at the next minute boundary.
        $cursor = $from - ($from % 60) + 60;
        $date = getdate($cursor);

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            if (self::day_matches($date, $fields)) {
                $minofday = $date['hours'] * 60 + $date['minutes'];
                foreach ($fields['hours'] as $hour) {
                    foreach ($fields['minutes'] as $minute) {
                        if ($hour * 60 + $minute >= $minofday) {
                            return mktime($hour, $minute, 0, $date['mon'], $date['mday'], $date['year']);
                        }
                    }
                }
            }
            // Jump to the start of the next day (mktime normalises month/year rollover).
            $cursor = mktime(0, 0, 0, $date['mon'], $date['mday'] + 1, $date['year']);
            $date = getdate($cursor);
        }

        return null;
    }

    /**
     * Most recent run strictly before the reference timestamp.
     *
     * @param string $expression 5-field cron expression.
     * @param int $reference Reference timestamp; the result is strictly before it.
     * @return int|null Unix timestamp or null when it cannot be computed.
     */
    public static function previous(string $expression, int $reference): ?int {
        $fields = self::parse($expression);
        if ($fields === null) {
            return null;
        }

        // Start at the last minute boundary strictly before the reference.
        $cursor = $reference - ($reference % 60) - 60;
        $date = getdate($cursor);

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            if (self::day_matches($date, $fields)) {
                $minofday = $date['hours'] * 60 + $date['minutes'];
                foreach (array_reverse($fields['hours']) as $hour) {
                    foreach (array_reverse($fields['minutes']) as $minute) {
                        if ($hour * 60 + $minute <= $minofday) {
                            return mktime($hour, $minute, 0, $date['mon'], $date['mday'], $date['year']);
                        }
                    }
                }
            }
            // Jump to the last minute of the previous day.
            $cursor = mktime(23, 59, 0, $date['mon'], $date['mday'] - 1, $date['year']);
            $date = getdate($cursor);
        }

        return null;
    }

    /**
     * The next N runs after the reference timestamp.
     *
     * @param string $expression 5-field cron expression.
     * @param int $from Reference timestamp.
     * @param int $count How many runs to return.
     * @return int[] Unix timestamps, possibly fewer than requested.
     */
    public static function upcoming(string $expression, int $from, int $count): array {
        $runs = [];
        $cursor = $from;
        for ($i = 0; $i < $count; $i++) {
            $next = self::next($expression, $cursor);
            if ($next === null) {
                break;
            }
            $runs[] = $next;
            $cursor = $next;
        }
        return $runs;
    }

    /**
     * Whether the given date matches the day-level cron fields.
     *
     * @param array $date getdate() array.
     * @param array $fields Parsed cron fields.
     * @return bool
     */
    private static function day_matches(array $date, array $fields): bool {
        return in_array($date['mon'], $fields['months'], true)
            && in_array($date['mday'], $fields['days'], true)
            && in_array($date['wday'], $fields['dows'], true);
    }

    /**
     * Parse a 5-field cron expression into sorted value sets.
     *
     * @param string $expression Cron expression.
     * @return array|null Keys minutes, hours, days, months, dows — or null when invalid.
     */
    private static function parse(string $expression): ?array {
        $parts = preg_split('/\s+/', trim($expression));
        if ($parts === false || count($parts) !== 5) {
            return null;
        }
        [$minute, $hour, $day, $month, $dow] = $parts;

        // Any concrete scheduled_task subclass exposes core's public cron field evaluator.
        $evaluator = new process_tasks();
        try {
            $fields = [
                'minutes' => $evaluator->eval_cron_field($minute, 0, 59),
                'hours' => $evaluator->eval_cron_field($hour, 0, 23),
                'days' => $evaluator->eval_cron_field($day, 1, 31),
                'months' => $evaluator->eval_cron_field($month, 1, 12),
                'dows' => $evaluator->eval_cron_field($dow, 0, 6),
            ];
        } catch (Throwable $e) {
            return null;
        }

        foreach ($fields as &$values) {
            if (empty($values)) {
                return null;
            }
            $values = array_map('intval', $values);
            sort($values);
        }

        return $fields;
    }
}
