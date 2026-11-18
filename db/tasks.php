<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Scheduled tasks of mod_mylkpd.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'mod_mylkpd\task\ai_autocheck',
        'blocking' => 0,
        'minute' => '*/10',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
