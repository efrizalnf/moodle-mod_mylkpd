/**
 * MyLKPD: AI check panel on the grading page. Shows the suggested score and feedback per question;
 * the teacher decides whether to copy them into the grade form.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
var MyLKPDAI = (function() {
    'use strict';

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) {
            node.className = cls;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function init(cfg) {
        // Same decimal separator as the Moodle site language, so the grade form accepts the value.
        var fmt = function(n) {
            return (Math.round(n * 100) / 100).toString().replace('.', cfg.decsep || '.');
        };
        var S = cfg.strings;
        var out = document.getElementById('mylkpd-ai-result');
        var btn = document.querySelector('[data-mylkpd-ai="check"]');
        if (!out || !btn) {
            return;
        }
        var last = null;

        function render(result) {
            last = result;
            out.textContent = '';
            if (!result) {
                return;
            }
            var table = el('table', 'generaltable mylkpd-ai-table');
            var head = el('tr');
            [S.colnumber, S.colanswer, S.colscore, S.colfeedback].forEach(function(h) {
                head.appendChild(el('th', null, h));
            });
            table.appendChild(head);
            result.items.forEach(function(it) {
                var tr = el('tr');
                tr.appendChild(el('td', 'mylkpd-ai-num', it.label || '-'));
                tr.appendChild(el('td', 'mylkpd-ai-answer', it.answer || S.empty));
                tr.appendChild(el('td', 'mylkpd-ai-score', it.score === null ? '?' : fmt(it.score) + ' / ' + fmt(it.max)));
                var fb = el('td', 'mylkpd-ai-feedback', it.feedback || '');
                if (it.missing && it.missing.length) {
                    fb.appendChild(el('div', 'mylkpd-ai-missing', S.missing + ': ' + it.missing.join('; ')));
                }
                tr.appendChild(fb);
                table.appendChild(tr);
            });
            var wrap = el('div', 'mylkpd-ai-tablewrap');
            wrap.appendChild(table);
            out.appendChild(wrap);
            var total = el('div', 'mylkpd-ai-total');
            total.appendChild(el('strong', null, S.total + ': ' + fmt(result.total) + ' / ' + fmt(result.max)));
            if (cfg.grade > 0 && result.max > 0) {
                total.appendChild(document.createTextNode(' \u2192 ' + fmt(result.total / result.max * cfg.grade) +
                    ' / ' + fmt(cfg.grade)));
            }
            out.appendChild(total);
            out.appendChild(el('div', 'small text-muted', S.checkedat.replace('{time}', result.timetext || '')
                .replace('{model}', result.model || '')));
            var apply = el('button', 'btn btn-primary btn-sm mt-2', S.apply);
            apply.type = 'button';
            apply.addEventListener('click', applyToForm);
            out.appendChild(apply);
            out.appendChild(el('div', 'small text-muted mt-1', S.review));
        }

        function applyToForm() {
            if (!last) {
                return;
            }
            var gradeInput = document.getElementById('id_grade');
            if (gradeInput && gradeInput.tagName === 'INPUT' && !cfg.hasRubric && cfg.grade > 0 && last.max > 0) {
                gradeInput.value = fmt(last.total / last.max * cfg.grade);
                gradeInput.dispatchEvent(new Event('change', {bubbles: true}));
            }
            var feedback = document.getElementById('id_feedback');
            if (feedback) {
                var lines = last.items.filter(function(it) {
                    return it.feedback;
                }).map(function(it) {
                    return S.number + ' ' + (it.label || '-') + ': ' + it.feedback;
                });
                var text = lines.join('\n');
                if (feedback.value.trim() && !window.confirm(S.replacefeedback)) {
                    text = feedback.value.trim() + '\n\n' + text;
                }
                feedback.value = text;
                feedback.dispatchEvent(new Event('input', {bubbles: true}));
                feedback.scrollIntoView({behavior: 'smooth', block: 'center'});
            }
        }

        btn.addEventListener('click', function() {
            btn.disabled = true;
            var label = btn.textContent;
            btn.textContent = S.checking;
            var body = new URLSearchParams();
            body.append('sesskey', cfg.sesskey);
            body.append('cmid', cfg.cmid);
            body.append('targetuser', cfg.target.targetuser || 0);
            body.append('targetgroup', cfg.target.targetgroup || 0);
            fetch(cfg.url, {method: 'POST', credentials: 'same-origin', body: body}).then(function(r) {
                return r.json();
            }).then(function(res) {
                if (!res || res.error || !res.result) {
                    throw new Error((res && res.error) || S.failed);
                }
                render(res.result);
            }).catch(function(err) {
                out.textContent = '';
                out.appendChild(el('div', 'alert alert-danger', S.failed + ' ' + (err && err.message ? err.message : '')));
            }).then(function() {
                btn.disabled = false;
                btn.textContent = label;
            });
        });

        render(cfg.result);
    }

    return {init: init};
})();
