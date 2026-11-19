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
 * Teacher views one filled worksheet (a student's or a group's) and grades it.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'mylkpd');
$mylkpd = $DB->get_record('mylkpd', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/mylkpd:grade', $context);

$team = !empty($mylkpd->teamsubmission);
if ($team) {
    $group = groups_get_group($groupid, '*', MUST_EXIST);
    if ($group->courseid != $course->id) {
        throw new moodle_exception('invalidgroupid');
    }
    $title = format_string($group->name);
    $params = ['mylkpdid' => $mylkpd->id, 'groupid' => $groupid];
    $urlparams = ['id' => $cm->id, 'groupid' => $groupid];
} else {
    $user = core_user::get_user($userid, '*', MUST_EXIST);
    if (!is_enrolled($context, $user, 'mod/mylkpd:submit')) {
        throw new moodle_exception('nostudents', 'mod_mylkpd');
    }
    $title = fullname($user);
    $params = ['mylkpdid' => $mylkpd->id, 'userid' => $userid, 'groupid' => 0];
    $urlparams = ['id' => $cm->id, 'userid' => $userid];
}
$submission = $DB->get_record('mylkpd_submissions', $params);
$controller = mylkpd_grading_controller($mylkpd, $context);
if ($controller && !$submission) {
    // A rubric needs an item to attach to, so make an (empty) submission record.
    $now = time();
    $submission = (object) ($params + [
        'userid' => $userid,
        'answers' => '{"items":{},"blocks":{}}',
        'status' => MYLKPD_STATUS_DRAFT,
        'revision' => 0,
        'timecreated' => $now,
        'timemodified' => $now,
        'timegraded' => 0,
    ]);
    $submission->id = $DB->insert_record('mylkpd_submissions', $submission);
}
$gradinginstance = null;
$rubricwarning = '';
if ($controller) {
    if ($controller->is_form_available()) {
        $instanceid = optional_param('advancedgradinginstanceid', 0, PARAM_INT);
        $gradinginstance = $controller->get_or_create_instance($instanceid, $USER->id, $submission->id);
    } else {
        $rubricwarning = $controller->form_unavailable_notification();
    }
}
$memberids = mylkpd_member_ids($mylkpd, $groupid, $userid);

$PAGE->set_url('/mod/mylkpd/grade.php', $urlparams);
$PAGE->set_title(format_string($mylkpd->name) . ': ' . $title);
$PAGE->set_heading(format_string($course->fullname));
$returnurl = new moodle_url('/mod/mylkpd/submissions.php', ['id' => $cm->id]);
$PAGE->navbar->add(get_string('submissions', 'mod_mylkpd'), $returnurl);
$PAGE->navbar->add($title);

$form = new \mod_mylkpd\form\grade_form($PAGE->url, ['mylkpd' => $mylkpd, 'gradinginstance' => $gradinginstance]);
$form->set_data($urlparams + [
    'grade' => $submission->grade ?? null,
    'feedback' => $submission->feedback ?? '',
]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    $grade = null;
    if ($gradinginstance) {
        $grade = $gradinginstance->submit_and_get_grade($data->advancedgrading, $submission->id);
        if ($grade === null || $grade < 0) {
            $grade = null;
        }
    } else if ($mylkpd->grade > 0 && isset($data->grade) && $data->grade !== '') {
        $grade = (float) $data->grade;
    } else if ($mylkpd->grade < 0 && !empty($data->grade)) {
        $grade = (int) $data->grade;
    }
    $now = time();
    if (!$submission) {
        $submission = (object) ($params + [
            'userid' => $userid,
            'answers' => '{"items":{},"blocks":{}}',
            'status' => MYLKPD_STATUS_DRAFT,
            'revision' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $submission->id = $DB->insert_record('mylkpd_submissions', $submission);
    }
    $submission->grade = $grade;
    $submission->feedback = $data->feedback;
    $submission->timegraded = $now;
    if (!empty($data->reopen)) {
        $submission->status = MYLKPD_STATUS_DRAFT;
        $submission->submittedby = null;
    }
    $DB->update_record('mylkpd_submissions', $submission);
    mylkpd_update_grades($mylkpd);

    redirect($returnurl, get_string('gradesaved', 'mod_mylkpd'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// The teacher can write, correct and leave notes directly on the student's worksheet.
$teacherfor = mylkpd_teacher_target($mylkpd, $userid, $groupid);
$worksheet = mylkpd_render_worksheet($mylkpd, $cm, $context, $submission ?: null, false, $memberids, $teacherfor);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('gradeuser', 'mod_mylkpd', $title), 3);

if (!$submission) {
    echo $OUTPUT->notification(get_string('statusnotstarted', 'mod_mylkpd'), \core\output\notification::NOTIFY_INFO);
} else {
    echo html_writer::tag('p', mylkpd_status_badge($submission, $mylkpd, $context) . ' ' .
        html_writer::span(userdate($submission->timesubmitted ?: $submission->timemodified), 'small text-muted'));
}

if ($team) {
    // Who changed what, so the teacher can see each member's share of the group work.
    $members = mylkpd_members($memberids);
    $contrib = mylkpd_contributions(mylkpd_decode_data($submission->answers ?? null));
    $table = new html_table();
    $table->head = [get_string('member', 'mod_mylkpd'), get_string('contribblocks', 'mod_mylkpd'),
        get_string('contribitems', 'mod_mylkpd')];
    $table->attributes['class'] = 'generaltable mylkpd-contrib';
    foreach ($members as $uid => $m) {
        $table->data[] = [
            html_writer::span('', 'mylkpd-dot', ['style' => 'background:' . $m['color']]) . ' ' . s($m['name']),
            $contrib[$uid]['blocks'] ?? 0,
            $contrib[$uid]['items'] ?? 0,
        ];
    }
    echo $OUTPUT->heading(get_string('contributions', 'mod_mylkpd'), 4);
    echo html_writer::tag('p', get_string('contributions_help', 'mod_mylkpd'), ['class' => 'text-muted small']);
    echo html_writer::table($table);
}

echo html_writer::div(get_string('teacherannotatehint', 'mod_mylkpd'), 'mylkpd-teacherhint small text-muted');
echo $worksheet['html'];
if ($worksheet['ready']) {
    echo html_writer::start_div('mylkpd-actions mylkpd-teacheractions');
    echo html_writer::span('', 'mylkpd-savestate small', ['id' => 'mylkpd-savestate', 'role' => 'status',
        'aria-live' => 'polite']);
    echo html_writer::tag(
        'button',
        '&#128190; ' . get_string('saveannotations', 'mod_mylkpd'),
        ['type' => 'button', 'class' => 'btn btn-secondary', 'data-mylkpd-action' => 'save']
    );
    echo html_writer::end_div();
}
// AI essay checking: suggestion only, the teacher decides what goes into the grade form.
if (mylkpd_ai_enabled($mylkpd)) {
    $aiquestions = mylkpd_get_questions($mylkpd);
    echo $OUTPUT->box_start('generalbox mylkpd-aibox', 'mylkpd-ai');
    echo $OUTPUT->heading('&#10024; ' . get_string('aicheck', 'mod_mylkpd'), 4);
    $keysurl = new moodle_url('/mod/mylkpd/keys.php', ['id' => $cm->id]);
    if (!$aiquestions) {
        echo html_writer::div(
            get_string('noanswerkeys', 'mod_mylkpd') . ' ' .
            html_writer::link($keysurl, get_string('answerkeys', 'mod_mylkpd'), ['class' => 'btn btn-sm btn-outline-primary']),
            'small'
        );
    } else {
        echo html_writer::div(
            html_writer::tag(
                'button',
                '&#10024; ' . get_string('aicheckbutton', 'mod_mylkpd'),
                ['type' => 'button', 'class' => 'btn btn-secondary', 'data-mylkpd-ai' => 'check']
            ) . ' ' .
            html_writer::span(get_string('aicheckinfo', 'mod_mylkpd', count($aiquestions)), 'small text-muted') . ' ' .
            html_writer::link($keysurl, get_string('answerkeys', 'mod_mylkpd'), ['class' => 'small']),
            'mylkpd-ai-tools'
        );
        echo html_writer::tag(
            'label',
            html_writer::empty_tag('input', ['type' => 'checkbox', 'checked' => 'checked',
            'data-mylkpd-ai' => 'marks', 'class' => 'form-check-input me-1 mr-1']) . get_string('aimarks', 'mod_mylkpd'),
            ['class' => 'small d-block mb-2']
        );
        echo html_writer::div('', 'mylkpd-ai-result', ['id' => 'mylkpd-ai-result', 'aria-live' => 'polite']);
        $areas = [];
        foreach ($aiquestions as $q) {
            $areas[$q->id] = $q->area;
        }
        $stored = ($submission && !empty($submission->airesult)) ? json_decode($submission->airesult, true) : null;
        if ($stored) {
            $stored['timetext'] = userdate($stored['time'] ?? 0);
        }
        if (!empty($mylkpd->aiautotime)) {
            echo html_writer::div('&#9201; ' . get_string(
                time() >= $mylkpd->aiautotime ? 'aiautoactive' : 'aiautoplanned',
                'mod_mylkpd',
                userdate($mylkpd->aiautotime)
            ), 'small text-muted mb-2');
        }
        $aistrings = [];
        foreach (
            ['colnumber', 'colanswer', 'colscore', 'colstatus', 'colfeedback', 'total', 'checkedat', 'apply', 'review',
                'checking', 'failed', 'missing', 'empty', 'number', 'replacefeedback', 'vright', 'vpartial', 'vwrong', 'vunknown',
                'unchanged', 'outside', 'autodone', 'autoerror'] as $k
        ) {
            $aistrings[$k] = get_string('ai' . $k, 'mod_mylkpd');
        }
        echo $OUTPUT->render_from_template('mod_mylkpd/config_holder', ['id' => 'mylkpd-ai-config', 'config' => json_encode([
            'cmid' => (int) $cm->id,
            'target' => ['targetuser' => $teacherfor['userid'], 'targetgroup' => $teacherfor['groupid']],
            'grade' => (float) $mylkpd->grade,
            'hasRubric' => (bool) $controller,
            'decsep' => get_string('decsep', 'langconfig'),
            'result' => $stored,
            'areas' => (object) $areas,
            'strings' => $aistrings,
        ], JSON_UNESCAPED_UNICODE)]);
        $PAGE->requires->js_call_amd('mod_mylkpd/aicheck', 'init', ['mylkpd-ai-config']);
    }
    echo $OUTPUT->box_end();
}

echo $OUTPUT->box_start('generalbox mylkpd-gradebox');
echo $OUTPUT->heading(get_string('grading', 'mod_mylkpd'), 4);
$latepenalty = $submission ? mylkpd_late_penalty($mylkpd, $submission) : 0;
if ($latepenalty > 0) {
    // The teacher grades the work itself; the penalty is taken off automatically for the gradebook.
    echo $OUTPUT->notification(get_string('latepenaltygrading', 'mod_mylkpd', (object) [
        'late' => format_time(mylkpd_late_seconds($mylkpd, $submission)),
        'penalty' => format_float($latepenalty, 2),
    ]), \core\output\notification::NOTIFY_WARNING);
    if ($submission->grade !== null) {
        echo html_writer::div(html_writer::tag('strong', get_string('finalgrade', 'mod_mylkpd') . ': ') .
            s(mylkpd_display_final_grade($mylkpd, $submission)), 'mb-2');
    }
}
if ($rubricwarning) {
    echo $OUTPUT->notification($rubricwarning, \core\output\notification::NOTIFY_WARNING);
}
if (!$controller && $mylkpd->grade != 0 && has_capability('moodle/grade:managegradingforms', $context)) {
    echo html_writer::div(
        get_string('rubricsetuphint', 'mod_mylkpd') . ' ' .
        html_writer::link(mylkpd_grading_manage_url($context), get_string('rubricsetup', 'mod_mylkpd')),
        'mylkpd-rubrichint small text-muted mb-2'
    );
}
$form->display();
echo $OUTPUT->box_end();
echo $OUTPUT->footer();
