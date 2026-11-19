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
 * MyLKPD worksheet editor.
 *
 * Shows the worksheet (PDF via PDF.js, or DOCX via docx-preview). Two ways of working:
 *  - annotate: put answers on top of each page (text, tick, cross, circle, highlight, line, pen,
 *    move, eraser, undo). Positions are percentages of the page, so they fit any screen size.
 *  - edit: type directly into the paragraphs and table cells of a .docx worksheet.
 * Work is autosaved through the mod_mylkpd_sync_worksheet web service. For group work the page also syncs every few seconds, shows
 * who is online, and locks a paragraph while a group mate is typing in it.
 *
 *
 * @module     mod_mylkpd/worksheet
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import Ajax from 'core/ajax';
import Notification from 'core/notification';

/** @type {Object|null} The worksheet started on this page (for the answer key editor and the AI panel). */
let current = null;


var SVGNS = 'http://www.w3.org/2000/svg';
var MAX_UNDO = 50;

// ---------------------------------------------------------------- Library loading.
// Moodle pages use RequireJS. The UMD builds of PDF.js, JSZip and docx-preview then register
// as AMD modules instead of globals, so they must be loaded through require().

/**
 * Whether amd.
 */
function hasAmd() {
    return typeof window.require === 'function' && typeof window.define === 'function' && window.define.amd;
}

/**
 * Loads script.
 *
 * @param {*} src
 */
function loadScript(src) {
    return new Promise(function(resolve, reject) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = function() {
            reject(new Error('Cannot load ' + src));
        };
        document.head.appendChild(s);
    });
}

/**
 * Amd.
 *
 * @param {*} paths
 * @param {String} name
 */
function amd(paths, name) {
    return new Promise(function(resolve, reject) {
        window.require.config({paths: paths});
        window.require([name], resolve, reject);
    });
}

/**
 * Loads pdfjs.
 *
 * @param {*} base
 */
function loadPdfjs(base) {
    if (window.pdfjsLib) {
        return Promise.resolve(window.pdfjsLib);
    }
    if (hasAmd()) {
        return amd({'pdfjs-dist/build/pdf': base + '/pdf.min'}, 'pdfjs-dist/build/pdf');
    }
    return loadScript(base + '/pdf.min.js').then(function() {
        return window.pdfjsLib;
    });
}

/**
 * Loads docx.
 *
 * @param {*} base
 */
function loadDocx(base) {
    if (window.docx && window.docx.renderAsync) {
        return Promise.resolve(window.docx);
    }
    if (hasAmd()) {
        return amd({'jszip': base + '/jszip.min', 'mylkpd-docx-preview': base + '/docx-preview.min'},
            'mylkpd-docx-preview');
    }
    return loadScript(base + '/jszip.min.js').then(function() {
        return loadScript(base + '/docx-preview.min.js');
    }).then(function() {
        return window.docx;
    });
}

var katexPromise = null;

/**
 * Loads katex.
 *
 * @param {*} base
 */
function loadKatex(base) {
    if (katexPromise) {
        return katexPromise;
    }
    if (!document.querySelector('link[data-mylkpd-katex]')) {
        var css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = base + '/katex.min.css';
        css.setAttribute('data-mylkpd-katex', '1');
        document.head.appendChild(css);
    }
    if (window.katex) {
        katexPromise = Promise.resolve(window.katex);
    } else if (hasAmd()) {
        katexPromise = amd({'mylkpd-katex': base + '/katex.min'}, 'mylkpd-katex');
    } else {
        katexPromise = loadScript(base + '/katex.min.js').then(function() {
            return window.katex;
        });
    }
    return katexPromise;
}

// ---------------------------------------------------------------- Helpers.

/**
 * Clamp.
 *
 * @param {*} v
 */
function clamp(v) {
    return Math.max(0, Math.min(100, Math.round(v * 100) / 100));
}

/**
 * Svg el.
 *
 * @param {String} name
 * @param {*} attrs
 */
function svgEl(name, attrs) {
    var el = document.createElementNS(SVGNS, name);
    Object.keys(attrs).forEach(function(k) {
        el.setAttribute(k, attrs[k]);
    });
    return el;
}

/**
 * Finds the closest item.
 *
 * @param {HTMLElement} node
 */
function closestItem(node) {
    while (node && node.nodeType === 1) {
        if (node.classList && node.classList.contains('mylkpd-item')) {
            return node;
        }
        node = node.parentNode;
    }
    return null;
}

/**
 * Grow.
 *
 * @param {HTMLElement} ta
 */
function grow(ta) {
    var longest = ta.value.split('\n').reduce(function(m, l) {
        return Math.max(m, l.length);
    }, 0);
    ta.style.width = Math.max(4, longest + 2) + 'ch';
    ta.style.height = 'auto';
    ta.style.height = ta.scrollHeight + 'px';
}

// ---------------------------------------------------------------- Editor.

/**
 * Copies.
 *
 * @param {*} o
 */
function copy(o) {
    return JSON.parse(JSON.stringify(o));
}

/**
 * Item without bookkeeping fields, as a stable string (sorted keys) for comparisons.
 *
 * @param {Object} it
 * @return {String}
 */
function canon(it) {
    var o = {};
    Object.keys(it).forEach(function(k) {
        if (k !== 'v' && k !== 'by' && k !== 'id' && k !== 'teacher') {
            o[k] = it[k];
        }
    });
    return JSON.stringify(o, Object.keys(o).sort());
}

/**
 * Strip.
 *
 * @param {Object} it
 */
function strip(it) {
    var o = {};
    Object.keys(it).forEach(function(k) {
        if (k !== 'v' && k !== 'by' && k !== 'id' && k !== 'teacher') {
            o[k] = it[k];
        }
    });
    return o;
}

/**
 * Starts.
 *
 * @param {Object} cfg
 */
