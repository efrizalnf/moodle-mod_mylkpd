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

namespace mod_mylkpd\ai;

/**
 * Talks to the AI provider chosen in the plugin settings: Anthropic (Claude) or OpenAI / an OpenAI-compatible API.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {

    /** Model used for Claude when the setting is empty. */
    const DEFAULT_ANTHROPIC_MODEL = 'claude-sonnet-5-5';

    /**
     * Whether AI checking is switched on for the site and has an API key.
     *
     * @return bool
     */
    public static function site_enabled(): bool {
        return (bool) get_config('mod_mylkpd', 'aienabled') && trim((string) get_config('mod_mylkpd', 'aiapikey')) !== '';
    }

    /**
     * Provider and model actually used, for display.
     *
     * @return string
     */
    public static function describe(?array $override = null): string {
        [$provider, $model] = self::provider_model($override);
        return $provider . ' / ' . ($model ?: '?');
    }

    /**
     * @return array [provider, model]
     */
    protected static function provider_model(?array $override = null): array {
        $provider = self::setting('aiprovider', $override) === 'openai' ? 'openai' : 'anthropic';
        $model = trim((string) self::setting('aimodel', $override));
        if ($model === '' && $provider === 'anthropic') {
            $model = self::DEFAULT_ANTHROPIC_MODEL;
        }
        return [$provider, $model];
    }

    /**
     * Sends one request and returns the model's text answer.
     *
     * @param string $system instructions
     * @param string $prompt the task (with the data)
     * @return string
     * @throws \moodle_exception
     */
    public static function complete(string $system, string $prompt, ?array $override = null): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        // A connection test ($override) may run before the feature is switched on.
        if ($override === null && !self::site_enabled()) {
            throw new \moodle_exception('aidisabled', 'mod_mylkpd');
        }
        [$provider, $model] = self::provider_model($override);
        if ($model === '') {
            throw new \moodle_exception('aimodelmissing', 'mod_mylkpd');
        }
        $key = trim((string) self::setting('aiapikey', $override));
        if ($key === '') {
            throw new \moodle_exception('aikeymissing', 'mod_mylkpd');
        }
        $base = rtrim(trim((string) self::setting('aiendpoint', $override)), '/');

        if ($provider === 'anthropic') {
            $url = ($base ?: 'https://api.anthropic.com/v1') . '/messages';
            $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
            $body = ['model' => $model, 'max_tokens' => 4000, 'temperature' => 0, 'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]]];
        } else {
            $url = ($base ?: 'https://api.openai.com/v1') . '/chat/completions';
            $headers = ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $key];
            $body = ['model' => $model, 'temperature' => 0, 'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]]];
        }

        // Some models or compatible APIs reject optional fields; retry once without them.
        $optional = ['temperature', 'response_format'];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $curl = new \curl();
            $curl->setHeader($headers);
            $curl->setopt(['CURLOPT_TIMEOUT' => 120, 'CURLOPT_CONNECTTIMEOUT' => 15]);
            $raw = $curl->post($url, json_encode($body));
            if ($curl->get_errno()) {
                throw new \moodle_exception('aierror', 'mod_mylkpd', '', $curl->error);
            }
            $status = (int) ($curl->get_info()['http_code'] ?? 0);
            $data = json_decode((string) $raw, true);
            if ($status >= 200 && $status < 300 && is_array($data)) {
                return $provider === 'anthropic' ? self::anthropic_text($data) : self::openai_text($data);
            }
            $message = is_array($data) ? (string) ($data['error']['message'] ?? $data['message'] ?? '') : '';
            // Routers and some models reject optional fields, not always naming them: retry once without.
            $retry = false;
            if (in_array($status, [400, 422], true) && $attempt === 0) {
                foreach ($optional as $field) {
                    if (isset($body[$field])) {
                        unset($body[$field]);
                        $retry = true;
                    }
                }
            }
            if (!$retry) {
                throw new \moodle_exception('aierror', 'mod_mylkpd', '', 'HTTP ' . $status . ($message ? ': ' . $message : ''));
            }
        }
        throw new \moodle_exception('aierror', 'mod_mylkpd', '', 'retry failed');
    }

    /**
     * A plugin setting, or the value being tested from the settings form.
     *
     * @param string $name
     * @param array|null $override
     * @return mixed
     */
    protected static function setting(string $name, ?array $override) {
        if ($override !== null && array_key_exists($name, $override)) {
            return $override[$name];
        }
        return get_config('mod_mylkpd', $name);
    }

    /**
     * Sends a tiny request and reports whether the connection works.
     *
     * @param array|null $override values from the settings form (null = saved settings)
     * @return array ['ok' => bool, 'seconds' => float, 'model' => string, 'message' => string, 'reply' => string]
     */
    public static function test_connection(?array $override = null): array {
        $start = microtime(true);
        $result = ['ok' => false, 'seconds' => 0, 'model' => self::describe($override), 'message' => '', 'reply' => ''];
        try {
            $reply = self::complete('You check essay answers. Reply with JSON only.',
                'Connection test. Reply exactly with {"results":[{"id":"q1","score":1,"feedback":"OK"}]}', $override);
            $parsed = grader::find_json($reply);
            $result['reply'] = \core_text::substr($reply, 0, 1500);
            $result['ok'] = $parsed !== null && isset($parsed['results'][0]['score']);
            $result['message'] = get_string($result['ok'] ? 'aistatusok' : 'aistatusformat', 'mod_mylkpd');
        } catch (\moodle_exception $e) {
            $result['message'] = $e->getMessage();
        }
        $result['seconds'] = round(microtime(true) - $start, 1);
        $result['time'] = time();
        // Remember the outcome so the settings page can show it straight away next time.
        set_config('aistatus', json_encode($result + ['tested' => $override === null ? 'saved' : 'form']), 'mod_mylkpd');
        return $result;
    }

    /**
     * Text of an OpenAI-style chat completion. Some compatible routers send the content as a list of parts.
     *
     * @param array $data
     * @return string
     */
    protected static function openai_text(array $data): string {
        $content = $data['choices'][0]['message']['content'] ?? ($data['choices'][0]['text'] ?? '');
        if (is_array($content)) {
            $content = implode('', array_map(function($part) {
                return is_array($part) ? (string) ($part['text'] ?? '') : (string) $part;
            }, $content));
        }
        return (string) $content;
    }

    /**
     * Joins the text blocks of an Anthropic Messages API response.
     *
     * @param array $data
     * @return string
     */
    protected static function anthropic_text(array $data): string {
        $out = '';
        foreach ($data['content'] ?? [] as $part) {
            if (($part['type'] ?? '') === 'text') {
                $out .= $part['text'];
            }
        }
        return $out;
    }
}
