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

namespace local_coursetransfermanager;

use local_coursetransfermanager\manager\schedule;

/**
 * Tests for the direct cron schedule calculator.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\schedule
 */
final class schedule_test extends \advanced_testcase {

    /**
     * The yearly USJ-style expression resolves to the exact next 1st of September.
     */
    public function test_next_yearly(): void {
        $from = mktime(12, 0, 0, 7, 28, 2026);
        $next = schedule::next('0 2 1 9 *', $from);
        $this->assertSame(mktime(2, 0, 0, 9, 1, 2026), $next);

        // From just after the run, it jumps a full year.
        $next2 = schedule::next('0 2 1 9 *', $next);
        $this->assertSame(mktime(2, 0, 0, 9, 1, 2027), $next2);
    }

    /**
     * Monthly and sub-daily expressions resolve correctly.
     */
    public function test_next_monthly_and_hourly(): void {
        $from = mktime(12, 0, 0, 7, 28, 2026);
        $this->assertSame(mktime(3, 30, 0, 8, 15, 2026), schedule::next('30 3 15 * *', $from));
        $this->assertSame(mktime(18, 0, 0, 7, 28, 2026), schedule::next('0 */6 * * *', $from));
    }

    /**
     * Day-of-week constraints are honoured (AND semantics with day-of-month *).
     */
    public function test_next_day_of_week(): void {
        // 2026-07-28 is a Tuesday; next Monday 04:15 is 2026-08-03.
        $from = mktime(12, 0, 0, 7, 28, 2026);
        $this->assertSame(mktime(4, 15, 0, 8, 3, 2026), schedule::next('15 4 * * 1', $from));
    }

    /**
     * A 29th of February expression finds the next leap year.
     */
    public function test_next_leap_year(): void {
        $from = mktime(12, 0, 0, 7, 28, 2026);
        $this->assertSame(mktime(0, 0, 0, 2, 29, 2028), schedule::next('0 0 29 2 *', $from));
    }

    /**
     * previous() is the exact inverse of next().
     */
    public function test_previous_matches_next(): void {
        $from = mktime(12, 0, 0, 7, 28, 2026);
        foreach (['0 2 1 9 *', '30 3 15 * *', '15 4 * * 1'] as $expression) {
            $next = schedule::next($expression, $from);
            $this->assertSame($next, schedule::previous($expression, $next + 60), $expression);
        }
    }

    /**
     * Invalid expressions are rejected everywhere, never fatals.
     */
    public function test_invalid_expressions(): void {
        foreach (['nonsense', '61 2 1 9 *', '0 2 1 13 *', '0 2 1 9', ''] as $bad) {
            $this->assertFalse(schedule::is_valid($bad), $bad);
            $this->assertNull(schedule::next($bad, time()), $bad);
            $this->assertNull(schedule::previous($bad, time()), $bad);
        }
        $this->assertTrue(schedule::is_valid('0 2 1 9 *'));
    }

    /**
     * upcoming() returns consecutive future runs.
     */
    public function test_upcoming(): void {
        $from = mktime(12, 0, 0, 7, 28, 2026);
        $runs = schedule::upcoming('0 2 1 9 *', $from, 3);
        $this->assertCount(3, $runs);
        $this->assertSame(mktime(2, 0, 0, 9, 1, 2026), $runs[0]);
        $this->assertSame(mktime(2, 0, 0, 9, 1, 2027), $runs[1]);
        $this->assertSame(mktime(2, 0, 0, 9, 1, 2028), $runs[2]);
    }
}
