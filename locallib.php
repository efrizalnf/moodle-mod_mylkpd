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

/**
 * Internal helpers for mod_mylkpd.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

/** PDF.js is bundled with the plugin (js/pdfjs), so no internet access is needed. */
define('MYLKPD_PDFJS_PATH', '/mod/mylkpd/js/pdfjs');
/** docx-preview + JSZip are bundled with the plugin (js/docx). */
define('MYLKPD_DOCX_PATH', '/mod/mylkpd/js/docx');
/** KaTeX (equations) is bundled with the plugin (js/katex). */
define('MYLKPD_KATEX_PATH', '/mod/mylkpd/js/katex');

/**
 * Pen colours students can choose from. The first one is the default.
 *
 * @return string[]
 */
function mylkpd_colors(): array {
    return ['#1a47b8', '#c62828', '#111111', '#2e7d32', '#f9a825'];
}

/**
 * Border colours offered for tables.
 *
 * @return string[]
 */
function mylkpd_table_border_colors(): array {
    return ['#111111', '#adb5bd', '#1a47b8', '#c62828', '#2e7d32', '#f9a825'];
}

/**
 * Light fill colours offered for table header rows and cells.
 *
 * @return string[]
 */
function mylkpd_table_fill_colors(): array {
    return ['#fff3cd', '#d1e7dd', '#cfe2ff', '#f8d7da', '#e2e3e5', '#ffe5d0'];
}

/**
 * Stores the display mode chosen for this activity.
 *
 * @param stdClass $mylkpd
 * @param string $mode pdf|docx
 */
function mylkpd_set_rendermode(stdClass $mylkpd, string $mode): void {
    global $DB;
    if (($mylkpd->rendermode ?? '') !== $mode) {
        $DB->set_field('mylkpd', 'rendermode', $mode, ['id' => $mylkpd->id]);
        $mylkpd->rendermode = $mode;
    }
}

/**
 * Finds how the worksheet should be shown.
 *
 * PDF files are shown as they are. Word/ODT files are converted to PDF when the site has a
 * document converter. When it has none, .docx files are rendered in the browser instead.
 * The choice is remembered per activity so answer positions never jump between modes.
 *
 * @param stdClass $mylkpd
 * @param context_module $context
 * @return array ['status' => ready|pending|failed|nofile, 'mode' => pdf|docx, 'file' => stored_file]
 */
function mylkpd_get_document(stdClass $mylkpd, context_module $context): array {
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_mylkpd', 'worksheet', 0, 'id', false);
    if (!$files) {
        return ['status' => 'nofile'];
    }
    $source = reset($files);
    $ext = core_text::strtolower(pathinfo($source->get_filename(), PATHINFO_EXTENSION));
    $isdocx = ($ext === 'docx');
    $mode = $mylkpd->rendermode ?? '';

    if (($mylkpd->workmode ?? '') === 'edit') {
        // Editing the text needs the real document structure, so always render the .docx itself.
        return $isdocx ? ['status' => 'ready', 'mode' => 'docx', 'file' => $source] : ['status' => 'editneedsdocx'];
    }
    if ($source->get_mimetype() === 'application/pdf' || $ext === 'pdf') {
        return ['status' => 'ready', 'mode' => 'pdf', 'file' => $source];
    }
    if ($mode === 'docx' && $isdocx) {
        return ['status' => 'ready', 'mode' => 'docx', 'file' => $source];
    }

    $pdfs = $fs->get_area_files($context->id, 'mod_mylkpd', 'pdf', 0, 'id', false);
    if ($pdfs) {
        mylkpd_set_rendermode($mylkpd, 'pdf');
        return ['status' => 'ready', 'mode' => 'pdf', 'file' => reset($pdfs)];
    }

    $status = 'failed';
    try {
        $converter = new \core_files\converter();
        if ($converter->can_convert_storedfile_to($source, 'pdf')) {
            $conversion = $converter->start_conversion($source, 'pdf');
            $state = (int) $conversion->get('status');
            if ($state === \core_files\conversion::STATUS_COMPLETE && ($dest = $conversion->get_destfile())) {
                $pdf = mylkpd_store_pdf($context, $dest);
                mylkpd_set_rendermode($mylkpd, 'pdf');
                return ['status' => 'ready', 'mode' => 'pdf', 'file' => $pdf];
            }
            $status = ($state === \core_files\conversion::STATUS_FAILED) ? 'failed' : 'pending';
        }
    } catch (\Throwable $e) {
        debugging('MyLKPD conversion error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        $status = 'failed';
    }

    if ($isdocx) {
        // No (working) converter: show the Word file directly in the browser.
        mylkpd_set_rendermode($mylkpd, 'docx');
        return ['status' => 'ready', 'mode' => 'docx', 'file' => $source];
    }
    return ['status' => $status];
}

/**
 * Copies the converted PDF into the plugin's pdf file area (safe against concurrent requests).
 *
 * @param context_module $context
 * @param stored_file $source
 * @return stored_file
 */
function mylkpd_store_pdf(context_module $context, stored_file $source): stored_file {
    $fs = get_file_storage();
    $record = [
        'contextid' => $context->id,
        'component' => 'mod_mylkpd',
        'filearea' => 'pdf',
        'itemid' => 0,
        'filepath' => '/',
        'filename' => 'mylkpd.pdf',
    ];
    try {
        return $fs->create_file_from_storedfile($record, $source);
    } catch (stored_file_creation_exception $e) {
        return $fs->get_file($context->id, 'mod_mylkpd', 'pdf', 0, '/', 'mylkpd.pdf');
    }
}

/**
 * Validates one answer item sent by the browser.
 *
 * Coordinates are percentages of the page (0-100). Supported types:
 * text, check, cross (x, y), ellipse, highlight (x, y, w, h), line (x1, y1, x2, y2), pen (points).
 *
 * @param mixed $a
 * @return array|null clean item, or null when it should not be stored
 */
function mylkpd_clean_item($a): ?array {
    if (!is_array($a)) {
        return null;
    }
    $colors = mylkpd_colors();
    $pct = function($v): float {
        return min(100, max(0, round(is_numeric($v) ? (float) $v : 0, 2)));
    };
    $type = (string) ($a['type'] ?? '');
    $item = [
        'page' => max(1, min(1000, (int) ($a['page'] ?? 1))),
        'type' => $type,
        'color' => in_array($a['color'] ?? '', $colors, true) ? $a['color'] : $colors[0],
    ];
    switch ($type) {
        case 'text':
            $text = core_text::substr(clean_param((string) ($a['text'] ?? ''), PARAM_TEXT), 0, 5000);
            if (trim($text) === '') {
                return null;
            }
            $item += ['x' => $pct($a['x'] ?? 0), 'y' => $pct($a['y'] ?? 0), 'text' => $text];
            if (in_array($a['align'] ?? '', ['left', 'center', 'right', 'justify'], true)) {
                $item['align'] = $a['align'];
            }
            break;
        case 'check':
        case 'cross':
            $item += ['x' => $pct($a['x'] ?? 0), 'y' => $pct($a['y'] ?? 0)];
            break;
        case 'math':
            // LaTeX source. Only ever shown through KaTeX (trust off) or as plain text.
            $tex = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) ($a['tex'] ?? '')));
            if ($tex === '' || core_text::strlen($tex) > 1000) {
                return null;
            }
            $item += ['x' => $pct($a['x'] ?? 0), 'y' => $pct($a['y'] ?? 0), 'tex' => $tex];
            break;
        case 'table':
            // A table the student (or teacher) put on the page: cells are plain text.
            $rows = max(1, min(20, (int) ($a['rows'] ?? 2)));
            $cols = max(1, min(8, (int) ($a['cols'] ?? 2)));
            $cells = [];
            for ($r = 0; $r < $rows; $r++) {
                for ($c = 0; $c < $cols; $c++) {
                    $value = $a['cells'][$r][$c] ?? '';
                    $cells[$r][$c] = core_text::substr(clean_param(is_scalar($value) ? (string) $value : '', PARAM_TEXT), 0, 500);
                }
            }
            $fills = [];
            foreach ((array) ($a['fills'] ?? []) as $key => $fill) {
                if (preg_match('/^(\d+)-(\d+)$/', (string) $key, $m) && $m[1] < $rows && $m[2] < $cols &&
                        in_array($fill, mylkpd_table_fill_colors(), true)) {
                    $fills[$key] = $fill;
                }
            }
            $item += [
                'x' => $pct($a['x'] ?? 0), 'y' => $pct($a['y'] ?? 0), 'w' => max(10, $pct($a['w'] ?? 60)),
                'rows' => $rows, 'cols' => $cols, 'cells' => $cells,
                'bstyle' => in_array($a['bstyle'] ?? '', ['solid', 'dashed', 'dotted', 'none'], true) ? $a['bstyle'] : 'solid',
                'bwidth' => in_array((int) ($a['bwidth'] ?? 1), [1, 2, 3], true) ? (int) $a['bwidth'] : 1,
                'bcolor' => in_array($a['bcolor'] ?? '', mylkpd_table_border_colors(), true) ? $a['bcolor'] : '#111111',
                'head' => in_array($a['head'] ?? '', mylkpd_table_fill_colors(), true) ? $a['head'] : '',
                'fills' => (object) $fills,
            ];
            break;
        case 'note':
            // Teacher's sticky note on the page (only teachers may create them, see mylkpd_sync()).
            $text = trim(core_text::substr(clean_param((string) ($a['text'] ?? ''), PARAM_TEXT), 0, 2000));
            if ($text === '') {
                return null;
            }
            $item += ['x' => $pct($a['x'] ?? 0), 'y' => $pct($a['y'] ?? 0), 'text' => $text];
            break;
        case 'image':
            // A picture the student uploaded; the file lives in the submission's answerimage area.
            $file = clean_param((string) ($a['file'] ?? ''), PARAM_FILE);
            if (!preg_match('/^img_[a-z0-9_]+\.(jpg|png|gif|webp)$/', $file)) {
                return null;
            }
            $item += ['x' => $pct($a['x'] ?? 0), 'y' => $pct($a['y'] ?? 0),
                'w' => max(2, $pct($a['w'] ?? 30)), 'h' => max(2, $pct($a['h'] ?? 20)), 'file' => $file];
            break;
        case 'link':
            $url = clean_param(trim((string) ($a['url'] ?? '')), PARAM_URL);
            if (!preg_match('~^https?://~i', $url)) {
                return null;
            }
            $label = trim(core_text::substr(clean_param((string) ($a['text'] ?? ''), PARAM_TEXT), 0, 200));
            $item += ['x' => $pct($a['x'] ?? 0), 'y' => $pct($a['y'] ?? 0), 'text' => $label, 'url' => $url];
            break;
        case 'ellipse':
        case 'highlight':
            $item += ['x' => $pct($a['x'] ?? 0), 'y' => $pct($a['y'] ?? 0),
                'w' => $pct($a['w'] ?? 0), 'h' => $pct($a['h'] ?? 0)];
            if ($item['w'] <= 0 && $item['h'] <= 0) {
                return null;
            }
            break;
        case 'line':
            $item += ['x1' => $pct($a['x1'] ?? 0), 'y1' => $pct($a['y1'] ?? 0),
                'x2' => $pct($a['x2'] ?? 0), 'y2' => $pct($a['y2'] ?? 0)];
            break;
        case 'pen':
            $points = [];
            foreach (array_slice((array) ($a['points'] ?? []), 0, 3000) as $pt) {
                if (is_array($pt) && count($pt) >= 2) {
                    $points[] = [$pct($pt[0]), $pct($pt[1])];
                }
            }
            if (count($points) < 2) {
                return null;
            }
            $item['points'] = $points;
            break;
        default:
            return null;
    }
    if (in_array($type, ['ellipse', 'line', 'pen'], true)) {
        $width = (int) ($a['width'] ?? 4);
        $item['width'] = in_array($width, [2, 4, 8], true) ? $width : 4;
    }
    return $item;
}

