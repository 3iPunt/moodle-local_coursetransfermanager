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
 * Lifecycle settings of local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

/**
 * Single place that resolves the notice and grace windows of the destructive lifecycle.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lifecycle_config {
    /** @var int Default days of advance notice before an origin deletion. */
    public const DEFAULT_WARNING_DAYS = 7;

    /** @var int Default days of grace between announcing a pruning and running it. */
    public const DEFAULT_GRACE_DAYS = 7;

    /** @var string Validation regex of both settings: a whole number of days, at least one. */
    public const DAYS_REGEX = '/^[1-9][0-9]{0,3}$/';

    /**
     * Days of advance notice before deleting a category in the origin.
     *
     * @return int At least 1.
     */
    public static function warning_days(): int {
        return self::days('deletionwarningdays', self::DEFAULT_WARNING_DAYS);
    }

    /**
     * Days between announcing an archive pruning and running it.
     *
     * @return int At least 1.
     */
    public static function grace_days(): int {
        return self::days('prunegracedays', self::DEFAULT_GRACE_DAYS);
    }

    /**
     * R3: hold an origin deletion when the origin category no longer matches what was archived.
     *
     * @return bool True unless an admin turned it off (unset counts as on).
     */
    public static function hold_if_origin_changed(): bool {
        return self::enabled('holdiforiginchanged');
    }

    /**
     * Whether each task's first origin deletion (and the first after a policy change) waits for approval.
     *
     * @return bool True unless an admin turned it off (unset counts as on).
     */
    public static function require_first_deletion_approval(): bool {
        return self::enabled('requirefirstdeletionapproval');
    }

    /**
     * Read a safety switch: a safeguard is on until somebody explicitly turns it off.
     *
     * @param string $name Setting name.
     * @return bool
     */
    private static function enabled(string $name): bool {
        $value = get_config('local_coursetransfermanager', $name);
        return $value === false || $value === '' || !empty($value);
    }

    /**
     * Read a days setting.
     *
     * One day is the floor: zero would announce and delete in the same cron pass.
     *
     * @param string $name Setting name.
     * @param int $default Value when unset.
     * @return int
     */
    private static function days(string $name, int $default): int {
        $value = get_config('local_coursetransfermanager', $name);
        $value = ($value === false || $value === '') ? $default : (int) $value;
        return max(1, $value);
    }
}
