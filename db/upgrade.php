<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Upgrade steps for mod_mylkpd.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_mylkpd_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026110300) {
        $table = new xmldb_table('mylkpd');
        $field = new xmldb_field('rendermode', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'grade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026110300, 'mylkpd');
    }

    if ($oldversion < 2026110400) {
        $table = new xmldb_table('mylkpd');
        $fields = [
            new xmldb_field('workmode', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'rendermode'),
            new xmldb_field('teamsubmission', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'workmode'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('mylkpd_submissions');
        $fields = [
            new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'userid'),
            new xmldb_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'status'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('mylkpd_presence');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('block', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $table->add_field('timeseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('submissionid', XMLDB_KEY_FOREIGN, ['submissionid'], 'mylkpd_submissions', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026110400, 'mylkpd');
    }

    if ($oldversion < 2026110500) {
        $table = new xmldb_table('mylkpd');
        $field = new xmldb_field('allowresubmit', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'teamsubmission');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026110500, 'mylkpd');
    }

    if ($oldversion < 2026111800) {
        $table = new xmldb_table('mylkpd');
        $field = new xmldb_field('aienabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'allowresubmit');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('mylkpd_submissions');
        $fields = [
            new xmldb_field('airesult', XMLDB_TYPE_TEXT, null, null, null, null, null, 'revision'),
            new xmldb_field('aitime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'airesult'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('mylkpd_questions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('mylkpdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('label', XMLDB_TYPE_CHAR, '30', null, null, null, null);
            $table->add_field('question', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('answerkey', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('keypoints', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('maxscore', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '10');
            $table->add_field('anchor', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('mylkpdid', XMLDB_KEY_FOREIGN, ['mylkpdid'], 'mylkpd', ['id']);
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026111800, 'mylkpd');
    }

    return true;
}
