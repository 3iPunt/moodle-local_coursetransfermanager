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

namespace local_coursetransfermanager\event;

use context_system;

/**
 * Common shape of the audit events of the destructive lifecycle.
 *
 * other: category (name), rule (which safety rule allowed or held it), detail (optional reason).
 *
 * @package    local_coursetransfermanager
 * @copyright  2026 3iPunt (contacte@tresipunt.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class lifecycle_event extends \core\event\base {
    /** @var string Table of the object (an execution or a pruning candidate). */
    protected const OBJECTTABLE = 'local_coursetransfermanager_executions';

    /** @var string CRUD letter. */
    protected const CRUD = 'u';

    /**
     * Fire the event in the system context.
     *
     * @param int $objectid Execution or pruning candidate id.
     * @param string $category Category name.
     * @param string $rule Rule that allowed or held the action.
     * @param int|null $relateduserid User the action was done on behalf of (the task creator).
     * @param string $detail Human reason, when there is one.
     * @return void
     */
    public static function log(
        int $objectid,
        string $category,
        string $rule,
        ?int $relateduserid = null,
        string $detail = ''
    ): void {
        $data = [
            'context' => context_system::instance(),
            'objectid' => $objectid,
            'other' => ['category' => $category, 'rule' => $rule, 'detail' => $detail],
        ];
        if ($relateduserid) {
            $data['relateduserid'] = $relateduserid;
        }
        static::create($data)->trigger();
    }

    /**
     * Init the event.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = static::CRUD;
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = static::OBJECTTABLE;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        $parts = explode('\\', static::class);
        return get_string('event_' . end($parts), 'local_coursetransfermanager');
    }

    /**
     * Non-localised description for the logs.
     *
     * @return string
     */
    public function get_description() {
        $parts = explode('\\', static::class);
        $text = "The user with id '{$this->userid}' – " . str_replace('_', ' ', end($parts))
            . " for category '" . ($this->other['category'] ?? '') . "' (id {$this->objectid})"
            . " under rule '" . ($this->other['rule'] ?? '') . "'";
        if (!empty($this->relateduserid)) {
            $text .= ", on behalf of the user with id '{$this->relateduserid}'";
        }
        if (!empty($this->other['detail'])) {
            $text .= ': ' . $this->other['detail'];
        }
        return $text . '.';
    }

    /**
     * Validate the data.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['category']) || !isset($this->other['rule'])) {
            throw new \coding_exception('The \'category\' and \'rule\' values must be set in other.');
        }
    }

    /**
     * The plugin's tables are not part of course backups.
     *
     * @return int
     */
    public static function get_objectid_mapping() {
        return self::NOT_MAPPED;
    }

    /**
     * Nothing in other needs mapping on restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