function start(cfg) {
    cfg = withDefaults(cfg);
    var container = document.getElementById(cfg.containerId);
    if (!container) {
        return;
    }
    var readonly = !!cfg.readonly;
    var canType = !!cfg.canType;
    var katex = null;
    var prevTool = null;
    var lastTarget = null;
    var savedRange = null;
    var hintEl = document.querySelector('[data-mylkpd-hint]');
    var hintText = hintEl ? hintEl.textContent : '';
    var members = cfg.members;
    var items = Array.isArray(cfg.items) ? cfg.items.filter(function(a) {
        return a && typeof a === 'object' && a.page && a.id;
    }) : [];
    var pages = {};
    var pdfPageObjs = {};
    var elems = new Map();
    var labels = new Map();
    var owners = new WeakMap();
    var showNames = !!cfg.showNames;
    var undoStack = [];
    var tool = cfg.defaultTool;
    var isTeacher = !!cfg.isTeacher;
    var teacherIds = cfg.teacherIds;
    var color = cfg.colors[isTeacher ? 1 : 0] || cfg.colors[0];
    var width = 4;

    // Sync state.
    var rev = cfg.rev;
    var shadowItems = {};
    var shadowBlocks = {};
    var blockEls = {};
    var touched = {};
    var outbox = [];
    var inflight = null;
    var again = false;
    var saveTimer = null;
    var currentBlock = '';
    var failed = false;
    var lastError = '';
    var submissionId = cfg.submissionId;
    var imageBase = cfg.imageBase.replace(/\/0\/x$/, '');
    var stateEl = document.getElementById('mylkpd-savestate');
    var presenceEl = document.getElementById('mylkpd-presence');

    items.forEach(function(it) {
        shadowItems[it.id] = canon(it);
    });

    /**
     * Whether the current user may change this item (students cannot touch the teacher's annotations).
     *
     * @param {Object} item
     * @return {Boolean}
     */
    function canEdit(item) {
        return !readonly && (isTeacher || !item.teacher);
    }

    /**
     * Whether teacher author.
     *
     * @param {*} uid
     */
    function isTeacherAuthor(uid) {
        return teacherIds.indexOf(uid) >= 0;
    }

    /**
     * Name and colour for an author label. Teachers who annotated after the page was opened are not in
     * the member list yet, so they are shown as "Teacher" in red.
     *
     * @param {Number} uid
     * @param {Boolean} byTeacher
     * @return {Object|null} {name, color}
     */
    function authorInfo(uid, byTeacher) {
        if (byTeacher && uid && !isTeacherAuthor(uid)) {
            teacherIds.push(uid);
        }
        if (members[uid]) {
            return members[uid];
        }
        return byTeacher ? {name: cfg.strings.teacher, color: '#c62828'} : null;
    }

    /**
     * Makes a new id.
     */
    function newId() {
        return cfg.userid + '-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    }

    /**
     * Member name.
     *
     * @param {*} uid
     */
    function memberName(uid) {
        if (uid === cfg.userid) {
            return cfg.strings.you;
        }
        return members[uid] ? members[uid].name : '';
    }

    /**
     * Sets state.
     *
     * @param {*} key
     */
    function setState(key) {
        if (!stateEl) {
            return;
        }
        var text = key ? cfg.strings[key] : '';
        if (key === 'saved') {
            var now = new Date();
            var hm = ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
            text = cfg.strings.savedat.replace('{time}', hm);
        }
        if (key === 'saveerror' && lastError) {
            text += ' (' + lastError + ')';
        }
        stateEl.textContent = text;
        stateEl.className = 'mylkpd-savestate small mylkpd-state-' + (key || 'none');
    }

    // ------------------------------------------------ Equations.

    /**
     * Renders tex.
     *
     * @param {HTMLElement} el
     * @param {*} tex
     */
    function renderTex(el, tex) {
        if (katex) {
            try {
                katex.render(tex, el, {throwOnError: false, trust: false, strict: 'ignore'});
                return;
            } catch (err) {
                // Fall through to plain text.
            }
        }
        el.textContent = tex;
    }

    /**
     * Math chip.
     *
     * @param {*} tex
     */
    function mathChip(tex) {
        var chip = document.createElement('span');
        chip.className = 'mylkpd-math';
        chip.contentEditable = 'false';
        chip.setAttribute('data-tex', tex);
        renderTex(chip, tex);
        return chip;
    }

    /**
     * Paragraph HTML as stored: equation chips become plain \(TeX\) text, which HTMLPurifier keeps.
     *
     * @param {HTMLElement} p
     * @return {String}
     */
    function serializeBlock(p) {
        var clone = p.cloneNode(true);
        Array.prototype.forEach.call(clone.querySelectorAll('.mylkpd-math'), function(c) {
            c.parentNode.replaceChild(document.createTextNode('\\(' + c.getAttribute('data-tex') + '\\)'), c);
        });
        return clone.innerHTML;
    }

    var URL_RE = /\b(?:https?:\/\/|www\.)[^\s<>"']*[^\s<>"'.,;:!?)\]]/gi;

    /**
     * Href for.
     *
     * @param {String} url
     */
    function hrefFor(url) {
        return /^www\./i.test(url) ? 'https://' + url : url;
    }

    /**
     * Makes web addresses that were typed as plain text into links (not inside existing links/equations).
     *
     * @param {HTMLElement} p
     * @return {Boolean} whether anything changed
     */
    function linkify(p) {
        var walker = document.createTreeWalker(p, NodeFilter.SHOW_TEXT, null);
        var nodes = [];
        while (walker.nextNode()) {
            var n = walker.currentNode;
            URL_RE.lastIndex = 0;
            if (URL_RE.test(n.nodeValue) && !(n.parentNode.closest && n.parentNode.closest('a, .mylkpd-math'))) {
                nodes.push(n);
            }
        }
        nodes.forEach(function(node) {
            var text = node.nodeValue;
            var frag = document.createDocumentFragment();
            var last = 0;
            var m;
            URL_RE.lastIndex = 0;
            while ((m = URL_RE.exec(text)) !== null) {
                frag.appendChild(document.createTextNode(text.slice(last, m.index)));
                var a = document.createElement('a');
                a.href = hrefFor(m[0]);
                a.textContent = m[0];
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                frag.appendChild(a);
                last = URL_RE.lastIndex;
            }
            frag.appendChild(document.createTextNode(text.slice(last)));
            node.parentNode.replaceChild(frag, node);
        });
        return nodes.length > 0;
    }

    var ALIGNS = ['left', 'center', 'right', 'justify'];

    /**
     * What is stored for a paragraph: its alignment and its content, as one comparable string.
     *
     * @param {HTMLElement} p
     * @return {String}
     */
    function blockState(p) {
        return (p.getAttribute('data-mylkpd-textalign') || '') + '\u0001' + serializeBlock(p);
    }

    /**
     * Sets block align.
     *
     * @param {HTMLElement} p
     * @param {String} align
     */
    function setBlockAlign(p, align) {
        if (ALIGNS.indexOf(align) >= 0) {
            p.setAttribute('data-mylkpd-textalign', align);
            p.style.textAlign = align;
        }
    }

    /**
     * Turns \(TeX\) text inside a paragraph into rendered equation chips; makes links open in a new tab.
     *
     * @param {HTMLElement} p
     */
    function hydrateBlock(p) {
        var walker = document.createTreeWalker(p, NodeFilter.SHOW_TEXT, null);
        var nodes = [];
        while (walker.nextNode()) {
            if (walker.currentNode.nodeValue.indexOf('\\(') >= 0) {
                nodes.push(walker.currentNode);
            }
        }
        nodes.forEach(function(node) {
            var text = node.nodeValue;
            var re = /\\\((.+?)\\\)/g;
            var frag = document.createDocumentFragment();
            var last = 0;
            var m;
            while ((m = re.exec(text)) !== null) {
                frag.appendChild(document.createTextNode(text.slice(last, m.index)));
                frag.appendChild(mathChip(m[1]));
                last = re.lastIndex;
            }
            if (last > 0) {
                frag.appendChild(document.createTextNode(text.slice(last)));
                node.parentNode.replaceChild(frag, node);
            }
        });
        linkify(p);
        Array.prototype.forEach.call(p.querySelectorAll('a[href]'), function(a) {
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
        });
    }

    // ---- Small "Open link" bubble for links inside paragraphs that are being edited.

    var linkPop = null;

    /**
     * Hides link pop.
     */
    function hideLinkPop() {
        if (linkPop) {
            linkPop.style.display = 'none';
        }
    }

    /**
     * Shows link pop.
     *
     * @param {*} a
     */
    function showLinkPop(a) {
        if (!linkPop) {
            linkPop = document.createElement('div');
            linkPop.className = 'mylkpd-linkpop';
            var open = document.createElement('a');
            open.target = '_blank';
            open.rel = 'noopener noreferrer';
            open.className = 'mylkpd-linkpop-open';
            linkPop.appendChild(open);
            document.body.appendChild(linkPop);
            document.addEventListener('mousedown', function(e) {
                if (linkPop && !linkPop.contains(e.target) && !(e.target.closest && e.target.closest('a[href]'))) {
                    hideLinkPop();
                }
            });
            window.addEventListener('scroll', hideLinkPop, true);
        }
        var link = linkPop.firstChild;
        link.href = a.href;
        link.textContent = '\u2197 ' + cfg.strings.openlink + ': ' +
            (a.href.length > 60 ? a.href.slice(0, 57) + '\u2026' : a.href);
        var r = a.getBoundingClientRect();
        linkPop.style.display = 'block';
        linkPop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - linkPop.offsetWidth - 8)) + 'px';
        linkPop.style.top = Math.max(8, r.top - linkPop.offsetHeight - 6) + 'px';
    }

    // ------------------------------------------------ Saving and syncing.

    /**
     * Schedules save.
     *
     * @param {Number} delay
     */
    function scheduleSave(delay) {
        if (readonly) {
            return;
        }
        clearTimeout(saveTimer);
        saveTimer = setTimeout(flush, delay === undefined ? 700 : delay);
    }

    /**
     * Notes a change.
     */
    function changed() {
        scheduleSave();
    }

    /**
     * Collects ops.
     */
    function collectOps() {
        var ops = [];
        var seen = {};
        items.forEach(function(it) {
            seen[it.id] = true;
            var c = canon(it);
            if (shadowItems[it.id] !== c) {
                ops.push({op: 'put', kind: 'item', id: it.id, data: strip(it)});
                shadowItems[it.id] = c;
            }
        });
        Object.keys(shadowItems).forEach(function(id) {
            if (!seen[id]) {
                ops.push({op: 'del', kind: 'item', id: id});
                delete shadowItems[id];
            }
        });
        Object.keys(touched).forEach(function(id) {
            var el = blockEls[id];
            var state = el ? blockState(el) : null;
            if (el && state !== shadowBlocks[id]) {
                var op = {op: 'put', kind: 'block', id: id, html: serializeBlock(el)};
                if (el.getAttribute('data-mylkpd-textalign')) {
                    op.align = el.getAttribute('data-mylkpd-textalign');
                }
                ops.push(op);
                shadowBlocks[id] = state;
            }
            delete touched[id];
        });
        // Older unsent operations first; a later operation on the same thing replaces an earlier one.
        var all = outbox.concat(ops);
        var map = {};
        var order = [];
        all.forEach(function(o) {
            var k = o.kind + ':' + o.id;
            if (!(k in map)) {
                order.push(k);
            }
            map[k] = o;
        });
        outbox = [];
        return order.map(function(k) {
            return map[k];
        });
    }

    /**
     * Whether pending.
     */
    function hasPending() {
        if (outbox.length || Object.keys(touched).length) {
            return true;
        }
        var seen = {};
        var dirty = items.some(function(it) {
            seen[it.id] = true;
            return shadowItems[it.id] !== canon(it);
        });
        return dirty || Object.keys(shadowItems).some(function(id) {
            return !seen[id];
        });
    }

    /**
     * Whether outbox.
     *
     * @param {String} kind
     * @param {String} id
     */
    function inOutbox(kind, id) {
        return outbox.some(function(o) {
            return o.kind === kind && o.id === id;
        });
    }

    /**
     * Builds the request args.
     *
     * @param {Array} ops
     */
    function requestArgs(ops) {
        var args = {cmid: cfg.cmid, rev: rev, ops: JSON.stringify(ops), block: currentBlock};
        if (cfg.target) {
            args.targetuser = cfg.target.targetuser || 0;
            args.targetgroup = cfg.target.targetgroup || 0;
        }
        return args;
    }

    /**
     * Sends.
     */
    function flush() {
        if (readonly || !cfg.cmid) {
            return Promise.resolve();
        }
        clearTimeout(saveTimer);
        if (inflight) {
            again = true;
            return inflight;
        }
        var ops = collectOps();
        if (ops.length) {
            setState('saving');
        }
        inflight = Promise.resolve(Ajax.call([{methodname: 'mod_mylkpd_sync_worksheet', args: requestArgs(ops)}])[0])
        .then(function(r) {
            return JSON.parse(r.data);
        }).then(function(res) {
            if (!res || typeof res.rev !== 'number') {
                var failure = (res && (res.error || res.message)) || 'Sync failed';
                throw new Error(failure);
            }
            failed = false;
            lastError = '';
            applyRemote(res);
            if (ops.length || (stateEl && stateEl.classList.contains('mylkpd-state-saveerror'))) {
                setState('saved');
            }
            if ((res.status === 1 || res.closed) && !isTeacher) {
                // Someone (a group mate) submitted: reload to show the locked worksheet.
                window.removeEventListener('beforeunload', warnUnsaved);
                window.location.reload();
            }
            return null;
        }).catch(function(err) {
            outbox = ops.concat(outbox);
            failed = true;
            lastError = (err && err.message) ? String(err.message).slice(0, 120) : '';
            setState('saveerror');
            window.console.error('MyLKPD sync:', err);
            return null;
        }).then(function() {
            inflight = null;
            if (again || failed) {
                again = false;
                scheduleSave(failed ? 5000 : 200);
            }
            return null;
        });
        return inflight;
    }

    /**
     * Applies remote.
     *
     * @param {Object} res
     */
    function applyRemote(res) {
        rev = Math.max(rev, res.rev);
        var touchedPages = false;
        Object.keys(res.items || {}).forEach(function(id) {
            var r = res.items[id];
            var idx = indexOfId(id);
            var local = idx >= 0 ? items[idx] : null;
            if ((local && canon(local) !== shadowItems[id]) || inOutbox('item', id)) {
                return; // We have a newer unsent change; ours will win when it is sent.
            }
            if (r.deleted) {
                if (local) {
                    removeItem(local, true);
                }
                delete shadowItems[id];
                return;
            }
            var item = copy(r);
            item.id = id;
            shadowItems[id] = canon(item);
            if (local && canon(local) === shadowItems[id]) {
                local.by = item.by;
                updateLabel(local);
                return;
            }
            if (local) {
                removeItem(local, true);
                items.splice(idx, 0, item);
            } else {
                items.push(item);
            }
            createEl(item);
            touchedPages = true;
        });
        Object.keys(res.blocks || {}).forEach(function(id) {
            var b = res.blocks[id];
            var el = blockEls[id];
            if (!el || el === document.activeElement || touched[id] || inOutbox('block', id) ||
                    blockState(el) !== shadowBlocks[id]) {
                return;
            }
            if (b.html !== undefined && b.html !== serializeBlock(el)) {
                el.innerHTML = b.html;
                hydrateBlock(el);
            }
            setBlockAlign(el, b.align);
            shadowBlocks[id] = blockState(el);
            markEdited(el, b.by, b.teacher);
        });
        if (res.presence) {
            applyPresence(res.presence);
        }
        return touchedPages;
    }

    /**
     * Applies presence.
     *
     * @param {Array} list
     */
    function applyPresence(list) {
        var locks = {};
        var online = [];
        list.forEach(function(p) {
            if (p.ago < 40) {
                online.push(p.userid);
            }
            if (p.userid !== cfg.userid && p.block !== '' && p.ago < 25) {
                locks[p.block] = p.userid;
            }
        });
        Object.keys(blockEls).forEach(function(id) {
            var el = blockEls[id];
            var uid = locks[id];
            if (uid && el !== document.activeElement) {
                el.contentEditable = 'false';
                el.classList.add('mylkpd-locked');
                el.setAttribute('data-mylkpd-editor', cfg.strings.editing.replace('{name}', memberName(uid)));
                el.style.setProperty('--mylkpd-peer', members[uid] ? members[uid].color : '#666');
            } else if (el.classList.contains('mylkpd-locked')) {
                el.classList.remove('mylkpd-locked');
                el.removeAttribute('data-mylkpd-editor');
                el.contentEditable = readonly ? 'false' : 'true';
            }
        });
        if (presenceEl) {
            presenceEl.textContent = '';
            var label = document.createElement('span');
            label.className = 'mylkpd-presence-label';
            label.textContent = cfg.strings.online + ':';
            presenceEl.appendChild(label);
            Object.keys(members).forEach(function(uidKey) {
                var uid = parseInt(uidKey, 10);
                var chip = document.createElement('span');
                chip.className = 'mylkpd-chip' + (online.indexOf(uid) >= 0 ? ' mylkpd-online' : '');
                var dot = document.createElement('span');
                dot.className = 'mylkpd-dot';
                dot.style.background = members[uidKey].color;
                chip.appendChild(dot);
                chip.appendChild(document.createTextNode(members[uidKey].name +
                    (uid === cfg.userid ? ' (' + cfg.strings.you + ')' : '')));
                presenceEl.appendChild(chip);
            });
        }
    }

    // ------------------------------------------------ Undo (per change, so it never undoes a group mate).

    /**
     * Updates undo.
     */
    function updateUndo() {
        var b = document.querySelector('[data-mylkpd-action="undo"]');
        if (b) {
            b.disabled = tool !== 'type' && undoStack.length === 0;
        }
        var r = document.querySelector('[data-mylkpd-action="redo"]');
        if (r) {
            r.disabled = tool !== 'type';
        }
    }

    /**
     * Records undo.
     *
     * @param {*} group
     */
    function recordUndo(group) {
        undoStack.push(group);
        if (undoStack.length > MAX_UNDO) {
            undoStack.shift();
        }
        updateUndo();
    }

    /**
     * Index of id.
     *
     * @param {String} id
     */
    function indexOfId(id) {
        for (var i = 0; i < items.length; i++) {
            if (items[i].id === id) {
                return i;
            }
        }
        return -1;
    }

    /**
     * Undoes.
     */
    function undo() {
        var group = undoStack.pop();
        if (!group) {
            return;
        }
        for (var i = group.length - 1; i >= 0; i--) {
            var e = group[i];
            var idx = indexOfId(e.id);
            if (e.before === null) {
                if (idx >= 0) {
                    items.splice(idx, 1);
                }
            } else if (idx >= 0) {
                items[idx] = copy(e.before);
            } else {
                items.push(copy(e.before));
            }
        }
        rebuild();
        changed();
        updateUndo();
    }

    // ------------------------------------------------ Author name labels (group work).

    /**
     * Builds the label anchor.
     *
     * @param {Object} item
     */
    function labelAnchor(item) {
        if (item.type === 'line') {
            return {x: Math.min(item.x1, item.x2), y: Math.min(item.y1, item.y2)};
        }
        if (item.type === 'pen') {
            var minx = 100;
            var miny = 100;
            item.points.forEach(function(q) {
                minx = Math.min(minx, q[0]);
                miny = Math.min(miny, q[1]);
            });
            return {x: minx, y: miny};
        }
        return {x: item.x, y: item.y};
    }

    /**
     * Updates label.
     *
     * @param {Object} item
     */
    function updateLabel(item) {
        var label = labels.get(item);
        var info = authorInfo(item.by, !!item.teacher);
        var name = (info && (showNames || item.teacher || isTeacherAuthor(item.by))) ? info.name : '';
        if (!name) {
            if (label && label.parentNode) {
                label.parentNode.removeChild(label);
            }
            labels.delete(item);
            return;
        }
        var page = pages[item.page];
        if (!page) {
            return;
        }
        if (!label) {
            label = document.createElement('div');
            label.setAttribute('aria-hidden', 'true');
            labels.set(item, label);
        }
        label.className = 'mylkpd-namelabel mylkpd-namelabel-' + item.type;
        label.textContent = name;
        label.style.background = info.color;
        var a = labelAnchor(item);
        label.style.left = a.x + '%';
        label.style.top = a.y + '%';
        if (label.parentNode !== page.html) {
            page.html.appendChild(label);
        }
    }

    /**
     * Removes label.
     *
     * @param {Object} item
     */
    function removeLabel(item) {
        var label = labels.get(item);
        if (label && label.parentNode) {
            label.parentNode.removeChild(label);
        }
        labels.delete(item);
    }

    /**
     * Claims.
     *
     * @param {Object} item
     */
    function claim(item) {
        // The current user is now the last one who changed this item.
        if (item.by !== cfg.userid) {
            item.by = cfg.userid;
            updateLabel(item);
        }
    }

    // ------------------------------------------------ Drawing items.

    /**
     * Stroke style.
     *
     * @param {Number} w
     */
    function strokeStyle(w) {
        return 'stroke-width: calc(var(--mylkpd-k, 1) * ' + (w || 4) + 'px)';
    }

    /**
     * Makes shape.
     *
     * @param {Object} item
     */
    function makeShape(item) {
        var s;
        if (item.type === 'ellipse') {
            s = svgEl('ellipse', {cx: item.x + item.w / 2, cy: item.y + item.h / 2,
                rx: item.w / 2, ry: item.h / 2, fill: 'none'});
        } else if (item.type === 'highlight') {
            return svgEl('rect', {x: item.x, y: item.y, width: item.w, height: item.h,
                fill: item.color, 'fill-opacity': '0.35', stroke: 'none'});
        } else if (item.type === 'line') {
            s = svgEl('line', {x1: item.x1, y1: item.y1, x2: item.x2, y2: item.y2, 'stroke-linecap': 'round'});
        } else {
            s = svgEl('polyline', {points: item.points.map(function(p) {
                return p[0] + ',' + p[1];
            }).join(' '), fill: 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round'});
        }
        s.setAttribute('stroke', item.color);
        s.setAttribute('vector-effect', 'non-scaling-stroke');
        s.setAttribute('style', strokeStyle(item.width));
        return s;
    }

    /**
     * Draws.
     *
     * @param {Object} item
     */
    function draw(item) {
        var el = elems.get(item);
        if (!el) {
            return;
        }
        if (labels.has(item)) {
            updateLabel(item);
        }
        if (item.type === 'text' || item.type === 'check' || item.type === 'cross' ||
                item.type === 'math' || item.type === 'link' || item.type === 'image' || item.type === 'note' ||
                item.type === 'table') {
            el.style.left = item.x + '%';
            el.style.top = item.y + '%';
            if (item.type === 'image') {
                el.style.width = item.w + '%';
                el.style.height = item.h + '%';
            }
            if (item.type === 'table') {
                el.style.width = item.w + '%'; // Height follows the rows.
            }
            if (item.type === 'note') {
                // Open the bubble towards the middle of the page so it is never cut off.
                el.classList.toggle('mylkpd-note-left', item.x > 55);
                el.classList.toggle('mylkpd-note-up', item.y > 70);
            }
            return;
        }
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
        var hit = makeShape(item);
        hit.setAttribute('class', 'mylkpd-hit ' + (item.type === 'highlight' ? 'mylkpd-hit-fill' : 'mylkpd-hit-stroke'));
        el.appendChild(hit);
        el.appendChild(makeShape(item));
    }

    /**
     * Creates text.
     *
     * @param {Object} item
     */
    function createText(item) {
        var el = document.createElement('div');
        el.className = 'mylkpd-item mylkpd-text';
        var ta = document.createElement('textarea');
        ta.rows = 1;
        ta.value = item.text || '';
        ta.readOnly = !canEdit(item);
        ta.spellcheck = false;
        ta.style.color = item.color;
        ta.style.borderColor = item.color;
        ta.setAttribute('aria-label', cfg.strings.answer);
        ta.dir = 'auto'; // Arabic answers are written right to left.
        if (ALIGNS.indexOf(item.align) >= 0) {
            ta.style.textAlign = item.align;
        }
        if (item.by && memberName(item.by)) {
            el.title = cfg.strings.editedby.replace('{name}', memberName(item.by));
        }
        if (canEdit(item)) {
            var before = null;
            ta.addEventListener('focus', function() {
                // New boxes are already covered by the undo entry made when they were created.
                before = item.text ? copy(item) : null;
            });
            ta.addEventListener('input', function() {
                if (before) {
                    recordUndo([{id: item.id, before: before}]);
                    before = null;
                }
                item.text = ta.value;
                grow(ta);
                claim(item);
                changed();
            });
            ta.addEventListener('blur', function() {
                if (ta.value.trim() === '' && items.indexOf(item) >= 0) {
                    // An empty box was never really an answer: drop it and its undo entry.
                    var top = undoStack[undoStack.length - 1];
                    if (top && top.length === 1 && top[0].id === item.id && top[0].before === null) {
                        undoStack.pop();
                        updateUndo();
                    }
                    removeItem(item);
                }
            });
        }
        el.appendChild(ta);
        var linkRow = document.createElement('div');
        linkRow.className = 'mylkpd-textlinks';
        el.appendChild(linkRow);
        var showLinks = function() {
            linkRow.textContent = '';
            (ta.value.match(URL_RE) || []).slice(0, 3).forEach(function(url) {
                var a = document.createElement('a');
                a.href = hrefFor(url);
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = '\u2197 ' + (url.length > 40 ? url.slice(0, 37) + '\u2026' : url);
                a.title = url;
                linkRow.appendChild(a);
            });
        };
        showLinks();
        ta.addEventListener('input', showLinks);
        return el;
    }

    /**
     * Teacher's sticky note: a pin on the page that opens a bubble with the note text.
     *
     * @param {Object} item
     * @return {HTMLElement}
     */
    function createNote(item) {
        var el = document.createElement('div');
        el.className = 'mylkpd-item mylkpd-note';
        el.style.setProperty('--mylkpd-note', item.color);
        var pin = document.createElement('button');
        pin.type = 'button';
        pin.className = 'mylkpd-note-pin';
        pin.textContent = '\uD83D\uDCDD';
        pin.setAttribute('aria-label', cfg.strings.note);
        pin.setAttribute('aria-expanded', 'false');
        var bubble = document.createElement('div');
        bubble.className = 'mylkpd-note-bubble';
        if (canEdit(item)) {
            var ta = document.createElement('textarea');
            ta.rows = 3;
            ta.value = item.text || '';
            ta.dir = 'auto';
            ta.placeholder = cfg.strings.notehint;
            ta.setAttribute('aria-label', cfg.strings.note);
            var before = null;
            ta.addEventListener('focus', function() {
                before = item.text ? copy(item) : null;
            });
            ta.addEventListener('input', function() {
                if (before) {
                    recordUndo([{id: item.id, before: before}]);
                    before = null;
                }
                item.text = ta.value;
                claim(item);
                changed();
            });
            ta.addEventListener('blur', function() {
                if (ta.value.trim() === '' && items.indexOf(item) >= 0) {
                    var top = undoStack[undoStack.length - 1];
                    if (top && top.length === 1 && top[0].id === item.id && top[0].before === null) {
                        undoStack.pop();
                        updateUndo();
                    }
                    removeItem(item);
                }
            });
            bubble.appendChild(ta);
        } else {
            bubble.textContent = item.text || '';
        }
        pin.addEventListener('click', function() {
            var open = !el.classList.contains('mylkpd-open');
            el.classList.toggle('mylkpd-open', open);
            pin.setAttribute('aria-expanded', open ? 'true' : 'false');
            var field = bubble.querySelector('textarea');
            if (open && field) {
                field.focus();
            }
        });
        el.appendChild(pin);
        el.appendChild(bubble);
        return el;
    }

    /**
     * Creates el.
     *
     * @param {Object} item
     */
    function createEl(item) {
        var page = pages[item.page];
        if (!page) {
            return null;
        }
        var el;
        if (item.type === 'text') {
            el = createText(item);
        } else if (item.type === 'note') {
            el = createNote(item);
        } else if (item.type === 'table') {
            el = createTable(item);
        } else if (item.type === 'image') {
            el = document.createElement('div');
            el.className = 'mylkpd-item mylkpd-imageitem';
            var img = document.createElement('img');
            img.src = imageUrl(item.file);
            img.alt = cfg.strings.image;
            img.draggable = false;
            el.appendChild(img);
            if (!readonly) {
                var handle = document.createElement('span');
                handle.className = 'mylkpd-resize';
                handle.title = cfg.strings.resize;
                handle.setAttribute('aria-hidden', 'true');
                el.appendChild(handle);
            }
        } else if (item.type === 'math') {
            el = document.createElement('div');
            el.className = 'mylkpd-item mylkpd-mathitem';
            el.style.color = item.color;
            renderTex(el, item.tex);
            if (!readonly) {
                el.addEventListener('dblclick', function() {
                    editMathItem(item);
                });
            }
        } else if (item.type === 'link') {
            el = document.createElement('a');
            el.className = 'mylkpd-item mylkpd-linkitem';
            el.href = item.url;
            el.target = '_blank';
            el.rel = 'noopener noreferrer';
            el.textContent = item.text || item.url;
            el.title = item.url;
            el.style.color = item.color;
            el.addEventListener('click', function(e) {
                if (tool === 'move' || tool === 'erase') {
                    e.preventDefault();
                }
            });
        } else if (item.type === 'check' || item.type === 'cross') {
            el = document.createElement('div');
            el.className = 'mylkpd-item mylkpd-mark';
            el.textContent = item.type === 'check' ? '\u2713' : '\u2717';
            el.style.color = item.color;
            el.setAttribute('role', 'img');
            el.setAttribute('aria-label', cfg.strings[item.type]);
        } else {
            el = svgEl('g', {'class': 'mylkpd-item'});
        }
        (el.namespaceURI === SVGNS ? page.svg : page.html).appendChild(el);
        page.layer.parentNode.classList.remove('mylkpd-page-hidden');
        elems.set(item, el);
        owners.set(el, item);
        draw(item);
        updateLabel(item);
        if (item.type === 'text') {
            grow(el.firstChild);
        }
        return el;
    }

    /**
     * Removes item.
     *
     * @param {Object} item
     * @param {Boolean} silent
     */
    function removeItem(item, silent) {
        var i = items.indexOf(item);
        if (i >= 0) {
            items.splice(i, 1);
        }
        var el = elems.get(item);
        elems.delete(item);
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }
        removeLabel(item);
        if (toolsFor === item) {
            hideTableTools();
        }
        if (!silent) {
            changed();
        }
    }

    /**
     * Rebuilds.
     */
    function rebuild() {
        var old = elems;
        elems = new Map();
        old.forEach(function(el) {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        });
        labels.forEach(function(label) {
            if (label.parentNode) {
                label.parentNode.removeChild(label);
            }
        });
        labels = new Map();
        items.forEach(createEl);
    }

    // ------------------------------------------------ Pointer input (annotate mode).

    /**
     * Gets the point from.
     *
     * @param {HTMLElement} layer
     * @param {Event} e
     */
    function pointFrom(layer, e) {
        var r = layer.getBoundingClientRect();
        return {x: clamp((e.clientX - r.left) / r.width * 100), y: clamp((e.clientY - r.top) / r.height * 100)};
    }

    /**
     * Item at.
     *
     * @param {Event} e
     */
    function itemAt(e) {
        var target = closestItem(document.elementFromPoint(e.clientX, e.clientY));
        return target ? owners.get(target) : null;
    }

    /**
     * Erases at.
     *
     * @param {Event} e
     * @param {Object} state
     */
    function eraseAt(e, state) {
        var item = itemAt(e);
        if (!item || items.indexOf(item) < 0 || !canEdit(item)) {
            return;
        }
        if (!state.group) {
            state.group = [];
            recordUndo(state.group);
        }
        state.group.push({id: item.id, before: copy(item)});
        removeItem(item);
    }

    /**
     * Moves item.
     *
     * @param {Object} state
     * @param {HTMLElement} p
     */
    function moveItem(state, p) {
        var o = state.orig;
        var it = state.item;
        var dx = p.x - state.start.x;
        var dy = p.y - state.start.y;
        if (o.type === 'line') {
            it.x1 = clamp(o.x1 + dx);
            it.y1 = clamp(o.y1 + dy);
            it.x2 = clamp(o.x2 + dx);
            it.y2 = clamp(o.y2 + dy);
        } else if (o.type === 'pen') {
            it.points = o.points.map(function(q) {
                return [clamp(q[0] + dx), clamp(q[1] + dy)];
            });
        } else {
            it.x = clamp(o.x + dx);
            it.y = clamp(o.y + dy);
        }
    }

    /**
     * Binds pointer.
     *
     * @param {Number} num
     * @param {HTMLElement} layer
     */
    function bindPointer(num, layer) {
        var state = null;

        /**
         * Starts dragging an item (moving or resizing) from the pointer position.
         *
         * @param {Event} e pointer event
         * @param {Object} item
         * @param {String} mode 'move' or 'resize'
         * @param {Object} p pointer position in page percent
         */
        function grab(e, item, mode, p) {
            state = {mode: mode, item: item, start: p, orig: copy(item)};
            layer.setPointerCapture(e.pointerId);
        }

        /**
         * Pointer down on an item with its own handling: the resize handle, a table, or a picture.
         *
         * @param {Event} e pointer event
         * @param {Object|null} targetItem item under the pointer
         * @param {Object} p pointer position in page percent
         * @return {Boolean} whether it was handled
         */
        function pointerOnItem(e, targetItem, p) {
            if (!targetItem) {
                return false;
            }
            if (e.target.classList && e.target.classList.contains('mylkpd-resize')) {
                // Resize from the bottom-right corner, keeping the picture's proportions.
                e.preventDefault();
                e.stopPropagation();
                selectItem(targetItem);
                grab(e, targetItem, 'resize', p);
                return true;
            }
            if (targetItem.type === 'table' && tool !== 'erase' && tool !== 'move') {
                selectItem(targetItem);
                if (e.target.closest && e.target.closest('.mylkpd-table-grip')) {
                    e.preventDefault();
                    grab(e, targetItem, 'move', p);
                }
                // Otherwise a cell was clicked: let it take the cursor.
                return true;
            }
            if (targetItem.type === 'image' && tool !== 'erase') {
                // Pictures can always be dragged, whatever tool is active.
                e.preventDefault();
                selectItem(targetItem);
                grab(e, targetItem, 'move', p);
                return true;
            }
            return false;
        }

        /**
         * Puts a text box, tick, cross or note where the student clicked.
         *
         * @param {Event} e pointer event
         * @param {Object} p pointer position in page percent
         * @param {HTMLElement|null} targetEl item element under the pointer
         */
        function placeMark(e, p, targetEl) {
            if (targetEl) {
                return; // Let the student click into an existing box.
            }
            e.preventDefault();
            var it = {id: newId(), page: num, type: tool, x: p.x, y: p.y, color: color, by: cfg.userid};
            if (tool === 'text' || tool === 'note') {
                it.text = '';
            }
            recordUndo([{id: it.id, before: null}]);
            items.push(it);
            var el = createEl(it);
            changed();
            if (tool === 'text' && el) {
                el.firstChild.focus();
            } else if (tool === 'note' && el) {
                el.classList.add('mylkpd-open');
                el.querySelector('textarea').focus();
            }
        }

        /**
         * A new shape for the drawing tools (circle, highlight, line, pen) starting at the pointer.
         *
         * @param {Object} p pointer position in page percent
         * @return {Object}
         */
        function newShape(p) {
            var shape;
            if (tool === 'circle' || tool === 'highlight') {
                shape = {page: num, type: tool === 'circle' ? 'ellipse' : 'highlight', x: p.x, y: p.y, w: 0, h: 0, color: color};
            } else if (tool === 'line') {
                shape = {page: num, type: 'line', x1: p.x, y1: p.y, x2: p.x, y2: p.y, color: color};
            } else {
                shape = {page: num, type: 'pen', points: [[p.x, p.y]], color: color};
            }
            shape.id = newId();
            shape.by = cfg.userid;
            if (shape.type !== 'highlight') {
                shape.width = width;
            }
            return shape;
        }

        layer.addEventListener('pointerdown', function(e) {
            if (e.button !== 0) {
                return;
            }
            var p = pointFrom(layer, e);
            var targetEl = closestItem(e.target);
            var targetItem = targetEl ? owners.get(targetEl) : null;
            if (targetItem && !canEdit(targetItem)) {
                // The teacher's annotations can be read (notes opened) but not moved or erased.
                return;
            }
            if (pointerOnItem(e, targetItem, p)) {
                return;
            }
            selectItem(null);

            if (tool === 'math' || tool === 'link') {
                e.preventDefault();
                placeItem(tool, num, p);
                return;
            }
            if (['text', 'check', 'cross', 'note'].indexOf(tool) >= 0) {
                placeMark(e, p, targetEl);
                return;
            }
            e.preventDefault();
            if (document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
            if (tool === 'erase') {
                state = {mode: 'erase', group: null};
                layer.setPointerCapture(e.pointerId);
                eraseAt(e, state);
                return;
            }
            if (tool === 'move') {
                if (targetItem) {
                    grab(e, targetItem, 'move', p);
                }
                return;
            }
            var shape = newShape(p);
            state = {mode: 'draw', item: shape, start: p};
            items.push(shape);
            createEl(shape);
            layer.setPointerCapture(e.pointerId);
        });

        layer.addEventListener('pointermove', function(e) {
            if (!state) {
                return;
            }
            if (state.mode === 'erase') {
                eraseAt(e, state);
                return;
            }
            var p = pointFrom(layer, e);
            if (state.mode === 'move') {
                moveItem(state, p);
                draw(state.item);
                positionTableTools();
                return;
            }
            if (state.mode === 'resize' && state.item.type === 'table') {
                state.item.w = clamp(Math.max(10, Math.min(100 - state.orig.x, state.orig.w + (p.x - state.start.x))));
                draw(state.item);
                positionTableTools();
                return;
            }
            if (state.mode === 'resize') {
                var o = state.orig;
                var w = Math.max(4, Math.min(100 - o.x, o.w + (p.x - state.start.x)));
                var h = o.h * w / o.w;
                if (o.y + h > 100) {
                    h = 100 - o.y;
                    w = o.w * h / o.h;
                }
                state.item.w = clamp(w);
                state.item.h = clamp(h);
                draw(state.item);
                return;
            }
            var it = state.item;
            var s = state.start;
            if (it.type === 'ellipse' || it.type === 'highlight') {
                it.x = Math.min(s.x, p.x);
                it.y = Math.min(s.y, p.y);
                it.w = clamp(Math.abs(p.x - s.x));
                it.h = clamp(Math.abs(p.y - s.y));
            } else if (it.type === 'line') {
                it.x2 = p.x;
                it.y2 = p.y;
            } else {
                var last = it.points[it.points.length - 1];
                if (Math.abs(last[0] - p.x) + Math.abs(last[1] - p.y) > 0.2 && it.points.length < 3000) {
                    it.points.push([p.x, p.y]);
                }
            }
            draw(it);
        });

        /**
         * Finish.
         *
         * @param {Event} e
         */
        function finish(e) {
            if (!state) {
                return;
            }
            var st = state;
            state = null;
            try {
                layer.releasePointerCapture(e.pointerId);
            } catch (err) {
                // Capture may already be gone.
            }
            if (st.mode === 'draw') {
                var it = st.item;
                var tiny = false;
                if (it.type === 'ellipse' || it.type === 'highlight') {
                    tiny = it.w < 0.5 && it.h < 0.5;
                    if (!tiny && it.type === 'highlight' && it.h < 1.5) {
                        // A quick horizontal swipe makes a marker stripe.
                        it.y = clamp(it.y - (1.5 - it.h) / 2);
                        it.h = 1.5;
                    }
                } else if (it.type === 'line') {
                    tiny = Math.abs(it.x2 - it.x1) + Math.abs(it.y2 - it.y1) < 0.5;
                } else if (it.points.length < 2) {
                    it.points.push(it.points[0].slice()); // A single tap leaves a dot.
                }
                if (tiny) {
                    removeItem(it, true);
                    return;
                }
                draw(it);
                recordUndo([{id: it.id, before: null}]);
                changed();
            } else if ((st.mode === 'move' || st.mode === 'resize') && canon(st.item) !== canon(st.orig)) {
                claim(st.item);
                recordUndo([{id: st.item.id, before: st.orig}]);
                changed();
            }
        }
        layer.addEventListener('pointerup', finish);
        layer.addEventListener('pointercancel', finish);
    }

    // ------------------------------------------------ Pages.

    /**
     * Setup page.
     *
     * @param {Number} num
     * @param {HTMLElement} host
     */
    function setupPage(num, host) {
        host.classList.add('mylkpd-host');
        var layer = document.createElement('div');
        layer.className = 'mylkpd-layer';
        var svg = svgEl('svg', {viewBox: '0 0 100 100', preserveAspectRatio: 'none', 'class': 'mylkpd-svg'});
        var html = document.createElement('div');
        html.className = 'mylkpd-html';
        layer.appendChild(svg);
        layer.appendChild(html);
        host.appendChild(layer);
        pages[num] = {layer: layer, svg: svg, html: html};

        var setScale = function() {
            layer.style.setProperty('--mylkpd-k', Math.max(0.4, layer.clientWidth / 800).toFixed(3));
        };
        setScale();
        if (window.ResizeObserver) {
            new ResizeObserver(setScale).observe(layer);
        }
        if (!readonly) {
            bindPointer(num, layer);
        }
    }

    // ------------------------------------------------ Edit mode: paragraphs of the .docx.

    /**
     * Marks edited.
     *
     * @param {HTMLElement} el
     * @param {*} by
     * @param {*} byTeacher
     */
    function markEdited(el, by, byTeacher) {
        el.classList.add('mylkpd-edited');
        var info = by ? authorInfo(by, !!byTeacher) : null;
        if (info) {
            el.style.setProperty('--mylkpd-peer', info.color);
            el.title = cfg.strings.editedby.replace('{name}', info.name);
            if (showNames || byTeacher || isTeacherAuthor(by)) {
                el.setAttribute('data-mylkpd-by', info.name);
            } else {
                el.removeAttribute('data-mylkpd-by');
            }
        }
    }

    /**
     * Makes editable.
     *
     * @param {HTMLElement} p
     * @param {String} id
     */
    function makeEditable(p, id) {
        p.contentEditable = 'true';
        p.spellcheck = false;
        p.addEventListener('input', function() {
            touched[id] = true;
            markEdited(p, cfg.userid, isTeacher);
            scheduleSave();
        });
        p.addEventListener('focus', function() {
            currentBlock = id;
            if (cfg.collab) {
                scheduleSave(150); // Tell group mates right away that this paragraph is taken.
            }
        });
        p.addEventListener('blur', function() {
            if (currentBlock === id) {
                currentBlock = '';
            }
            if (linkify(p)) {
                touched[id] = true;
            }
            if (cfg.collab || touched[id]) {
                scheduleSave(150);
            }
        });
        p.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                // Keep one paragraph per block so every member's paragraphs line up.
                e.preventDefault();
                document.execCommand('insertLineBreak');
            }
        });
        p.addEventListener('paste', function(e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text');
            document.execCommand('insertText', false, text);
        });
        p.addEventListener('drop', function(e) {
            e.preventDefault();
        });
        p.addEventListener('click', function(e) {
            var chip = e.target.closest ? e.target.closest('.mylkpd-math') : null;
            if (chip && tool === 'type') {
                editMathChip(chip, id);
                return;
            }
            var a = e.target.closest ? e.target.closest('a[href]') : null;
            if (a && (e.ctrlKey || e.metaKey)) {
                window.open(a.href, '_blank', 'noopener');
            } else if (a) {
                showLinkPop(a);
            } else {
                hideLinkPop();
            }
        });
    }

    /**
     * Setup blocks.
     *
     * @param {HTMLElement} holder
     */
    function setupBlocks(holder) {
        var saved = cfg.blocks || {};
        var ps = holder.querySelectorAll('section.docx > article p');
        Array.prototype.forEach.call(ps, function(p, i) {
            var id = String(i);
            blockEls[id] = p;
            p.setAttribute('data-mylkpd-block', id);
            p.classList.add('mylkpd-block');
            if (saved[id] && typeof saved[id].html === 'string') {
                p.innerHTML = saved[id].html;
                markEdited(p, saved[id].by, saved[id].teacher);
            }
            hydrateBlock(p);
            if (saved[id]) {
                setBlockAlign(p, saved[id].align);
            }
            shadowBlocks[id] = blockState(p);
            if (!readonly) {
                makeEditable(p, id);
            }
        });
    }

    // ------------------------------------------------ Rendering the document.

    /**
     * Renders pdf.
     *
     * @param {*} width
     */
    function renderPdf(width) {
        return loadPdfjs(cfg.pdfjsBase).then(function(pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = cfg.workerUrl;
            return pdfjsLib.getDocument(cfg.docUrl).promise;
        }).then(function(pdf) {
            return renderPdfPages(pdf, width);
        });
    }

    /**
     * Renders all pages of a PDF, one after another (pages appear in order).
     *
     * @param {Object} pdf PDF.js document
     * @param {Number} width display width in pixels
     * @return {Promise}
     */
    function renderPdfPages(pdf, width) {
        var chain = Promise.resolve();
        for (var n = 1; n <= pdf.numPages; n++) {
            chain = chain.then(renderPdfPage.bind(null, pdf, n, width));
        }
        return chain;
    }

    /**
     * Renders one PDF page and marks it when it is blank.
     *
     * @param {Object} pdf PDF.js document
     * @param {Number} num page number
     * @param {Number} width display width in pixels
     * @return {Promise}
     */
    function renderPdfPage(pdf, num, width) {
        var view = null;
        return pdf.getPage(num).then(function(page) {
            view = preparePdfPage(num, page, width);
            return page.render({canvasContext: view.canvas.getContext('2d'), viewport: view.viewport}).promise;
        }).then(function() {
            markIfBlank(view.wrap, view.canvas);
            return null;
        });
    }

    /**
     * Adds the page element and canvas for one PDF page.
     *
     * @param {Number} num page number
     * @param {Object} page PDF.js page
     * @param {Number} width display width in pixels
     * @return {Object} {wrap, canvas, viewport}
     */
    function preparePdfPage(num, page, width) {
        pdfPageObjs[num] = page;
        var base = page.getViewport({scale: 1});
        var ratio = window.devicePixelRatio || 1;
        var vp = page.getViewport({scale: (width / base.width) * ratio});
        var wrap = document.createElement('div');
        wrap.className = 'mylkpd-page';
        wrap.style.aspectRatio = base.width + ' / ' + base.height;
        var canvas = document.createElement('canvas');
        canvas.width = Math.floor(vp.width);
        canvas.height = Math.floor(vp.height);
        wrap.appendChild(canvas);
        container.appendChild(wrap);
        setupPage(num, wrap);
        return {wrap: wrap, canvas: canvas, viewport: vp};
    }

    /**
     * Marks a rendered PDF page as blank when, sampled at low resolution, it is all near-white.
     *
     * @param {HTMLElement} wrap page element
     * @param {HTMLCanvasElement} canvas rendered page
     */
    function markIfBlank(wrap, canvas) {
        var probe = document.createElement('canvas');
        probe.width = 60;
        probe.height = Math.max(1, Math.round(60 * canvas.height / canvas.width));
        var pctx = probe.getContext('2d');
        pctx.drawImage(canvas, 0, 0, probe.width, probe.height);
        var px = pctx.getImageData(0, 0, probe.width, probe.height).data;
        for (var i = 0; i < px.length; i += 4) {
            if (px[i + 3] > 10 && (px[i] < 245 || px[i + 1] < 245 || px[i + 2] < 245)) {
                return;
            }
        }
        wrap.setAttribute('data-mylkpd-blank', '1');
    }

    /**
     * The docx-preview library puts "in front of / behind text" pictures (wrapNone) in a 0x0 inline-block, so the
     * picture still takes up a whole line and pushes the worksheet text down, and it ignores "behind text".
     * Page-wide pictures (backgrounds, banners, frames) are placed on the page at their position from
     * the document, behind the text; smaller ones are taken out of the text flow where they are.
     *
     * @param {HTMLElement} holder
     */
    function fixFloatingImages(holder) {
        Array.prototype.forEach.call(holder.querySelectorAll('section.docx'), function(section) {
            var pageWidth = section.getBoundingClientRect().width;
            Array.prototype.forEach.call(section.querySelectorAll('div'), function(wrap) {
                var st = wrap.style;
                if (st.position !== 'relative' || st.width !== '0px' || st.height !== '0px') {
                    return;
                }
                var img = wrap.querySelector('img, svg');
                if (!img) {
                    return;
                }
                var left = st.left || '0pt';
                var top = st.top || '0pt';
                img.style.position = 'absolute';
                img.style.left = left;
                img.style.top = top;
                img.style.maxWidth = 'none';
                if (img.getBoundingClientRect().width >= pageWidth * 0.6) {
                    // Positioned from the page corner, behind the text (header/body text sit above it).
                    img.classList.add('mylkpd-docx-bg');
                    section.insertBefore(img, section.firstChild);
                } else {
                    img.classList.add('mylkpd-docx-float');
                }
            });
        });
    }

    /**
     * Removes empty paragraphs at the end of a Word page that only spill over the page height
     * (docx-preview does not split pages by itself). Empty lines that fit on the page stay: teachers
     * often leave them as writing space. Paragraphs a student already wrote in are never removed.
     *
     * @param {HTMLElement} holder
     */
    function trimOverflowingEmptyParagraphs(holder) {
        var saved = cfg.blocks || {};
        var all = holder.querySelectorAll('section.docx > article p');
        var indexOf = new Map();
        Array.prototype.forEach.call(all, function(p, i) {
            indexOf.set(p, i);
        });
        var isEmpty = function(p) {
            return !p.textContent.replace(/[\s\u00a0\u200b]/g, '') && !p.querySelector('img, svg, table, canvas, .mylkpd-math');
        };
        Array.prototype.forEach.call(holder.querySelectorAll('section.docx'), function(section) {
            var article = section.querySelector(':scope > article');
            var pageHeight = parseFloat(window.getComputedStyle(section).minHeight) || 0;
            if (!article || !pageHeight) {
                return;
            }
            var guard = 500;
            while (guard-- > 0 && section.getBoundingClientRect().height > pageHeight + 1) {
                var last = article.lastElementChild;
                if (!last || last.tagName !== 'P' || !isEmpty(last) || saved[String(indexOf.get(last))]) {
                    break;
                }
                article.removeChild(last);
            }
        });
    }

    /**
     * Whether a page shows nothing: a Word page without text, tables or pictures (a background picture
     * alone does not count), or a PDF page whose rendering is plain white.
     *
     * @param {HTMLElement} host
     * @return {Boolean}
     */
    function isBlankPage(host) {
        if (host.getAttribute('data-mylkpd-blank') === '1') {
            return true;
        }
        if (!host.classList.contains('docx')) {
            return false;
        }
        var parts = host.querySelectorAll(':scope > article, :scope > header, :scope > footer');
        for (var i = 0; i < parts.length; i++) {
            if (parts[i].textContent.replace(/[\s\u00a0\u200b]/g, '') ||
                    parts[i].querySelector('table, svg, canvas, img:not(.mylkpd-docx-bg)')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Hides blank pages (e.g. after a page break at the end of the document). Page numbers do not
     * change, and a page with any answer or annotation on it is always shown.
     */
    function hideBlankPages() {
        Object.keys(pages).forEach(function(num) {
            var host = pages[num].layer.parentNode;
            var hasWork = pages[num].html.children.length > 0 || pages[num].svg.children.length > 0;
            host.classList.toggle('mylkpd-page-hidden', isBlankPage(host) && !hasWork);
        });
    }

    /**
     * Renders docx.
     */
    function renderDocx() {
        var holder = document.createElement('div');
        holder.className = 'mylkpd-docx';
        container.appendChild(holder);
        var fit = function() {
            holder.style.zoom = '';
            var wrapper = holder.firstElementChild;
            var needed = wrapper ? wrapper.scrollWidth : 0;
            var avail = container.clientWidth;
            if (needed > avail && avail > 0) {
                holder.style.zoom = (avail / needed).toFixed(3);
            }
        };
        var docxLib = null;
        return loadDocx(cfg.docxBase).then(function(docx) {
            docxLib = docx;
            return fetch(cfg.docUrl, {credentials: 'same-origin'});
        }).then(function(r) {
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }
            return r.blob();
        }).then(function(blob) {
            return docxLib.renderAsync(blob, holder, null, {
                inWrapper: true,
                breakPages: true,
                ignoreLastRenderedPageBreak: false,
                renderHeaders: true,
                renderFooters: true,
                useBase64URL: true,
                experimental: false
            });
        }).then(function() {
            fixFloatingImages(holder);
            trimOverflowingEmptyParagraphs(holder);
            if (canType) {
                setupBlocks(holder);
            }
            Array.prototype.forEach.call(holder.querySelectorAll('section.docx'), function(section, i) {
                setupPage(i + 1, section);
            });
            fit();
            window.addEventListener('resize', fit);
            return null;
        });
    }

    // ------------------------------------------------ Insert tools (equation, symbol, Arabic, link).

    var MATH_TEMPLATES = [
        ['\\frac{a}{b}', '\\frac{|}{}'], ['x^{n}', '^{|}'], ['x_{n}', '_{|}'], ['\\sqrt{x}', '\\sqrt{|}'],
        ['\\sqrt[n]{x}', '\\sqrt[|]{}'], ['\\left(x\\right)', '\\left( | \\right)'], ['\\sum_{i=1}^{n}', '\\sum_{i=1}^{n} |'],
        ['\\int_{a}^{b}', '\\int_{|}^{} '], ['\\lim_{x\\to a}', '\\lim_{x \\to |} '], ['\\vec{v}', '\\vec{|}'],
        ['\\overline{AB}', '\\overline{|}'], ['\\begin{pmatrix}a&b\\\\c&d\\end{pmatrix}', '\\begin{pmatrix}|&\\\\&\\end{pmatrix}'],
        ['\\pm', '\\pm '], ['\\times', '\\times '], ['\\div', '\\div '], ['\\cdot', '\\cdot '], ['\\le', '\\le '],
        ['\\ge', '\\ge '], ['\\ne', '\\ne '], ['\\approx', '\\approx '], ['\\infty', '\\infty '], ['\\pi', '\\pi '],
        ['\\alpha', '\\alpha '], ['\\beta', '\\beta '], ['\\theta', '\\theta '], ['\\Delta', '\\Delta '],
        ['x^{\\circ}', '^{\\circ}|'], ['\\rightarrow', '\\rightarrow '], ['\\angle', '\\angle '], ['\\perp', '\\perp ']
    ];
    var SYMBOLS = ('± × ÷ = ≠ ≈ ≤ ≥ < > √ ∛ ∞ π ° ∠ ⊥ ∥ △ ∑ ∫ Δ α β γ θ λ μ σ ω Ω ² ³ ¹ ½ ¼ ¾ ' +
        '→ ← ↑ ↓ ↔ ⇒ ⇔ ∈ ∉ ⊂ ∪ ∩ ∅ ∴ ∵ • … — « » € © ‰ ℃').split(' ');
    var ARABIC_ROWS = [
        'ض ص ث ق ف غ ع ه خ ح ج د',
        'ش س ي ب ل ا ت ن م ك ط',
        'ئ ء ؤ ر لا ى ة و ز ظ ذ',
        'آ أ إ ـ \u064E \u0650 \u064F \u0652 \u0651 \u064B \u064D \u064C \u0670',
        '٠ ١ ٢ ٣ ٤ ٥ ٦ ٧ ٨ ٩ ، ؛ ؟'
    ];

    /**
     * Flashes hint.
     *
     * @param {String} text
     * @param {*} info
     */
    function flashHint(text, info) {
        if (!hintEl) {
            return;
        }
        hintEl.textContent = text;
        hintEl.classList.toggle('text-danger', !info);
        setTimeout(function() {
            hintEl.classList.remove('text-danger');
            hintEl.textContent = (tool === 'math' || tool === 'link') ? cfg.strings.clicktoplace : hintText;
        }, 3500);
    }

    /**
     * Whether target.
     *
     * @param {HTMLElement} el
     */
    function isTarget(el) {
        return el && document.body.contains(el) && !el.readOnly &&
            (el.tagName === 'TEXTAREA' || (el.classList.contains('mylkpd-block') && el.isContentEditable));
    }

    // Remember where the student was typing, so toolbar buttons and dialogs can insert there.
    container.addEventListener('focusin', function(e) {
        if (isTarget(e.target)) {
            lastTarget = e.target;
        }
    });
    document.addEventListener('selectionchange', function() {
        var sel = window.getSelection();
        if (sel.rangeCount && lastTarget && lastTarget.tagName !== 'TEXTAREA' &&
                lastTarget.contains(sel.getRangeAt(0).startContainer)) {
            savedRange = sel.getRangeAt(0).cloneRange();
        }
    });

    /**
     * Puts the cursor back where the student was typing.
     *
     * @param {Boolean} paragraphsOnly
     * @return {Boolean} whether there is a place to type
     */
    function focusTarget(paragraphsOnly) {
        if (!isTarget(lastTarget) || (paragraphsOnly && lastTarget.tagName === 'TEXTAREA')) {
            return false;
        }
        if (document.activeElement !== lastTarget) {
            lastTarget.focus();
        }
        if (lastTarget.tagName !== 'TEXTAREA' && savedRange && lastTarget.contains(savedRange.startContainer)) {
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(savedRange);
        }
        return true;
    }

    /**
     * Inserts text.
     *
     * @param {String} text
     */
    function insertText(text) {
        if (!focusTarget(false)) {
            flashHint(cfg.strings.needfocus);
            return;
        }
        if (lastTarget.tagName === 'TEXTAREA') {
            lastTarget.setRangeText(text, lastTarget.selectionStart, lastTarget.selectionEnd, 'end');
            lastTarget.dispatchEvent(new Event('input', {bubbles: true}));
        } else {
            document.execCommand('insertText', false, text);
        }
    }

    /**
     * Delete back.
     */
    function deleteBack() {
        if (!focusTarget(false)) {
            return;
        }
        if (lastTarget.tagName === 'TEXTAREA') {
            var start = lastTarget.selectionStart;
            var end = lastTarget.selectionEnd;
            if (start === end && start > 0) {
                // Remove one code point (Arabic letters and marks are single code units).
                start -= 1;
            }
            lastTarget.setRangeText('', start, end, 'end');
            lastTarget.dispatchEvent(new Event('input', {bubbles: true}));
        } else {
            document.execCommand('delete');
        }
    }

    /**
     * Inserts node.
     *
     * @param {HTMLElement} node
     */
    function insertNode(node) {
        if (!focusTarget(true)) {
            flashHint(cfg.strings.needfocus);
            return false;
        }
        var sel = window.getSelection();
        var range = sel.getRangeAt(0);
        range.deleteContents();
        range.insertNode(node);
        range.setStartAfter(node);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
        lastTarget.dispatchEvent(new Event('input', {bubbles: true}));
        return true;
    }

    /**
     * Keeps focus.
     *
     * @param {HTMLElement} el
     */
    function keepFocus(el) {
        el.addEventListener('mousedown', function(e) {
            if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
            }
        });
    }

    // ---- Modal dialog.

    /**
     * Dialog.
     *
     * @param {*} title
     * @param {*} build
     */
    function dialog(title, build) {
        return new Promise(function(resolve) {
            var back = document.createElement('div');
            back.className = 'mylkpd-modal-back';
            var box = document.createElement('div');
            box.className = 'mylkpd-modal';
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            box.setAttribute('aria-label', title);
            var h = document.createElement('h3');
            h.textContent = title;
            box.appendChild(h);
            var body = document.createElement('div');
            box.appendChild(body);
            var actions = document.createElement('div');
            actions.className = 'mylkpd-modal-actions';
            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn btn-secondary';
            cancel.textContent = cfg.strings.cancel;
            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'btn btn-primary';
            ok.textContent = cfg.strings.insert;
            actions.appendChild(cancel);
            actions.appendChild(ok);
            box.appendChild(actions);
            back.appendChild(box);
            document.body.appendChild(back);
            var getValue = build(body, function() {
                ok.click();
            });
            var done = function(value) {
                document.removeEventListener('keydown', onKey, true);
                back.parentNode.removeChild(back);
                resolve(value);
            };
            var onKey = function(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    done(null);
                }
            };
            document.addEventListener('keydown', onKey, true);
            cancel.addEventListener('click', function() {
                done(null);
            });
            back.addEventListener('mousedown', function(e) {
                if (e.target === back) {
                    done(null);
                }
            });
            ok.addEventListener('click', function() {
                var v = getValue();
                if (v !== false) {
                    done(v);
                }
            });
            var first = body.querySelector('textarea, input');
            if (first) {
                first.focus();
            }
        });
    }

    /**
     * Math dialog.
     *
     * @param {*} initial
     */
    function mathDialog(initial) {
        return dialog(cfg.strings.mathtitle, function(body, submit) {
            var palette = document.createElement('div');
            palette.className = 'mylkpd-math-palette';
            var input = document.createElement('textarea');
            input.className = 'form-control mylkpd-math-input';
            input.rows = 2;
            input.spellcheck = false;
            input.dir = 'ltr';
            input.value = initial || '';
            input.setAttribute('aria-label', 'LaTeX');
            var preview = document.createElement('div');
            preview.className = 'mylkpd-math-preview';
            preview.setAttribute('aria-label', cfg.strings.mathpreview);
            var hint = document.createElement('div');
            hint.className = 'small text-muted';
            hint.textContent = cfg.strings.mathhint;
            var update = function() {
                if (input.value.trim()) {
                    renderTex(preview, input.value);
                } else {
                    preview.textContent = '';
                }
            };
            MATH_TEMPLATES.forEach(function(t) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'btn btn-light btn-sm';
                b.title = t[0];
                renderTex(b, t[0]);
                b.addEventListener('click', function() {
                    var start = input.selectionStart;
                    var parts = t[1].split('|');
                    input.setRangeText(parts.join(''), start, input.selectionEnd, 'end');
                    var caret = start + parts[0].length;
                    input.focus();
                    input.setSelectionRange(caret, caret);
                    update();
                });
                palette.appendChild(b);
            });
            input.addEventListener('input', update);
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    submit();
                }
            });
            body.appendChild(palette);
            body.appendChild(input);
            body.appendChild(hint);
            body.appendChild(preview);
            update();
            return function() {
                var tex = input.value.trim();
                return tex ? tex.slice(0, 1000) : false;
            };
        });
    }

    /**
     * Link dialog.
     *
     * @param {*} initialText
     */
    function linkDialog(initialText) {
        return dialog(cfg.strings.linktitle, function(body, submit) {
            var mk = function(label, type, value) {
                var wrap = document.createElement('label');
                wrap.className = 'mylkpd-field';
                wrap.appendChild(document.createTextNode(label));
                var inp = document.createElement('input');
                inp.type = type;
                inp.className = 'form-control';
                inp.value = value || '';
                inp.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        submit();
                    }
                });
                wrap.appendChild(inp);
                body.appendChild(wrap);
                return inp;
            };
            var url = mk(cfg.strings.linkurl, 'url', 'https://');
            var text = mk(cfg.strings.linktext, 'text', initialText);
            var err = document.createElement('div');
            err.className = 'small text-danger';
            body.appendChild(err);
            return function() {
                var u = url.value.trim();
                if (u && !/^[a-z]+:/i.test(u)) {
                    u = 'https://' + u;
                }
                if (!/^https?:\/\/[^\s/$.?#].[^\s]*$/i.test(u)) {
                    err.textContent = cfg.strings.linkinvalid;
                    url.focus();
                    return false;
                }
                return {url: u, text: text.value.trim().slice(0, 200)};
            };
        });
    }

    // ---- Equations and links: typed into a paragraph, or placed on the page.

    /**
     * Edits math chip.
     *
     * @param {*} chip
     * @param {*} blockId
     */
    function editMathChip(chip, blockId) {
        if (!blockEls[blockId] || !blockEls[blockId].isContentEditable) {
            return;
        }
        mathDialog(chip.getAttribute('data-tex')).then(function(tex) {
            if (!tex) {
                return null;
            }
            chip.setAttribute('data-tex', tex);
            renderTex(chip, tex);
            touched[blockId] = true;
            markEdited(blockEls[blockId], cfg.userid);
            scheduleSave();
            return null;
        }).catch(Notification.exception);
    }

    /**
     * Edits math item.
     *
     * @param {Object} item
     */
    function editMathItem(item) {
        mathDialog(item.tex).then(function(tex) {
            if (!tex || tex === item.tex) {
                return null;
            }
            recordUndo([{id: item.id, before: copy(item)}]);
            item.tex = tex;
            renderTex(elems.get(item), tex);
            claim(item);
            changed();
            return null;
        }).catch(Notification.exception);
    }

    /**
     * Places item.
     *
     * @param {String} kind
     * @param {Number} num
     * @param {HTMLElement} p
     */
    function placeItem(kind, num, p) {
        var back = prevTool || 'text';
        var ask = kind === 'math' ? mathDialog('') : linkDialog('');
        ask.then(function(v) {
            setTool(back);
            prevTool = null;
            if (!v) {
                return null;
            }
            var it = {id: newId(), page: num, type: kind, x: p.x, y: p.y, color: color, by: cfg.userid};
            if (kind === 'math') {
                it.tex = v;
            } else {
                it.url = v.url;
                it.text = v.text;
            }
            recordUndo([{id: it.id, before: null}]);
            items.push(it);
            createEl(it);
            changed();
            return null;
        }).catch(Notification.exception);
    }

    /**
     * Arm placement.
     *
     * @param {String} kind
     */
    function armPlacement(kind) {
        if (tool !== 'math' && tool !== 'link') {
            prevTool = tool;
        }
        setTool(kind);
    }

    /**
     * Handles insert.
     *
     * @param {String} kind
     * @param {HTMLElement} button
     */
    function onInsert(kind, button) {
        if (kind === 'image') {
            chooseImage();
            return;
        }
        if (kind === 'table') {
            toggleTablePicker(button);
            return;
        }
        if (kind === 'symbol' || kind === 'arabic') {
            togglePanel(kind, button);
            return;
        }
        var inParagraph = canType && tool === 'type';
        if (!inParagraph) {
            armPlacement(kind);
            return;
        }
        if (!focusTarget(true)) {
            flashHint(cfg.strings.needfocus);
            return;
        }
        var range = savedRange;
        var selected = range ? range.toString() : '';
        (kind === 'math' ? mathDialog('') : linkDialog(selected)).then(function(v) {
            if (!v) {
                focusTarget(true);
                return null;
            }
            savedRange = range;
            if (kind === 'math') {
                insertNode(mathChip(v));
            } else {
                var a = document.createElement('a');
                a.href = v.url;
                a.textContent = v.text || selected || v.url;
                insertNode(a);
            }
            return null;
        }).catch(Notification.exception);
    }

    // ---- Symbol table and Arabic keyboard (floating panels).

    var panels = {};

    /**
     * Key.
     *
     * @param {String} label
     * @param {*} title
     * @param {String} action
     */
    function key(label, title, action) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'mylkpd-key';
        b.textContent = label;
        if (title) {
            b.title = title;
            b.setAttribute('aria-label', title);
        }
        keepFocus(b);
        b.addEventListener('click', action);
        return b;
    }

    /**
     * Builds panel.
     *
     * @param {String} kind
     */
    function buildPanel(kind) {
        var panel = document.createElement('div');
        panel.className = 'mylkpd-panel mylkpd-panel-' + kind;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', cfg.strings[kind === 'arabic' ? 'arabictitle' : 'symboltitle']);
        keepFocus(panel);
        var head = document.createElement('div');
        head.className = 'mylkpd-panel-head';
        head.textContent = cfg.strings[kind === 'arabic' ? 'arabictitle' : 'symboltitle'];
        head.appendChild(key('\u00d7', cfg.strings.close, function() {
            togglePanel(kind);
        }));
        panel.appendChild(head);
        if (kind === 'symbol') {
            var grid = document.createElement('div');
            grid.className = 'mylkpd-keys mylkpd-symbol-grid';
            SYMBOLS.forEach(function(sym) {
                grid.appendChild(key(sym, sym, function() {
                    insertText(sym);
                }));
            });
            panel.appendChild(grid);
        } else {
            ARABIC_ROWS.forEach(function(row) {
                var r = document.createElement('div');
                r.className = 'mylkpd-keys mylkpd-arabic-row';
                r.dir = 'rtl';
                row.split(' ').forEach(function(ch) {
                    var isMark = /[\u064B-\u0652\u0670]/.test(ch);
                    r.appendChild(key(isMark ? '\u25CC' + ch : ch, null, function() {
                        insertText(ch);
                    }));
                });
                panel.appendChild(r);
            });
            var last = document.createElement('div');
            last.className = 'mylkpd-keys mylkpd-arabic-row';
            var space = key(cfg.strings.space, cfg.strings.space, function() {
                insertText(' ');
            });
            space.classList.add('mylkpd-key-wide');
            last.appendChild(space);
            last.appendChild(key('\u232B', cfg.strings.backspace, deleteBack));
            panel.appendChild(last);
        }
        document.body.appendChild(panel);
        return panel;
    }

    /**
     * Toggles panel.
     *
     * @param {String} kind
     * @param {HTMLElement} button
     */
    function togglePanel(kind, button) {
        var panel = panels[kind] || (panels[kind] = buildPanel(kind));
        var open = !panel.classList.contains('mylkpd-open');
        Object.keys(panels).forEach(function(k) {
            panels[k].classList.remove('mylkpd-open');
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-mylkpd-insert="symbol"],[data-mylkpd-insert="arabic"]'),
            function(b) {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline-secondary');
            });
        if (open) {
            panel.classList.add('mylkpd-open');
            if (button) {
                button.classList.add('btn-primary');
                button.classList.remove('btn-outline-secondary');
            }
            if (!isTarget(lastTarget)) {
                flashHint(cfg.strings.needfocus);
            }
        }
    }

    /**
     * Current alignment of a paragraph or text box (from its own setting or the Word document).
     *
     * @param {HTMLElement} el
     * @return {String}
     */
    function currentAlign(el) {
        var a = window.getComputedStyle(el).textAlign;
        if (a === 'start' || a === '-webkit-left' || a === 'auto') {
            return el.dir === 'rtl' || window.getComputedStyle(el).direction === 'rtl' ? 'right' : 'left';
        }
        if (a === 'end') {
            return 'right';
        }
        return ALIGNS.indexOf(a) >= 0 ? a : 'left';
    }

    /**
     * Updates align buttons.
     */
    function updateAlignButtons() {
        var target = isTarget(lastTarget) ? lastTarget : null;
        var active = target ? currentAlign(target) : '';
        Array.prototype.forEach.call(document.querySelectorAll('[data-mylkpd-align]'), function(b) {
            var on = b.getAttribute('data-mylkpd-align') === active;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    /**
     * Aligns the paragraph (or text box) the student is typing in.
     *
     * @param {String} align left|center|right|justify
     */
    function applyAlign(align) {
        if (!isTarget(lastTarget)) {
            flashHint(cfg.strings.needfocus);
            return;
        }
        var el = lastTarget;
        if (el.tagName === 'TEXTAREA') {
            var item = owners.get(closestItem(el));
            if (!item || !canEdit(item)) {
                return;
            }
            recordUndo([{id: item.id, before: copy(item)}]);
            item.align = align;
            el.style.textAlign = align;
            claim(item);
            changed();
        } else {
            var id = el.getAttribute('data-mylkpd-block');
            setBlockAlign(el, align);
            touched[id] = true;
            markEdited(el, cfg.userid, isTeacher);
            scheduleSave();
        }
        el.focus();
        updateAlignButtons();
    }

    /**
     * Setup insert tools.
     */
    function setupInsertTools() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-mylkpd-align]'), function(b) {
            keepFocus(b);
            b.addEventListener('click', function() {
                applyAlign(b.getAttribute('data-mylkpd-align'));
            });
        });
        container.addEventListener('focusin', updateAlignButtons);
        Array.prototype.forEach.call(document.querySelectorAll('[data-mylkpd-insert]'), function(b) {
            keepFocus(b);
            b.addEventListener('click', function() {
                onInsert(b.getAttribute('data-mylkpd-insert'), b);
            });
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-mylkpd-format]'), keepFocus);
    }

    // ------------------------------------------------ Tables.

    var TABLE_BORDERS = cfg.tableBorderColors;
    var TABLE_FILLS = cfg.tableFillColors;
    var lastCell = null;
    var tableTools = null;
    var toolsFor = null;

    /**
     * Normalises table.
     *
     * @param {Object} item
     */
    function normaliseTable(item) {
        item.rows = Math.max(1, Math.min(20, item.rows || 1));
        item.cols = Math.max(1, Math.min(8, item.cols || 1));
        var cells = [];
        for (var r = 0; r < item.rows; r++) {
            cells[r] = [];
            for (var c = 0; c < item.cols; c++) {
                cells[r][c] = (item.cells && item.cells[r] && item.cells[r][c]) || '';
            }
        }
        item.cells = cells;
        if (!item.fills || Array.isArray(item.fills)) {
            item.fills = {};
        }
    }

    /**
     * Styles table.
     *
     * @param {Object} item
     * @param {HTMLElement} table
     */
    function styleTable(item, table) {
        table.classList.toggle('mylkpd-table-noborder', item.bstyle === 'none');
        Array.prototype.forEach.call(table.querySelectorAll('td'), function(td) {
            var r = +td.getAttribute('data-r');
            var c = +td.getAttribute('data-c');
            td.style.border = item.bstyle === 'none' ? '' :
                (item.bwidth || 1) + 'px ' + (item.bstyle || 'solid') + ' ' + (item.bcolor || '#111111');
            td.style.background = item.fills[r + '-' + c] || (r === 0 && item.head ? item.head : '');
            td.classList.toggle('mylkpd-table-head', r === 0 && !!item.head);
        });
    }

    /**
     * Renders table.
     *
     * @param {Object} item
     * @param {HTMLElement} table
     */
    function renderTable(item, table) {
        normaliseTable(item);
        table.textContent = '';
        var editable = canEdit(item);
        item.cells.forEach(function(row, r) {
            var tr = document.createElement('tr');
            row.forEach(function(text, c) {
                var td = document.createElement('td');
                td.setAttribute('data-r', r);
                td.setAttribute('data-c', c);
                var cell = document.createElement('div');
                cell.className = 'mylkpd-cell';
                cell.textContent = text;
                cell.dir = 'auto';
                if (editable) {
                    cell.contentEditable = 'true';
                    cell.spellcheck = false;
                    bindCell(item, cell, r, c);
                }
                td.appendChild(cell);
                tr.appendChild(td);
            });
            table.appendChild(tr);
        });
        styleTable(item, table);
    }

    /**
     * Binds cell.
     *
     * @param {Object} item
     * @param {HTMLElement} cell
     * @param {Object} r
     * @param {*} c
     */
    function bindCell(item, cell, r, c) {
        var before = null;
        cell.addEventListener('focus', function() {
            lastCell = {item: item, r: r, c: c};
            before = copy(item);
            selectItem(item);
        });
        cell.addEventListener('input', function() {
            if (before) {
                recordUndo([{id: item.id, before: before}]);
                before = null;
            }
            item.cells[r][c] = cell.innerText.replace(/\n$/, '');
            claim(item);
            changed();
            positionTableTools();
        });
        cell.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                // Tab / Shift+Tab move between cells, like in a word processor.
                e.preventDefault();
                var cells = Array.prototype.slice.call(elems.get(item).querySelectorAll('.mylkpd-cell'));
                var next = cells[cells.indexOf(cell) + (e.shiftKey ? -1 : 1)];
                if (next) {
                    next.focus();
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                document.execCommand('insertLineBreak');
            }
        });
        cell.addEventListener('paste', function(e) {
            e.preventDefault();
            document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text'));
        });
    }

    /**
     * Creates table.
     *
     * @param {Object} item
     */
    function createTable(item) {
        var el = document.createElement('div');
        el.className = 'mylkpd-item mylkpd-tableitem';
        if (canEdit(item)) {
            var grip = document.createElement('span');
            grip.className = 'mylkpd-table-grip';
            grip.textContent = '\u283F';
            grip.title = cfg.strings.tablemove;
            el.appendChild(grip);
        }
        var table = document.createElement('table');
        table.className = 'mylkpd-table';
        el.appendChild(table);
        renderTable(item, table);
        if (canEdit(item)) {
            var handle = document.createElement('span');
            handle.className = 'mylkpd-resize';
            handle.title = cfg.strings.resize;
            el.appendChild(handle);
        }
        return el;
    }

    /**
     * Changes a table (structure or look) with one undo step, then redraws it.
     *
     * @param {Object} item
     * @param {Function} change
     */
    function editTable(item, change) {
        recordUndo([{id: item.id, before: copy(item)}]);
        change();
        var el = elems.get(item);
        if (el) {
            renderTable(item, el.querySelector('table'));
        }
        claim(item);
        changed();
        positionTableTools();
    }

    /**
     * Moves fills.
     *
     * @param {Object} item
     * @param {Function} map
     */
    function moveFills(item, map) {
        var fills = {};
        Object.keys(item.fills).forEach(function(key) {
            var p = key.split('-').map(Number);
            var to = map(p[0], p[1]);
            if (to) {
                fills[to[0] + '-' + to[1]] = item.fills[key];
            }
        });
        item.fills = fills;
    }

    /**
     * Runs a table action.
     *
     * @param {String} action
     */
    function tableAction(action) {
        var item = toolsFor;
        if (!item) {
            return;
        }
        var at = (lastCell && lastCell.item === item) ? lastCell : {r: item.rows - 1, c: item.cols - 1};
        editTable(item, function() {
            if (action === 'addrow' && item.rows < 20) {
                item.cells.splice(at.r + 1, 0, new Array(item.cols).fill(''));
                item.rows++;
                moveFills(item, function(r, c) {
                    return [r > at.r ? r + 1 : r, c];
                });
            } else if (action === 'addcol' && item.cols < 8) {
                item.cells.forEach(function(row) {
                    row.splice(at.c + 1, 0, '');
                });
                item.cols++;
                moveFills(item, function(r, c) {
                    return [r, c > at.c ? c + 1 : c];
                });
            } else if (action === 'delrow' && item.rows > 1) {
                item.cells.splice(at.r, 1);
                item.rows--;
                moveFills(item, function(r, c) {
                    return r === at.r ? null : [r > at.r ? r - 1 : r, c];
                });
                lastCell = null;
            } else if (action === 'delcol' && item.cols > 1) {
                item.cells.forEach(function(row) {
                    row.splice(at.c, 1);
                });
                item.cols--;
                moveFills(item, function(r, c) {
                    return c === at.c ? null : [r, c > at.c ? c - 1 : c];
                });
                lastCell = null;
            }
        });
    }

    // ---- The mini toolbar above a selected table.

    /**
     * Builds row.
     *
     * @param {Array} colors
     * @param {*} current
     * @param {Function} onPick
     * @param {*} withNone
     */
    function swatchRow(colors, current, onPick, withNone) {
        var row = document.createElement('div');
        row.className = 'mylkpd-tablepop-swatches';
        (withNone ? [''].concat(colors) : colors).forEach(function(color) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'mylkpd-swatch mylkpd-swatch-sm' + (color === current ? ' active' : '') +
                (color ? '' : ' mylkpd-swatch-none');
            b.style.backgroundColor = color || '#fff';
            b.title = color || cfg.strings.tablenofill;
            b.setAttribute('aria-label', b.title);
            b.addEventListener('click', function() {
                onPick(color);
            });
            row.appendChild(b);
        });
        return row;
    }

    /**
     * Builds label.
     *
     * @param {String} text
     */
    function popLabel(text) {
        var l = document.createElement('div');
        l.className = 'mylkpd-tablepop-label';
        l.textContent = text;
        return l;
    }

    /**
     * Fills popover.
     *
     * @param {String} kind
     * @param {HTMLElement} pop
     */
    function fillPopover(kind, pop) {
        var item = toolsFor;
        pop.textContent = '';
        if (kind === 'border') {
            pop.appendChild(popLabel(cfg.strings.tablestyle));
            var styles = document.createElement('div');
            styles.className = 'mylkpd-tablepop-options';
            [['solid', cfg.strings.tablesolid], ['dashed', cfg.strings.tabledashed], ['dotted', cfg.strings.tabledotted],
                ['none', cfg.strings.tablenoborder]].forEach(function(o) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'mylkpd-borderopt' + ((item.bstyle || 'solid') === o[0] ? ' active' : '');
                b.title = o[1];
                b.setAttribute('aria-label', o[1]);
                var line = document.createElement('span');
                line.style.borderTop = o[0] === 'none' ? '2px dashed #d0d4d9' : '2px ' + o[0] + ' #212529';
                b.appendChild(line);
                b.addEventListener('click', function() {
                    editTable(item, function() {
                        item.bstyle = o[0];
                    });
                    fillPopover(kind, pop);
                });
                styles.appendChild(b);
            });
            pop.appendChild(styles);
            pop.appendChild(popLabel(cfg.strings.tablethickness));
            var widths = document.createElement('div');
            widths.className = 'mylkpd-tablepop-options';
            [1, 2, 3].forEach(function(w) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'mylkpd-borderopt' + ((item.bwidth || 1) === w ? ' active' : '');
                b.title = w + ' px';
                var line = document.createElement('span');
                line.style.borderTop = w + 'px solid #212529';
                b.appendChild(line);
                b.addEventListener('click', function() {
                    editTable(item, function() {
                        item.bwidth = w;
                    });
                    fillPopover(kind, pop);
                });
                widths.appendChild(b);
            });
            pop.appendChild(widths);
            pop.appendChild(popLabel(cfg.strings.tablebordercolor));
            pop.appendChild(swatchRow(TABLE_BORDERS, item.bcolor || '#111111', function(color) {
                editTable(item, function() {
                    item.bcolor = color;
                    if (item.bstyle === 'none') {
                        item.bstyle = 'solid';
                    }
                });
                fillPopover(kind, pop);
            }, false));
        } else {
            pop.appendChild(popLabel(cfg.strings.tableheader));
            pop.appendChild(swatchRow(TABLE_FILLS, item.head || '', function(color) {
                editTable(item, function() {
                    item.head = color;
                });
                fillPopover(kind, pop);
            }, true));
            pop.appendChild(popLabel(cfg.strings.tablecell));
            var cell = (lastCell && lastCell.item === item) ? lastCell : null;
            var key = cell ? cell.r + '-' + cell.c : null;
            var row = swatchRow(TABLE_FILLS, key ? (item.fills[key] || '') : null, function(color) {
                if (!key) {
                    return;
                }
                editTable(item, function() {
                    if (color) {
                        item.fills[key] = color;
                    } else {
                        delete item.fills[key];
                    }
                });
                fillPopover(kind, pop);
            }, true);
            if (!key) {
                row.classList.add('mylkpd-disabled');
            }
            pop.appendChild(row);
        }
    }

    /**
     * Builds table tools.
     */
    function buildTableTools() {
        var bar = document.createElement('div');
        bar.className = 'mylkpd-tabletools';
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', cfg.strings.table);
        keepFocus(bar);
        var pop = document.createElement('div');
        pop.className = 'mylkpd-tablepop';
        var openPop = null;
        var button = function(label, title, onClick, cls) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'mylkpd-tt-btn' + (cls ? ' ' + cls : '');
            b.innerHTML = label;
            b.title = title;
            b.setAttribute('aria-label', title);
            b.addEventListener('click', onClick);
            bar.appendChild(b);
            return b;
        };
        var sep = function() {
            var s = document.createElement('span');
            s.className = 'mylkpd-tt-sep';
            bar.appendChild(s);
        };
        var plus = '<svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true">' +
            '<rect x="1.5" y="2.5" width="13" height="11" rx="1.5" fill="none" stroke="currentColor"/>';
        var addMark = '<path d="M8 4.5v7M4.5 8h7" stroke="#0f6cbf" stroke-width="2"/></svg>';
        button(plus + '<path d="M1.5 8h13" stroke="currentColor"/>' + addMark,
            cfg.strings.tableaddrow, function() {
                tableAction('addrow');
            });
        button(plus + '<path d="M8 2.5v11" stroke="currentColor"/>' + addMark,
            cfg.strings.tableaddcol, function() {
                tableAction('addcol');
            });
        button(plus + '<path d="M1.5 8h13" stroke="currentColor"/><path d="M4.5 8h7" stroke="#c62828" stroke-width="2"/></svg>',
            cfg.strings.tabledelrow, function() {
                tableAction('delrow');
            });
        button(plus + '<path d="M8 2.5v11" stroke="currentColor"/><path d="M8 4.5v7" stroke="#c62828" stroke-width="2"/></svg>',
            cfg.strings.tabledelcol, function() {
                tableAction('delcol');
            });
        sep();
        var popButton = function(kind, label, title) {
            var b = button(label + ' <span class="mylkpd-tt-caret">\u25BE</span>', title, function() {
                if (openPop === kind) {
                    pop.style.display = 'none';
                    openPop = null;
                    return;
                }
                openPop = kind;
                fillPopover(kind, pop);
                pop.style.display = 'block';
                pop.style.left = b.offsetLeft + 'px';
                // Open away from the table so the menu never covers it.
                var tableEl = toolsFor ? elems.get(toolsFor) : null;
                var above = tableEl && bar.getBoundingClientRect().top < tableEl.getBoundingClientRect().top;
                pop.style.top = above ? 'auto' : 'calc(100% + 6px)';
                pop.style.bottom = above ? 'calc(100% + 6px)' : 'auto';
            }, 'mylkpd-tt-pop');
            return b;
        };
        popButton('border', '<svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true">' +
            '<rect x="1.5" y="1.5" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6"/>' +
            '<path d="M8 1.5v13M1.5 8h13" stroke="currentColor" stroke-dasharray="2 1.5"/></svg> ' +
            cfg.strings.tableborder, cfg.strings.tableborder);
        popButton('color', '<span class="mylkpd-tt-paint"></span> ' + cfg.strings.tablecolor, cfg.strings.tablecolor);
        sep();
        button('&#128465;', cfg.strings.tabledelete, function() {
            var item = toolsFor;
            if (item) {
                recordUndo([{id: item.id, before: copy(item)}]);
                hideTableTools();
                removeItem(item);
            }
        }, 'mylkpd-tt-danger');
        bar.appendChild(pop);
        bar.closePop = function() {
            pop.style.display = 'none';
            openPop = null;
        };
        document.body.appendChild(bar);
        window.addEventListener('scroll', positionTableTools, true);
        window.addEventListener('resize', positionTableTools);
        // A click anywhere else closes the open menu; outside the table it also deselects the table.
        document.addEventListener('pointerdown', function(e) {
            if (!toolsFor || bar.contains(e.target)) {
                return;
            }
            bar.closePop();
            if (!(e.target.closest && e.target.closest('.mylkpd-tableitem'))) {
                selectItem(null);
            }
        }, true);
        return bar;
    }

    /**
     * Positions table tools.
     */
    function positionTableTools() {
        if (!tableTools || !toolsFor || tableTools.style.display === 'none') {
            return;
        }
        var el = elems.get(toolsFor);
        if (!el || !document.body.contains(el)) {
            hideTableTools();
            return;
        }
        var r = el.getBoundingClientRect();
        var h = tableTools.offsetHeight;
        var top = r.top - h - 8;
        if (top < (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--mylkpd-sticky-top')) || 0) + 70) {
            top = r.bottom + 8; // No room above (toolbar in the way): show it under the table.
        }
        tableTools.style.top = Math.round(top) + 'px';
        tableTools.style.left = Math.round(Math.max(8, Math.min(r.left, window.innerWidth - tableTools.offsetWidth - 8))) + 'px';
    }

    /**
     * Shows table tools.
     *
     * @param {Object} item
     */
    function showTableTools(item) {
        if (!canEdit(item)) {
            return;
        }
        tableTools = tableTools || buildTableTools();
        if (toolsFor !== item) {
            tableTools.closePop();
        }
        toolsFor = item;
        tableTools.style.display = 'flex';
        positionTableTools();
    }

    /**
     * Hides table tools.
     */
    function hideTableTools() {
        if (tableTools) {
            tableTools.style.display = 'none';
            tableTools.closePop();
        }
        toolsFor = null;
    }

    // ---- Size picker for a new table (like a word processor's grid).

    var tablePicker = null;

    /**
     * Toggles table picker.
     *
     * @param {HTMLElement} button
     */
    function toggleTablePicker(button) {
        if (tablePicker && tablePicker.style.display === 'block') {
            tablePicker.style.display = 'none';
            return;
        }
        if (!tablePicker) {
            tablePicker = document.createElement('div');
            tablePicker.className = 'mylkpd-tablepicker';
            keepFocus(tablePicker);
            var label = document.createElement('div');
            label.className = 'mylkpd-tablepicker-label';
            var grid = document.createElement('div');
            grid.className = 'mylkpd-tablepicker-grid';
            var cells = [];
            var mark = function(rows, cols) {
                cells.forEach(function(c) {
                    c.classList.toggle('on', +c.getAttribute('data-r') < rows && +c.getAttribute('data-c') < cols);
                });
                label.textContent = rows ? rows + ' \u00d7 ' + cols : cfg.strings.tablepick;
            };
            var addCell = function(row, col) {
                var cell = document.createElement('button');
                cell.type = 'button';
                cell.setAttribute('data-r', row);
                cell.setAttribute('data-c', col);
                cell.setAttribute('aria-label', (row + 1) + ' \u00d7 ' + (col + 1));
                cell.addEventListener('mouseenter', mark.bind(null, row + 1, col + 1));
                cell.addEventListener('focus', mark.bind(null, row + 1, col + 1));
                cell.addEventListener('click', function() {
                    tablePicker.style.display = 'none';
                    insertTable(row + 1, col + 1);
                });
                cells.push(cell);
                grid.appendChild(cell);
            };
            for (var row = 0; row < 8; row++) {
                for (var col = 0; col < 8; col++) {
                    addCell(row, col);
                }
            }
            tablePicker.appendChild(grid);
            tablePicker.appendChild(label);
            grid.addEventListener('mouseleave', function() {
                mark(0, 0);
            });
            mark(0, 0);
            document.body.appendChild(tablePicker);
            document.addEventListener('mousedown', function(e) {
                if (tablePicker.style.display === 'block' && !tablePicker.contains(e.target) &&
                        !(e.target.closest && e.target.closest('[data-mylkpd-insert="table"]'))) {
                    tablePicker.style.display = 'none';
                }
            });
        }
        var at = button.getBoundingClientRect();
        tablePicker.style.display = 'block';
        tablePicker.style.left = Math.round(Math.min(at.left, window.innerWidth - tablePicker.offsetWidth - 8)) + 'px';
        tablePicker.style.top = Math.round(at.bottom + 6) + 'px';
    }

    /**
     * Inserts table.
     *
     * @param {Number} rows
     * @param {Number} cols
     */
    function insertTable(rows, cols) {
        var target = visiblePage();
        if (!target) {
            return;
        }
        var it = {id: newId(), page: target.num, type: 'table', x: 15, y: clamp(Math.max(2, target.cy - rows * 1.5)),
            w: 70, rows: rows, cols: cols, cells: [], bstyle: 'solid', bwidth: 1, bcolor: '#111111', head: '',
            fills: {}, color: color, by: cfg.userid};
        normaliseTable(it);
        recordUndo([{id: it.id, before: null}]);
        items.push(it);
        var el = createEl(it);
        changed();
        selectItem(it);
        var first = el && el.querySelector('.mylkpd-cell');
        if (first) {
            first.focus();
        }
    }

    // ------------------------------------------------ Pictures.

    /**
     * Image url.
     *
     * @param {*} file
     */
    function imageUrl(file) {
        return imageBase + '/' + submissionId + '/' + encodeURIComponent(file);
    }

    /**
     * Selects item.
     *
     * @param {Object} item
     */
    function selectItem(item) {
        container.querySelectorAll('.mylkpd-selected').forEach(function(el) {
            el.classList.remove('mylkpd-selected');
        });
        var el = item ? elems.get(item) : null;
        if (el) {
            el.classList.add('mylkpd-selected');
        }
        if (item && item.type === 'table') {
            showTableTools(item);
        } else {
            hideTableTools();
        }
    }

    /**
     * Shrinks a photo in the browser (long side at most 1600 px) so uploads stay small on phones.
     *
     * @param {File} file
     * @return {Promise} resolves with {blob, name, width, height}
     */
    function shrinkImage(file) {
        return new Promise(function(resolve, reject) {
            if (!/^image\//.test(file.type)) {
                reject(new Error(cfg.strings.imageerror));
                return;
            }
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function() {
                var scale = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
                var w = Math.max(1, Math.round(img.naturalWidth * scale));
                var h = Math.max(1, Math.round(img.naturalHeight * scale));
                var canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                var ctx = canvas.getContext('2d');
                var png = file.type === 'image/png' || file.type === 'image/gif';
                if (!png) {
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(0, 0, w, h);
                }
                ctx.drawImage(img, 0, 0, w, h);
                URL.revokeObjectURL(url);
                canvas.toBlob(function(blob) {
                    if (!blob) {
                        reject(new Error(cfg.strings.imageerror));
                        return;
                    }
                    resolve({blob: blob, name: png ? 'gambar.png' : 'gambar.jpg', width: w, height: h});
                }, png ? 'image/png' : 'image/jpeg', 0.85);
            };
            img.onerror = function() {
                URL.revokeObjectURL(url);
                reject(new Error(cfg.strings.imageerror));
            };
            img.src = url;
        });
    }

    /**
     * Uploads image.
     *
     * @param {*} prepared
     */
    function uploadImage(prepared) {
        var form = new FormData();
        form.append('sesskey', cfg.sesskey);
        form.append('cmid', cfg.cmid);
        form.append('image', prepared.blob, prepared.name);
        if (cfg.target) {
            form.append('targetuser', cfg.target.targetuser || 0);
            form.append('targetgroup', cfg.target.targetgroup || 0);
        }
        return fetch(cfg.uploadUrl, {method: 'POST', credentials: 'same-origin', body: form}).then(function(r) {
            return r.json();
        }).then(function(res) {
            if (!res || !res.file) {
                var failure = (res && (res.error || res.message)) || cfg.strings.imageerror;
                throw new Error(failure);
            }
            return res;
        });
    }

    /**
     * The page the student is looking at, and where its visible middle is (in page percent).
     *
     * @return {Object|null}
     */
    function visiblePage() {
        var best = null;
        var mid = window.innerHeight / 2;
        Object.keys(pages).forEach(function(num) {
            var r = pages[num].layer.getBoundingClientRect();
            var visible = Math.min(r.bottom, window.innerHeight) - Math.max(r.top, 0);
            if (visible > 0 && (!best || visible > best.visible)) {
                var cy = Math.min(Math.max(mid, r.top), r.bottom);
                best = {num: parseInt(num, 10), rect: r, visible: visible, cy: (cy - r.top) / r.height * 100};
            }
        });
        return best;
    }

    /**
     * Places image.
     *
     * @param {Object} res
     */
    function placeImage(res) {
        var target = visiblePage();
        if (!target) {
            return;
        }
        var r = target.rect;
        // About 40% of the page width, never larger than the picture itself.
        var wpx = Math.min(r.width * 0.4, res.width);
        var hpx = wpx * res.height / res.width;
        if (hpx > r.height * 0.6) {
            hpx = r.height * 0.6;
            wpx = hpx * res.width / res.height;
        }
        var w = clamp(wpx / r.width * 100);
        var h = clamp(hpx / r.height * 100);
        var it = {id: newId(), page: target.num, type: 'image', file: res.file,
            x: clamp((100 - w) / 2), y: clamp(Math.min(100 - h, Math.max(0, target.cy - h / 2))),
            w: w, h: h, color: color, by: cfg.userid};
        recordUndo([{id: it.id, before: null}]);
        items.push(it);
        createEl(it);
        selectItem(it);
        changed();
        flashHint(cfg.strings.imagehint, true);
    }

    var fileInput = null;

    /**
     * Chooses image.
     */
    function chooseImage() {
        if (!fileInput) {
            fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/*';
            fileInput.hidden = true;
            document.body.appendChild(fileInput);
            fileInput.addEventListener('change', function() {
                var file = fileInput.files && fileInput.files[0];
                fileInput.value = '';
                if (!file) {
                    return;
                }
                flashHint(cfg.strings.imageuploading, true);
                shrinkImage(file).then(uploadImage).then(function(res) {
                    submissionId = res.itemid;
                    placeImage(res);
                    return null;
                }).catch(function(err) {
                    flashHint(cfg.strings.imageerror + (err && err.message && err.message !== cfg.strings.imageerror ?
                        ': ' + err.message : ''));
                });
            });
        }
        fileInput.click();
    }

    // ------------------------------------------------ Answer areas (answer key editor for AI checking).

    var anchorMarks = [];

    /**
     * Finds the page of element.
     *
     * @param {HTMLElement} el
     */
    function pageOfElement(el) {
        var section = el.closest ? el.closest('section.docx') : null;
        var num = null;
        Object.keys(pages).forEach(function(n) {
            if (section && pages[n].layer.parentNode === section) {
                num = parseInt(n, 10);
            }
        });
        return num;
    }

    /**
     * Rel rect.
     *
     * @param {HTMLElement} el
     * @param {Number} num
     */
    function relRect(el, num) {
        var lr = pages[num].layer.getBoundingClientRect();
        var r = el.getBoundingClientRect();
        return {top: (r.top - lr.top) / lr.height * 100, bottom: (r.bottom - lr.top) / lr.height * 100};
    }

    /**
     * Highlights the answer area of every question (paragraphs and boxes), labelled with its number.
     *
     * @param {Array} list [{label, area: {blocks, regions}, active}]
     */
    function showAnchors(list) {
        anchorMarks.forEach(function(el) {
            if (el.classList.contains('mylkpd-anchor-rect')) {
                el.parentNode.removeChild(el);
            } else {
                el.classList.remove('mylkpd-anchor-block', 'mylkpd-anchor-active', 'mylkpd-anchor-q', 'mylkpd-verdict-right',
                    'mylkpd-verdict-partial', 'mylkpd-verdict-wrong', 'mylkpd-verdict-unknown');
                el.removeAttribute('data-mylkpd-anchor');
            }
        });
        anchorMarks = [];
        (list || []).forEach(function(q) {
            var area = q.area || {};
            (area.blocks || []).forEach(function(id) {
                var el = blockEls[id];
                if (el) {
                    el.classList.add('mylkpd-anchor-block');
                    el.classList.toggle('mylkpd-anchor-active', !!q.active);
                    if (q.verdict) {
                        el.classList.add('mylkpd-verdict-' + q.verdict);
                    }
                    el.classList.toggle('mylkpd-anchor-q', !!q.question);
                    el.setAttribute('data-mylkpd-anchor', q.label);
                    anchorMarks.push(el);
                }
            });
            (area.regions || []).forEach(function(r) {
                var page = pages[r.page];
                if (!page) {
                    return;
                }
                var box = document.createElement('div');
                box.className = 'mylkpd-anchor-rect' + (q.active ? ' mylkpd-anchor-active' : '') +
                    (q.verdict ? ' mylkpd-verdict-' + q.verdict : '') + (q.question ? ' mylkpd-anchor-q' : '');
                box.style.left = r.x + '%';
                box.style.top = r.y + '%';
                box.style.width = r.w + '%';
                box.style.height = r.h + '%';
                box.setAttribute('data-mylkpd-anchor', q.label);
                page.html.appendChild(box);
                anchorMarks.push(box);
            });
        });
    }

    /**
     * Finds numbered questions ("1. ...", "2) ...") in the Word worksheet and suggests their answer area:
     * the paragraphs/cells up to the next question, and the same stretch of the page as a box.
     *
     * @return {Array} [{label, question, blocks, original, region}]
     */
    function detectQuestions() {
        var ids = Object.keys(blockEls).sort(function(a, b) {
            return a - b;
        });
        var found = [];
        ids.forEach(function(id) {
            var el = blockEls[id];
            var m = /^\s*(?:soal|no\.?|nomor)?\s*(\d{1,2})\s*[.):]\s*(\S[\s\S]{3,})$/i.exec(el.textContent.replace(/\s+/g, ' '));
            if (m && !el.closest('td, th')) {
                found.push({label: m[1], question: m[2].trim(), id: id});
            }
        });
        return found.map(function(q, i) {
            var next = found[i + 1];
            var start = ids.indexOf(q.id);
            var end = next ? ids.indexOf(next.id) : Math.min(ids.length, start + 11);
            var blocks = ids.slice(start + 1, end).slice(0, 10);
            var original = {};
            blocks.forEach(function(id) {
                original[id] = blockEls[id].textContent;
            });
            var region = null;
            var num = pageOfElement(blockEls[q.id]);
            if (num) {
                var top = relRect(blockEls[q.id], num).bottom;
                var nextOnPage = next && pageOfElement(blockEls[next.id]) === num;
                var bottom = nextOnPage ? relRect(blockEls[next.id], num).top : Math.min(98, top + 18);
                if (bottom - top >= 2) {
                    region = {page: num, x: 5, y: clamp(top), w: 90, h: clamp(bottom - top)};
                }
            }
            return {label: q.label, question: q.question, blocks: blocks, original: original, region: region};
        });
    }

    /**
     * Text inside a box on a page: the Word paragraphs whose middle is inside it, or the words of the
     * PDF's own text layer that sit inside it (in reading order).
     *
     * @param {Object} r {page, x, y, w, h} in page percent
     * @return {Promise} resolves with the text
     */
    function textInRegion(r) {
        var inside = function(x, y) {
            return x >= r.x && x <= r.x + r.w && y >= r.y - 1 && y <= r.y + r.h + 1;
        };
        var pdfPage = pdfPageObjs[r.page];
        if (pdfPage) {
            var vp = pdfPage.getViewport({scale: 1});
            return pdfPage.getTextContent().then(function(content) {
                var parts = [];
                content.items.forEach(function(it) {
                    if (!it.str || !it.str.trim()) {
                        return;
                    }
                    var pt = vp.convertToViewportPoint(it.transform[4], it.transform[5]);
                    var x = pt[0] / vp.width * 100;
                    var y = pt[1] / vp.height * 100;
                    if (inside(x, y)) {
                        parts.push({x: x, y: y, s: it.str});
                    }
                });
                parts.sort(function(a, b) {
                    return Math.abs(a.y - b.y) > 1.2 ? a.y - b.y : a.x - b.x;
                });
                var text = '';
                parts.forEach(function(part, i) {
                    var gap = ' ';
                    if (i === 0) {
                        gap = '';
                    } else if (Math.abs(part.y - parts[i - 1].y) > 1.2) {
                        gap = '\n'; // A new line of text.
                    }
                    text += gap + part.s;
                });
                return text.replace(/[ \t]+/g, ' ').trim();
            });
        }
        var page = pages[r.page];
        if (!page) {
            return Promise.resolve('');
        }
        var lr = page.layer.getBoundingClientRect();
        var texts = [];
        Object.keys(blockEls).sort(function(a, b) {
            return a - b;
        }).forEach(function(id) {
            var el = blockEls[id];
            var br = el.getBoundingClientRect();
            if (pageOfElement(el) !== r.page || !br.height) {
                return;
            }
            var cx = (br.left + br.width / 2 - lr.left) / lr.width * 100;
            var cy = (br.top + br.height / 2 - lr.top) / lr.height * 100;
            if (inside(cx, cy) && el.textContent.trim()) {
                texts.push(el.textContent.trim());
            }
        });
        return Promise.resolve(texts.join('\n'));
    }

    /**
     * Dispatches.
     *
     * @param {String} name
     * @param {*} detail
     */
    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent('mylkpd:' + name, {detail: detail}));
    }

    /**
     * Click a paragraph/cell to add it to the active question's area, or drag to draw a box on the page.
     */
    function bindAnchorEditor() {
        container.classList.add('mylkpd-anchoring');
        Object.keys(pages).forEach(function(n) {
            var num = parseInt(n, 10);
            var layer = pages[n].layer;
            var start = null;
            var draft = null;
            layer.addEventListener('pointerdown', function(e) {
                if (e.button !== 0) {
                    return;
                }
                e.preventDefault();
                start = {p: pointFrom(layer, e), cx: e.clientX, cy: e.clientY};
                layer.setPointerCapture(e.pointerId);
            });
            layer.addEventListener('pointermove', function(e) {
                if (!start || (Math.abs(e.clientX - start.cx) + Math.abs(e.clientY - start.cy) < 6 && !draft)) {
                    return;
                }
                var p = pointFrom(layer, e);
                if (!draft) {
                    draft = document.createElement('div');
                    draft.className = 'mylkpd-anchor-rect mylkpd-anchor-draft';
                    pages[n].html.appendChild(draft);
                }
                draft.style.left = Math.min(p.x, start.p.x) + '%';
                draft.style.top = Math.min(p.y, start.p.y) + '%';
                draft.style.width = Math.abs(p.x - start.p.x) + '%';
                draft.style.height = Math.abs(p.y - start.p.y) + '%';
            });
            layer.addEventListener('pointerup', function(e) {
                if (!start) {
                    return;
                }
                var p = pointFrom(layer, e);
                if (draft) {
                    draft.parentNode.removeChild(draft);
                    draft = null;
                    emit('anchorrect', {page: num, x: Math.min(p.x, start.p.x), y: Math.min(p.y, start.p.y),
                        w: clamp(Math.abs(p.x - start.p.x)), h: clamp(Math.abs(p.y - start.p.y))});
                } else {
                    // A click: find the paragraph or table cell under the pointer.
                    var under = document.elementsFromPoint(e.clientX, e.clientY);
                    for (var i = 0; i < under.length; i++) {
                        var block = under[i].closest && under[i].closest('[data-mylkpd-block]');
                        if (block) {
                            emit('anchorblock', {id: block.getAttribute('data-mylkpd-block'), text: block.textContent});
                            break;
                        }
                    }
                }
                start = null;
            });
        });
    }

    // ------------------------------------------------ Toolbar and buttons.

    /**
     * Sets tool.
     *
     * @param {*} t
     */
    function setTool(t) {
        tool = t;
        container.className = container.className.replace(/\bmylkpd-mode-\S+/g, '').trim() + ' mylkpd-mode-' + t;
        Array.prototype.forEach.call(document.querySelectorAll('[data-mylkpd-tool]'), function(b) {
            var on = b.getAttribute('data-mylkpd-tool') === t;
            b.classList.toggle('btn-primary', on);
            b.classList.toggle('btn-outline-secondary', !on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-mylkpd-insert]'), function(b) {
            var on = b.getAttribute('data-mylkpd-insert') === t;
            b.classList.toggle('btn-primary', on);
            b.classList.toggle('btn-outline-secondary', !on);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-mylkpd-format]'), function(b) {
            b.disabled = t !== 'type';
        });
        if (hintEl) {
            hintEl.textContent = (t === 'math' || t === 'link') ? cfg.strings.clicktoplace : hintText;
        }
        updateUndo();
    }

    /**
     * Warns about unsaved.
     *
     * @param {Event} e
     */
    function warnUnsaved(e) {
        if (inflight || hasPending()) {
            e.preventDefault();
            e.returnValue = '';
        }
    }

    var namesToggle = document.querySelector('[data-mylkpd-names]');
    if (namesToggle) {
        namesToggle.addEventListener('change', function() {
            container.classList.toggle('mylkpd-hide-names', !namesToggle.checked);
        });
    }

    /**
     * Keeps the toolbar below whatever the theme pins to the top of the screen: the navbar, and in some
     * themes the activity's secondary navigation (MyLKPD · Settings · Advanced grading).
     */
    function stickyOffset() {
        var page = document.getElementById('page');
        var scroller = (page && /(auto|scroll)/.test(window.getComputedStyle(page).overflowY)) ? page : null;
        var baseTop = scroller ? scroller.getBoundingClientRect().top : 0;
        var toolbar = document.querySelector('.mylkpd-toolbar');
        var top = 0;
        var bars = document.querySelectorAll('header, nav, .navbar, .secondary-navigation, .sticky-top, .fixed-top, ' +
            '[class*="sticky"], [class*="fixed"]');
        Array.prototype.forEach.call(bars, function(el) {
            if (el === toolbar || (toolbar && (toolbar.contains(el) || el.contains(toolbar))) || container.contains(el)) {
                return;
            }
            var cs = window.getComputedStyle(el);
            if ((cs.position !== 'fixed' && cs.position !== 'sticky') || cs.display === 'none' ||
                    cs.visibility === 'hidden') {
                return;
            }
            var r = el.getBoundingClientRect();
            // Only bars across the top: not side drawers, small buttons or footers.
            if (r.height === 0 || r.height > window.innerHeight / 3 || r.width < window.innerWidth / 3) {
                return;
            }
            var bottom;
            if (cs.position === 'fixed') {
                if (r.top > window.innerHeight / 2) {
                    return;
                }
                bottom = r.bottom - baseTop;
            } else {
                var stick = parseFloat(cs.top);
                if (isNaN(stick)) {
                    return;
                }
                bottom = stick + r.height;
            }
            top = Math.max(top, Math.round(bottom));
        });
        document.documentElement.style.setProperty('--mylkpd-sticky-top', top + 'px');
    }

    if (!readonly) {
        stickyOffset();
        window.addEventListener('resize', stickyOffset);
        // Some themes pin their bars only after scrolling, so check again (at most every 400 ms).
        var stickyTimer = null;
        var onScroll = function() {
            if (!stickyTimer) {
                stickyTimer = setTimeout(function() {
                    stickyTimer = null;
                    stickyOffset();
                }, 400);
            }
        };
        window.addEventListener('scroll', onScroll, true);
        Array.prototype.forEach.call(document.querySelectorAll('[data-mylkpd-tool]'), function(b) {
            b.addEventListener('click', function() {
                setTool(b.getAttribute('data-mylkpd-tool'));
            });
        });
        var swatches = document.querySelectorAll('[data-mylkpd-color]');
        Array.prototype.forEach.call(swatches, function(b) {
            b.addEventListener('click', function() {
                color = b.getAttribute('data-mylkpd-color');
                Array.prototype.forEach.call(swatches, function(o) {
                    o.classList.toggle('active', o === b);
                    o.setAttribute('aria-pressed', o === b ? 'true' : 'false');
                });
            });
        });
        var widthSelect = document.querySelector('[data-mylkpd-width]');
        if (widthSelect) {
            widthSelect.addEventListener('change', function() {
                width = parseInt(widthSelect.value, 10) || 4;
            });
        }
        var undoBtn = document.querySelector('[data-mylkpd-action="undo"]');
        if (undoBtn) {
            undoBtn.addEventListener('mousedown', function(e) {
                e.preventDefault();
            });
            undoBtn.addEventListener('click', function() {
                if (tool === 'type') {
                    focusTarget();
                    document.execCommand('undo');
                } else {
                    // Leave the text box first, so an empty box is cleaned up before undoing.
                    if (document.activeElement && document.activeElement.blur) {
                        document.activeElement.blur();
                    }
                    undo();
                }
            });
        }
        var redoBtn = document.querySelector('[data-mylkpd-action="redo"]');
        if (redoBtn) {
            redoBtn.addEventListener('mousedown', function(e) {
                e.preventDefault();
            });
            redoBtn.addEventListener('click', function() {
                focusTarget();
                document.execCommand('redo');
            });
        }
        Array.prototype.forEach.call(document.querySelectorAll('[data-mylkpd-format]'), function(b) {
            b.addEventListener('mousedown', function(e) {
                e.preventDefault(); // Keep the cursor in the paragraph.
            });
            b.addEventListener('click', function() {
                if (!focusTarget(true)) {
                    flashHint(cfg.strings.needfocus);
                    return;
                }
                var cmd = b.getAttribute('data-mylkpd-format');
                document.execCommand('styleWithCSS', false, true);
                if (cmd === 'foreColor') {
                    document.execCommand('foreColor', false, color);
                } else if (cmd === 'hiliteColor') {
                    document.execCommand('hiliteColor', false, '#fff176');
                } else {
                    document.execCommand(cmd);
                }
            });
        });
        document.addEventListener('keydown', function(e) {
            var t = e.target || {};
            if (tool !== 'type' && (e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'z' &&
                    t.tagName !== 'TEXTAREA' && t.tagName !== 'INPUT' && !t.isContentEditable) {
                e.preventDefault();
                undo();
            }
        });

        var saveBtn = document.querySelector('[data-mylkpd-action="save"]');
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                if (document.activeElement && document.activeElement.blur) {
                    document.activeElement.blur();
                }
                saveBtn.disabled = true;
                setState('saving');
                // Wait for any request in progress, then send everything that is left.
                flush().then(function() {
                    return flush();
                }).then(function() {
                    saveBtn.disabled = false;
                    if (failed || hasPending()) {
                        setState('saveerror');
                        tell(cfg.strings.saveerror + (lastError ? '\n\n' + lastError : ''), 'error');
                    } else {
                        setState('saved');
                    }
                    return null;
                }).catch(Notification.exception);
            });
        }
        var submitBtn = document.querySelector('[data-mylkpd-action="submit"]');
        if (submitBtn) {
            submitBtn.addEventListener('click', function() {
                var cancelled = {};
                ask(cfg.strings.confirmsubmit).then(function(ok) {
                    if (!ok) {
                        return cancelled;
                    }
                    if (document.activeElement && document.activeElement.blur) {
                        document.activeElement.blur();
                    }
                    submitBtn.disabled = true;
                    return flush();
                }).then(function(step) {
                    return step === cancelled ? cancelled : flush();
                }).then(function(step) {
                    if (step === cancelled) {
                        return null;
                    }
                    if (failed || hasPending()) {
                        submitBtn.disabled = false;
                        setState('saveerror');
                        tell(cfg.strings.saveerror + (lastError ? '\n\n' + lastError : ''), 'error');
                        return null;
                    }
                    window.removeEventListener('beforeunload', warnUnsaved);
                    document.getElementById('mylkpd-submitfinal').value = '1';
                    submitBtn.form.submit();
                    return null;
                }).catch(Notification.exception);
            });
        }

        window.addEventListener('beforeunload', warnUnsaved);
        // On the grading page, save the annotations before the grade form is sent.
        Array.prototype.forEach.call(document.querySelectorAll('form'), function(form) {
            if (form.id === 'mylkpd-form' || form.contains(container)) {
                return;
            }
            form.addEventListener('submit', function(e) {
                if (form.getAttribute('data-mylkpd-saved') || (!inflight && !hasPending())) {
                    window.removeEventListener('beforeunload', warnUnsaved);
                    return;
                }
                e.preventDefault();
                var submitter = e.submitter;
                setState('saving');
                flush().then(flush).then(function() {
                    form.setAttribute('data-mylkpd-saved', '1');
                    window.removeEventListener('beforeunload', warnUnsaved);
                    if (form.requestSubmit) {
                        form.requestSubmit(submitter || undefined);
                    } else {
                        form.submit();
                    }
                    return null;
                }).catch(Notification.exception);
            });
        });
        window.addEventListener('pagehide', function() {
            // Last chance to save when the tab closes.
            if (hasPending() && navigator.sendBeacon && window.M && window.M.cfg) {
                // The core/ajax module cannot run while the page unloads: send the same call to Moodle's service endpoint.
                navigator.sendBeacon(window.M.cfg.wwwroot + '/lib/ajax/service.php?sesskey=' + window.M.cfg.sesskey +
                    '&info=mod_mylkpd_sync_worksheet', new Blob([JSON.stringify([{index: 0,
                    methodname: 'mod_mylkpd_sync_worksheet', args: requestArgs(collectOps())}])], {type: 'application/json'}));
            }
        });
        if (cfg.collab) {
            setInterval(function() {
                if (!document.hidden) {
                    flush();
                }
            }, 3000);
        }
        setTool(tool);
        setupInsertTools();
    }

    // ------------------------------------------------ Start.

    var width0 = Math.min(container.clientWidth || 800, 1200);
    loadKatex(cfg.katexBase).then(function(k) {
        katex = k;
        return null;
    }, function(err) {
        window.console.warn('MyLKPD: equations unavailable', err);
    }).then(function() {
        return cfg.mode === 'docx' ? renderDocx() : renderPdf(width0);
    }).then(function() {
        rebuild();
        hideBlankPages();
        if (cfg.anchorEditor) {
            bindAnchorEditor();
        }
        current = {showAnchors: showAnchors, detectQuestions: detectQuestions, textInRegion: textInRegion, canType: canType,
            workmode: cfg.workmode};
        emit('ready', current);
        if (cfg.collab) {
            flush(); // Announce presence and fetch anything newer than the page.
        }
        return null;
    }).catch(function(err) {
        container.textContent = cfg.strings.loaderror;
        var detail = document.createElement('div');
        detail.className = 'small text-muted mt-1';
        detail.textContent = (err && (err.message || err.name)) ? String(err.message || err.name) : String(err);
        container.appendChild(detail);
        window.console.error('MyLKPD:', err);
    });
}

