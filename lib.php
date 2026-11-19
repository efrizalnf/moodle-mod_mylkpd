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
 * Core API callbacks for mod_mylkpd.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Submission is still being worked on by the student. */
define('MYLKPD_STATUS_DRAFT', 0);
/** Submission has been handed in and is locked. */
define('MYLKPD_STATUS_SUBMITTED', 1);

/**
 * Features supported by this module.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed
 */
function mylkpd_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
        case FEATURE_ADVANCED_GRADING:
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Whether students can already start (the opening time has passed or none is set).
 *
 * @param stdClass $mylkpd
 * @param int|null $now
 * @return bool
 */
function mylkpd_is_open(stdClass $mylkpd, ?int $now = null): bool {
    return empty($mylkpd->timeopen) || ($now ?? time()) >= (int) $mylkpd->timeopen;
}

/**
 * Whether the activity is closed for students (past the cut-off time).
 *
 * @param stdClass $mylkpd
 * @param int|null $now
 * @return bool
 */
function mylkpd_is_closed(stdClass $mylkpd, ?int $now = null): bool {
    return !empty($mylkpd->cutoffdate) && ($now ?? time()) > (int) $mylkpd->cutoffdate;
}

/**
 * Whether students may work on and submit the worksheet right now.
 *
 * @param stdClass $mylkpd
 * @param int|null $now
 * @return bool
 */
function mylkpd_can_work(stdClass $mylkpd, ?int $now = null): bool {
    return mylkpd_is_open($mylkpd, $now) && !mylkpd_is_closed($mylkpd, $now);
}

/**
 * How many seconds after the deadline the work was submitted (0 = on time, no deadline, not submitted).
 *
 * @param stdClass $mylkpd
 * @param stdClass|null $submission
 * @return int
 */
function mylkpd_late_seconds(stdClass $mylkpd, ?stdClass $submission): int {
    if (empty($mylkpd->duedate) || !$submission || empty($submission->timesubmitted)) {
        return 0;
    }
    return max(0, (int) $submission->timesubmitted - (int) $mylkpd->duedate);
}

/**
 * Points taken off for lateness: once, or for every started day after the deadline.
 * Only for point grades (a scale cannot be reduced by points).
 *
 * @param stdClass $mylkpd
 * @param stdClass|null $submission
 * @return float
 */
function mylkpd_late_penalty(stdClass $mylkpd, ?stdClass $submission): float {
    $late = mylkpd_late_seconds($mylkpd, $submission);
    $points = (float) ($mylkpd->latepenalty ?? 0);
    if (!$late || $points <= 0 || $mylkpd->grade <= 0) {
        return 0.0;
    }
    $days = !empty($mylkpd->latepenaltymode) ? (int) ceil($late / DAYSECS) : 1;
    return round($points * $days, 2);
}

/**
 * Grade for the gradebook: the teacher's grade minus the late penalty, never below 0.
 *
 * @param stdClass $mylkpd
 * @param stdClass $submission
 * @return float|null
 */
function mylkpd_final_grade(stdClass $mylkpd, stdClass $submission): ?float {
    if ($submission->grade === null || $submission->grade === '') {
        return null;
    }
    $grade = (float) $submission->grade;
    return $mylkpd->grade > 0 ? max(0.0, round($grade - mylkpd_late_penalty($mylkpd, $submission), 5)) : $grade;
}

/**
 * Areas that can use advanced grading methods (rubrics, marking guides).
 *
 * @return array
 */
function mylkpd_grading_areas_list() {
    return ['submissions' => get_string('gradingarea', 'mod_mylkpd')];
}

/**
 * File manager options for the worksheet upload.
 *
 * @return array
 */
function mylkpd_filemanager_options(): array {
    return [
        'subdirs' => 0,
        'maxfiles' => 1,
        'accepted_types' => ['.pdf', '.doc', '.docx', '.odt'],
    ];
}

