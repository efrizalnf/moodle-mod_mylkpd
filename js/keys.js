/**
 * MyLKPD answer key editor: essay questions with model answers, each linked to its answer area.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
var MyLKPDKeys = (function() {
    'use strict';

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

    function init(cfg) {
        var S = cfg.strings;
        var list = document.getElementById('mylkpd-keys-rows');
        if (!list) {
            return;
        }
        var rows = [];
        var active = -1;
        var api = window.MyLKPD && window.MyLKPD.current ? window.MyLKPD.current : null;
        var counter = 0;

        function emptyArea() {
            return {blocks: [], original: {}, regions: []};
        }

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

        function show() {
            if (api) {
                api.showAnchors(rows.map(function(r, i) {
                    return {label: r.label.value || String(i + 1), area: r.area, active: i === active};
                }));
            }
        }

        function sync(row) {
            row.anchor.value = JSON.stringify(row.area);
            row.summary.textContent = summary(row.area);
            row.summary.classList.toggle('text-danger', !row.area.blocks.length && !row.area.regions.length);
            show();
        }

        function setActive(i) {
            active = (active === i) ? -1 : i;
            rows.forEach(function(r, k) {
                var on = k === active;
                r.node.classList.toggle('mylkpd-keyrow-active', on);
                r.pick.textContent = on ? S.qpicking : S.qpick;
                r.pick.className = 'btn btn-sm ' + (on ? 'btn-primary' : 'btn-outline-primary');
            });
            show();
        }

        function field(row, label, control) {
            var wrap = el('label', {'class': 'mylkpd-keyfield'});
            wrap.appendChild(el('span', {}, label));
            wrap.appendChild(control);
            row.appendChild(wrap);
            return control;
        }

        function addRow(data) {
            data = data || {};
            var k = counter++;
            var node = el('div', {'class': 'mylkpd-keyrow'});
            var head = el('div', {'class': 'mylkpd-keyrow-head'});
            node.appendChild(head);
            var row = {node: node, area: data.area || emptyArea()};

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
                setActive(rows.indexOf(row));
            });
            clear.addEventListener('click', function() {
                row.area = emptyArea();
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
                window.alert(S.qdetectnone);
                return;
            }
            var hasContent = rows.some(function(r) {
                return r.question.value.trim() || r.key.value.trim();
            });
            if (hasContent && !window.confirm(S.qconfirmreplace.replace('{n}', found.length))) {
                return;
            }
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
            window.alert(S.qdetected.replace('{n}', found.length));
        });

        (cfg.rows || []).forEach(addRow);
        if (!rows.length) {
            addRow({label: '1'});
        }
    }

    return {init: init};
})();
