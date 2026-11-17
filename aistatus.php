<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Checks the AI connection for the settings page. Tests the values currently typed in the settings form
 * (nothing has to be saved first), or the saved settings.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login(null, false, null, false, true);
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

$override = null;
if (!optional_param('saved', 0, PARAM_BOOL)) {
    $override = [
        'aiprovider' => optional_param('provider', 'anthropic', PARAM_ALPHA),
        'aimodel' => optional_param('model', '', PARAM_TEXT),
        'aiendpoint' => optional_param('endpoint', '', PARAM_URL),
    ];
    // Empty key field: test with the saved key.
    $key = optional_param('apikey', '', PARAM_RAW_TRIMMED);
    if ($key !== '') {
        $override['aiapikey'] = $key;
    }
}
core_php_time_limit::raise(150);
$result = \mod_mylkpd\ai\client::test_connection($override);
$result['timetext'] = userdate($result['time']);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
