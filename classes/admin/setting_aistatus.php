<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_mylkpd\admin;

/**
 * Settings page widget: shows whether the AI API is connected and lets the admin check it.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_aistatus extends \admin_setting {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->nosave = true;
        parent::__construct('mod_mylkpd/aistatuswidget', get_string('aiconnection', 'mod_mylkpd'),
            get_string('aiconnection_desc', 'mod_mylkpd'), '');
    }

    /**
     * Nothing is stored by this widget itself.
     *
     * @return bool
     */
    public function get_setting() {
        return true;
    }

    /**
     * Nothing to save.
     *
     * @param mixed $data
     * @return string
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Status line, "Check connection" button and link to the detailed test page.
     *
     * @param mixed $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        global $PAGE;
        $last = json_decode((string) get_config('mod_mylkpd', 'aistatus'), true);
        $state = $last ? (!empty($last['ok']) ? 'ok' : 'fail') : 'none';
        $title = get_string($state === 'ok' ? 'aiconnected' : ($state === 'fail' ? 'aidisconnected' : 'aineverchecked'),
            'mod_mylkpd');
        $details = $last ? get_string('ailastchecked', 'mod_mylkpd', (object) [
            'time' => userdate($last['time'] ?? 0),
            'model' => $last['model'] ?? '',
            'seconds' => $last['seconds'] ?? 0,
        ]) : '';

        $html = \html_writer::start_div('mylkpd-aistatus mylkpd-aistatus-' . $state, ['data-mylkpd-aistatus' => 'box']);
        $html .= \html_writer::div(
            \html_writer::span('', 'mylkpd-aistatus-dot', ['aria-hidden' => 'true']) .
            \html_writer::tag('strong', s($title), ['data-mylkpd-aistatus' => 'title']) . ' ' .
            \html_writer::span(s($details), 'mylkpd-aistatus-details small text-muted', ['data-mylkpd-aistatus' => 'details']),
            'mylkpd-aistatus-line', ['role' => 'status', 'aria-live' => 'polite']);
        $html .= \html_writer::div(($last && empty($last['ok'])) ? s($last['message'] ?? '') : '',
            'mylkpd-aistatus-message small', ['data-mylkpd-aistatus' => 'message']);
        $html .= \html_writer::div(
            \html_writer::tag('button', '&#128268; ' . get_string('aicheckconnection', 'mod_mylkpd'),
                ['type' => 'button', 'class' => 'btn btn-secondary', 'data-mylkpd-aistatus' => 'check']) . ' ' .
            \html_writer::link(new \moodle_url('/mod/mylkpd/aitest.php'), get_string('aitestdetail', 'mod_mylkpd')),
            'mylkpd-aistatus-actions');
        $html .= \html_writer::div(get_string('aicheckusesform', 'mod_mylkpd'), 'small text-muted');
        $html .= \html_writer::end_div();

        $strings = [];
        foreach (['aiconnected', 'aidisconnected', 'aichecking', 'aicheckfailed'] as $k) {
            $strings[$k] = get_string($k, 'mod_mylkpd');
        }
        $strings['details'] = get_string('ailastchecked', 'mod_mylkpd', (object) ['time' => '{time}', 'model' => '{model}',
            'seconds' => '{seconds}']);
        $PAGE->requires->js(new \moodle_url('/mod/mylkpd/js/aistatus.js'));
        $PAGE->requires->js_init_code('MyLKPDStatus.init(' . json_encode([
            'url' => (new \moodle_url('/mod/mylkpd/aistatus.php'))->out(false),
            'sesskey' => sesskey(),
            'fields' => ['provider' => 'id_s_mod_mylkpd_aiprovider', 'apikey' => 'id_s_mod_mylkpd_aiapikey',
                'model' => 'id_s_mod_mylkpd_aimodel', 'endpoint' => 'id_s_mod_mylkpd_aiendpoint'],
            'strings' => $strings,
        ]) . ');', true);

        return format_admin_setting($this, $this->visiblename, $html, $this->description, false, '', null, $query);
    }
}
