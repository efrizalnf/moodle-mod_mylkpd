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
 * Checks one student's (or group's) essay answers with AI and stores the suggestion.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_submission extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'targetuser' => new external_value(PARAM_INT, 'Student', VALUE_DEFAULT, 0),
            'targetgroup' => new external_value(PARAM_INT, 'Group', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Runs the check. Problems the teacher should see (no answer key, nothing to check, AI errors) are returned
     * as a message, not thrown, so the page can show them next to the right student.
     *
     * @param int $cmid
     * @param int $targetuser
     * @param int $targetgroup
     * @return array
     */
    public static function execute(int $cmid, int $targetuser = 0, int $targetgroup = 0): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/mylkpd/locallib.php');
        $params = self::validate_parameters(self::execute_parameters(), compact('cmid', 'targetuser', 'targetgroup'));
        [, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'mylkpd');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/mylkpd:grade', $context);
        $mylkpd = $DB->get_record('mylkpd', ['id' => $cm->instance], '*', MUST_EXIST);

        try {
            if (!mylkpd_ai_enabled($mylkpd)) {
                throw new \moodle_exception('aidisabled', 'mod_mylkpd');
            }
            $questions = mylkpd_get_questions($mylkpd);
            if (!$questions) {
                throw new \moodle_exception('noanswerkeys', 'mod_mylkpd');
            }
            $target = mylkpd_teacher_target($mylkpd, $params['targetuser'], $params['targetgroup']);
            $submission = mylkpd_target_submission($mylkpd, $cm, (int) $USER->id, $target, false);
            if (!$submission) {
                throw new \moodle_exception('nothingtocheck', 'mod_mylkpd');
            }
            \core_php_time_limit::raise(180);
            $result = \mod_mylkpd\ai\grader::check($questions, mylkpd_decode_data($submission->answers));
            $DB->update_record('mylkpd_submissions', (object) ['id' => $submission->id,
                'airesult' => json_encode($result, JSON_UNESCAPED_UNICODE), 'aitime' => $result['time']]);
            $result['timetext'] = userdate($result['time']);
            return ['data' => json_encode(['result' => $result], JSON_UNESCAPED_UNICODE)];
        } catch (\moodle_exception $e) {
            return ['data' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE)];
        }
    }

    /**
     * Return value: the result (per-question scores keyed by question) or an error message, as JSON.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'JSON with "result" or "error"'),
        ]);
    }
}
