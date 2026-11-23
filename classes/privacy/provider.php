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

namespace mod_mylkpd\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API implementation for mod_mylkpd.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes stored personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('mylkpd_submissions', [
            'userid' => 'privacy:metadata:mylkpd_submissions:userid',
            'answers' => 'privacy:metadata:mylkpd_submissions:answers',
            'submittedby' => 'privacy:metadata:mylkpd_submissions:submittedby',
            'grade' => 'privacy:metadata:mylkpd_submissions:grade',
            'feedback' => 'privacy:metadata:mylkpd_submissions:feedback',
            'timemodified' => 'privacy:metadata:mylkpd_submissions:timemodified',
            'airesult' => 'privacy:metadata:mylkpd_submissions:airesult',
        ], 'privacy:metadata:mylkpd_submissions');
        $collection->add_external_location_link('ai_provider', [
            'answers' => 'privacy:metadata:ai_provider:answers',
        ], 'privacy:metadata:ai_provider');
        $collection->add_database_table('mylkpd_presence', [
            'userid' => 'privacy:metadata:mylkpd_presence:userid',
            'block' => 'privacy:metadata:mylkpd_presence:block',
            'timeseen' => 'privacy:metadata:mylkpd_presence:timeseen',
        ], 'privacy:metadata:mylkpd_presence');
        $collection->add_subsystem_link('core_grading', [], 'privacy:metadata:core_grading');
        return $collection;
    }

    /**
     * Contexts holding data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $base = "SELECT c.id
                   FROM {context} c
                   JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :ctxlevel
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'mylkpd'
                   JOIN {mylkpd_submissions} s ON s.mylkpdid = cm.instance";
        $list = new contextlist();
        $list->add_from_sql("$base WHERE s.userid = :userid", ['ctxlevel' => CONTEXT_MODULE, 'userid' => $userid]);
        $list->add_from_sql(
            "$base JOIN {groups_members} gm ON gm.groupid = s.groupid WHERE gm.userid = :userid",
            ['ctxlevel' => CONTEXT_MODULE, 'userid' => $userid]
        );
        $list->add_from_sql(
            "$base JOIN {mylkpd_presence} p ON p.submissionid = s.id WHERE p.userid = :userid",
            ['ctxlevel' => CONTEXT_MODULE, 'userid' => $userid]
        );
        // Group work names its authors and who submitted inside JSON columns; this also finds people who have
        // left the group since.
        [$mentions, $mparams] = self::mentions_sql('s', $userid);
        $list->add_from_sql("$base WHERE s.groupid > 0 AND $mentions", ['ctxlevel' => CONTEXT_MODULE] + $mparams);
        return $list;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT s.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'mylkpd'
                  JOIN {mylkpd_submissions} s ON s.mylkpdid = cm.instance
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
        $sql = "SELECT p.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'mylkpd'
                  JOIN {mylkpd_submissions} s ON s.mylkpdid = cm.instance
                  JOIN {mylkpd_presence} p ON p.submissionid = s.id
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);

        // People named inside group submissions (authors of answers, members who submitted).
        $sql = "SELECT s.id, s.answers, s.submittedby
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'mylkpd'
                  JOIN {mylkpd_submissions} s ON s.mylkpdid = cm.instance
                 WHERE cm.id = :cmid AND s.groupid > 0";
        $rs = $DB->get_recordset_sql($sql, ['cmid' => $context->instanceid]);
        foreach ($rs as $sub) {
            $userlist->add_users(self::named_users($sub));
        }
        $rs->close();
    }

    /**
     * Exports a user's submissions.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('mylkpd', $context->instanceid);
            if (!$cm) {
                continue;
            }
            [$mentions, $mparams] = self::mentions_sql('s', (int) $user->id);
            $sql = "SELECT s.*
                      FROM {mylkpd_submissions} s
                     WHERE s.mylkpdid = :mylkpdid
                       AND ((s.groupid = 0 AND s.userid = :userid)
                            OR s.groupid IN (SELECT groupid FROM {groups_members} WHERE userid = :userid2)
                            OR (s.groupid > 0 AND $mentions))";
            $subs = $DB->get_records_sql($sql, ['mylkpdid' => $cm->instance, 'userid' => $user->id,
                'userid2' => $user->id] + $mparams);
            foreach ($subs as $sub) {
                $data = helper::get_context_data($context, $user);
                $data->groupwork = $sub->groupid ? transform::yesno(true) : transform::yesno(false);
                $data->answers = self::answers_for_export((string) $sub->answers, (int) $user->id);
                $submitted = json_decode((string) $sub->submittedby, true);
                if (is_array($submitted) && isset($submitted[$user->id])) {
                    $data->yousubmitted = transform::datetime((int) $submitted[$user->id]);
                }
                $data->grade = $sub->grade;
                $data->feedback = $sub->feedback;
                $data->aicheck = $sub->airesult ? json_decode($sub->airesult) : null;
                $data->timemodified = transform::datetime($sub->timemodified);
                $path = [get_string('submissions', 'mod_mylkpd'), $sub->id];
                writer::with_context($context)->export_data($path, $data);
                \core_grading\privacy\provider::export_item_data($context, (int) $sub->id, $path);
                writer::with_context($context)->export_area_files($path, 'mod_mylkpd', 'answerimage', $sub->id);
            }
        }
    }

    /**
     * Deletes all submissions in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        if ($cm = get_coursemodule_from_id('mylkpd', $context->instanceid)) {
            \core_grading\privacy\provider::delete_instance_data($context);
            get_file_storage()->delete_area_files($context->id, 'mod_mylkpd', 'answerimage');
            $DB->delete_records_select(
                'mylkpd_presence',
                'submissionid IN (SELECT id FROM {mylkpd_submissions} WHERE mylkpdid = ?)',
                [$cm->instance]
            );
            $DB->delete_records('mylkpd_submissions', ['mylkpdid' => $cm->instance]);
        }
    }

    /**
     * Deletes one user's submissions in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            if ($cm = get_coursemodule_from_id('mylkpd', $context->instanceid)) {
                // Group work belongs to the whole group, so only the member's own data is removed.
                $DB->delete_records_select(
                    'mylkpd_presence',
                    'userid = ? AND submissionid IN (SELECT id FROM {mylkpd_submissions} WHERE mylkpdid = ?)',
                    [$userid, $cm->instance]
                );
                $own = $DB->get_records('mylkpd_submissions', ['mylkpdid' => $cm->instance, 'userid' => $userid,
                    'groupid' => 0], '', 'id');
                foreach ($own as $sub) {
                    \core_grading\privacy\provider::delete_instance_data($context, (int) $sub->id);
                    get_file_storage()->delete_area_files($context->id, 'mod_mylkpd', 'answerimage', $sub->id);
                }
                $DB->delete_records('mylkpd_submissions', ['mylkpdid' => $cm->instance, 'userid' => $userid,
                    'groupid' => 0]);
                self::anonymise_group_submissions((int) $cm->instance, [(int) $userid]);
            }
        }
    }

    /**
     * Deletes submissions for several users in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_module || !$userlist->get_userids()) {
            return;
        }
        if ($cm = get_coursemodule_from_id('mylkpd', $context->instanceid)) {
            [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
            $params['mylkpdid'] = $cm->instance;
            $own = $DB->get_records_select(
                'mylkpd_submissions',
                "mylkpdid = :mylkpdid AND groupid = 0 AND userid $insql",
                $params,
                '',
                'id'
            );
            foreach ($own as $sub) {
                \core_grading\privacy\provider::delete_instance_data($context, (int) $sub->id);
                get_file_storage()->delete_area_files($context->id, 'mod_mylkpd', 'answerimage', $sub->id);
            }
            $DB->delete_records_select(
                'mylkpd_submissions',
                "mylkpdid = :mylkpdid AND groupid = 0 AND userid $insql",
                $params
            );
            $DB->delete_records_select('mylkpd_presence', "userid $insql AND submissionid IN
                (SELECT id FROM {mylkpd_submissions} WHERE mylkpdid = :mylkpdid)", $params);
            self::anonymise_group_submissions((int) $cm->instance, array_map('intval', $userlist->get_userids()));
        }
    }

    /**
     * SQL condition matching submissions whose JSON data names a user: as a key of submittedby (members who
     * submitted) or as the author ("by") of an answer. The JSON is written compactly by json_encode(), so the
     * id is followed by a comma or a closing brace (this keeps user 8 from matching user 80).
     *
     * @param string $alias table alias of mylkpd_submissions
     * @param int $userid
     * @return array [sql, params]
     */
    protected static function mentions_sql(string $alias, int $userid): array {
        global $DB;
        $params = [
            'mentionsubmitted' => '%"' . $userid . '":%',
            'mentionby1' => '%"by":' . $userid . ',%',
            'mentionby2' => '%"by":' . $userid . '}%',
        ];
        $sql = '(' . $DB->sql_like("$alias.submittedby", ':mentionsubmitted') . ' OR ' .
            $DB->sql_like("$alias.answers", ':mentionby1') . ' OR ' .
            $DB->sql_like("$alias.answers", ':mentionby2') . ')';
        return [$sql, $params];
    }

    /**
     * User ids named inside a submission's JSON data.
     *
     * @param \stdClass $sub record with answers and submittedby
     * @return int[]
     */
    protected static function named_users(\stdClass $sub): array {
        $ids = array_map('intval', array_keys((array) (json_decode((string) $sub->submittedby, true) ?: [])));
        $data = json_decode((string) $sub->answers, true);
        foreach (['items', 'blocks'] as $kind) {
            foreach ((array) ($data[$kind] ?? []) as $entry) {
                if (is_array($entry) && !empty($entry['by'])) {
                    $ids[] = (int) $entry['by'];
                }
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Answers for an export: other people's ids are removed; the user's own answers are marked as theirs.
     *
     * @param string $json stored answers
     * @param int $userid user the export is for
     * @return \stdClass|array|null
     */
    protected static function answers_for_export(string $json, int $userid) {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['items', 'blocks'] as $kind) {
            foreach ((array) ($data[$kind] ?? []) as $key => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $mine = (int) ($entry['by'] ?? 0) === $userid;
                unset($data[$kind][$key]['by']);
                $data[$kind][$key]['yours'] = transform::yesno($mine);
            }
        }
        return json_decode(json_encode($data));
    }

    /**
     * Removes users from the group submissions of an activity: their entry in submittedby, their name as author
     * of answers (set to 0) and as the member who submitted. The group's work itself is kept.
     *
     * @param int $mylkpdid activity instance
     * @param int[] $userids
     */
    protected static function anonymise_group_submissions(int $mylkpdid, array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        $rs = $DB->get_recordset_select(
            'mylkpd_submissions',
            'mylkpdid = ? AND groupid > 0',
            [$mylkpdid],
            '',
            'id, userid, answers, submittedby'
        );
        foreach ($rs as $sub) {
            $changes = [];
            if (in_array((int) $sub->userid, $userids, true)) {
                $changes['userid'] = 0;
            }
            $submitted = json_decode((string) $sub->submittedby, true);
            if (is_array($submitted)) {
                $kept = array_diff_key($submitted, array_flip($userids));
                if (count($kept) !== count($submitted)) {
                    $changes['submittedby'] = $kept ? json_encode($kept) : null;
                }
            }
            $data = json_decode((string) $sub->answers, true);
            if (is_array($data)) {
                $changed = false;
                foreach (['items', 'blocks'] as $kind) {
                    foreach ((array) ($data[$kind] ?? []) as $key => $entry) {
                        if (is_array($entry) && in_array((int) ($entry['by'] ?? 0), $userids, true)) {
                            $data[$kind][$key]['by'] = 0;
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    $changes['answers'] = json_encode($data, JSON_UNESCAPED_UNICODE);
                }
            }
            if ($changes) {
                $DB->update_record('mylkpd_submissions', (object) (['id' => $sub->id] + $changes));
            }
        }
        $rs->close();
    }
}
