/**
 * MyLKPD settings: check whether the AI API is connected, using the values typed in the form.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
var MyLKPDStatus = (function() {
    'use strict';

    function init(cfg) {
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
            var body = new URLSearchParams();
            body.append('sesskey', cfg.sesskey);
            body.append('provider', value('provider') || 'anthropic');
            body.append('apikey', value('apikey'));
            body.append('model', value('model'));
            body.append('endpoint', value('endpoint'));
            fetch(cfg.url, {method: 'POST', credentials: 'same-origin', body: body}).then(function(r) {
                return r.json();
            }).then(function(res) {
                if (!res || typeof res.ok !== 'boolean') {
                    throw new Error((res && (res.error || res.message)) || cfg.strings.aicheckfailed);
                }
                var details = cfg.strings.details.replace('{time}', res.timetext || '').replace('{model}', res.model || '')
                    .replace('{seconds}', res.seconds);
                show(res.ok ? 'ok' : 'fail', res.ok ? cfg.strings.aiconnected : cfg.strings.aidisconnected, details,
                    res.ok ? '' : res.message);
            }).catch(function(err) {
                show('fail', cfg.strings.aidisconnected, '', (err && err.message) ? err.message : cfg.strings.aicheckfailed);
            }).then(function() {
                btn.disabled = false;
            });
        });
    }

    return {init: init};
})();