/**
 * Returns an identifier for the current worksheet file, used to detect a new upload.
 *
 * @param context $context
 * @return string
 */
function mylkpd_worksheet_hash(context $context): string {
    $files = get_file_storage()->get_area_files($context->id, 'mod_mylkpd', 'worksheet', 0, 'id', false);
    if (!$files) {
        return '';
    }
    $file = reset($files);
    return $file->get_contenthash() . '/' . $file->get_filename();
}

/**
 * Saves the uploaded worksheet. When the file changed, the cached PDF and display mode are reset.
 *
 * @param stdClass $data Form data
 */
function mylkpd_save_worksheet(stdClass $data): void {
    global $DB;
    $context = context_module::instance($data->coursemodule);
    $before = mylkpd_worksheet_hash($context);
    file_save_draft_area_files(
        $data->worksheet,
        $context->id,
        'mod_mylkpd',
        'worksheet',
        0,
        mylkpd_filemanager_options()
    );
    if (mylkpd_worksheet_hash($context) !== $before) {
        get_file_storage()->delete_area_files($context->id, 'mod_mylkpd', 'pdf');
        $DB->set_field('mylkpd', 'rendermode', null, ['id' => $data->id]);
    }
}

/**
 * Adds a new instance.
 *
 * @param stdClass $data
 * @param mod_mylkpd_mod_form|null $mform
 * @return int new instance id
 */
function mylkpd_add_instance($data, $mform = null) {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->id = $DB->insert_record('mylkpd', $data);
    mylkpd_save_worksheet($data);
    mylkpd_grade_item_update($data);
    mylkpd_refresh_instance_events($data, (int) $data->coursemodule);
    return $data->id;
}

/**
 * Updates an instance.
 *
 * @param stdClass $data
 * @param mod_mylkpd_mod_form|null $mform
 * @return bool
 */
function mylkpd_update_instance($data, $mform = null) {
    global $DB;
    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record('mylkpd', $data);
    mylkpd_save_worksheet($data);
    mylkpd_grade_item_update($data);
    mylkpd_update_grades($data);
    mylkpd_refresh_instance_events($data, (int) $data->coursemodule);
    return true;
}

/**
 * Deletes an instance and its submissions. Files are removed with the context.
 *
 * @param int $id
 * @return bool
 */
function mylkpd_delete_instance($id) {
    global $DB;
    if (!$mylkpd = $DB->get_record('mylkpd', ['id' => $id])) {
        return false;
    }
    $DB->delete_records_select(
        'mylkpd_presence',
        'submissionid IN (SELECT id FROM {mylkpd_submissions} WHERE mylkpdid = ?)',
        [$mylkpd->id]
    );
    $DB->delete_records('mylkpd_submissions', ['mylkpdid' => $mylkpd->id]);
    $DB->delete_records('mylkpd_questions', ['mylkpdid' => $mylkpd->id]);
    $DB->delete_records('mylkpd', ['id' => $mylkpd->id]);
    mylkpd_grade_item_delete($mylkpd);
    return true;
}

/**
 * Creates or updates the gradebook item, optionally pushing grades.
 *
 * @param stdClass $mylkpd
 * @param array|string|null $grades
 * @return int GRADE_UPDATE_xx
 */
function mylkpd_grade_item_update($mylkpd, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = ['itemname' => $mylkpd->name];
    if (isset($mylkpd->cmidnumber)) {
        $params['idnumber'] = $mylkpd->cmidnumber;
    }
    if ($mylkpd->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $mylkpd->grade;
        $params['grademin'] = 0;
    } else if ($mylkpd->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid'] = -$mylkpd->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }
    return grade_update('mod/mylkpd', $mylkpd->course, 'mod', 'mylkpd', $mylkpd->id, 0, $grades, $params);
}

/**
 * Removes the gradebook item.
 *
 * @param stdClass $mylkpd
 * @return int
 */
function mylkpd_grade_item_delete($mylkpd) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update('mod/mylkpd', $mylkpd->course, 'mod', 'mylkpd', $mylkpd->id, 0, null, ['deleted' => 1]);
}

