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

use local_coursetransfermanager\manager\rotation;
use stdClass;

/**
 * Tests for the automatic academic-year rotation.
 *
 * The whole point of this class is that nobody edits the task every September:
 * the two retention windows decide, on their own, what is archived and what is
 * deleted for good on any given run.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursetransfermanager\manager\rotation
 */
final class rotation_test extends \advanced_testcase {

    /**
     * A task draft with the policy under test.
     *
     * @param int $originkeep Academic years kept in the origin.
     * @param int $destinationkeep Academic years kept in the archive.
     * @param string $mask Naming mask.
     * @param int $targetcategoryid Archive category id.
     * @return stdClass
     */
    private function task(int $originkeep, int $destinationkeep,
            string $mask = 'CAT-{YEAR}-{NEXTYEAR}', int $targetcategoryid = 0): stdClass {
        return (object) [
            'id' => 0,
            'originsiteid' => 0,
            'categorypattern' => $mask,
            'originkeepyears' => $originkeep,
            'destinationkeepyears' => $destinationkeep,
            'targetcategoryid' => $targetcategoryid,
        ];
    }

    /**
     * September 2026: the running course is 2026/27.
     *
     * @return int
     */
    private function september(int $year = 2026): int {
        return mktime(2, 0, 0, 9, 1, $year);
    }

    /**
     * The two thresholds are the whole model: archive at A−P, prune at A−P−V.
     */
    public function test_thresholds(): void {
        $this->resetAfterTest();

        $task = $this->task(2, 4);
        $this->assertSame(2024, rotation::archive_threshold($task, $this->september()));
        $this->assertSame(2020, rotation::prune_threshold($task, $this->september()));

        // Keep five years in production and the archive starts four years later.
        $wide = $this->task(5, 4);
        $this->assertSame(2021, rotation::archive_threshold($wide, $this->september()));
        $this->assertSame(2017, rotation::prune_threshold($wide, $this->september()));
    }

    /**
     * Same policy, next year: everything shifts by one on its own.
     *
     * This is the property that makes the task self-maintaining.
     */
    public function test_rotates_without_touching_the_task(): void {
        $this->resetAfterTest();

        $task = $this->task(2, 4);
        $this->assertSame(2024, rotation::archive_threshold($task, $this->september(2026)));
        $this->assertSame(2025, rotation::archive_threshold($task, $this->september(2027)));
        $this->assertSame(2026, rotation::archive_threshold($task, $this->september(2028)));
    }

    /**
     * Before the start month the running course is still the previous one.
     */
    public function test_start_month_moves_the_whole_policy(): void {
        $this->resetAfterTest();

        set_config('academicyearstartmonth', 9, 'local_coursetransfermanager');
        $task = $this->task(2, 4);

        // May 2027 still belongs to the 2026/27 course.
        $this->assertSame(2024, rotation::archive_threshold($task, mktime(2, 0, 0, 5, 1, 2027)));
        // September 2027 opens 2027/28.
        $this->assertSame(2025, rotation::archive_threshold($task, $this->september(2027)));
    }

    /**
     * The projection reproduces the conservation table the client works from.
     */
    public function test_projection_matches_the_conservation_table(): void {
        $this->resetAfterTest();

        $task = $this->task(2, 4);
        $rows = rotation::project($task, 6, $this->september(2026));

        $this->assertCount(6, $rows);

        // First row: the run happening now.
        $this->assertSame(2026, $rows[0]->year);
        $this->assertTrue($rows[0]->iscurrent);
        $this->assertSame([2026, 2025], array_column($rows[0]->production, 'year'));
        $this->assertSame([2024, 2023, 2022, 2021], array_column($rows[0]->archive, 'year'));
        $this->assertSame([2020], array_column($rows[0]->deleted, 'year'));

        // The run of 2027/28 deletes 2021/22 for good — the line that matters.
        $this->assertSame(2027, $rows[1]->year);
        $this->assertSame([2021], array_column($rows[1]->deleted, 'year'));

        // Idnumbers are projected with the mask, not invented.
        $this->assertSame('CAT-2024-2025', $rows[0]->archive[0]->idnumber);
    }

