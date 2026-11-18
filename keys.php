<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Answer key for AI essay checking: the teacher lists the essay questions with a model answer and
 * scoring points, and links each one to its answer area on the worksheet.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'mylkpd');
$mylkpd = $DB->get_record('mylkpd', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/mylkpd:grade', $context);

$PAGE->set_url('/mod/mylkpd/keys.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($mylkpd->name) . ': ' . get_string('answerkeys', 'mod_mylkpd'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('answerkeys', 'mod_mylkpd'));

// Save: update, add and remove questions in one go.
if (data_submitted() && confirm_sesskey()) {
    $ids = optional_param_array('qid', [], PARAM_INT);
    $labels = optional_param_array('qlabel', [], PARAM_TEXT);
    $texts = optional_param_array('qquestion', [], PARAM_TEXT);
    $keys = optional_param_array('qkey', [], PARAM_TEXT);
    $points = optional_param_array('qpoints', [], PARAM_TEXT);
    $scores = optional_param_array('qmax', [], PARAM_RAW);
    $anchors = optional_param_array('qanchor', [], PARAM_RAW);

    $existing = $DB->get_records('mylkpd_questions', ['mylkpdid' => $mylkpd->id], '', 'id');
    $keep = [];
    $now = time();
    $transaction = $DB->start_delegated_transaction();
    foreach (array_slice(array_keys($labels), 0, 100) as $order => $i) {
        $record = (object) [
            'mylkpdid' => $mylkpd->id,
            'sortorder' => $order,
            'label' => core_text::substr(trim($labels[$i] ?? ''), 0, 30),
            'question' => core_text::substr(trim($texts[$i] ?? ''), 0, 5000),
            'answerkey' => core_text::substr(trim($keys[$i] ?? ''), 0, 10000),
            'keypoints' => core_text::substr(trim($points[$i] ?? ''), 0, 5000),
            'maxscore' => max(0.5, min(1000, round((float) unformat_float($scores[$i] ?? 10), 2))),
            'anchor' => json_encode(mylkpd_clean_anchor($anchors[$i] ?? ''), JSON_UNESCAPED_UNICODE),
            'timemodified' => $now,
        ];
        if ($record->question === '' && $record->answerkey === '') {
            continue; // An empty row.
        }
        $qid = (int) ($ids[$i] ?? 0);
        if ($qid && isset($existing[$qid])) {
            $record->id = $qid;
            $DB->update_record('mylkpd_questions', $record);
            $keep[$qid] = true;
        } else {
            $keep[$DB->insert_record('mylkpd_questions', $record)] = true;
        }
    }
    foreach ($existing as $qid => $unused) {
        if (empty($keep[$qid])) {
            $DB->delete_records('mylkpd_questions', ['id' => $qid]);
        }
    }
    $transaction->allow_commit();
    redirect($PAGE->url, get_string('answerkeyssaved', 'mod_mylkpd'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$questions = mylkpd_get_questions($mylkpd);
$worksheet = mylkpd_render_worksheet($mylkpd, $cm, $context, null, true, [], null, ['anchorEditor' => true]);

$rows = [];
foreach ($questions as $q) {
    $rows[] = ['id' => (int) $q->id, 'label' => $q->label, 'question' => $q->question, 'key' => $q->answerkey,
        'points' => $q->keypoints, 'max' => (float) $q->maxscore, 'area' => $q->area];
}
$strings = [];
foreach (['qlabel', 'qquestion', 'qkey', 'qpoints', 'qmax', 'qarea', 'qareanone', 'qareablocks', 'qarearegions',
        'qpick', 'qpicking', 'qclear', 'qremove', 'qdetected', 'qdetectnone', 'qconfirmreplace', 'qpickquestion',
        'qpickingquestion', 'qquestionarea', 'qquestionnone', 'qquestionpicked'] as $k) {
    $strings[$k] = get_string($k, 'mod_mylkpd');
}
$PAGE->requires->js(new moodle_url('/mod/mylkpd/js/keys.js'));
$PAGE->requires->js_init_code('MyLKPDKeys.init(' . json_encode(['rows' => $rows, 'strings' => $strings]) . ');', true);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('answerkeys', 'mod_mylkpd'), 3);

if (!mylkpd_ai_enabled($mylkpd)) {
    echo $OUTPUT->notification(get_string(\mod_mylkpd\ai\client::site_enabled() ? 'aiactivityoff' : 'aisiteoff', 'mod_mylkpd'),
        \core\output\notification::NOTIFY_WARNING);
}
echo html_writer::div(get_string('answerkeys_help', 'mod_mylkpd'), 'mylkpd-keys-help small');

echo html_writer::start_div('mylkpd-keys-layout');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'mylkpd-keys-form',
    'id' => 'mylkpd-keys-form']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::div(
    html_writer::tag('button', '&#10010; ' . get_string('qadd', 'mod_mylkpd'), ['type' => 'button', 'class' => 'btn btn-secondary btn-sm',
        'data-mylkpd-keys' => 'add']) . ' ' .
    html_writer::tag('button', '&#128269; ' . get_string('qdetect', 'mod_mylkpd'), ['type' => 'button',
        'class' => 'btn btn-outline-secondary btn-sm', 'data-mylkpd-keys' => 'detect']),
    'mylkpd-keys-tools');
echo html_writer::div('', 'mylkpd-keys-rows', ['id' => 'mylkpd-keys-rows']);
echo html_writer::div(
    html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']) . ' ' .
    html_writer::link(new moodle_url('/mod/mylkpd/submissions.php', ['id' => $cm->id]), get_string('back'),
        ['class' => 'btn btn-link']),
    'mylkpd-keys-actions');
echo html_writer::end_tag('form');
echo html_writer::div($worksheet['html'], 'mylkpd-keys-preview');
echo html_writer::end_div();

echo $OUTPUT->footer();
