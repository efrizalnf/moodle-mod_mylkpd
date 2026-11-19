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

namespace mod_mylkpd\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Settings page: checks whether the AI API answers, with the values typed in the form or the saved ones.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_ai_connection extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'saved' => new external_value(PARAM_BOOL, 'Test the saved settings instead of the form values', VALUE_DEFAULT, false),
            'provider' => new external_value(PARAM_ALPHA, 'anthropic or openai', VALUE_DEFAULT, 'anthropic'),
            'apikey' => new external_value(PARAM_RAW_TRIMMED, 'API key (empty = saved key)', VALUE_DEFAULT, ''),
            'model' => new external_value(PARAM_TEXT, 'Model', VALUE_DEFAULT, ''),
            'endpoint' => new external_value(PARAM_URL, 'Base URL', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Sends a tiny request to the provider.
     *
     * @param bool $saved
     * @param string $provider
     * @param string $apikey
     * @param string $model
     * @param string $endpoint
     * @return array
     */
    public static function execute(
        bool $saved = false,
        string $provider = 'anthropic',
        string $apikey = '',
        string $model = '',
        string $endpoint = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'saved',
            'provider',
            'apikey',
            'model',
            'endpoint'
        ));
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $override = null;
        if (!$params['saved']) {
            $override = ['aiprovider' => $params['provider'], 'aimodel' => $params['model'], 'aiendpoint' => $params['endpoint']];
            if ($params['apikey'] !== '') {
                $override['aiapikey'] = $params['apikey'];
            }
        }
        \core_php_time_limit::raise(150);
        $result = \mod_mylkpd\ai\client::test_connection($override);
        return [
            'ok' => (bool) $result['ok'],
            'seconds' => (float) $result['seconds'],
            'model' => (string) $result['model'],
            'message' => (string) $result['message'],
            'reply' => (string) $result['reply'],
            'timetext' => userdate($result['time']),
        ];
    }

    /**
     * Return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the API answered with readable JSON'),
            'seconds' => new external_value(PARAM_FLOAT, 'Response time'),
            'model' => new external_value(PARAM_TEXT, 'Provider / model'),
            'message' => new external_value(PARAM_TEXT, 'Result or error message'),
            'reply' => new external_value(PARAM_RAW, 'Start of the raw reply'),
            'timetext' => new external_value(PARAM_TEXT, 'When it was checked'),
        ]);
    }
}
