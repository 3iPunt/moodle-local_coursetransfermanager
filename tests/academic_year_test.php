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

use local_coursetransfermanager\manager\academic_year;

/**
 * Tests for the academic year recognition (2.1 rotation model).
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\academic_year
 */
final class academic_year_test extends \advanced_testcase {

    /**
     * The three formats the client uses all resolve to the same starting year.
     */
    public function test_client_formats(): void {
        $this->assertSame(2025, (new academic_year('CAT-{YEAR}-{NEXTYEAR}'))->year_of('CAT-2025-2026'));
        $this->assertSame(2025, (new academic_year('CAT-{YEAR}-{NEXTYY}'))->year_of('CAT-2025-26'));
        $this->assertSame(2025, (new academic_year('CAT-{YY}-{NEXTYY}'))->year_of('CAT-25-26'));
    }

    /**
     * A single-year mask works too (SJD2025).
     */
    public function test_single_year_mask(): void {
        $mask = new academic_year('SJD{YEAR}');
        $this->assertSame(2025, $mask->year_of('SJD2025'));
        $this->assertNull($mask->year_of('SJD25'));
        $this->assertNull($mask->year_of('OTHER2025'));
    }

    /**
     * Wildcards let ONE task cover every degree.
     */
    public function test_wildcards_cover_several_degrees(): void {
        $mask = new academic_year('{ANY}-{YEAR}-{NEXTYEAR}');
        $this->assertSame(2025, $mask->year_of('GINF-2025-2026'));
        $this->assertSame(2025, $mask->year_of('MED-2025-2026'));
        $this->assertSame(2024, $mask->year_of('GINF-2024-2025'));
        // The wildcard is not optional: it must match something.
        $this->assertNull($mask->year_of('-2025-2026'));

        $digits = new academic_year('PLAN{DIGITS}-{YEAR}');
        $this->assertSame(2025, $digits->year_of('PLAN2019-2025'));
        $this->assertNull($digits->year_of('PLANX-2025'));
    }

    /**
     * Non-consecutive years are NOT an academic year: a false positive here
     * would end up deleting content.
     */
    public function test_consecutiveness_is_required(): void {
        $mask = new academic_year('CAT-{YEAR}-{NEXTYEAR}');
        $this->assertSame(2025, $mask->year_of('CAT-2025-2026'));
        $this->assertNull($mask->year_of('CAT-2025-2030'));
        $this->assertNull($mask->year_of('CAT-2025-2025'));

        $short = new academic_year('CAT-{YY}-{NEXTYY}');
        $this->assertSame(2025, $short->year_of('CAT-25-26'));
        $this->assertNull($short->year_of('CAT-25-30'));
    }

    /**
     * Anything that does not match the mask is ignored entirely.
     */
    public function test_non_matching_is_ignored(): void {
        $mask = new academic_year('CAT-{YEAR}-{NEXTYEAR}');
        foreach (['MED1042', 'FACULTAD', '', 'CAT-2025', 'PRE-CAT-2025-2026', 'CAT-1999-2000'] as $idnumber) {
            $this->assertNull($mask->year_of($idnumber), $idnumber);
        }
    }

    /**
     * Literal text around the placeholders is escaped, so separators are free.
     */
    public function test_literals_and_separators(): void {
        $this->assertSame(2025, (new academic_year('{YEAR}/{NEXTYY}'))->year_of('2025/26'));
        $this->assertSame(2025, (new academic_year('CURS {YEAR}-{NEXTYEAR} (A)'))->year_of('CURS 2025-2026 (A)'));
        // A dot in the literal must not behave as "any character".
        $mask = new academic_year('C.{YEAR}');
        $this->assertSame(2025, $mask->year_of('C.2025'));
        $this->assertNull($mask->year_of('CX2025'));
    }

    /**
     * Masks without a year, empty or duplicated are rejected.
     */
    public function test_invalid_masks(): void {
        foreach (['', 'CAT-NOYEAR', '{ANY}', '{YEAR}-{YEAR}'] as $mask) {
            $this->assertFalse(academic_year::is_valid_mask($mask), $mask);
        }
        foreach (['CAT-{YEAR}-{NEXTYEAR}', 'SJD{YEAR}', '{YY}-{NEXTYY}'] as $mask) {
            $this->assertTrue(academic_year::is_valid_mask($mask), $mask);
        }
    }

    /**
     * The current academic year depends on the start month: in May the
     * running course is still the previous one.
     */
    public function test_current_year_uses_start_month(): void {
        $this->resetAfterTest();

        $may = mktime(12, 0, 0, 5, 15, 2026);
        $october = mktime(12, 0, 0, 10, 15, 2026);
        $firstofseptember = mktime(2, 0, 0, 9, 1, 2026);

        // Default start month (September).
        $this->assertSame(2025, academic_year::current_year($may));
        $this->assertSame(2026, academic_year::current_year($october));
        $this->assertSame(2026, academic_year::current_year($firstofseptember));

        // A site whose courses start in January behaves as the natural year.
        set_config('academicyearstartmonth', 1, 'local_coursetransfermanager');
        $this->assertSame(2026, academic_year::current_year($may));
    }

    /**
     * example() renders the mask, for previews and help texts.
     */
    public function test_example(): void {
        $this->assertSame('CAT-2025-2026', academic_year::example('CAT-{YEAR}-{NEXTYEAR}', 2025));
        $this->assertSame('CAT-2025-26', academic_year::example('CAT-{YEAR}-{NEXTYY}', 2025));
        $this->assertSame('CAT-25-26', academic_year::example('CAT-{YY}-{NEXTYY}', 2025));
        $this->assertSame('SJD2025', academic_year::example('SJD{YEAR}', 2025));
    }
}
