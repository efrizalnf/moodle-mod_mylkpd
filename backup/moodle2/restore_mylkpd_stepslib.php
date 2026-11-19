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
 * Restore structure for mod_mylkpd.
 *
 * @package    mod_mylkpd
 * @category   backup
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Reads mylkpd.xml back into a (new) course.
 */
class restore_mylkpd_activity_structure_step extends restore_activity_structure_step {
    /**
     * Paths to process.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $paths = [
            new restore_path_element('mylkpd', '/activity/mylkpd'),
            new restore_path_element('mylkpd_question', '/activity/mylkpd/questions/question'),
        ];
        if ($userinfo) {
            $paths[] = new restore_path_element('mylkpd_submission', '/activity/mylkpd/submissions/submission');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * The activity itself. Dates move with the course start date.
     *
     * @param array $data
     */
    protected function process_mylkpd($data) {
        global $DB;
        $data = (object) $data;
        $data->course = $this->get_courseid();
        foreach (['timeopen', 'duedate', 'cutoffdate', 'aiautotime'] as $field) {
            $data->$field = empty($data->$field) ? 0 : $this->apply_date_offset($data->$field);
        }
        if ($data->grade < 0) {
            // A scale: use the scale as restored on this site.
            $data->grade = -(int) $this->get_mappingid('scale', abs($data->grade), abs($data->grade));
        }
        $data->timemodified = time();
        $newid = $DB->insert_record('mylkpd', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * One answer-key question.
     *
     * @param array $data
     */
    protected function process_mylkpd_question($data) {
        global $DB;
        $data = (object) $data;
        unset($data->id);
        $data->mylkpdid = $this->get_new_parentid('mylkpd');
        $DB->insert_record('mylkpd_questions', $data);
    }

    /**
     * One student's or group's work, with user ids inside the answers mapped to this site.
     *
     * @param array $data
     */
    protected function process_mylkpd_submission($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        unset($data->id);
        $data->mylkpdid = $this->get_new_parentid('mylkpd');
        $data->userid = (int) $this->get_mappingid('user', $data->userid, 0);
        $data->groupid = empty($data->groupid) ? 0 : (int) $this->get_mappingid('group', $data->groupid, 0);
        if (!$data->userid && !$data->groupid) {
            return; // The person (or group) is not part of this restore.
        }
        $data->answers = $this->map_answer_users((string) $data->answers);
        if (!empty($data->submittedby)) {
            $mapped = [];
            foreach ((array) (json_decode($data->submittedby, true) ?: []) as $uid => $time) {
                if ($newuid = $this->get_mappingid('user', $uid)) {
                    $mapped[$newuid] = $time;
                }
            }
            $data->submittedby = $mapped ? json_encode($mapped) : null;
        }
        $newid = $DB->insert_record('mylkpd_submissions', $data);
        // Pictures are stored under the submission id; filled-in rubrics refer to it too.
        $this->set_mapping('mylkpd_submission', $oldid, $newid, true);
        $this->set_mapping(restore_gradingform_plugin::itemid_mapping('submissions'), $oldid, $newid);
    }

    /**
     * Maps the author ("by") of every answer and annotation to the user id on this site.
     *
     * @param string $json
     * @return string
     */
    protected function map_answer_users(string $json): string {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        foreach (['items', 'blocks'] as $kind) {
            foreach ((array) ($data[$kind] ?? []) as $key => $entry) {
                if (is_array($entry) && !empty($entry['by'])) {
                    $data[$kind][$key]['by'] = (int) $this->get_mappingid('user', $entry['by'], 0);
                }
            }
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Rebuilds the calendar events once everything is restored: Moodle does not restore "action" events
     * (the deadline shown on the Timeline), the activity has to create them itself.
     */
    protected function after_restore() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/mylkpd/lib.php');
        $mylkpd = $DB->get_record('mylkpd', ['id' => $this->task->get_activityid()]);
        if ($mylkpd) {
            mylkpd_refresh_instance_events($mylkpd, (int) $this->task->get_moduleid());
        }
    }

    /**
     * Restores the files: description, worksheet, its converted PDF and the students' pictures.
     */
    protected function after_execute() {
        $this->add_related_files('mod_mylkpd', 'intro', null);
        $this->add_related_files('mod_mylkpd', 'worksheet', null);
        $this->add_related_files('mod_mylkpd', 'pdf', null);
        $this->add_related_files('mod_mylkpd', 'answerimage', 'mylkpd_submission');
    }
}
