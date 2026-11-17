<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests the AI connection from the plugin settings (provider, key, model, base URL) and shows the raw reply.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_admin();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/mod/mylkpd/aitest.php');
$PAGE->set_title(get_string('aitest', 'mod_mylkpd'));
$PAGE->set_heading(get_string('aitest', 'mod_mylkpd'));
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingmylkpd']);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('aitest', 'mod_mylkpd'));

$base = trim((string) get_config('mod_mylkpd', 'aiendpoint'));
echo html_writer::tag('p', s(get_string('aitestusing', 'mod_mylkpd', (object) [
    'provider' => \mod_mylkpd\ai\client::describe(),
    'endpoint' => $base !== '' ? $base : get_string('aitestofficial', 'mod_mylkpd'),
])));

if (optional_param('run', 0, PARAM_BOOL) && confirm_sesskey()) {
    $start = microtime(true);
    try {
        $reply = \mod_mylkpd\ai\client::complete(
            'You check essay answers. Reply with JSON only.',
            'Connection test. Reply exactly with {"results":[{"id":"q1","score":1,"feedback":"OK"}]}');
        $seconds = round(microtime(true) - $start, 1);
        $parsed = \mod_mylkpd\ai\grader::find_json($reply);
        $ok = $parsed && isset($parsed['results'][0]['score']);
        echo $OUTPUT->notification(get_string($ok ? 'aitestok' : 'aitestunreadable', 'mod_mylkpd', $seconds),
            $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
        echo html_writer::tag('p', get_string('aitestreply', 'mod_mylkpd'));
        echo html_writer::tag('pre', s(core_text::substr($reply, 0, 3000)), ['class' => 'mylkpd-aitest-reply']);
    } catch (moodle_exception $e) {
        echo $OUTPUT->notification(get_string('aitestfailed', 'mod_mylkpd') . ' ' . s($e->getMessage()),
            \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->single_button(new moodle_url($PAGE->url, ['run' => 1, 'sesskey' => sesskey()]),
    get_string('aitestrun', 'mod_mylkpd'), 'post');
echo html_writer::div(html_writer::link($settingsurl, get_string('aitestback', 'mod_mylkpd')), 'mt-3');
echo $OUTPUT->footer();
