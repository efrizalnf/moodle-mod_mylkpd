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
 * Backup task for mod_mylkpd: lets courses with MyLKPD be backed up, imported and duplicated.
 *
 * @package    mod_mylkpd
 * @category   backup
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/mylkpd/backup/moodle2/backup_mylkpd_stepslib.php');

/**
 * Provides the steps to back up one MyLKPD activity.
 */
class backup_mylkpd_activity_task extends backup_activity_task {

    /**
     * No specific settings for this activity.
     */
    protected function define_my_settings() {
    }

    /**
     * The activity structure: settings, answer key and (with user data) the students' work.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_mylkpd_activity_structure_step('mylkpd_structure', 'mylkpd.xml'));
    }

    /**
     * Encodes links to the activity so they keep working after a restore.
     *
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;
        $base = preg_quote($CFG->wwwroot, '/');
        $content = preg_replace('/(' . $base . '\/mod\/mylkpd\/index.php\?id\=)([0-9]+)/', '$@MYLKPDINDEX*$2@$', $content);
        $content = preg_replace('/(' . $base . '\/mod\/mylkpd\/view.php\?id\=)([0-9]+)/', '$@MYLKPDVIEWBYID*$2@$', $content);
        return $content;
    }
}
