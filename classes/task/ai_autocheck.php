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

namespace mod_mylkpd\task;

/**
 * Checks and grades submitted worksheets with AI at the time the teacher chose.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
