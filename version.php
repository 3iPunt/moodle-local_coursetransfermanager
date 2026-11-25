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
 * Version details.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 sebas3ipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_coursetransfermanager';
$plugin->version = 2026112401;
$plugin->requires = 2024100700;
$plugin->supported = [405, 503];
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '2.2.3';
$plugin->dependencies = [
    // HARD dependency on CourseTransfer 2.2.1 on THIS platform (2.2.2 recommended): the deletion locks
    // rely on its complete transfers and its restore manifest. The ORIGIN should run 2.2.1 or later too:
    // older origins make backups that the manifest holds, so their deletions wait.
    'local_coursetransfer' => 2026112002,
];