/**
 * Reads the stored answers. Also understands the old format (a plain list of items).
 *
 * Stored format: {"items": {id: item+{v,by} | {deleted,v}}, "blocks": {paragraph: {html,v,by}}}
 *
 * @param string|null $json
 * @return array ['items' => array, 'blocks' => array]
 */
function mylkpd_decode_data(?string $json): array {
    $d = json_decode($json ?? '', true);
    if (!is_array($d)) {
        return ['items' => [], 'blocks' => []];
    }
    if (!array_key_exists('items', $d) && !array_key_exists('blocks', $d)) {
        $items = [];
        foreach (array_values($d) as $i => $old) {
            if ($clean = mylkpd_clean_item($old)) {
                $items['m' . $i] = $clean + ['v' => 0, 'by' => 0];
            }
        }
        return ['items' => $items, 'blocks' => []];
    }
    return [
        'items' => is_array($d['items'] ?? null) ? $d['items'] : [],
        'blocks' => is_array($d['blocks'] ?? null) ? $d['blocks'] : [],
    ];
}

/**
 * Data sent to the browser when a page is opened: live items (with id) and edited paragraphs.
 *
 * @param array $data from mylkpd_decode_data()
 * @return array ['items' => list, 'blocks' => map]
 */
function mylkpd_client_data(array $data): array {
    $items = [];
    foreach ($data['items'] as $id => $item) {
        if (empty($item['deleted'])) {
            $items[] = ['id' => (string) $id] + $item;
        }
    }
    $blocks = [];
    foreach ($data['blocks'] as $id => $block) {
        if (isset($block['html'])) {
            $blocks[(string) $id] = $block;
        }
    }
    return ['items' => $items, 'blocks' => (object) $blocks];
}

/**
 * The user's group for this activity (first group in the activity's grouping), or 0.
 *
 * @param cm_info|stdClass $cm
 * @param int $userid
 * @return int
 */
function mylkpd_get_user_groupid($cm, int $userid): int {
    $groups = groups_get_all_groups($cm->course, $userid, $cm->groupingid, 'g.id');
    return $groups ? (int) reset($groups)->id : 0;
}

/**
 * Finds (and optionally creates) the submission a user works on: their own, or their group's.
 *
 * @param stdClass $mylkpd
 * @param cm_info|stdClass $cm
 * @param int $userid
 * @param bool $create
 * @return stdClass|null null in group mode when the user has no group
 */
function mylkpd_find_submission(stdClass $mylkpd, $cm, int $userid, bool $create = false): ?stdClass {
    global $DB;
    if (!empty($mylkpd->teamsubmission)) {
        $groupid = mylkpd_get_user_groupid($cm, $userid);
        if (!$groupid) {
            return null;
        }
        $params = ['mylkpdid' => $mylkpd->id, 'groupid' => $groupid];
    } else {
        $params = ['mylkpdid' => $mylkpd->id, 'userid' => $userid, 'groupid' => 0];
    }
    $sub = $DB->get_record('mylkpd_submissions', $params);
    if (!$sub && $create) {
        $now = time();
        $sub = (object) ($params + [
            'userid' => $userid,
            'answers' => '{"items":{},"blocks":{}}',
            'status' => MYLKPD_STATUS_DRAFT,
            'revision' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timegraded' => 0,
        ]);
        $sub->id = $DB->insert_record('mylkpd_submissions', $sub);
    }
    return $sub ?: null;
}

