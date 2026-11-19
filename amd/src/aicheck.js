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
 * MyLKPD: AI check panel on the grading page. Shows the suggested score and feedback per question;
 * the teacher decides whether to copy them into the grade form.
 *
 *
 * @module     mod_mylkpd/aicheck
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import Ajax from 'core/ajax';
import {getCurrent} from 'mod_mylkpd/worksheet';
import Notification from 'core/notification';

/**
 * Asks a yes/no question in a Moodle dialog.
 *
 * @param {String} question
 * @return {Promise} resolves true when confirmed, false when cancelled
 */
function ask(question) {
    return Notification.saveCancelPromise(getString('confirm', 'core'), question, getString('yes', 'core'))
        .then(function() {
            return true;
        }).catch(function() {
            return false;
        });
}

/**
 * Runs the AI check for one student or group.
 *
 * @param {Number} cmid
 * @param {Number} targetuser
 * @param {Number} targetgroup
 * @return {Promise} resolves with {result} or {error}
 */
function check(cmid, targetuser, targetgroup) {
    return Promise.resolve(Ajax.call([{methodname: 'mod_mylkpd_check_submission',
        args: {cmid: cmid, targetuser: targetuser, targetgroup: targetgroup}}])[0]).then(function(r) {
        return JSON.parse(r.data);
    });
}


/**
 * Creates the element.
 *
 * @param {String} tag
 * @param {String} cls
 * @param {String} text
 */
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

/**
 * Starts panel.
 *
 * @param {Object} cfg
 */
