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
 * Main page: students (alone or as a group) work on the worksheet, teachers preview it.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'mylkpd');
$mylkpd = $DB->get_record('mylkpd', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/mylkpd:view', $context);

$PAGE->set_url('/mod/mylkpd/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($mylkpd->name));
$PAGE->set_heading(format_string($course->fullname));

// Only real students fill in the worksheet (admins hold every capability, so exclude doanything).
$cansubmit = has_capability('mod/mylkpd:submit', $context, null, false);
$cangrade = has_capability('mod/mylkpd:grade', $context);
$team = !empty($mylkpd->teamsubmission);
$groupid = ($cansubmit && $team) ? mylkpd_get_user_groupid($cm, $USER->id) : 0;
$nogroup = $cansubmit && $team && !$groupid;

$canwork = mylkpd_can_work($mylkpd);
$allmembers = mylkpd_requires_all_members($mylkpd);

// Message for a student who cannot work right now (not open yet / closed).
$notworkingmessage = function (stdClass $mylkpd): string {
    return mylkpd_is_open($mylkpd) ? get_string('closedmessage', 'mod_mylkpd', userdate($mylkpd->cutoffdate))
        : get_string('notopenmessage', 'mod_mylkpd', userdate($mylkpd->timeopen));
};

// Final submission. Answers themselves are saved continuously through the sync web service.
if ($cansubmit && !$nogroup && data_submitted() && confirm_sesskey() && optional_param('submitfinal', 0, PARAM_BOOL)) {
    if (!$canwork) {
        redirect($PAGE->url, $notworkingmessage($mylkpd), null, \core\output\notification::NOTIFY_WARNING);
    }
    $submission = mylkpd_find_submission($mylkpd, $cm, $USER->id, true);
    if ($submission->status == MYLKPD_STATUS_SUBMITTED) {
        redirect(
            $PAGE->url,
            get_string('alreadysubmitted', 'mod_mylkpd'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    $now = time();
    if ($allmembers) {
        // Every member must submit: record this member; the group's work is submitted when all have.
        $done = mylkpd_team_confirmations($submission);
        $done[$USER->id] = $now;
        $submission->submittedby = json_encode($done);
        $pending = mylkpd_team_pending($mylkpd, $context, $submission);
        if ($pending) {
            $DB->update_record('mylkpd_submissions', $submission);
            $names = implode(', ', array_column(mylkpd_members($pending), 'name'));
            redirect(
                $PAGE->url,
                get_string('teamconfirmed', 'mod_mylkpd', $names),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }
    $submission->status = MYLKPD_STATUS_SUBMITTED;
    $submission->userid = $USER->id;
    $submission->timesubmitted = $now;
    $submission->timemodified = $now;
    $DB->update_record('mylkpd_submissions', $submission);
    mylkpd_cleanup_images($context, $submission);
    $late = mylkpd_late_seconds($mylkpd, $submission);
    redirect(
        $PAGE->url,
        $late ? get_string('submittedlate', 'mod_mylkpd', format_time($late)) : get_string('submitted', 'mod_mylkpd'),
        null,
        $late ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}

// Every member must submit: a member takes back their own submission (the group's work is not submitted yet).
if ($cansubmit && $allmembers && !$nogroup && data_submitted() && confirm_sesskey() && optional_param('unconfirm', 0, PARAM_BOOL)) {
    $submission = mylkpd_find_submission($mylkpd, $cm, $USER->id);
    if ($submission && $submission->status != MYLKPD_STATUS_SUBMITTED) {
        $done = mylkpd_team_confirmations($submission);
        unset($done[$USER->id]);
        $submission->submittedby = $done ? json_encode($done) : null;
        $DB->update_record('mylkpd_submissions', $submission);
    }
    redirect($PAGE->url, get_string('teamunconfirmed', 'mod_mylkpd'), null, \core\output\notification::NOTIFY_INFO);
}

// Edit again: put a submitted worksheet back to draft so it can be changed and submitted again.
if ($cansubmit && !$nogroup && data_submitted() && confirm_sesskey() && optional_param('reopen', 0, PARAM_BOOL)) {
    $submission = mylkpd_find_submission($mylkpd, $cm, $USER->id);
    if (!$canwork || !mylkpd_can_reopen($mylkpd, $submission)) {
        redirect(
            $PAGE->url,
            $canwork ? get_string('cannotreopen', 'mod_mylkpd') : $notworkingmessage($mylkpd),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    $submission->status = MYLKPD_STATUS_DRAFT;
    $submission->submittedby = null; // Everyone submits again.
    $submission->timemodified = time();
    $DB->update_record('mylkpd_submissions', $submission);
    redirect($PAGE->url, get_string('reopened', 'mod_mylkpd'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$event = \mod_mylkpd\event\course_module_viewed::create(['objectid' => $mylkpd->id, 'context' => $context]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('mylkpd', $mylkpd);
$event->trigger();
(new completion_info($course))->set_module_viewed($cm);

$submission = ($cansubmit && !$nogroup) ? mylkpd_find_submission($mylkpd, $cm, $USER->id) : null;
$issubmitted = $submission && $submission->status == MYLKPD_STATUS_SUBMITTED;
$readonly = !$cansubmit || $nogroup || $issubmitted || !$canwork;
$memberids = $cansubmit ? mylkpd_member_ids($mylkpd, $groupid, $USER->id) : [];
// Before the opening time students only see when it opens (teachers always see the worksheet).
$hideworksheet = $cansubmit && !mylkpd_is_open($mylkpd);
$worksheet = $hideworksheet ? ['html' => '', 'ready' => false] :
    mylkpd_render_worksheet($mylkpd, $cm, $context, $submission, $readonly, $memberids);
$myconfirmed = $allmembers && $submission && isset(mylkpd_team_confirmations($submission)[$USER->id]);

echo $OUTPUT->header();

if ($cangrade) {
    $count = $DB->count_records('mylkpd_submissions', ['mylkpdid' => $mylkpd->id, 'status' => MYLKPD_STATUS_SUBMITTED]);
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/mod/mylkpd/submissions.php', ['id' => $cm->id]),
            get_string('viewsubmissions', 'mod_mylkpd', $count),
            ['class' => 'btn btn-secondary']
        ),
        'mb-3'
    );
}

if ($nogroup) {
    echo $OUTPUT->notification(get_string('nogroup', 'mod_mylkpd'), \core\output\notification::NOTIFY_WARNING);
}

// Status card: schedule, where the student stands, the grade, feedback and the filled-in rubric.
if ($cansubmit && !$nogroup) {
    $now = time();
    $late = mylkpd_late_seconds($mylkpd, $submission);
    $badges = [];
    if (!mylkpd_is_open($mylkpd, $now)) {
        $badges[] = ['text' => get_string('statusnotopen', 'mod_mylkpd'), 'class' => 'bg-secondary badge-secondary'];
        $info = get_string('notopenmessage', 'mod_mylkpd', userdate($mylkpd->timeopen));
    } else if ($issubmitted) {
        $badges[] = ['text' => get_string('statussubmitted', 'mod_mylkpd'), 'class' => 'bg-success badge-success'];
        $when = $submission->timesubmitted ?: $submission->timemodified;
        $a = (object) ['time' => userdate($when), 'name' => fullname(core_user::get_user($submission->userid))];
        $info = get_string($team ? 'groupsubmittedinfo' : 'submittedinfo', 'mod_mylkpd', $team ? $a : $a->time);
        if ($late) {
            $badges[] = ['text' => get_string('statuslate', 'mod_mylkpd', format_time($late)), 'class' => 'bg-danger badge-danger'];
        }
    } else if (mylkpd_is_closed($mylkpd, $now)) {
        $badges[] = ['text' => get_string('statusclosed', 'mod_mylkpd'), 'class' => 'bg-dark badge-dark'];
        $info = get_string('closedmessage', 'mod_mylkpd', userdate($mylkpd->cutoffdate));
    } else {
        $badges[] = ['text' => get_string($submission ? 'statusdraft' : 'statusnotstarted', 'mod_mylkpd'),
            'class' => 'bg-warning text-dark badge-warning'];
        $info = get_string('draftinfo', 'mod_mylkpd');
    }

    // Schedule: opens, deadline (with time left / overdue), closes.
    $schedule = [];
    if (!empty($mylkpd->timeopen)) {
        $schedule[] = ['label' => get_string('timeopen', 'mod_mylkpd'), 'value' => userdate($mylkpd->timeopen)];
    }
    if (!empty($mylkpd->duedate)) {
        $left = $mylkpd->duedate - $now;
        $schedule[] = ['label' => get_string('duedate', 'mod_mylkpd'), 'value' => userdate($mylkpd->duedate), 'strong' => true,
            'hint' => $issubmitted ? '' : get_string($left > 0 ? 'timeleft' : 'overdue', 'mod_mylkpd', format_time(abs($left))),
            'hintclass' => $left > 0 ? 'mylkpd-timeleft' : 'mylkpd-overdue'];
    }
    if (!empty($mylkpd->cutoffdate)) {
        $schedule[] = ['label' => get_string('cutoffdate', 'mod_mylkpd'), 'value' => userdate($mylkpd->cutoffdate)];
    }
    // A warning before submitting late.
    $latewarning = '';
    if (!$issubmitted && $canwork && !empty($mylkpd->duedate) && $now > $mylkpd->duedate) {
        $key = 'latewarning';
        if ((float) $mylkpd->latepenalty > 0 && $mylkpd->grade > 0) {
            $key = !empty($mylkpd->latepenaltymode) ? 'latewarningperday' : 'latewarningonce';
        }
        $latewarning = get_string($key, 'mod_mylkpd', format_float($mylkpd->latepenalty, 2));
    }

    $groupinfo = '';
    $teamprogress = false;
    if ($team) {
        $groupinfo = get_string('yourgroup', 'mod_mylkpd', (object) [
            'group' => format_string(groups_get_group_name($groupid)),
            'members' => implode(', ', array_column(mylkpd_members($memberids), 'name')),
        ]);
        if ($allmembers && !$issubmitted) {
            // Every member must submit: who has, who has not yet.
            $done = mylkpd_team_confirmations($submission);
            $people = [];
            $memberlist = array_keys(get_enrolled_users($context, 'mod/mylkpd:submit', $groupid, 'u.id'));
            foreach (mylkpd_members($memberlist) as $uid => $m) {
                $people[] = ['name' => $m['name'], 'done' => isset($done[$uid])];
            }
            $teamprogress = ['label' => get_string('teamsubmitprogress', 'mod_mylkpd'), 'members' => $people];
        }
    }

    $grade = '';
    $feedback = '';
    $rubric = '';
    if ($submission && ($submission->grade !== null || !empty($submission->feedback))) {
        $grade = mylkpd_display_final_grade($mylkpd, $submission);
        $feedback = (string) $submission->feedback;
        // Show the rubric with the levels the teacher chose.
        if ($submission->grade !== null && ($controller = mylkpd_grading_controller($mylkpd, $context))) {
            try {
                require_once($CFG->libdir . '/gradelib.php');
                $gradeinfo = grade_get_grades($course->id, 'mod', 'mylkpd', $mylkpd->id, $USER->id);
                $gradeitem = $gradeinfo->items[0] ?? null;
                if ($gradeitem && $controller->is_form_available()) {
                    $rubric = $controller->render_grade($PAGE, $submission->id, $gradeitem, '', false);
                }
            } catch (\Throwable $e) {
                debugging('MyLKPD rubric display: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
    echo $OUTPUT->render_from_template('mod_mylkpd/status_card', [
        'badges' => $badges, 'info' => $info, 'schedule' => $schedule, 'latewarning' => $latewarning,
        'groupinfo' => $groupinfo, 'teamprogress' => $teamprogress, 'grade' => $grade, 'feedback' => $feedback,
        'rubric' => $rubric, 'gradelabel' => get_string('yourgrade', 'mod_mylkpd'),
        'feedbacklabel' => get_string('feedback', 'mod_mylkpd'),
    ]);
}

$canreopen = $issubmitted && $canwork && mylkpd_can_reopen($mylkpd, $submission);

if (!$readonly && $worksheet['ready']) {
    echo $OUTPUT->render_from_template('mod_mylkpd/work_form', [
        'action' => $PAGE->url->out(false), 'sesskey' => sesskey(), 'worksheet' => $worksheet['html'],
        'savelabel' => get_string('savenow', 'mod_mylkpd'),
        'submitlabel' => get_string($allmembers ? 'teamsubmitmine' : 'submitfinal', 'mod_mylkpd'),
        'confirmed' => $myconfirmed, 'confirmedlabel' => get_string('teamyouconfirmed', 'mod_mylkpd'),
        'unconfirmlabel' => get_string('teamunconfirm', 'mod_mylkpd'),
    ]);
} else {
    echo $worksheet['html'];
    if ($issubmitted) {
        // Same place as the submit button: the bottom action bar.
        $reason = 'editagainhint';
        if (!$canreopen) {
            $reason = !$canwork ? 'cannotreopenclosed' : (((int) ($mylkpd->allowresubmit ?? 1) === 0) ? 'cannotreopensetting' :
                'cannotreopengraded');
        }
        echo $OUTPUT->render_from_template('mod_mylkpd/reopen_bar', [
            'action' => $PAGE->url->out(false), 'sesskey' => sesskey(), 'canreopen' => $canreopen,
            'confirm' => get_string($team ? 'editagainconfirmgroup' : 'editagainconfirm', 'mod_mylkpd'),
            'message' => get_string($reason, 'mod_mylkpd'), 'label' => get_string('editagain', 'mod_mylkpd'),
        ]);
        if ($canreopen) {
            $PAGE->requires->js_call_amd('mod_mylkpd/confirm', 'init');
        }
    }
}

echo $OUTPUT->footer();
