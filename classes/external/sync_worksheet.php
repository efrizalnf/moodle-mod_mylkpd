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

namespace mod_mylkpd\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Saves a student's (or group's) changes to a worksheet and returns what others changed.
 * Teachers use it with targetuser/targetgroup to annotate a student's worksheet while grading.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_worksheet extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'rev' => new external_value(PARAM_INT, 'Last revision the page has', VALUE_DEFAULT, 0),
            'ops' => new external_value(PARAM_RAW, 'Changes as a JSON list', VALUE_DEFAULT, '[]'),
            'block' => new external_value(PARAM_ALPHANUMEXT, 'Paragraph the user is editing (presence)', VALUE_DEFAULT, ''),
            'targetuser' => new external_value(PARAM_INT, 'Teacher: student whose worksheet is annotated', VALUE_DEFAULT, 0),
            'targetgroup' => new external_value(PARAM_INT, 'Teacher: group whose worksheet is annotated', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Applies the changes and returns the current state.
     *
     * @param int $cmid
     * @param int $rev
     * @param string $ops
     * @param string $block
     * @param int $targetuser
     * @param int $targetgroup
     * @return array
     */
    public static function execute(int $cmid, int $rev = 0, string $ops = '[]', string $block = '', int $targetuser = 0,
            int $targetgroup = 0): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/mylkpd/locallib.php');
        $params = self::validate_parameters(self::execute_parameters(), compact('cmid', 'rev', 'ops', 'block', 'targetuser',
            'targetgroup'));
        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'mylkpd');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        $mylkpd = $DB->get_record('mylkpd', ['id' => $cm->instance], '*', MUST_EXIST);

        $teacherfor = null;
        if ($params['targetuser'] || $params['targetgroup']) {
            require_capability('mod/mylkpd:grade', $context);
            $teacherfor = mylkpd_teacher_target($mylkpd, $params['targetuser'], $params['targetgroup']);
            if ($teacherfor['groupid'] && groups_get_group($teacherfor['groupid'])->courseid != $course->id) {
                throw new \moodle_exception('invalidgroupid');
            }
            if ($teacherfor['userid'] && !is_enrolled($context, $teacherfor['userid'], 'mod/mylkpd:submit')) {
                throw new \moodle_exception('invaliduser');
            }
        } else {
            require_capability('mod/mylkpd:submit', $context, null, false);
        }

        $changes = json_decode($params['ops'], true);
        $result = mylkpd_sync($mylkpd, $cm, (int) $USER->id, $params['rev'], is_array($changes) ? $changes : [],
            (string) $params['block'], $teacherfor);
        return ['data' => json_encode($result, JSON_UNESCAPED_UNICODE)];
    }

    /**
     * Return value: the state as JSON (its maps are keyed by item ids, which a fixed structure cannot describe).
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'Revision, items, paragraphs and presence as JSON'),
        ]);
    }
}
