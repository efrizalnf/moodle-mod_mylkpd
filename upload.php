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
 * Receives an image a student adds to the worksheet and stores it with their (or their group's) submission.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'mylkpd');
require_login($course, false, $cm, false, true);
require_sesskey();

$context = context_module::instance($cm->id);
$mylkpd = $DB->get_record('mylkpd', ['id' => $cm->instance], '*', MUST_EXIST);

// A teacher annotating a student's (or group's) worksheet from the grading page.
$targetuser = optional_param('targetuser', 0, PARAM_INT);
$targetgroup = optional_param('targetgroup', 0, PARAM_INT);
$teacherfor = null;
if ($targetuser || $targetgroup) {
    require_capability('mod/mylkpd:grade', $context);
    $teacherfor = mylkpd_teacher_target($mylkpd, $targetuser, $targetgroup);
    if ($teacherfor['groupid'] && groups_get_group($teacherfor['groupid'])->courseid != $course->id) {
        throw new moodle_exception('invalidgroupid');
    }
    if ($teacherfor['userid'] && !is_enrolled($context, $teacherfor['userid'], 'mod/mylkpd:submit')) {
        throw new moodle_exception('invaliduser');
    }
} else if (!has_capability('mod/mylkpd:submit', $context, null, false)) {
    throw new required_capability_exception($context, 'mod/mylkpd:submit', 'nopermissions', '');
}

echo json_encode(mylkpd_store_answer_image($mylkpd, $cm, $context, (int) $USER->id, $_FILES['image'] ?? null, $teacherfor));