/**
 * Which submission a teacher is working on from the grading page.
 *
 * @param stdClass $mylkpd
 * @param int $targetuser student (individual work)
 * @param int $targetgroup group (group work)
 * @return array ['userid' => int, 'groupid' => int]
 */
function mylkpd_teacher_target(stdClass $mylkpd, int $targetuser, int $targetgroup): array {
    if (!empty($mylkpd->teamsubmission)) {
        if ($targetgroup <= 0) {
            throw new moodle_exception('invalidgroupid');
        }
        return ['userid' => 0, 'groupid' => $targetgroup];
    }
    if ($targetuser <= 0) {
        throw new moodle_exception('invaliduser');
    }
    return ['userid' => $targetuser, 'groupid' => 0];
}

/**
 * The submission being edited: the user's own (or their group's), or the one a teacher grades.
 *
 * @param stdClass $mylkpd
 * @param cm_info|stdClass $cm
 * @param int $userid
 * @param array|null $teacherfor from mylkpd_teacher_target(), null for students
 * @param bool $create
 * @return stdClass|null
 */
function mylkpd_target_submission(stdClass $mylkpd, $cm, int $userid, ?array $teacherfor, bool $create): ?stdClass {
    global $DB;
    if (!$teacherfor) {
        return mylkpd_find_submission($mylkpd, $cm, $userid, $create);
    }
    $params = !empty($mylkpd->teamsubmission)
        ? ['mylkpdid' => $mylkpd->id, 'groupid' => $teacherfor['groupid']]
        : ['mylkpdid' => $mylkpd->id, 'userid' => $teacherfor['userid'], 'groupid' => 0];
    $sub = $DB->get_record('mylkpd_submissions', $params);
    if (!$sub && $create) {
        $now = time();
        $sub = (object) ($params + [
            'userid' => $teacherfor['userid'],
            'answers' => '{"items":{},"blocks":{}}',
            'status' => MYLKPD_STATUS_DRAFT,
            'revision' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timegraded' => 0,
        ]);
        $sub->id = $DB->insert_record('mylkpd_submissions', $sub);
    }
    return $sub ?: null;
}

/**
 * Saves changes from one browser and returns everything that changed since $sincerev.
 *
 * Every item and paragraph carries the revision of its last change ("v"), so each browser only
 * receives what is new to it. Writes are serialised with a Moodle lock per submission.
 *
 * @param stdClass $mylkpd
 * @param cm_info|stdClass $cm
 * @param int $userid
 * @param int $sincerev
 * @param array $ops list of ['op' => put|del, 'kind' => item|block, 'id' => string, ...]
 * @param string $block paragraph the user is editing now ('' if none)
 * @return array
 */
