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
 * Test data generator for mod_mylkpd.
 *
 * @package    mod_mylkpd
 * @category   test
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_mylkpd_generator extends testing_module_generator {
    /**
     * Creates an activity with sensible defaults (no worksheet file).
     *
     * @param array|stdClass|null $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (array) ($record ?? []) + [
            'grade' => 100,
            'workmode' => 'annotate',
            'teamsubmission' => 0,
            'teamsubmitmode' => 0,
            'allowresubmit' => 1,
            'aienabled' => 0,
            'timeopen' => 0,
            'duedate' => 0,
            'cutoffdate' => 0,
            'latepenalty' => 0,
            'latepenaltymode' => 0,
            'aiautotime' => 0,
            'worksheet' => file_get_unused_draft_itemid(),
        ];
        return parent::create_instance($record, (array) $options);
    }

    /**
     * Creates a submission. Arrays given for answers and submittedby are stored as JSON.
     *
     * @param array $record must contain mylkpdid, and userid or groupid
     * @return stdClass the record as stored
     */
    public function create_submission(array $record): stdClass {
        global $DB;
        foreach (['answers', 'submittedby'] as $field) {
            if (isset($record[$field]) && is_array($record[$field])) {
                $record[$field] = json_encode($record[$field]);
            }
        }
        $now = time();
        $record += [
            'userid' => 0,
            'groupid' => 0,
            'answers' => json_encode(['items' => [], 'blocks' => []]),
            'status' => 0,
            'revision' => 1,
            'timesubmitted' => 0,
            'submittedby' => null,
            'grade' => null,
            'feedback' => '',
            'timecreated' => $now,
            'timemodified' => $now,
            'timegraded' => 0,
            'aitime' => 0,
        ];
        $record['id'] = $DB->insert_record('mylkpd_submissions', (object) $record);
        return (object) $record;
    }
}