/**
 * Pushes grades from submissions to the gradebook. A group grade goes to every group member.
 *
 * @param stdClass $mylkpd
 * @param int $userid 0 for all users
 * @param bool $nullifnone
 */
function mylkpd_update_grades($mylkpd, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/grouplib.php');
    $grades = [];
    foreach ($DB->get_records('mylkpd_submissions', ['mylkpdid' => $mylkpd->id]) as $sub) {
        $users = $sub->groupid ? array_keys(groups_get_members($sub->groupid, 'u.id')) : [$sub->userid];
        foreach ($users as $uid) {
            if (!$uid || ($userid && $uid != $userid)) {
                continue;
            }
            // The gradebook gets the teacher's grade minus the late penalty, with a note in the feedback.
            $penalty = mylkpd_late_penalty($mylkpd, $sub);
            $feedback = (string) $sub->feedback;
            if ($penalty > 0 && $sub->grade !== null) {
                $feedback = trim($feedback . "\n\n" . get_string('latepenaltynote', 'mod_mylkpd', (object) [
                    'penalty' => format_float($penalty, 2),
                    'late' => format_time(mylkpd_late_seconds($mylkpd, $sub)),
                ]));
            }
            $grades[$uid] = (object) [
                'userid' => $uid,
                'rawgrade' => mylkpd_final_grade($mylkpd, $sub),
                'feedback' => $feedback,
                'feedbackformat' => FORMAT_PLAIN,
                'dategraded' => $sub->timegraded ?: null,
            ];
        }
    }
    mylkpd_grade_item_update($mylkpd, $grades ?: null);
}

/**
 * Serves the worksheet files.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if the file was not found
 */
function mylkpd_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }
    if (!in_array($filearea, ['worksheet', 'pdf', 'answerimage'])) {
        return false;
    }
    require_login($course, true, $cm);
    require_capability('mod/mylkpd:view', $context);

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    if ($filearea === 'answerimage') {
        // Images in answers: the student, their group mates, and teachers who grade.
        $sub = $DB->get_record('mylkpd_submissions', ['id' => $itemid, 'mylkpdid' => $cm->instance]);
        if (!$sub) {
            return false;
        }
        $allowed = has_capability('mod/mylkpd:grade', $context)
            || (!$sub->groupid && $sub->userid == $USER->id)
            || ($sub->groupid && groups_is_member($sub->groupid, $USER->id));
        if (!$allowed) {
            return false;
        }
    }

    $file = get_file_storage()->get_file($context->id, 'mod_mylkpd', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}

/**
 * Creates, updates or removes the calendar events for opening, deadline and closing.
 * The deadline is an "action" event, so it also appears on the students' Timeline.
 *
 * @param stdClass $mylkpd instance record (or form data with ->id)
 * @param int $cmid
 */
function mylkpd_refresh_instance_events(stdClass $mylkpd, int $cmid): void {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/calendar/lib.php');
    $types = [
        'open' => ['timeopen', CALENDAR_EVENT_TYPE_STANDARD, 'calendaropen'],
        'due' => ['duedate', CALENDAR_EVENT_TYPE_ACTION, 'calendardue'],
        'close' => ['cutoffdate', CALENDAR_EVENT_TYPE_STANDARD, 'calendarclose'],
    ];
    foreach ($types as $eventtype => [$field, $type, $label]) {
        $time = (int) ($mylkpd->$field ?? 0);
        $existing = $DB->get_record('event', ['modulename' => 'mylkpd', 'instance' => $mylkpd->id, 'eventtype' => $eventtype]);
        if (!$time) {
            if ($existing) {
                calendar_event::load($existing->id)->delete();
            }
            continue;
        }
        $event = (object) [
            'name' => get_string($label, 'mod_mylkpd', format_string($mylkpd->name)),
            'description' => '',
            'format' => FORMAT_HTML,
            'courseid' => $mylkpd->course,
            'groupid' => 0,
            'userid' => 0,
            'modulename' => 'mylkpd',
            'instance' => $mylkpd->id,
            'eventtype' => $eventtype,
            'type' => $type,
            'timestart' => $time,
            'timesort' => $time,
            'timeduration' => 0,
            'visible' => instance_is_visible('mylkpd', $mylkpd),
        ];
        if ($existing) {
            calendar_event::load($existing->id)->update($event, false);
        } else {
            calendar_event::create($event, false);
        }
    }
}