function mylkpd_sync(stdClass $mylkpd, $cm, int $userid, int $sincerev, array $ops, string $block,
        ?array $teacherfor = null): array {
    global $DB;

    $isteacher = $teacherfor !== null;
    if ($isteacher) {
        $key = $mylkpd->teamsubmission ? 'g' . $teacherfor['groupid'] : 'u' . $teacherfor['userid'];
    } else {
        $key = $mylkpd->teamsubmission ? 'g' . mylkpd_get_user_groupid($cm, $userid) : 'u' . $userid;
        if ($key === 'g0') {
            throw new moodle_exception('nogroup', 'mod_mylkpd');
        }
    }
    $lock = \core\lock\lock_config::get_lock_factory('mod_mylkpd_sync')
        ->get_lock('sub_' . $mylkpd->id . '_' . $key, 10);
    if (!$lock) {
        throw new moodle_exception('locktimeout', 'mod_mylkpd');
    }

    try {
        $sub = mylkpd_target_submission($mylkpd, $cm, $userid, $teacherfor, true);
        $data = mylkpd_decode_data($sub->answers);
        $changed = false;

        // Teachers may annotate at any time; students only until they submit, and only while it is open.
        $studentcanwork = $sub->status != MYLKPD_STATUS_SUBMITTED && mylkpd_can_work($mylkpd);
        if ($ops && ($isteacher || $studentcanwork)) {
            $live = count(array_filter($data['items'], function($i) {
                return empty($i['deleted']);
            }));
            foreach (array_slice($ops, 0, 500) as $op) {
                if (!is_array($op)) {
                    continue;
                }
                $id = clean_param((string) ($op['id'] ?? ''), PARAM_ALPHANUMEXT);
                if ($id === '' || strlen($id) > 40) {
                    continue;
                }
                $kind = $op['kind'] ?? '';
                $action = $op['op'] ?? '';

                $existing = $data['items'][$id] ?? null;
                $teacheritem = $existing && empty($existing['deleted']) && !empty($existing['teacher']);
                if ($kind === 'item' && $teacheritem && !$isteacher) {
                    continue; // Students cannot change or remove what the teacher wrote.
                }
                if ($kind === 'item' && $action === 'put') {
                    $item = mylkpd_clean_item($op['data'] ?? null);
                    if ($item && $item['type'] === 'note' && !$isteacher) {
                        continue;
                    }
                    if ($item && $item['type'] === 'image' && !get_file_storage()->file_exists(
                            context_module::instance($cm->id)->id, 'mod_mylkpd', 'answerimage', $sub->id, '/', $item['file'])) {
                        continue;
                    }
                    $isnew = !isset($data['items'][$id]) || !empty($data['items'][$id]['deleted']);
                    if (!$item || ($isnew && $live >= 2000)) {
                        continue;
                    }
                    $live += $isnew ? 1 : 0;
                    $item += ['v' => ++$sub->revision, 'by' => $userid];
                    if ($teacheritem || ($isnew && $isteacher)) {
                        $item['teacher'] = 1;
                    }
                    $data['items'][$id] = $item;
                    $changed = true;
                } else if ($kind === 'item' && $action === 'del') {
                    if (isset($data['items'][$id]) && empty($data['items'][$id]['deleted'])) {
                        $data['items'][$id] = ['deleted' => 1, 'v' => ++$sub->revision];
                        $live--;
                        $changed = true;
                    }
                } else if ($kind === 'block' && $action === 'put' && ctype_digit($id) && (int) $id < 100000) {
                    $html = purify_html(core_text::substr((string) ($op['html'] ?? ''), 0, 20000));
                    $data['blocks'][$id] = ['html' => $html, 'v' => ++$sub->revision, 'by' => $userid];
                    // Paragraph alignment is kept beside the content (HTMLPurifier would drop it inside).
                    if (in_array($op['align'] ?? '', ['left', 'center', 'right', 'justify'], true)) {
                        $data['blocks'][$id]['align'] = $op['align'];
                    }
                    if ($isteacher) {
                        $data['blocks'][$id]['teacher'] = 1;
                    }
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $sub->answers = json_encode($data, JSON_UNESCAPED_UNICODE);
            if (!$isteacher) {
                // Teacher annotations do not count as the student's last change.
                $sub->timemodified = time();
            }
            $DB->update_record('mylkpd_submissions', $sub);
        }
    } finally {
        $lock->release();
    }

    $presence = [];
    if ($mylkpd->teamsubmission) {
        $now = time();
        $block = ctype_digit($block) ? $block : '';
        // Teachers see who is online but are not recorded, so they never lock paragraphs for students.
        if (!$isteacher) {
            $mine = $DB->get_record('mylkpd_presence', ['submissionid' => $sub->id, 'userid' => $userid]);
            if (!$mine) {
                $DB->insert_record('mylkpd_presence', (object) ['submissionid' => $sub->id, 'userid' => $userid,
                    'block' => $block, 'timeseen' => $now]);
            } else if ((string) $mine->block !== $block || $now - $mine->timeseen >= 10) {
                $DB->update_record('mylkpd_presence', (object) ['id' => $mine->id, 'block' => $block, 'timeseen' => $now]);
            }
        }
        $rows = $DB->get_records_select('mylkpd_presence', 'submissionid = ? AND timeseen > ?', [$sub->id, $now - 40]);
        foreach ($rows as $row) {
            $presence[] = ['userid' => (int) $row->userid, 'block' => (string) $row->block,
                'ago' => $row->userid == $userid ? 0 : max(0, $now - $row->timeseen)];
        }
    }

    $items = [];
    foreach ($data['items'] as $id => $item) {
        if (($item['v'] ?? 0) > $sincerev) {
            $items[(string) $id] = $item;
        }
    }
    $blocks = [];
    foreach ($data['blocks'] as $id => $b) {
        if (($b['v'] ?? 0) > $sincerev) {
            $blocks[(string) $id] = $b;
        }
    }
    return [
        'rev' => (int) $sub->revision,
        'status' => (int) $sub->status,
        'closed' => !$isteacher && !mylkpd_can_work($mylkpd),
        'items' => (object) $items,
        'blocks' => (object) $blocks,
        'presence' => $presence,
    ];
}

/** Largest image a student can upload (bytes, after resizing in the browser). */
define('MYLKPD_IMAGE_MAXBYTES', 5 * 1024 * 1024);
/** Most images one submission can hold. */
define('MYLKPD_IMAGE_MAXCOUNT', 30);

/**
 * Stores an uploaded answer image in the user's (or group's) submission.
 *
 * @param stdClass $mylkpd
 * @param cm_info|stdClass $cm
 * @param context_module $context
 * @param int $userid
 * @param array|null $upload entry from $_FILES
 * @return array ['file', 'itemid', 'width', 'height'] or ['error' => message]
 */
function mylkpd_store_answer_image(stdClass $mylkpd, $cm, context_module $context, int $userid, ?array $upload,
        ?array $teacherfor = null): array {
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        return ['error' => get_string('imageerror', 'mod_mylkpd')];
    }
    if ($upload['size'] > MYLKPD_IMAGE_MAXBYTES) {
        return ['error' => get_string('imagetoolarge', 'mod_mylkpd', display_size(MYLKPD_IMAGE_MAXBYTES))];
    }
    // Check the content itself, not the name or the browser's claim.
    $info = @getimagesize($upload['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        return ['error' => get_string('imagetypeinvalid', 'mod_mylkpd')];
    }
    if (!$teacherfor && !empty($mylkpd->teamsubmission) && !mylkpd_get_user_groupid($cm, $userid)) {
        return ['error' => get_string('nogroup', 'mod_mylkpd')];
    }
    $sub = mylkpd_target_submission($mylkpd, $cm, $userid, $teacherfor, true);
    if (!$teacherfor && !mylkpd_can_work($mylkpd)) {
        return ['error' => get_string(mylkpd_is_open($mylkpd) ? 'closedshort' : 'notopenshort', 'mod_mylkpd')];
    }
    if (!$teacherfor && $sub->status == MYLKPD_STATUS_SUBMITTED) {
        return ['error' => get_string('alreadysubmitted', 'mod_mylkpd')];
    }
    $fs = get_file_storage();
    if (count($fs->get_area_files($context->id, 'mod_mylkpd', 'answerimage', $sub->id, 'id', false)) >= MYLKPD_IMAGE_MAXCOUNT) {
        return ['error' => get_string('imagelimit', 'mod_mylkpd', MYLKPD_IMAGE_MAXCOUNT)];
    }
    $name = 'img_' . time() . '_' . strtolower(random_string(8)) . '.' . $types[$info[2]];
    $fs->create_file_from_pathname([
        'contextid' => $context->id,
        'component' => 'mod_mylkpd',
        'filearea' => 'answerimage',
        'itemid' => $sub->id,
        'filepath' => '/',
        'filename' => $name,
        'userid' => $userid,
    ], $upload['tmp_name']);
    return ['file' => $name, 'itemid' => (int) $sub->id, 'width' => (int) $info[0], 'height' => (int) $info[1]];
}

/**
 * Removes uploaded images that no answer uses any more (called when a worksheet is submitted).
 *
 * @param context_module $context
 * @param stdClass $submission
 */
function mylkpd_cleanup_images(context_module $context, stdClass $submission): void {
    $used = [];
    foreach (mylkpd_decode_data($submission->answers)['items'] as $item) {
        if (empty($item['deleted']) && ($item['type'] ?? '') === 'image') {
            $used[$item['file']] = true;
        }
    }
    $fs = get_file_storage();
    foreach ($fs->get_area_files($context->id, 'mod_mylkpd', 'answerimage', $submission->id, 'id', false) as $file) {
        if (empty($used[$file->get_filename()])) {
            $file->delete();
        }
    }
}

/**
 * Feedback text from an AI result ("No. 1: …" per question).
 *
 * @param array $result
 * @return string
 */
function mylkpd_ai_feedback_text(array $result): string {
    $lines = [];
    foreach ($result['items'] ?? [] as $item) {
        if (!empty($item['feedback'])) {
            $lines[] = get_string('ainumber', 'mod_mylkpd') . ' ' . ($item['label'] ?: '-') . ': ' . $item['feedback'];
        }
    }
    return implode("\n", $lines);
}

/**
 * Scheduled AI grading: checks submitted work in activities whose automatic check time has come and saves
 * the grade and feedback. Work the teacher already graded is left alone. Work submitted later (also after
 * the scheduled time) is checked in a later run.
 *
 * @param int $budget most submissions to check in this run
 * @param callable|null $log receives progress messages
 * @return array ['checked' => int, 'graded' => int, 'failed' => int]
 */
function mylkpd_ai_autocheck_run(int $budget = 40, ?callable $log = null): array {
    global $DB;
    $stats = ['checked' => 0, 'graded' => 0, 'failed' => 0];
    $log = $log ?? function($m) {
    };
    if (!\mod_mylkpd\ai\client::site_enabled()) {
        return $stats;
    }
    $instances = $DB->get_records_select('mylkpd', 'aienabled = 1 AND aiautotime > 0 AND aiautotime <= ?', [time()]);
    foreach ($instances as $mylkpd) {
        if ($budget <= 0) {
            break;
        }
        $cm = get_coursemodule_from_instance('mylkpd', $mylkpd->id, $mylkpd->course, false, IGNORE_MISSING);
        $questions = mylkpd_get_questions($mylkpd);
        if (!$cm || !$questions) {
            continue;
        }
        // Submitted, not graded by the teacher, and not yet checked since it was (last) submitted.
        $subs = $DB->get_records_select('mylkpd_submissions',
            'mylkpdid = ? AND status = ? AND timegraded = 0 AND (aitime = 0 OR aitime < timesubmitted)',
            [$mylkpd->id, MYLKPD_STATUS_SUBMITTED], 'timesubmitted ASC', '*', 0, $budget);
        if (!$subs) {
            continue;
        }
        $rubric = (bool) mylkpd_grading_controller($mylkpd, context_module::instance($cm->id));
        foreach ($subs as $sub) {
            $budget--;
            $stats['checked']++;
            $now = time();
            try {
                $result = \mod_mylkpd\ai\grader::check($questions, mylkpd_decode_data($sub->answers));
                $result['auto'] = 1;
                $update = (object) ['id' => $sub->id, 'airesult' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'aitime' => $now];
                // A rubric has to be filled in by the teacher: then only the suggestion is kept.
                if (!$rubric && $mylkpd->grade > 0 && $result['max'] > 0) {
                    $update->grade = round($result['total'] / $result['max'] * $mylkpd->grade, 2);
                    $update->feedback = trim(mylkpd_ai_feedback_text($result) . "\n\n" .
                        get_string('aiautonote', 'mod_mylkpd'));
                    $update->timegraded = $now;
                    $stats['graded']++;
                }
                $DB->update_record('mylkpd_submissions', $update);
                $log("MyLKPD {$mylkpd->id}: submission {$sub->id} checked ({$result['total']}/{$result['max']})");
            } catch (\Throwable $e) {
                // Remember the failure so the same work is not retried every run; the teacher can check it by hand.
                $DB->update_record('mylkpd_submissions', (object) ['id' => $sub->id, 'aitime' => $now,
                    'airesult' => json_encode(['error' => $e->getMessage(), 'time' => $now, 'auto' => 1], JSON_UNESCAPED_UNICODE)]);
                $stats['failed']++;
                $log("MyLKPD {$mylkpd->id}: submission {$sub->id} failed: " . $e->getMessage());
            }
        }
        mylkpd_update_grades($mylkpd);
    }
    return $stats;
}

/**
 * Members shown in the editor (names and a colour each).
 *
 * @param int[] $userids
 * @return array userid => ['name' => string, 'color' => string]
 */
function mylkpd_members(array $userids): array {
    global $DB;
    $palette = ['#e91e63', '#3f51b5', '#009688', '#ff9800', '#795548', '#9c27b0', '#00acc1', '#7cb342'];
    $out = [];
    if (!$userids) {
        return $out;
    }
    $users = $DB->get_records_list('user', 'id', $userids);
    $i = 0;
    foreach ($userids as $uid) {
        if (isset($users[$uid])) {
            $out[$uid] = ['name' => fullname($users[$uid]), 'color' => $palette[$i++ % count($palette)]];
        }
    }
    return $out;
}

/**
 * Everyone whose name can appear on the worksheet: the members, plus teachers who annotated it.
 *
 * @param int[] $memberids
 * @param array $data from mylkpd_decode_data()
 * @param int $graderid the teacher on the grading page (0 for students)
 * @return array ['members' => userid => [name, color], 'teachers' => int[]]
 */
function mylkpd_editor_people(array $memberids, array $data, int $graderid = 0): array {
    $teachers = $graderid ? [$graderid => true] : [];
    foreach (array_merge(array_values($data['items']), array_values($data['blocks'])) as $entry) {
        if (!empty($entry['teacher']) && !empty($entry['by']) && empty($entry['deleted'])) {
            $teachers[(int) $entry['by']] = true;
        }
    }
    $members = mylkpd_members($memberids);
    foreach (mylkpd_members(array_keys($teachers)) as $uid => $info) {
        $members[$uid] = ['name' => $info['name'] . ' (' . get_string('teacherlabel', 'mod_mylkpd') . ')',
            'color' => '#c62828'];
    }
    return ['members' => $members, 'teachers' => array_map('intval', array_keys($teachers))];
}

/**
 * Members of the group or person a submission belongs to.
 *
 * @param stdClass $mylkpd
 * @param int $groupid
 * @param int $userid
 * @return int[]
 */
function mylkpd_member_ids(stdClass $mylkpd, int $groupid, int $userid): array {
    if ($mylkpd->teamsubmission && $groupid) {
        return array_map('intval', array_keys(groups_get_members($groupid, 'u.id', 'u.lastname, u.firstname')));
    }
    return $userid ? [$userid] : [];
}

/**
 * Counts what each member last changed, for the teacher.
 *
 * @param array $data from mylkpd_decode_data()
 * @return array userid => ['items' => int, 'blocks' => int]
 */
function mylkpd_contributions(array $data): array {
    $out = [];
    foreach ($data['items'] as $item) {
        if (empty($item['deleted']) && !empty($item['by'])) {
            $out[$item['by']]['items'] = ($out[$item['by']]['items'] ?? 0) + 1;
        }
    }
    foreach ($data['blocks'] as $b) {
        if (!empty($b['by'])) {
            $out[$b['by']]['blocks'] = ($out[$b['by']]['blocks'] ?? 0) + 1;
        }
    }
    return $out;
}

/**
 * Whether students may reopen (edit again) a submitted worksheet.
 *
 * @param stdClass $mylkpd
 * @param stdClass|null $submission
 * @return bool
 */
function mylkpd_can_reopen(stdClass $mylkpd, ?stdClass $submission): bool {
    if (!$submission || $submission->status != MYLKPD_STATUS_SUBMITTED) {
        return false;
    }
    $mode = (int) ($mylkpd->allowresubmit ?? 1);
    if ($mode === 2) {
        return true;
    }
    // "Until graded" means until a real grade is saved; feedback alone does not lock the work.
    return $mode === 1 && ($submission->grade === null || $submission->grade === '');
}

/**
 * The active advanced grading controller (rubric, marking guide), if the teacher set one up.
 *
 * @param stdClass $mylkpd
 * @param context_module $context
 * @return gradingform_controller|null
 */
function mylkpd_grading_controller(stdClass $mylkpd, context_module $context) {
    global $CFG;
    if ($mylkpd->grade == 0) {
        return null;
    }
    require_once($CFG->dirroot . '/grade/grading/lib.php');
    $manager = get_grading_manager($context, 'mod_mylkpd', 'submissions');
    if (!$method = $manager->get_active_method()) {
        return null;
    }
    $controller = $manager->get_controller($method);
    $controller->set_grade_range(make_grades_menu($mylkpd->grade), $mylkpd->grade > 0);
    return $controller;
}

/**
 * Link to Moodle's page where the teacher chooses and builds the rubric.
 *
 * @param context_module $context
 * @return moodle_url
 */
function mylkpd_grading_manage_url(context_module $context): moodle_url {
    return new moodle_url('/grade/grading/manage.php',
        ['contextid' => $context->id, 'component' => 'mod_mylkpd', 'area' => 'submissions']);
}

/**
 * Whether AI essay checking can be used in this activity (site switch + activity switch).
 *
 * @param stdClass $mylkpd
 * @return bool
 */
function mylkpd_ai_enabled(stdClass $mylkpd): bool {
    return !empty($mylkpd->aienabled) && \mod_mylkpd\ai\client::site_enabled();
}

/**
 * Validates an answer area sent from the answer-key editor.
 *
 * Format: {"blocks": ["12", ...], "original": {"12": "text before the student wrote"},
 *          "regions": [{"page": 1, "x": 10, "y": 40, "w": 80, "h": 15}]} (percent of the page).
 *
 * @param mixed $raw JSON string or array
 * @return array
 */
function mylkpd_clean_anchor($raw): array {
    $a = is_array($raw) ? $raw : json_decode((string) $raw, true);
    $out = ['blocks' => [], 'original' => [], 'regions' => [], 'qblocks' => [], 'qregions' => []];
    if (!is_array($a)) {
        return $out;
    }
    // Where the question itself is on the worksheet (only to show it again in the answer key editor).
    foreach (array_slice((array) ($a['qblocks'] ?? []), 0, 30) as $id) {
        $id = (string) $id;
        if (ctype_digit($id) && (int) $id < 100000 && !in_array($id, $out['qblocks'], true)) {
            $out['qblocks'][] = $id;
        }
    }
    foreach (array_slice((array) ($a['blocks'] ?? []), 0, 60) as $id) {
        $id = (string) $id;
        if (ctype_digit($id) && (int) $id < 100000 && !in_array($id, $out['blocks'], true)) {
            $out['blocks'][] = $id;
            $orig = $a['original'][$id] ?? '';
            $out['original'][$id] = core_text::substr(clean_param(is_scalar($orig) ? (string) $orig : '', PARAM_TEXT), 0, 2000);
        }
    }
    $pct = function($v): float {
        return min(100, max(0, round(is_numeric($v) ? (float) $v : 0, 2)));
    };
    foreach (array_slice((array) ($a['regions'] ?? []), 0, 8) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $region = ['page' => max(1, min(1000, (int) ($r['page'] ?? 1))), 'x' => $pct($r['x'] ?? 0),
            'y' => $pct($r['y'] ?? 0), 'w' => $pct($r['w'] ?? 0), 'h' => $pct($r['h'] ?? 0)];
        if ($region['w'] >= 1 && $region['h'] >= 1) {
            $out['regions'][] = $region;
        }
    }
    foreach (array_slice((array) ($a['qregions'] ?? []), 0, 4) as $r) {
        if (is_array($r)) {
            $region = ['page' => max(1, min(1000, (int) ($r['page'] ?? 1))), 'x' => $pct($r['x'] ?? 0),
                'y' => $pct($r['y'] ?? 0), 'w' => $pct($r['w'] ?? 0), 'h' => $pct($r['h'] ?? 0)];
            if ($region['w'] >= 1 && $region['h'] >= 1) {
                $out['qregions'][] = $region;
            }
        }
    }
    return $out;
}

/**
 * Essay questions with answer keys, in order, each with its decoded answer area in ->area.
 *
 * @param stdClass $mylkpd
 * @return array
 */
function mylkpd_get_questions(stdClass $mylkpd): array {
    global $DB;
    $questions = $DB->get_records('mylkpd_questions', ['mylkpdid' => $mylkpd->id], 'sortorder, id');
    foreach ($questions as $q) {
        $q->area = mylkpd_clean_anchor($q->anchor);
    }
    return $questions;
}

/**
 * Text of the "made by Zalira" credit shown under the worksheet.
 *
 * @return string
 */
function mylkpd_credit_text(): string {
    $info = core_plugin_manager::instance()->get_plugin_info('mod_mylkpd');
    return get_string('credit', 'mod_mylkpd', ($info && !empty($info->release)) ? $info->release : '');
}

/**
 * Teacher's grade with the late penalty shown ("70 (80 − 10 late)"), or the plain grade.
 *
 * @param stdClass $mylkpd
 * @param stdClass|null $submission
 * @return string
 */
function mylkpd_display_final_grade(stdClass $mylkpd, ?stdClass $submission): string {
    if (!$submission || $submission->grade === null || $submission->grade === '') {
        return '-';
    }
    $penalty = mylkpd_late_penalty($mylkpd, $submission);
    if ($penalty <= 0) {
        return mylkpd_display_grade($mylkpd, $submission->grade);
    }
    return get_string('gradewithpenalty', 'mod_mylkpd', (object) [
        'final' => mylkpd_display_grade($mylkpd, mylkpd_final_grade($mylkpd, $submission)),
        'grade' => format_float($submission->grade, 2),
        'penalty' => format_float($penalty, 2),
    ]);
}

/**
 * Group work where every member must submit: who has submitted (userid => time).
 *
 * @param stdClass|null $submission
 * @return array
 */
function mylkpd_team_confirmations(?stdClass $submission): array {
    $data = $submission ? json_decode((string) ($submission->submittedby ?? ''), true) : null;
    return is_array($data) ? array_map('intval', $data) : [];
}

/**
 * Whether every group member has to press Submit themselves.
 *
 * @param stdClass $mylkpd
 * @return bool
 */
function mylkpd_requires_all_members(stdClass $mylkpd): bool {
    return !empty($mylkpd->teamsubmission) && !empty($mylkpd->teamsubmitmode);
}

/**
 * Members (who can submit) that have not submitted yet, when every member must submit.
 *
 * @param stdClass $mylkpd
 * @param context_module $context
 * @param stdClass $submission
 * @return int[]
 */
function mylkpd_team_pending(stdClass $mylkpd, context_module $context, stdClass $submission): array {
    if (!mylkpd_requires_all_members($mylkpd) || empty($submission->groupid)) {
        return [];
    }
    $members = array_map('intval', array_keys(get_enrolled_users($context, 'mod/mylkpd:submit', $submission->groupid, 'u.id')));
    return array_values(array_diff($members, array_keys(mylkpd_team_confirmations($submission))));
}

/**
 * Formats a grade for display.
 *
 * @param stdClass $mylkpd
 * @param float|null $grade
 * @return string
 */
function mylkpd_display_grade(stdClass $mylkpd, $grade): string {
    if ($grade === null || $mylkpd->grade == 0) {
        return '-';
    }
    if ($mylkpd->grade < 0) {
        $menu = make_grades_menu($mylkpd->grade);
        return $menu[(int) $grade] ?? '-';
    }
    return format_float($grade, 2) . ' / ' . $mylkpd->grade;
}

/**
 * One toolbar button (template data for mod_mylkpd/toolbar_button).
 *
 * @param string $attr data attribute name (without data-mylkpd-)
 * @param string $value
 * @param string $icon HTML
 * @param string $label
 * @param bool $showlabel
 * @param bool $active
 * @return array
 */
function mylkpd_toolbar_button(string $attr, string $value, string $icon, string $label, bool $showlabel = true,
        bool $active = false): array {
    return ['attr' => $attr, 'value' => $value, 'icon' => $icon, 'label' => $label, 'showlabel' => $showlabel,
        'active' => $active, 'istool' => $attr === 'tool'];
}

/**
 * Template data for the editing toolbar (inspired by CKEditor): one sticky panel with grouped tools.
 *
 * Row 1: tools (type, move, draw...) | alignment | undo/redo.
 * Row 2: text formatting (docx) | colour and thickness | inserts | help.
 * Then a message line and, for group work, who is online.
 *
 * @param bool $cantype whether the document is a .docx shown in the browser (typing in paragraphs possible)
 * @param string $defaulttool
 * @param bool $teacher whether a teacher annotates a student's worksheet
 * @param bool $team whether it is group work
 * @return array context for mod_mylkpd/toolbar
 */
function mylkpd_toolbar_context(bool $cantype, string $defaulttool, bool $teacher = false, bool $team = false): array {
    $s = function($key) {
        return get_string($key, 'mod_mylkpd');
    };
    $group = function(string $label, string $extra, array $more) {
        return ['label' => $label, 'extra' => $extra] + $more;
    };

    // Row 1.
    $tools = [];
    if ($cantype) {
        $tools['type'] = '&#9000;';
    }
    $tools += ['move' => '&#10021;', 'text' => 'T'];
    if ($teacher) {
        $tools['note'] = '&#128221;';
    }
    $tools += ['check' => '&#10003;', 'cross' => '&#10007;',
        'circle' => '&#9711;', 'highlight' => '&#9644;', 'line' => '&#9585;', 'pen' => '&#9998;', 'erase' => '&#9003;'];
    $buttons = [];
    foreach ($tools as $tool => $icon) {
        $buttons[] = mylkpd_toolbar_button('tool', $tool, $icon, $s('tool' . $tool), true, $tool === $defaulttool);
    }
    $row1 = [$group($s('tools'), 'mylkpd-tools', ['buttons' => $buttons])];

    $icon = function(array $widths, string $anchor): string {
        // Four lines of different lengths, aligned like the text they stand for.
        $lines = '';
        foreach ($widths as $i => $w) {
            $x = $anchor === 'right' ? 14 - $w : ($anchor === 'center' ? (14 - $w) / 2 : 1);
            $lines .= '<rect x="' . $x . '" y="' . (2 + $i * 3.2) . '" width="' . $w . '" height="1.6" rx=".8"/>';
        }
        return '<svg class="mylkpd-svgicon" viewBox="0 0 15 15" width="15" height="15" fill="currentColor" aria-hidden="true">' .
            $lines . '</svg>';
    };
    $buttons = [];
    foreach (['left' => [[13, 9, 13, 7], 'left'], 'center' => [[13, 9, 13, 7], 'center'], 'right' => [[13, 9, 13, 7], 'right'],
            'justify' => [[13, 13, 13, 13], 'left']] as $align => [$widths, $anchor]) {
        $buttons[] = mylkpd_toolbar_button('align', $align, $icon($widths, $anchor), $s('align' . $align), false);
    }
    $row1[] = $group($s('alignment'), 'mylkpd-aligngroup', ['buttons' => $buttons]);
    $row1[] = $group($s('history'), 'mylkpd-history', ['buttons' => [
        mylkpd_toolbar_button('action', 'undo', '&#8630;', $s('undo') . ' (Ctrl+Z)', false),
        mylkpd_toolbar_button('action', 'redo', '&#8631;', $s('redo') . ' (Ctrl+Y)', false),
    ]]);

    // Row 2.
    $row2 = [];
    if ($cantype) {
        $formats = [
            'bold' => '<b>B</b>', 'italic' => '<i>I</i>', 'underline' => '<u>U</u>', 'strikeThrough' => '<s>S</s>',
            'superscript' => 'x&sup2;', 'subscript' => 'x&#8322;',
            'foreColor' => '<span class="mylkpd-a-color">A</span>', 'hiliteColor' => '<span class="mylkpd-a-mark">ab</span>',
            'removeFormat' => '&#10008;<small>T</small>',
        ];
        $buttons = [];
        foreach ($formats as $cmd => $fmticon) {
            $buttons[] = mylkpd_toolbar_button('format', $cmd, $fmticon, $s('format' . strtolower($cmd)), false);
        }
        $row2[] = $group($s('formatting'), 'mylkpd-formatgroup', ['buttons' => $buttons]);
    }
    $names = ['colorblue', 'colorred', 'colorblack', 'colorgreen', 'coloryellow'];
    $activecolor = $teacher ? 1 : 0; // Teachers correct in red.
    $swatches = [];
    foreach (mylkpd_colors() as $i => $color) {
        $swatches[] = ['color' => $color, 'label' => $s($names[$i]), 'active' => $i === $activecolor];
    }
    $widths = [];
    foreach ([2 => 'widththin', 4 => 'widthmedium', 8 => 'widththick'] as $value => $key) {
        $widths[] = ['value' => $value, 'label' => $s($key), 'selected' => $value === 4];
    }
    $row2[] = $group($s('color'), 'mylkpd-style', ['swatches' => $swatches,
        'widths' => ['label' => $s('width'), 'options' => $widths]]);
    $buttons = [];
    foreach (['image' => '&#128444;', 'table' => '&#9638;', 'math' => '&#8721;', 'symbol' => '&#937;', 'arabic' => '&#1593;',
            'link' => '&#128279;'] as $what => $inserticon) {
        $buttons[] = mylkpd_toolbar_button('insert', $what, $inserticon, $s('insert' . $what));
    }
    $row2[] = $group($s('insert'), 'mylkpd-inserts', ['buttons' => $buttons]);

    return [
        'label' => $s('toolbar'),
        'rows' => [
            ['groups' => $row1],
            ['groups' => $row2, 'help' => ['title' => $s('help'),
                'text' => $s($teacher ? 'teacherhint' : ($cantype ? 'toolhinttype' : 'toolhint'))]],
        ],
        'presence' => $team && !$teacher,
        'namestoggle' => $team ? get_string('shownames', 'mod_mylkpd') : '',
    ];
}

/**
 * The editing toolbar as HTML.
 *
 * @param bool $cantype
 * @param string $defaulttool
 * @param bool $teacher
 * @param bool $team
 * @return string
 */
function mylkpd_render_toolbar(bool $cantype, string $defaulttool, bool $teacher = false, bool $team = false): string {
    global $OUTPUT;
    return $OUTPUT->render_from_template('mod_mylkpd/toolbar', mylkpd_toolbar_context($cantype, $defaulttool, $teacher, $team));
}

/**
 * Status badge for a submission.
 *
 * @param stdClass|null $sub
 * @return string
 */
function mylkpd_status_badge(?stdClass $sub, ?stdClass $mylkpd = null, ?context_module $context = null): string {
    if (!$sub) {
        return html_writer::span(get_string('statusnotstarted', 'mod_mylkpd'), 'badge bg-light text-dark');
    }
    if ($sub->status == MYLKPD_STATUS_SUBMITTED) {
        $out = html_writer::span(get_string('statussubmitted', 'mod_mylkpd'), 'badge bg-success');
        if ($mylkpd && ($late = mylkpd_late_seconds($mylkpd, $sub))) {
            $out .= ' ' . html_writer::span(get_string('statuslate', 'mod_mylkpd', format_time($late)), 'badge bg-danger');
        }
        return $out;
    }
    $out = html_writer::span(get_string('statusdraft', 'mod_mylkpd'), 'badge bg-warning text-dark');
    // Every member must submit: how many already have.
    if ($mylkpd && $context && mylkpd_requires_all_members($mylkpd) && !empty($sub->groupid)) {
        $done = count(mylkpd_team_confirmations($sub));
        $total = $done + count(mylkpd_team_pending($mylkpd, $context, $sub));
        if ($done) {
            $out .= ' ' . html_writer::span(get_string('teamsubmitcount', 'mod_mylkpd', (object) ['done' => $done,
                'total' => $total]), 'badge bg-info text-dark');
        }
    }
    return $out;
}

/**
 * Builds the worksheet viewer/editor and loads its JavaScript.
 *
 * @param stdClass $mylkpd
 * @param cm_info|stdClass $cm
 * @param context_module $context
 * @param stdClass|null $submission the submission to show (null = empty worksheet)
 * @param bool $readonly
 * @param int[] $memberids people shown with names/colours (group members)
 * @return array ['html' => string, 'ready' => bool]
 */
function mylkpd_render_worksheet(stdClass $mylkpd, $cm, context_module $context, ?stdClass $submission,
        bool $readonly, array $memberids = [], ?array $teacherfor = null, array $extracfg = []): array {
    global $PAGE, $OUTPUT, $USER;

    $doc = mylkpd_get_document($mylkpd, $context);
    if ($doc['status'] !== 'ready') {
        $type = $doc['status'] === 'pending' ? \core\output\notification::NOTIFY_INFO
            : \core\output\notification::NOTIFY_ERROR;
        $key = $doc['status'] === 'editneedsdocx' ? 'editneedsdocx' : 'pdf' . $doc['status'];
        return ['html' => $OUTPUT->notification(get_string($key, 'mod_mylkpd'), $type), 'ready' => false];
    }

    $file = $doc['file'];
    $docurl = moodle_url::make_pluginfile_url($context->id, 'mod_mylkpd', $file->get_filearea(),
        $file->get_itemid(), $file->get_filepath(), $file->get_filename());
    $workmode = ($mylkpd->workmode ?? '') === 'edit' ? 'edit' : 'annotate';
    // A .docx shown in the browser can always be typed into, alongside the drawing tools.
    $cantype = $doc['mode'] === 'docx';
    $defaulttool = ($cantype && $workmode === 'edit') ? 'type' : 'text';
    $decoded = mylkpd_decode_data($submission->answers ?? null);
    $data = mylkpd_client_data($decoded);
    $team = !empty($mylkpd->teamsubmission);
    $isteacher = $teacherfor !== null && !$readonly;
    $people = mylkpd_editor_people($memberids, $decoded, $isteacher ? (int) $USER->id : 0);
    if ($isteacher) {
        // On Word worksheets the teacher starts by editing the text; otherwise by adding notes.
        $defaulttool = $cantype ? 'type' : 'note';
    }

    $config = [
        'containerId' => 'mylkpd-container',
        'mode' => $doc['mode'],
        'workmode' => $workmode,
        'docUrl' => $docurl->out(false),
        'pdfjsBase' => (new moodle_url(MYLKPD_PDFJS_PATH))->out(false),
        'workerUrl' => (new moodle_url(MYLKPD_PDFJS_PATH . '/pdf.worker.min.js'))->out(false),
        'docxBase' => (new moodle_url(MYLKPD_DOCX_PATH))->out(false),
        'katexBase' => (new moodle_url(MYLKPD_KATEX_PATH))->out(false),
        'canType' => $cantype,
        'defaultTool' => $defaulttool,
        'uploadUrl' => (new moodle_url('/mod/mylkpd/upload.php'))->out(false),
        'imageBase' => moodle_url::make_pluginfile_url($context->id, 'mod_mylkpd', 'answerimage', 0, '/', 'x')->out(false),
        'submissionId' => (int) ($submission->id ?? 0),
        'cmid' => (int) $cm->id,
        'sesskey' => sesskey(),
        'userid' => (int) $USER->id,
        'rev' => (int) ($submission->revision ?? 0),
        'collab' => ($team || $isteacher) && !$readonly,
        'showNames' => $team,
        'isTeacher' => $isteacher,
        'teacherIds' => $people['teachers'],
        'target' => $isteacher ? ['targetuser' => $teacherfor['userid'], 'targetgroup' => $teacherfor['groupid']] : null,
        'colors' => mylkpd_colors(),
        'tableBorderColors' => mylkpd_table_border_colors(),
        'tableFillColors' => mylkpd_table_fill_colors(),
        'items' => $data['items'],
        'blocks' => $data['blocks'],
        'members' => (object) $people['members'],
        'readonly' => $readonly,
        'strings' => [
            'answer' => get_string('answer', 'mod_mylkpd'),
            'check' => get_string('toolcheck', 'mod_mylkpd'),
            'cross' => get_string('toolcross', 'mod_mylkpd'),
            'confirmsubmit' => get_string($team ? 'confirmsubmitgroup' : 'confirmsubmit', 'mod_mylkpd'),
            'loaderror' => get_string('loaderror', 'mod_mylkpd'),
            'saving' => get_string('saving', 'mod_mylkpd'),
            'saved' => get_string('saved', 'mod_mylkpd'),
            'savedat' => get_string('savedat', 'mod_mylkpd', '{time}'),
            'saveerror' => get_string('saveerror', 'mod_mylkpd'),
            'online' => get_string('online', 'mod_mylkpd'),
            'editing' => get_string('editing', 'mod_mylkpd', '{name}'),
            'editedby' => get_string('editedby', 'mod_mylkpd', '{name}'),
            'you' => get_string('you', 'mod_mylkpd'),
            'insert' => get_string('insertbutton', 'mod_mylkpd'),
            'cancel' => get_string('cancel'),
            'close' => get_string('closebuttontitle'),
            'mathtitle' => get_string('insertmath', 'mod_mylkpd'),
            'mathhint' => get_string('mathhint', 'mod_mylkpd'),
            'mathpreview' => get_string('mathpreview', 'mod_mylkpd'),
            'linktitle' => get_string('insertlink', 'mod_mylkpd'),
            'linktext' => get_string('linktext', 'mod_mylkpd'),
            'linkurl' => get_string('linkurl', 'mod_mylkpd'),
            'linkinvalid' => get_string('linkinvalid', 'mod_mylkpd'),
            'symboltitle' => get_string('insertsymbol', 'mod_mylkpd'),
            'arabictitle' => get_string('insertarabic', 'mod_mylkpd'),
            'space' => get_string('arabicspace', 'mod_mylkpd'),
            'backspace' => get_string('arabicbackspace', 'mod_mylkpd'),
            'clicktoplace' => get_string('clicktoplace', 'mod_mylkpd'),
            'needfocus' => get_string('needfocus', 'mod_mylkpd'),
            'image' => get_string('imagealt', 'mod_mylkpd'),
            'imageuploading' => get_string('imageuploading', 'mod_mylkpd'),
            'imageerror' => get_string('imageerror', 'mod_mylkpd'),
            'imagehint' => get_string('imagehint', 'mod_mylkpd'),
            'resize' => get_string('imageresize', 'mod_mylkpd'),
            'note' => get_string('toolnote', 'mod_mylkpd'),
            'table' => get_string('inserttable', 'mod_mylkpd'),
            'tablemove' => get_string('tablemove', 'mod_mylkpd'),
            'tableaddrow' => get_string('tableaddrow', 'mod_mylkpd'),
            'tableaddcol' => get_string('tableaddcol', 'mod_mylkpd'),
            'tabledelrow' => get_string('tabledelrow', 'mod_mylkpd'),
            'tabledelcol' => get_string('tabledelcol', 'mod_mylkpd'),
            'tableborder' => get_string('tableborder', 'mod_mylkpd'),
            'tablecolor' => get_string('tablecolor', 'mod_mylkpd'),
            'tabledelete' => get_string('tabledelete', 'mod_mylkpd'),
            'tablestyle' => get_string('tablestyle', 'mod_mylkpd'),
            'tablesolid' => get_string('tablesolid', 'mod_mylkpd'),
            'tabledashed' => get_string('tabledashed', 'mod_mylkpd'),
            'tabledotted' => get_string('tabledotted', 'mod_mylkpd'),
            'tablenoborder' => get_string('tablenoborder', 'mod_mylkpd'),
            'tablethickness' => get_string('tablethickness', 'mod_mylkpd'),
            'tablebordercolor' => get_string('tablebordercolor', 'mod_mylkpd'),
            'tableheader' => get_string('tableheader', 'mod_mylkpd'),
            'tablecell' => get_string('tablecell', 'mod_mylkpd'),
            'tablenofill' => get_string('tablenofill', 'mod_mylkpd'),
            'tablepick' => get_string('tablepick', 'mod_mylkpd'),
            'teacher' => get_string('teacherlabel', 'mod_mylkpd'),
            'notehint' => get_string('notehint', 'mod_mylkpd'),
            'openlink' => get_string('openlink', 'mod_mylkpd'),
        ],
    ];

    $config = $extracfg + $config;
    // The settings travel in a data attribute: they are too large for js_call_amd arguments.
    $PAGE->requires->js_call_amd('mod_mylkpd/worksheet', 'init', ['mylkpd-container']);
    $html = $OUTPUT->render_from_template('mod_mylkpd/worksheet', [
        'toolbar' => $readonly ? false : mylkpd_toolbar_context($cantype, $defaulttool, $isteacher, $team),
        'namestoggle' => ($readonly && $team) ? get_string('shownames', 'mod_mylkpd') : '',
        'classes' => 'mylkpd-container' . ($cantype ? ' mylkpd-work-edit' : '') . ' mylkpd-mode-' . ($readonly ? 'view' : $defaulttool),
        'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
        'credit' => mylkpd_credit_text(),
    ]);

    return ['html' => $html, 'ready' => true];
}
