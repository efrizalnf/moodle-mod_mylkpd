<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Checks one submission's essay answers with AI and returns suggested scores and feedback.
 * The teacher reviews the suggestion; nothing is written to the gradebook here.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
$targetuser = optional_param('targetuser', 0, PARAM_INT);
$targetgroup = optional_param('targetgroup', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'mylkpd');
require_login($course, false, $cm, false, true);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('mod/mylkpd:grade', $context);
$mylkpd = $DB->get_record('mylkpd', ['id' => $cm->instance], '*', MUST_EXIST);

try {
    if (!mylkpd_ai_enabled($mylkpd)) {
        throw new moodle_exception('aidisabled', 'mod_mylkpd');
    }
    $questions = mylkpd_get_questions($mylkpd);
    if (!$questions) {
        throw new moodle_exception('noanswerkeys', 'mod_mylkpd');
    }
    $target = mylkpd_teacher_target($mylkpd, $targetuser, $targetgroup);
    $submission = mylkpd_target_submission($mylkpd, $cm, (int) $USER->id, $target, false);
    if (!$submission) {
        throw new moodle_exception('nothingtocheck', 'mod_mylkpd');
    }
    core_php_time_limit::raise(180);
    $result = \mod_mylkpd\ai\grader::check($questions, mylkpd_decode_data($submission->answers));
    $DB->update_record('mylkpd_submissions', (object) ['id' => $submission->id,
        'airesult' => json_encode($result, JSON_UNESCAPED_UNICODE), 'aitime' => $result['time']]);
    $result['timetext'] = userdate($result['time']);
    echo json_encode(['result' => $result], JSON_UNESCAPED_UNICODE);
} catch (moodle_exception $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