/**
 * Rebuilds the calendar events (called by Moodle, e.g. after a course reset or restore).
 *
 * @param int $courseid
 * @param stdClass|int|null $instance
 * @param stdClass|int|null $cm
 * @return bool
 */
function mylkpd_refresh_events($courseid = 0, $instance = null, $cm = null) {
    global $DB;
    if ($instance) {
        $instances = [is_object($instance) ? $instance : $DB->get_record('mylkpd', ['id' => $instance], '*', MUST_EXIST)];
    } else {
        $instances = $DB->get_records('mylkpd', $courseid ? ['course' => $courseid] : []);
    }
    foreach ($instances as $mylkpd) {
        $cmrecord = get_coursemodule_from_instance('mylkpd', $mylkpd->id, $mylkpd->course, false, IGNORE_MISSING);
        if ($cmrecord) {
            mylkpd_refresh_instance_events($mylkpd, (int) $cmrecord->id);
        }
    }
    return true;
}

/**
 * What the deadline event on the Timeline links to ("Work on it"), hidden once the work is submitted.
 *
 * @param calendar_event $event
 * @param \core_calendar\action_factory $factory
 * @param int $userid
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_mylkpd_core_calendar_provide_event_action(
    calendar_event $event,
    \core_calendar\action_factory $factory,
    int $userid = 0
) {
    global $DB, $USER;
    $userid = $userid ?: (int) $USER->id;
    $cm = get_fast_modinfo($event->courseid, $userid)->instances['mylkpd'][$event->instance] ?? null;
    if (!$cm || !$cm->uservisible) {
        return null;
    }
    $context = context_module::instance($cm->id);
    if ($event->eventtype === 'due') {
        if (!has_capability('mod/mylkpd:submit', $context, $userid, false)) {
            return null;
        }
        require_once(__DIR__ . '/locallib.php');
        $mylkpd = $DB->get_record('mylkpd', ['id' => $event->instance], '*', MUST_EXIST);
        $sub = mylkpd_find_submission($mylkpd, $cm, $userid);
        if ($sub && $sub->status == MYLKPD_STATUS_SUBMITTED) {
            return null;
        }
        return $factory->create_instance(
            get_string('workonit', 'mod_mylkpd'),
            new moodle_url('/mod/mylkpd/view.php', ['id' => $cm->id]),
            1,
            mylkpd_can_work($mylkpd)
        );
    }
    return null;
}

/**
 * Cached course module information: the dates, so the course page and activity header can show them.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|false
 */
function mylkpd_get_coursemodule_info($coursemodule) {
    global $DB;
    $mylkpd = $DB->get_record(
        'mylkpd',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat, timeopen, duedate, cutoffdate'
    );
    if (!$mylkpd) {
        return false;
    }
    $info = new cached_cm_info();
    $info->name = $mylkpd->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('mylkpd', $mylkpd, $coursemodule->id, false);
    }
    $info->customdata = [
        'timeopen' => (int) $mylkpd->timeopen,
        'duedate' => (int) $mylkpd->duedate,
        'cutoffdate' => (int) $mylkpd->cutoffdate,
    ];
    return $info;
}
