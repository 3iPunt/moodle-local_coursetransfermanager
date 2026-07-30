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
 * Origin site helpers for local_coursetransfermanager.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursetransfermanager\manager;

use local_coursetransfer\coursetransfer_sites;
use moodle_exception;
use stdClass;

/**
 * Resolves configured origin sites and formats remote error structures.
 *
 * Site management (pairing, tokens, connection tests) is owned by
 * local_coursetransfer; this helper only reads what it persists.
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class origin {

    /**
     * Resolve a configured origin site to the {host, token} object coursetransfer expects.
     *
     * @param int $originsiteid Origin site id (local_coursetransfer_origin).
     * @return stdClass Site with id, host, token, hosttoken and last test data.
     * @throws moodle_exception When the id is not set or the site does not exist.
     */
    public static function site(int $originsiteid): stdClass {
        if ($originsiteid <= 0) {
            throw new moodle_exception('originsitenotset', 'local_coursetransfermanager');
        }
        $record = coursetransfer_sites::get('origin', $originsiteid);
        $site = new stdClass();
        $site->id = (int) $record->id;
        $site->host = $record->host;
        $site->token = $record->token;
        $site->hosttoken = $record->token;
        $site->lasttest = $record->lasttest ?? null;
        $site->lastteststatus = $record->lastteststatus ?? null;
        return $site;
    }

    /**
     * Flatten a coursetransfer errors structure to a human-readable string.
     *
     * @param array $errors Errors as arrays, objects or strings.
     * @return string
     */
    public static function format_errors(array $errors): string {
        if (empty($errors)) {
            return 'Unknown error';
        }
        $messages = [];
        foreach ($errors as $error) {
            if (is_array($error)) {
                $messages[] = trim(($error['code'] ?? '') . ' ' . ($error['msg'] ?? ''));
            } else if (is_object($error)) {
                $messages[] = trim(($error->code ?? '') . ' ' . ($error->msg ?? ''));
            } else {
                $messages[] = (string) $error;
            }
        }
        return implode(' | ', array_filter($messages));
    }
}
