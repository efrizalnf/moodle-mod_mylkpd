<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_mylkpd\ai;

/**
 * Talks to the AI provider chosen in the plugin settings: Anthropic (Claude) or OpenAI / an OpenAI-compatible API.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
    public static function describe(): string {
        [$provider, $model] = self::provider_model();
        return $provider . ' / ' . ($model ?: '?');
    }

    /**
     * @return array [provider, model]
     */
    protected static function provider_model(): array {
        $provider = get_config('mod_mylkpd', 'aiprovider') === 'openai' ? 'openai' : 'anthropic';
        $model = trim((string) get_config('mod_mylkpd', 'aimodel'));
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
    public static function complete(string $system, string $prompt): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (!self::site_enabled()) {
            throw new \moodle_exception('aidisabled', 'mod_mylkpd');
        }
        [$provider, $model] = self::provider_model();
        if ($model === '') {
            throw new \moodle_exception('aimodelmissing', 'mod_mylkpd');
        }
        $key = trim((string) get_config('mod_mylkpd', 'aiapikey'));
        $base = rtrim(trim((string) get_config('mod_mylkpd', 'aiendpoint')), '/');

        if ($provider === 'anthropic') {
            $url = ($base ?: 'https://api.anthropic.com/v1') . '/messages';
            $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
            $body = ['model' => $model, 'max_tokens' => 4000, 'temperature' => 0, 'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]]];
        } else {
            $url = ($base ?: 'https://api.openai.com/v1') . '/chat/completions';
            $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
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
                return $provider === 'anthropic' ? self::anthropic_text($data) : (string) ($data['choices'][0]['message']['content'] ?? '');
            }
            $message = is_array($data) ? (string) ($data['error']['message'] ?? $data['message'] ?? '') : '';
            $retry = false;
            if ($status === 400) {
                foreach ($optional as $field) {
                    if (isset($body[$field]) && stripos($message, $field) !== false) {
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
