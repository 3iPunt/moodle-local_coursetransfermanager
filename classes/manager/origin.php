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

use local_coursetransfer\api\request;
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
     * Categories of an origin platform, with their idnumber.
     *
     * Reads the backend WS of local_coursetransfer as-is (no changes to the
     * inter-platform contract). Tolerates older remotes: anything the remote
     * does not report is simply absent, and a failure returns an empty list
     * instead of breaking the caller — a rotation that cannot see the origin
     * must archive nothing, never guess.
     *
     * @param int $originsiteid Origin site id.
     * @param int $limit Maximum categories to read.
     * @return stdClass[] {id, name, idnumber, parentid, totalcourses}
     */
    public static function categories(int $originsiteid, int $limit = 500): array {
        return self::categories_result($originsiteid, $limit)->categories;
    }

    /**
     * Categories in the origin plus the reason when there are none.
     *
     * A rotation that cannot see the origin must archive nothing, never guess; the
     * wizard, on the other hand, has to tell the admin exactly why it saw nothing.
     *
     * @param int $originsiteid Origin site id (local_coursetransfer_origin).
     * @param int $limit Maximum categories to ask for.
     * @return stdClass {categories: stdClass[], error: string} — error is empty on success.
     */
    public static function categories_result(int $originsiteid, int $limit = 500): stdClass {
        global $USER;

        $result = (object) ['categories' => [], 'error' => ''];

        try {
            $site = self::site($originsiteid);
        } catch (moodle_exception $e) {
            $result->error = $e->getMessage();
            return $result;
        }

        // Reuse coursetransfer's own client: it resolves the identity field the
        // remote authenticates against (origin_field_search_user) and honours the
        // cURL security bypass site-to-site setups need. Rolling our own call here
        // would break that contract.
        try {
            $response = (new request($site))->origin_get_categories($USER, 0, $limit);
        } catch (\Throwable $e) {
            $result->error = $e->getMessage();
            return $result;
        }

        if (empty($response->success)) {
            $result->error = self::format_errors((array) ($response->errors ?? []));
            return $result;
        }

        foreach ((array) ($response->data ?? []) as $row) {
            $row = (object) $row;
            if (empty($row->id)) {
                continue;
            }
            $result->categories[] = (object) [
                'id' => (int) $row->id,
                'name' => (string) ($row->name ?? ''),
                'idnumber' => (string) ($row->idnumber ?? ''),
                'parentid' => (int) ($row->parentid ?? 0),
                'totalcourses' => (int) ($row->totalcourseschild ?? $row->totalcourses ?? 0),
            ];
        }

        return $result;
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
