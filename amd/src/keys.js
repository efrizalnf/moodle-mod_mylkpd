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
 * MyLKPD answer key editor: essay questions with model answers, each linked to its answer area.
 *
 *
 * @module     mod_mylkpd/keys
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import {getCurrent} from 'mod_mylkpd/worksheet';
import Notification from 'core/notification';


/**
 * Creates the element.
 *
 * @param {String} tag
 * @param {*} attrs
 * @param {String} text
 */
function el(tag, attrs, text) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function(k) {
        node.setAttribute(k, attrs[k]);
    });
    if (text !== undefined) {
        node.textContent = text;
    }
    return node;
}

/**
 * Starts.
 *
 * @param {Object} cfg
 */
function start(cfg) {
    var S = cfg.strings;
    var list = document.getElementById('mylkpd-keys-rows');
    if (!list) {
        return;
    }
    var rows = [];
    var active = -1;
    var mode = 'answer'; // What a click on the worksheet picks for the active row: 'question' or 'answer'.
    var api = getCurrent();
    var counter = 0;

    /**
     * Builds an empty area.
     */
    function emptyArea() {
        return {blocks: [], original: {}, regions: [], qblocks: [], qregions: []};
    }

    /**
     * Builds a full area.
     *
     * @param {Object} area
     */
    function fullArea(area) {
        var a = emptyArea();
        Object.keys(area || {}).forEach(function(k) {
            a[k] = area[k];
        });
        return a;
    }

    /**
     * Summarises.
     *
     * @param {Object} area
     */
    function summary(area) {
        var parts = [];
        if (area.blocks.length) {
            parts.push(S.qareablocks.replace('{n}', area.blocks.length));
        }
        if (area.regions.length) {
            parts.push(S.qarearegions.replace('{n}', area.regions.length));
        }
        return parts.length ? parts.join(' + ') : S.qareanone;
    }

    /**
     * Shows.
     */
    function show() {
        if (!api) {
            return;
        }
        var list = [];
        rows.forEach(function(r, i) {
            var label = r.label.value || String(i + 1);
            list.push({label: label, area: {blocks: r.area.blocks, regions: r.area.regions},
                active: i === active && mode === 'answer'});
            if (r.area.qblocks.length || r.area.qregions.length) {
                list.push({label: 'S' + label, area: {blocks: r.area.qblocks, regions: r.area.qregions},
                    active: i === active && mode === 'question', question: true});
            }
        });
        api.showAnchors(list);
    }

    /**
     * Synchronises.
     *
     * @param {Object} row
     */
    function sync(row) {
        row.anchor.value = JSON.stringify(row.area);
        row.summary.textContent = summary(row.area);
        row.summary.classList.toggle('text-danger', !row.area.blocks.length && !row.area.regions.length);
        var q = row.area.qblocks.length + row.area.qregions.length;
        row.qsummary.textContent = q ? S.qquestionpicked.replace('{n}', q) : S.qquestionnone;
        show();
    }

    /**
     * Sets active.
     *
     * @param {Number} i
     * @param {*} what
     */
    function setActive(i, what) {
        what = what || 'answer';
        if (active === i && mode === what) {
            active = -1;
        } else {
            active = i;
            mode = what;
            if (rows[i]) {
                rows[i].qFresh = true; // The first picked question text replaces, later ones are added.
            }
        }
        rows.forEach(function(r, k) {
            var onA = k === active && mode === 'answer';
            var onQ = k === active && mode === 'question';
            r.node.classList.toggle('mylkpd-keyrow-active', k === active);
            r.pick.textContent = onA ? S.qpicking : S.qpick;
            r.pick.className = 'btn btn-sm ' + (onA ? 'btn-primary' : 'btn-outline-primary');
            r.pickq.textContent = onQ ? S.qpickingquestion : S.qpickquestion;
            r.pickq.className = 'btn btn-sm ' + (onQ ? 'btn-mylkpd-q-active' : 'btn-outline-secondary');
        });
        show();
    }

    /**
     * Puts picked question text into the Question field; a leading "1." becomes the number.
     *
     * @param {Object} row
     * @param {String} text
     */
    function fillQuestion(row, text) {
        text = String(text || '').replace(/\s+\n/g, '\n').trim();
        if (!text) {
            return;
        }
        var m = /^\s*(?:soal|no\.?|nomor)?\s*(\d{1,2}[a-z]?)\s*[.):]\s*/i.exec(text);
        if (m) {
            if (!row.label.value.trim()) {
                row.label.value = m[1];
            }
            text = text.slice(m[0].length);
        }
        row.question.value = (row.qFresh || !row.question.value.trim()) ? text : row.question.value.trim() + '\n' + text;
        row.qFresh = false;
        row.question.classList.add('mylkpd-flash');
        setTimeout(function() {
            row.question.classList.remove('mylkpd-flash');
        }, 700);
    }

    /**
     * Field.
     *
     * @param {Object} row
     * @param {String} label
     * @param {*} control
     */
    function field(row, label, control) {
        var wrap = el('label', {'class': 'mylkpd-keyfield'});
        wrap.appendChild(el('span', {}, label));
        wrap.appendChild(control);
        row.appendChild(wrap);
        return control;
    }

    /**
     * Adds row.
     *
     * @param {Object} data
     */
    function addRow(data) {
        data = data || {};
        var k = counter++;
        var node = el('div', {'class': 'mylkpd-keyrow'});
        var head = el('div', {'class': 'mylkpd-keyrow-head'});
        node.appendChild(head);
        var row = {node: node, area: fullArea(data.area)};

        row.label = field(head, S.qlabel, el('input', {type: 'text', name: 'qlabel[' + k + ']', 'class': 'form-control',
            maxlength: '30', size: '4'}));
        row.label.value = data.label || '';
        row.max = field(head, S.qmax, el('input', {type: 'number', name: 'qmax[' + k + ']', 'class': 'form-control',
            min: '0.5', step: '0.5', max: '1000'}));
        row.max.value = data.max || 10;
        var remove = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger mylkpd-keyrow-remove',
            title: S.qremove}, '\u2715');
        head.appendChild(remove);

        row.question = field(node, S.qquestion, el('textarea', {name: 'qquestion[' + k + ']', 'class': 'form-control', rows: '2'}));
        row.question.value = data.question || '';
        row.key = field(node, S.qkey, el('textarea', {name: 'qkey[' + k + ']', 'class': 'form-control', rows: '3'}));
        row.key.value = data.key || '';
        row.points = field(node, S.qpoints, el('textarea', {name: 'qpoints[' + k + ']', 'class': 'form-control', rows: '3'}));
        row.points.value = data.points || '';

        // Where the question is on the worksheet: clicking it copies its text into the Question field.
        var qLine = el('div', {'class': 'mylkpd-keyrow-area mylkpd-keyrow-qarea'});
        qLine.appendChild(el('strong', {}, S.qquestionarea + ': '));
        row.qsummary = el('span', {'class': 'mylkpd-keyrow-summary'});
        qLine.appendChild(row.qsummary);
        row.pickq = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary'}, S.qpickquestion);
        qLine.appendChild(row.pickq);
        node.insertBefore(qLine, row.question.parentNode.nextSibling);

        var areaLine = el('div', {'class': 'mylkpd-keyrow-area'});
        areaLine.appendChild(el('strong', {}, S.qarea + ': '));
        row.summary = el('span', {'class': 'mylkpd-keyrow-summary'});
        areaLine.appendChild(row.summary);
        row.pick = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary'}, S.qpick);
        var clear = el('button', {type: 'button', 'class': 'btn btn-sm btn-link'}, S.qclear);
        areaLine.appendChild(row.pick);
        areaLine.appendChild(clear);
        node.appendChild(areaLine);

        node.appendChild(el('input', {type: 'hidden', name: 'qid[' + k + ']', value: String(data.id || 0)}));
        row.anchor = el('input', {type: 'hidden', name: 'qanchor[' + k + ']'});
        node.appendChild(row.anchor);

        row.pick.addEventListener('click', function() {
            setActive(rows.indexOf(row), 'answer');
        });
        row.pickq.addEventListener('click', function() {
            setActive(rows.indexOf(row), 'question');
        });
        clear.addEventListener('click', function() {
            var q = {qblocks: row.area.qblocks, qregions: row.area.qregions};
            row.area = emptyArea();
            row.area.qblocks = q.qblocks;
            row.area.qregions = q.qregions;
            sync(row);
        });
        remove.addEventListener('click', function() {
            var i = rows.indexOf(row);
            if (active === i) {
                active = -1;
            } else if (active > i) {
                active--;
            }
            rows.splice(i, 1);
            node.parentNode.removeChild(node);
            show();
        });
        row.label.addEventListener('input', show);

        list.appendChild(node);
        rows.push(row);
        sync(row);
        return row;
    }

    // Answer area picked on the worksheet preview.
    document.addEventListener('mylkpd:anchorblock', function(e) {
        var row = rows[active];
        if (!row) {
            return;
        }
        var id = String(e.detail.id);
        if (mode === 'question') {
            if (row.area.qblocks.indexOf(id) < 0) {
                row.area.qblocks.push(id);
            }
            fillQuestion(row, e.detail.text);
            sync(row);
            return;
        }
        var i = row.area.blocks.indexOf(id);
        if (i >= 0) {
            row.area.blocks.splice(i, 1);
            delete row.area.original[id];
        } else {
            row.area.blocks.push(id);
            row.area.original[id] = String(e.detail.text || '').slice(0, 2000);
        }
        sync(row);
    });
    document.addEventListener('mylkpd:anchorrect', function(e) {
        var row = rows[active];
        if (!row || e.detail.w < 1 || e.detail.h < 1) {
            return;
        }
        if (mode === 'question') {
            row.area.qregions = [e.detail];
            api.textInRegion(e.detail).then(function(text) {
                fillQuestion(row, text);
                sync(row);
                return null;
            }).catch(Notification.exception);
            return;
        }
        row.area.regions.push(e.detail);
        row.area.regions = row.area.regions.slice(-8);
        sync(row);
    });
    document.addEventListener('mylkpd:ready', function(e) {
        api = e.detail;
        show();
    });

    document.querySelector('[data-mylkpd-keys="add"]').addEventListener('click', function() {
        addRow({label: String(rows.length + 1)});
        setActive(rows.length - 1);
        rows[rows.length - 1].question.focus();
    });
    document.querySelector('[data-mylkpd-keys="detect"]').addEventListener('click', function() {
        if (!api) {
            return;
        }
        var found = api.detectQuestions();
        if (!found.length) {
            tell(S.qdetectnone);
            return;
        }
        var hasContent = rows.some(function(r) {
            return r.question.value.trim() || r.key.value.trim();
        });
        var go = hasContent ? ask(S.qconfirmreplace.replace('{n}', found.length)) : Promise.resolve(true);
        go.then(function(ok) {
            if (ok) {
                useDetected(found);
            }
            return null;
        }).catch(Notification.exception);
    });

    /**
     * Replaces the empty rows by the questions found on the worksheet.
     *
     * @param {Array} found detected questions
     */
    function useDetected(found) {
        // Drop empty rows, then add the detected questions.
        rows.slice().forEach(function(r) {
            if (!r.question.value.trim() && !r.key.value.trim()) {
                r.node.querySelector('.mylkpd-keyrow-remove').click();
            }
        });
        found.forEach(function(q) {
            var area = {blocks: q.blocks, original: q.original, regions: q.region ? [q.region] : []};
            addRow({label: q.label, question: q.question, area: area});
        });
        tell(S.qdetected.replace('{n}', found.length));
    }

    (cfg.rows || []).forEach(addRow);
    if (!rows.length) {
        addRow({label: '1'});
    }
}

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
 * Shows a message in a Moodle dialog.
 *
 * @param {String} message
 * @param {String} titlekey core string for the title
 * @return {Promise}
 */
function tell(message, titlekey) {
    return Notification.alert(getString(titlekey || 'notice', 'core'), message);
}

/**
 * Starts the answer key editor (settings in data-mylkpd-config of the element).
 *
 * @param {String} configId
 */
export const init = (configId) => {
    const holder = document.getElementById(configId);
    if (holder) {
        start(JSON.parse(holder.getAttribute('data-mylkpd-config')));
    }
};