    /**
     * A mask with no year placeholder can rotate nothing: it must not guess.
     */
    public function test_unusable_mask_projects_nothing(): void {
        $this->resetAfterTest();

        $task = $this->task(2, 4, 'CAT-FIXED');
        $this->assertNull(rotation::mask($task));
        $this->assertSame([], rotation::project($task, 6, $this->september()));
        $this->assertSame([], rotation::to_archive($task, $this->september()));
    }

    /**
     * Adoption: what the task did not bring is invisible to the pruning until
     * somebody says otherwise, and that decision is reversible.
     */
    public function test_adoption_is_explicit_and_reversible(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $archive = $generator->create_category(['name' => 'Archive']);
        $mine = $generator->create_category(['name' => '2020/21', 'idnumber' => 'CAT-2020-2021',
            'parent' => $archive->id]);
        $foreign = $generator->create_category(['name' => 'Medicine', 'idnumber' => 'MED1042',
            'parent' => $archive->id]);

        $task = $this->task(2, 4, 'CAT-{YEAR}-{NEXTYEAR}', (int) $archive->id);
        $task->id = $DB->insert_record('local_ctm_tasks', (object) [
            'name' => 'Yearly archive',
            'originsiteid' => 0,
            'categorypattern' => $task->categorypattern,
            'originkeepyears' => 2,
            'destinationkeepyears' => 4,
            'targetcategoryid' => $archive->id,
            'cronexpression' => '0 2 1 9 *',
            'retentiondays' => 30,
            'restoreuserdata' => 1,
            'enabled' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Nothing is managed yet: the task brought neither of them.
        $this->assertSame([], rotation::managed_category_ids($task));

        // Only the one matching the mask is offered for adoption.
        $adoptable = array_column(rotation::adoptable($task), 'idnumber');
        $this->assertSame(['CAT-2020-2021'], $adoptable);
        $this->assertNotContains('MED1042', $adoptable);

        rotation::adopt((int) $task->id, (int) $mine->id, 2);
        $this->assertSame([(int) $mine->id], rotation::managed_category_ids($task));
        // Adopting twice is not an error and does not duplicate.
        rotation::adopt((int) $task->id, (int) $mine->id, 2);
        $this->assertCount(1, $DB->get_records('local_ctm_adopted', ['taskid' => $task->id]));
        // Once adopted it is no longer offered.
        $this->assertSame([], rotation::adoptable($task));

        rotation::unadopt((int) $task->id, (int) $mine->id);
        $this->assertSame([], rotation::managed_category_ids($task));
        $this->assertSame(['CAT-2020-2021'], array_column(rotation::adoptable($task), 'idnumber'));

        // A category with no idnumber never becomes a candidate.
        $generator->create_category(['name' => 'Loose', 'parent' => $archive->id]);
        $this->assertSame(['CAT-2020-2021'], array_column(rotation::adoptable($task), 'idnumber'));
        unset($foreign);
    }

    /**
     * Adoption needs a destination: with no archive category there is nothing
     * to adopt from, and the code must not go looking site-wide.
     */
    public function test_no_archive_category_means_nothing_adoptable(): void {
        $this->resetAfterTest();

        $this->assertSame([], rotation::adoptable($this->task(2, 4)));
    }

    /**
     * The per-run cap exists so one delayed year cannot flood the origin with
     * simultaneous restorations.
     */
    public function test_max_per_tick_is_a_positive_cap(): void {
        $this->resetAfterTest();

        $this->assertGreaterThan(0, rotation::max_per_tick());

        set_config('maxarchivepertick', 7, 'local_coursetransfermanager');
        $this->assertSame(7, rotation::max_per_tick());
    }
}
