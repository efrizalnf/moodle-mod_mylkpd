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

namespace mod_mylkpd\admin;

/**
 * Settings page widget: shows whether the AI API is connected and lets the admin check it.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_aistatus extends \admin_setting {
    /**
     * Constructor.
     */
    public function __construct() {
        $this->nosave = true;
        parent::__construct(
            'mod_mylkpd/aistatuswidget',
            get_string('aiconnection', 'mod_mylkpd'),
            get_string('aiconnection_desc', 'mod_mylkpd'),
            ''
        );
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
        global $OUTPUT, $PAGE;
        $last = json_decode((string) get_config('mod_mylkpd', 'aistatus'), true);
        $state = $last ? (!empty($last['ok']) ? 'ok' : 'fail') : 'none';
        $title = get_string(
            $state === 'ok' ? 'aiconnected' : ($state === 'fail' ? 'aidisconnected' : 'aineverchecked'),
            'mod_mylkpd'
        );
        $details = $last ? get_string('ailastchecked', 'mod_mylkpd', (object) [
            'time' => userdate($last['time'] ?? 0),
            'model' => $last['model'] ?? '',
            'seconds' => $last['seconds'] ?? 0,
        ]) : '';

        $strings = [];
        foreach (['aiconnected', 'aidisconnected', 'aichecking', 'aicheckfailed'] as $k) {
            $strings[$k] = get_string($k, 'mod_mylkpd');
        }
        $strings['details'] = get_string('ailastchecked', 'mod_mylkpd', (object) ['time' => '{time}', 'model' => '{model}',
            'seconds' => '{seconds}']);
        $html = $OUTPUT->render_from_template('mod_mylkpd/ai_status', [
            'state' => $state,
            'title' => $title,
            'details' => $details,
            'message' => ($last && empty($last['ok'])) ? (string) ($last['message'] ?? '') : '',
            'checklabel' => get_string('aicheckconnection', 'mod_mylkpd'),
            'testurl' => (new \moodle_url('/mod/mylkpd/aitest.php'))->out(false),
            'testlabel' => get_string('aitestdetail', 'mod_mylkpd'),
            'hint' => get_string('aicheckusesform', 'mod_mylkpd'),
            'config' => json_encode([
                'fields' => ['provider' => 'id_s_mod_mylkpd_aiprovider', 'apikey' => 'id_s_mod_mylkpd_aiapikey',
                    'model' => 'id_s_mod_mylkpd_aimodel', 'endpoint' => 'id_s_mod_mylkpd_aiendpoint'],
                'strings' => $strings,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $PAGE->requires->js_call_amd('mod_mylkpd/aistatus', 'init', ['mylkpd-aistatus']);

        return format_admin_setting($this, $this->visiblename, $html, $this->description, false, '', null, $query);
    }
}
