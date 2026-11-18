<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_mylkpd\task;

/**
 * Checks and grades submitted worksheets with AI at the time the teacher chose.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_autocheck extends \core\task\scheduled_task {

    /**
     * Name shown in the scheduled tasks list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskaiautocheck', 'mod_mylkpd');
    }

    /**
     * Runs the check.
     */
    public function execute() {
        global $CFG;
        require_once($CFG->dirroot . '/mod/mylkpd/locallib.php');
        \core_php_time_limit::raise(600);
        $stats = mylkpd_ai_autocheck_run(40, function($message) {
            mtrace('  ' . $message);
        });
        mtrace("  MyLKPD AI: {$stats['checked']} checked, {$stats['graded']} graded, {$stats['failed']} failed.");
    }
}
