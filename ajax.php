<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Autosave and live sync for the worksheet editor.
 *
 * @package    mod_mylkpd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
$rev = optional_param('rev', 0, PARAM_INT);
$opsjson = optional_param('ops', '[]', PARAM_RAW);
$block = optional_param('block', '', PARAM_ALPHANUMEXT);

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

$ops = json_decode($opsjson, true);
$result = mylkpd_sync($mylkpd, $cm, (int) $USER->id, $rev, is_array($ops) ? $ops : [], (string) $block, $teacherfor);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
