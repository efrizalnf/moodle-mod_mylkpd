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
 * MyLKPD settings: check whether the AI API is connected, using the values typed in the form.
 *
 *
 * @module     mod_mylkpd/aistatus
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';


/**
 * Starts.
 *
 * @param {Object} cfg
 */
function start(cfg) {
    var box = document.querySelector('[data-mylkpd-aistatus="box"]');
    if (!box) {
        return;
    }
    var part = function(name) {
        return box.querySelector('[data-mylkpd-aistatus="' + name + '"]');
    };
    var btn = part('check');
    var value = function(key) {
        var field = document.getElementById(cfg.fields[key]);
        return field ? field.value.trim() : '';
    };
    var show = function(state, title, details, message) {
        box.className = 'mylkpd-aistatus mylkpd-aistatus-' + state;
        part('title').textContent = title;
        part('details').textContent = details || '';
        part('message').textContent = message || '';
    };

    btn.addEventListener('click', function() {
        btn.disabled = true;
        show('checking', cfg.strings.aichecking, '', '');
        Promise.resolve(Ajax.call([{methodname: 'mod_mylkpd_test_ai_connection', args: {
            provider: value('provider') || 'anthropic', apikey: value('apikey'), model: value('model'), endpoint: value('endpoint'),
        }}])[0]).then(function(res) {
            if (!res || typeof res.ok !== 'boolean') {
                var failure = (res && (res.error || res.message)) || cfg.strings.aicheckfailed;
                throw new Error(failure);
            }
            var details = cfg.strings.details.replace('{time}', res.timetext || '').replace('{model}', res.model || '')
                .replace('{seconds}', res.seconds);
            show(res.ok ? 'ok' : 'fail', res.ok ? cfg.strings.aiconnected : cfg.strings.aidisconnected, details,
                res.ok ? '' : res.message);
            return null;
        }).catch(function(err) {
            show('fail', cfg.strings.aidisconnected, '', (err && err.message) ? err.message : cfg.strings.aicheckfailed);
            return null;
        }).then(function() {
            btn.disabled = false;
            return null;
        }).catch(Notification.exception);
    });
}

/**
 * Starts the connection status widget (settings in data-mylkpd-config of the element).
 *
 * @param {String} configId
 */
export const init = (configId) => {
    const holder = document.getElementById(configId);
    if (holder) {
        start(JSON.parse(holder.getAttribute('data-mylkpd-config')));
    }
};
