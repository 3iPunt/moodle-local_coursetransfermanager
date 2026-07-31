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
 * Academic year recognition for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use moodle_exception;

/**
 * Recognises the academic year of a category from its idnumber.
 *
 * The task no longer stores a pattern that resolves to one year: it stores a
 * MASK that describes how the yearly categories are named. The mask is
 * compiled to a regular expression, applied to every candidate idnumber, and
 * the starting year is extracted from it. That is what lets a task run
 * unattended for years: the policy (how many years to keep) decides what to
 * archive and what to prune, not a hardcoded year.
 *
 * Supported placeholders:
 * - {YEAR}, {NEXTYEAR} — four digits (2025, 2026).
 * - {YY}, {NEXTYY} — two digits (25, 26), expanded to 20YY.
 * - {ANY} — any non-empty text that is NOT the year (course codes...).
 * - {DIGITS} — digits that are not the year (plan codes...).
 *
 * The starting year of the course is always the one {YEAR}/{YY} matches, so
 * a mask reads in the same order as the idnumber it describes.
 *
 * Everything else in the mask is literal, so any prefix, suffix or separator
 * works. When the mask carries a start/next pair, the two years must be
 * consecutive — that rules out false positives such as CAT-2025-2030, which
 * matters because a false positive here ends up deleting content.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class academic_year {

    /** @var string Default month the academic year starts in (September). */
    public const DEFAULT_START_MONTH = 9;

    /** @var string[] Placeholders that capture the STARTING year of the course. */
    private const START_TOKENS = ['{YEAR}', '{YY}'];

    /** @var string[] Placeholders that capture the year AFTER the starting one. */
    private const NEXT_TOKENS = ['{NEXTYEAR}', '{NEXTYY}'];

    /** @var string Regex compiled from the mask. */
    private string $regex;

    /** @var array Capture group index per role: start, next (0 = absent). */
    private array $groups;

    /** @var array Whether each year role is two-digit. */
    private array $short;

    /** @var string The mask this instance was compiled from. */
    private string $mask;

    /**
     * Compile a mask.
     *
     * @param string $mask Naming mask, e.g. CAT-{YEAR}-{NEXTYEAR}.
     * @throws moodle_exception When the mask carries no year placeholder or does not compile.
     */
    public function __construct(string $mask) {
        [$this->regex, $this->groups, $this->short] = self::compile($mask);
        $this->mask = trim($mask);
    }

    /**
     * The idnumber a given academic year would have with this mask.
     *
     * @param self $mask Compiled mask.
     * @param int $year Starting academic year.
     * @return string
     */
    public static function example_for(self $mask, int $year): string {
        return self::example($mask->mask, $year);
    }

    /**
     * Whether a mask is usable.
     *
     * @param string $mask Naming mask.
     * @return bool
     */
    public static function is_valid_mask(string $mask): bool {
        try {
            new self($mask);
            return true;
        } catch (moodle_exception $e) {
            return false;
        }
    }

    /**
     * Starting academic year of an idnumber, or null when it does not match.
     *
     * @param string $idnumber Category idnumber.
     * @return int|null Four-digit starting year.
     */
    public function year_of(string $idnumber): ?int {
        if (!preg_match($this->regex, trim($idnumber), $matches)) {
            return null;
        }

        $start = $this->captured($matches, 'start');
        $next = $this->captured($matches, 'next');

        // The starting year comes from {YEAR}/{YY}; with only {NEXTYEAR} it is that minus one.
        if ($start === null) {
            if ($next === null) {
                return null;
            }
            $start = $next - 1;
        }

        // Consecutiveness: a real academic year spans N and N+1.
        if ($next !== null && $next !== $start + 1) {
            return null;
        }

        return $start;
    }

    /**
     * Render the mask for a given starting year (previews and examples).
     *
     * @param string $mask Naming mask.
     * @param int $year Starting academic year.
     * @return string The idnumber such a year would have.
     */
    public static function example(string $mask, int $year): string {
        $replacements = [
            '{YEAR}' => (string) $year,
            '{NEXTYEAR}' => (string) ($year + 1),
            '{YY}' => substr((string) $year, -2),
            '{NEXTYY}' => substr((string) ($year + 1), -2),
            '{ANY}' => 'XXX',
            '{DIGITS}' => '000',
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $mask);
    }

    /**
     * Current academic year at a given time.
     *
     * Before the start month the academic year is still the previous one: on
     * May 2026 the running course is 2025/26, so the year is 2025.
     *
     * @param int|null $time Reference timestamp (defaults to now).
     * @param int|null $startmonth Month the academic year starts in (defaults to the site setting).
     * @return int Four-digit starting year of the running academic year.
     */
    public static function current_year(?int $time = null, ?int $startmonth = null): int {
        $time = $time ?? time();
        $startmonth = $startmonth ?? self::current_year_start_month();
        $startmonth = max(1, min(12, $startmonth));

        $year = (int) date('Y', $time);
        $month = (int) date('n', $time);

        return $month >= $startmonth ? $year : $year - 1;
    }

    /**
     * Month the academic year starts in, as configured for this site.
     *
     * @return int Month number, 1-12.
     */
    public static function current_year_start_month(): int {
        $configured = get_config('local_coursetransfermanager', 'academicyearstartmonth');
        if ($configured === false || $configured === '') {
            return self::DEFAULT_START_MONTH;
        }

        return max(1, min(12, (int) $configured));
    }

    /**
     * Value captured for a year role, normalised to four digits.
     *
     * @param array $matches preg_match result.
     * @param string $role start or next.
     * @return int|null
     */
    private function captured(array $matches, string $role): ?int {
        $group = $this->groups[$role];
        if ($group === 0 || !isset($matches[$group]) || $matches[$group] === '') {
            return null;
        }
        $value = (int) $matches[$group];
        // Two-digit years belong to this century: 25 -> 2025.
        return $this->short[$role] ? 2000 + $value : $value;
    }

    /**
     * Compile a mask into a regex plus the capture group map.
     *
     * @param string $mask Naming mask.
     * @return array [regex, groups, short]
     * @throws moodle_exception When there is no year placeholder or the regex does not compile.
     */
    private static function compile(string $mask): array {
        $mask = trim($mask);
        if ($mask === '') {
            throw new moodle_exception('maskempty', 'local_coursetransfermanager');
        }

        $tokens = array_merge(self::START_TOKENS, self::NEXT_TOKENS, ['{ANY}', '{DIGITS}']);

        // Split keeping the placeholders, so everything else can be quoted as literal.
        $parts = preg_split('/(' . implode('|', array_map(
            static fn(string $token): string => preg_quote($token, '/'), $tokens)) . ')/',
            $mask, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $groups = ['start' => 0, 'next' => 0];
        $short = ['start' => false, 'next' => false];
        $group = 0;
        $regex = '';

        foreach ($parts as $part) {
            if (in_array($part, self::START_TOKENS, true)) {
                if ($groups['start'] !== 0) {
                    throw new moodle_exception('maskduplicatedyear', 'local_coursetransfermanager');
                }
                $group++;
                $groups['start'] = $group;
                $short['start'] = ($part === '{YY}');
                $regex .= $short['start'] ? '(\d{2})' : '(20\d{2})';
            } else if (in_array($part, self::NEXT_TOKENS, true)) {
                if ($groups['next'] !== 0) {
                    throw new moodle_exception('maskduplicatedyear', 'local_coursetransfermanager');
                }
                $group++;
                $groups['next'] = $group;
                $short['next'] = ($part === '{NEXTYY}');
                $regex .= $short['next'] ? '(\d{2})' : '(20\d{2})';
            } else if ($part === '{ANY}') {
                $group++;
                $regex .= '(.+?)';
            } else if ($part === '{DIGITS}') {
                $group++;
                $regex .= '(\d+)';
            } else {
                $regex .= preg_quote($part, '/');
            }
        }

        if ($groups['start'] === 0 && $groups['next'] === 0) {
            throw new moodle_exception('masknoyear', 'local_coursetransfermanager');
        }

        $regex = '/^' . $regex . '$/';
        if (@preg_match($regex, '') === false) {
            throw new moodle_exception('maskinvalid', 'local_coursetransfermanager');
        }

        return [$regex, $groups, $short];
    }
}
