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
 * Teacher overview of all submissions (per student or per group).
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'mylkpd');
$mylkpd = $DB->get_record('mylkpd', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/mylkpd:grade', $context);

$filter = optional_param('filter', 'all', PARAM_ALPHA);
if (!in_array($filter, mylkpd_submission_filters(), true)) {
    $filter = 'all';
}
$urlparams = ['id' => $cm->id] + ($filter === 'all' ? [] : ['filter' => $filter]);
$PAGE->set_url('/mod/mylkpd/submissions.php', $urlparams);
$PAGE->set_title(format_string($mylkpd->name) . ': ' . get_string('submissions', 'mod_mylkpd'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('submissions', 'mod_mylkpd'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('submissions', 'mod_mylkpd'), 3);

$aion = mylkpd_ai_enabled($mylkpd);
if ($aion) {
    $count = $DB->count_records('mylkpd_questions', ['mylkpdid' => $mylkpd->id]);
    echo html_writer::div(
        html_writer::span(
            '&#10024; ' . get_string($count ? 'aikeysready' : 'noanswerkeys', 'mod_mylkpd', $count),
            'small text-muted'
        ) . ' ' .
        html_writer::link(
            new moodle_url('/mod/mylkpd/keys.php', ['id' => $cm->id]),
            get_string('answerkeys', 'mod_mylkpd'),
            ['class' => 'btn btn-sm btn-outline-primary']
        ) . ' ' .
        ($count ? html_writer::tag(
            'button',
            '&#10024; ' . get_string('aibulk', 'mod_mylkpd'),
            ['type' => 'button', 'class' => 'btn btn-sm btn-secondary', 'data-mylkpd-ai' => 'bulk']
        ) : ''),
        'mylkpd-rubricbar mb-2'
    );
    $bulkstrings = [];
    foreach (['bulkconfirm', 'bulkprogress', 'bulkdone', 'checking', 'failed'] as $k) {
        $bulkstrings[$k] = get_string('ai' . $k, 'mod_mylkpd');
    }
    echo $OUTPUT->render_from_template('mod_mylkpd/config_holder', ['id' => 'mylkpd-bulk-config', 'config' => json_encode([
        'cmid' => (int) $cm->id, 'grade' => (float) $mylkpd->grade, 'decsep' => get_string('decsep', 'langconfig'),
        'strings' => $bulkstrings,
    ], JSON_UNESCAPED_UNICODE)]);
    $PAGE->requires->js_call_amd('mod_mylkpd/aicheck', 'initBulk', ['mylkpd-bulk-config']);
}

// AI score cell for the submissions table.
$aicell = function (stdClass $mylkpd, ?stdClass $sub, array $target) {
    $text = '-';
    if ($sub && !empty($sub->airesult) && ($r = json_decode($sub->airesult, true))) {
        if (isset($r['total'])) {
            $text = format_float($r['total'], 2) . ' / ' . format_float($r['max'], 2);
            if ($mylkpd->grade > 0 && $r['max'] > 0) {
                $text .= ' (' . format_float($r['total'] / $r['max'] * $mylkpd->grade, 2) . ')';
            }
            if (!empty($r['auto'])) {
                $text .= ' · ' . get_string('aiautoshort', 'mod_mylkpd');
            }
        } else if (isset($r['error'])) {
            $text = '⚠ ' . get_string('aiautofailedshort', 'mod_mylkpd');
        }
    }
    $cell = new html_table_cell(s($text));
    $cell->attributes = ['data-mylkpd-aicell' => 1, 'data-targetuser' => $target['targetuser'],
        'data-targetgroup' => $target['targetgroup'], 'data-mylkpd-hasanswers' => $sub ? 1 : 0, 'class' => 'mylkpd-aicol'];
    return $cell;
};

if ($mylkpd->grade != 0 && has_capability('moodle/grade:managegradingforms', $context)) {
    $haverubric = (bool) mylkpd_grading_controller($mylkpd, $context);
    echo html_writer::div(
        html_writer::span(get_string($haverubric ? 'rubricactive' : 'rubricsetuphint', 'mod_mylkpd'), 'small text-muted') . ' ' .
        html_writer::link(
            mylkpd_grading_manage_url($context),
            get_string($haverubric ? 'rubricedit' : 'rubricsetup', 'mod_mylkpd'),
            ['class' => 'btn btn-sm btn-outline-primary']
        ),
        'mylkpd-rubricbar mb-3'
    );
}

$counts = [];
$table = new html_table();
$table->attributes['class'] = 'generaltable table-hover';
$fmt = get_string('strftimedatetimeshort', 'langconfig');

if ($mylkpd->teamsubmission) {
    $groups = groups_get_all_groups($course->id, 0, $cm->groupingid);
    $subs = $DB->get_records_select('mylkpd_submissions', 'mylkpdid = ? AND groupid > 0', [$mylkpd->id]);
    $bygroup = [];
    foreach ($subs as $s) {
        $bygroup[$s->groupid] = $s;
    }
    $table->head = array_merge(
        [get_string('group'), get_string('groupmembers', 'mod_mylkpd'), get_string('status'),
        get_string('lastmodified', 'mod_mylkpd')],
        $aion ? [get_string('aiscore', 'mod_mylkpd')] : [],
        [get_string('grade', 'grades'), '']
    );
    $rows = [];
    foreach ($groups as $group) {
        $rows[] = [$group, $bygroup[$group->id] ?? null];
    }
    $counts = mylkpd_filter_counts($rows);
    foreach ($rows as [$group, $sub]) {
        if (!mylkpd_submission_matches($sub, $filter)) {
            continue;
        }
        $members = mylkpd_members(mylkpd_member_ids($mylkpd, $group->id, 0));
        $table->data[] = array_merge([
            format_string($group->name),
            s(implode(', ', array_column($members, 'name'))) ?: '-',
            mylkpd_status_badge($sub, $mylkpd, $context),
            $sub ? userdate($sub->timemodified, $fmt) : '-',
        ], $aion ? [$aicell($mylkpd, $sub, ['targetuser' => 0, 'targetgroup' => $group->id])] : [], [
            s(mylkpd_display_final_grade($mylkpd, $sub)),
            html_writer::link(
                new moodle_url('/mod/mylkpd/grade.php', ['groupid' => $group->id] + $urlparams),
                get_string('gradeaction', 'mod_mylkpd'),
                ['class' => 'btn btn-sm btn-outline-primary']
            ),
        ]);
    }
    if (!$groups) {
        echo $OUTPUT->notification(get_string('nogroups', 'mod_mylkpd'), \core\output\notification::NOTIFY_WARNING);
    }
} else {
    groups_print_activity_menu($cm, $PAGE->url);
    $groupid = groups_get_activity_group($cm, true);
    $students = get_enrolled_users($context, 'mod/mylkpd:submit', $groupid, 'u.*', 'u.lastname, u.firstname');
    $subs = $DB->get_records(
        'mylkpd_submissions',
        ['mylkpdid' => $mylkpd->id, 'groupid' => 0],
        '',
        'userid, id, groupid, status, grade, timemodified, timesubmitted, submittedby, airesult'
    );
    $table->head = array_merge(
        [get_string('fullname'), get_string('status'), get_string('lastmodified', 'mod_mylkpd')],
        $aion ? [get_string('aiscore', 'mod_mylkpd')] : [],
        [get_string('grade', 'grades'), '']
    );
    $rows = [];
    foreach ($students as $student) {
        $rows[] = [$student, $subs[$student->id] ?? null];
    }
    $counts = mylkpd_filter_counts($rows);
    foreach ($rows as [$student, $sub]) {
        if (!mylkpd_submission_matches($sub, $filter)) {
            continue;
        }
        $table->data[] = array_merge([
            fullname($student),
            mylkpd_status_badge($sub, $mylkpd, $context),
            $sub ? userdate($sub->timemodified, $fmt) : '-',
        ], $aion ? [$aicell($mylkpd, $sub, ['targetuser' => $student->id, 'targetgroup' => 0])] : [], [
            s(mylkpd_display_final_grade($mylkpd, $sub)),
            html_writer::link(
                new moodle_url('/mod/mylkpd/grade.php', ['userid' => $student->id] + $urlparams),
                get_string('gradeaction', 'mod_mylkpd'),
                ['class' => 'btn btn-sm btn-outline-primary']
            ),
        ]);
    }
    if (!$students) {
        echo $OUTPUT->notification(get_string('nostudents', 'mod_mylkpd'), \core\output\notification::NOTIFY_INFO);
    }
}

if (!empty($counts['all'])) {
    // How many students (or groups) are in each state; each filter is a link.
    $items = [];
    foreach (mylkpd_submission_filters() as $key) {
        $url = new moodle_url('/mod/mylkpd/submissions.php', ['id' => $cm->id] + ($key === 'all' ? [] : ['filter' => $key]));
        $items[] = [
            'label' => get_string('filter' . $key, 'mod_mylkpd'),
            'title' => get_string('filter' . $key . '_desc', 'mod_mylkpd'),
            'count' => $counts[$key],
            'url' => $url->out(false),
            'active' => $key === $filter,
        ];
    }
    echo $OUTPUT->render_from_template('mod_mylkpd/submission_filters', [
        'label' => get_string('filterlabel', 'mod_mylkpd'),
        'items' => $items,
    ]);
}
if (!empty($table->data)) {
    echo html_writer::table($table);
} else if (!empty($counts['all'])) {
    echo $OUTPUT->notification(get_string('filternoresults', 'mod_mylkpd'), \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->footer();
