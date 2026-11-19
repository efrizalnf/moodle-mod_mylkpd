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
 * Backup structure for mod_mylkpd.
 *
 * @package    mod_mylkpd
 * @category   backup
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines what is written to mylkpd.xml.
 */
class backup_mylkpd_activity_structure_step extends backup_activity_structure_step {

    /**
     * Activity settings, answer key questions and, with user data, submissions and their pictures.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $mylkpd = new backup_nested_element('mylkpd', ['id'], [
            'name', 'intro', 'introformat', 'grade', 'rendermode', 'workmode', 'teamsubmission', 'allowresubmit',
            'aienabled', 'timeopen', 'duedate', 'cutoffdate', 'latepenalty', 'latepenaltymode', 'teamsubmitmode',
            'aiautotime', 'timecreated', 'timemodified',
        ]);
        $questions = new backup_nested_element('questions');
        $question = new backup_nested_element('question', ['id'], [
            'sortorder', 'label', 'question', 'answerkey', 'keypoints', 'maxscore', 'anchor', 'timemodified',
        ]);
        $submissions = new backup_nested_element('submissions');
        $submission = new backup_nested_element('submission', ['id'], [
            'userid', 'groupid', 'answers', 'status', 'revision', 'timesubmitted', 'submittedby', 'airesult', 'aitime',
            'grade', 'feedback', 'timecreated', 'timemodified', 'timegraded',
        ]);

        $mylkpd->add_child($questions);
        $questions->add_child($question);
        $mylkpd->add_child($submissions);
        $submissions->add_child($submission);

        // The answer key belongs to the activity, so it is always included (also when importing into another course).
        $mylkpd->set_source_table('mylkpd', ['id' => backup::VAR_ACTIVITYID]);
        $question->set_source_table('mylkpd_questions', ['mylkpdid' => backup::VAR_PARENTID], 'sortorder ASC');
        if ($userinfo) {
            $submission->set_source_table('mylkpd_submissions', ['mylkpdid' => backup::VAR_PARENTID], 'id ASC');
        }

        $submission->annotate_ids('user', 'userid');
        $submission->annotate_ids('group', 'groupid');
        // People named inside the answers (who wrote what, who has submitted) must be restored too.
        if ($userinfo) {
            foreach ($this->users_in_answers() as $userid) {
                $this->annotate_user($userid);
            }
        }

        $mylkpd->annotate_files('mod_mylkpd', 'intro', null);
        $mylkpd->annotate_files('mod_mylkpd', 'worksheet', null);
        $mylkpd->annotate_files('mod_mylkpd', 'pdf', null);
        $submission->annotate_files('mod_mylkpd', 'answerimage', 'id');

        return $this->prepare_activity_structure($mylkpd);
    }

    /**
     * Every user id mentioned inside the stored answers of this activity.
     *
     * @return int[]
     */
    protected function users_in_answers(): array {
        global $DB;
        $ids = [];
        $rs = $DB->get_recordset('mylkpd_submissions', ['mylkpdid' => $this->task->get_activityid()], '', 'id, answers, submittedby');
        foreach ($rs as $sub) {
            $data = json_decode((string) $sub->answers, true) ?: [];
            foreach (['items', 'blocks'] as $kind) {
                foreach ((array) ($data[$kind] ?? []) as $entry) {
                    if (!empty($entry['by'])) {
                        $ids[(int) $entry['by']] = true;
                    }
                }
            }
            foreach (array_keys((array) (json_decode((string) $sub->submittedby, true) ?: [])) as $uid) {
                $ids[(int) $uid] = true;
            }
        }
        $rs->close();
        unset($ids[0]);
        return array_keys($ids);
    }

    /**
     * Adds one user to the backup's user list.
     *
     * @param int $userid
     */
    protected function annotate_user(int $userid): void {
        backup_structure_dbops::insert_backup_ids_record($this->get_backupid(), 'user', $userid);
    }
}