function startPanel(cfg) {
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

    /**
     * Renders.
     *
     * @param {Object} result
     */
    function render(result) {
        last = result;
        out.textContent = '';
        if (!result) {
            return;
        }
        if (result.error) {
            // A failed automatic check: show why; the teacher can run the check again by hand.
            last = null;
            out.appendChild(el('div', 'alert alert-warning', S.autoerror.replace('{error}', result.error)));
            return;
        }
        if (result.auto) {
            out.appendChild(el('div', 'mylkpd-ai-autonote', '\u23F1 ' + S.autodone));
        }
        var table = el('table', 'generaltable mylkpd-ai-table');
        var head = el('tr');
        [S.colnumber, S.colanswer, S.colscore, S.colstatus, S.colfeedback].forEach(function(h) {
            head.appendChild(el('th', null, h));
        });
        table.appendChild(head);
        result.items.forEach(function(it) {
            var tr = el('tr');
            tr.appendChild(el('td', 'mylkpd-ai-num', it.label || '-'));
            var answerCell = el('td', 'mylkpd-ai-answer', it.answer || S.empty);
            if (it.unchanged) {
                answerCell.appendChild(el('div', 'mylkpd-ai-warn', S.unchanged));
            }
            tr.appendChild(answerCell);
            tr.appendChild(el('td', 'mylkpd-ai-score', it.score === null ? '?' : fmt(it.score) + ' / ' + fmt(it.max)));
            var verdict = it.verdict || 'unknown';
            tr.appendChild(el('td', null)).appendChild(el('span', 'mylkpd-badge mylkpd-badge-' + verdict, S['v' + verdict]));
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
        if (result.outside > 0) {
            out.appendChild(el('div', 'alert alert-warning mylkpd-ai-outside', S.outside.replace('{n}', result.outside)));
        }
        markWorksheet();
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

    // Show right / partly right / wrong on the worksheet itself, at each question's answer area.
    var marksToggle = document.querySelector('[data-mylkpd-ai="marks"]');

    /**
     * Marks worksheet.
     */
    function markWorksheet() {
        var api = getCurrent();
        if (!api || !api.showAnchors) {
            return;
        }
        if (!last || (marksToggle && !marksToggle.checked)) {
            api.showAnchors([]);
            return;
        }
        var icon = {right: '\u2713', partial: '\u25D0', wrong: '\u2717', unknown: '?'};
        api.showAnchors(last.items.filter(function(it) {
            return cfg.areas && cfg.areas[it.id];
        }).map(function(it) {
            var v = it.verdict || 'unknown';
            return {label: (it.label || '') + ' ' + icon[v] + (it.score === null ? '' : ' ' + fmt(it.score) + '/' + fmt(it.max)),
                area: cfg.areas[it.id], verdict: v};
        }));
    }
    document.addEventListener('mylkpd:ready', markWorksheet);
    if (marksToggle) {
        marksToggle.addEventListener('change', markWorksheet);
    }

    /**
     * Applies to form.
     */
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
            var put = function(keepOld) {
                feedback.value = keepOld ? feedback.value.trim() + '\n\n' + text : text;
                feedback.dispatchEvent(new Event('input', {bubbles: true}));
                feedback.scrollIntoView({behavior: 'smooth', block: 'center'});
            };
            if (feedback.value.trim()) {
                // Replace the feedback already written, or add the AI feedback under it.
                ask(S.replacefeedback).then(function(replace) {
                    put(!replace);
                    return null;
                }).catch(Notification.exception);
            } else {
                put(false);
            }
        }
    }

    btn.addEventListener('click', function() {
        btn.disabled = true;
        var label = btn.textContent;
        btn.textContent = S.checking;
        check(cfg.cmid, cfg.target.targetuser || 0, cfg.target.targetgroup || 0).then(function(res) {
            if (!res || res.error || !res.result) {
                var failure = (res && res.error) || S.failed;
                throw new Error(failure);
            }
            render(res.result);
            return null;
        }).catch(function(err) {
            out.textContent = '';
            out.appendChild(el('div', 'alert alert-danger', S.failed + ' ' + (err && err.message ? err.message : '')));
            return null;
        }).then(function() {
            btn.disabled = false;
            btn.textContent = label;
            return null;
        }).catch(Notification.exception);
    });

    render(cfg.result);
}

/**
 * Submissions list: "Check all with AI" runs the check for every row in turn and fills the AI score column.
 *
 * @param {Object} cfg
 */
function startBulk(cfg) {
    var S = cfg.strings;
    var btn = document.querySelector('[data-mylkpd-ai="bulk"]');
    if (!btn) {
        return;
    }
    var fmt = function(n) {
        return (Math.round(n * 100) / 100).toString().replace('.', cfg.decsep || '.');
    };
    btn.addEventListener('click', function() {
        var cells = Array.prototype.slice.call(document.querySelectorAll('[data-mylkpd-aicell]')).filter(function(c) {
            return c.getAttribute('data-mylkpd-hasanswers') === '1';
        });
        if (!cells.length) {
            return;
        }
        ask(S.bulkconfirm.replace('{n}', cells.length)).then(function(ok) {
            if (ok) {
                runAll(cells);
            }
            return null;
        }).catch(Notification.exception);
    });

    /**
     * Checks the given rows one after another.
     *
     * @param {Array} cells score cells still to check
     */
    function runAll(cells) {
        btn.disabled = true;
        var done = 0;
        var checkNext = function() {
            if (!cells.length) {
                btn.disabled = false;
                btn.textContent = S.bulkdone.replace('{n}', done);
                return;
            }
            var cell = cells.shift();
            cell.textContent = S.checking;
            btn.textContent = S.bulkprogress.replace('{n}', done + 1).replace('{total}', done + 1 + cells.length);
            check(cfg.cmid, +cell.getAttribute('data-targetuser') || 0, +cell.getAttribute('data-targetgroup') || 0)
            .then(function(res) {
                if (!res || res.error || !res.result) {
                    var failure = (res && res.error) || S.failed;
                    throw new Error(failure);
                }
                var r = res.result;
                cell.textContent = fmt(r.total) + ' / ' + fmt(r.max) +
                    (cfg.grade > 0 && r.max > 0 ? ' (' + fmt(r.total / r.max * cfg.grade) + ')' : '');
                return null;
            }).catch(function(err) {
                cell.textContent = '\u26A0 ' + (err && err.message ? err.message : S.failed);
                cell.classList.add('text-danger');
                return null;
            }).then(function() {
                done++;
                checkNext();
                return null;
            }).catch(Notification.exception);
        };
        checkNext();
    }
}

/**
 * Grading page: the AI panel (settings in data-mylkpd-config of the element).
 *
 * @param {String} configId
 */
export const init = (configId) => {
    const holder = document.getElementById(configId);
    if (holder) {
        startPanel(JSON.parse(holder.getAttribute('data-mylkpd-config')));
    }
};

/**
 * Submissions page: "Check all with AI".
 *
 * @param {String} configId
 */
export const initBulk = (configId) => {
    const holder = document.getElementById(configId);
    if (holder) {
        startBulk(JSON.parse(holder.getAttribute('data-mylkpd-config')));
    }
};
