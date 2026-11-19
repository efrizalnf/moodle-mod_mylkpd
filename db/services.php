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
 * External functions of mod_mylkpd (called from the plugin's JavaScript through core/ajax).
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_mylkpd_sync_worksheet' => [
        'classname' => 'mod_mylkpd\external\sync_worksheet',
        'description' => 'Saves changes to a worksheet and returns what others changed (also teacher annotations).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/mylkpd:submit, mod/mylkpd:grade',
    ],
    'mod_mylkpd_check_submission' => [
        'classname' => 'mod_mylkpd\external\check_submission',
        'description' => 'Checks a student\'s essay answers with AI and stores the suggested score.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/mylkpd:grade',
    ],
    'mod_mylkpd_test_ai_connection' => [
        'classname' => 'mod_mylkpd\external\test_ai_connection',
        'description' => 'Checks whether the AI provider set in the plugin settings answers.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/site:config',
    ],
];