/**
 * Fills in the settings that may be missing from the page configuration.
 *
 * @param {Object} cfg configuration from the page
 * @return {Object} the same object, completed
 */
function withDefaults(cfg) {
    var defaults = {
        members: {}, defaultTool: 'text', teacherIds: [], colors: ['#1a47b8', '#c62828'], rev: 0, submissionId: 0,
        imageBase: '', tableBorderColors: ['#111111', '#adb5bd', '#1a47b8', '#c62828', '#2e7d32', '#f9a825'],
        tableFillColors: ['#fff3cd', '#d1e7dd', '#cfe2ff', '#f8d7da', '#e2e3e5', '#ffe5d0'],
    };
    Object.keys(defaults).forEach(function(key) {
        if (cfg[key] === undefined || cfg[key] === null || (Array.isArray(defaults[key]) && !Array.isArray(cfg[key]))) {
            cfg[key] = defaults[key];
        }
    });
    if (!cfg.colors.length) {
        cfg.colors = defaults.colors;
    }
    return cfg;
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
 * Starts the worksheet whose settings are stored on an element (data-mylkpd-config).
 *
 * @param {String} configId id of that element
 */
export const init = (configId) => {
    const holder = document.getElementById(configId);
    if (holder) {
        start(JSON.parse(holder.getAttribute('data-mylkpd-config')));
    }
};

/**
 * The worksheet started on this page, or null while it is still loading.
 *
 * @return {Object|null}
 */
export const getCurrent = () => current;
