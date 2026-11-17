<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Site settings for mod_mylkpd: AI essay checking.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading('mod_mylkpd/credit', '',
        html_writer::div(get_string('creditsettings', 'mod_mylkpd'), 'mylkpd-credit-admin')));

    $settings->add(new admin_setting_heading('mod_mylkpd/aiheading', get_string('aiheading', 'mod_mylkpd'),
        get_string('aiheading_desc', 'mod_mylkpd')));

    $settings->add(new admin_setting_configcheckbox('mod_mylkpd/aienabled',
        get_string('aisiteenabled', 'mod_mylkpd'), get_string('aisiteenabled_desc', 'mod_mylkpd'), 0));

    $settings->add(new admin_setting_configselect('mod_mylkpd/aiprovider',
        get_string('aiprovider', 'mod_mylkpd'), get_string('aiprovider_desc', 'mod_mylkpd'), 'anthropic', [
            'anthropic' => get_string('aiprovideranthropic', 'mod_mylkpd'),
            'openai' => get_string('aiprovideropenai', 'mod_mylkpd'),
        ]));

    $settings->add(new admin_setting_configpasswordunmask('mod_mylkpd/aiapikey',
        get_string('aiapikey', 'mod_mylkpd'), get_string('aiapikey_desc', 'mod_mylkpd'), ''));

    $settings->add(new admin_setting_configtext('mod_mylkpd/aimodel',
        get_string('aimodel', 'mod_mylkpd'), get_string('aimodel_desc', 'mod_mylkpd'), '', PARAM_TEXT));

    $settings->add(new admin_setting_configtext('mod_mylkpd/aiendpoint',
        get_string('aiendpoint', 'mod_mylkpd'), get_string('aiendpoint_desc', 'mod_mylkpd'), '', PARAM_URL));

    // Connection status right on the settings page (checks the values typed above, no need to save first).
    $settings->add(new \mod_mylkpd\admin\setting_aistatus());
}
