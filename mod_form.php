<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Activity settings form.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/mylkpd/lib.php');

/**
 * Settings form for mod_mylkpd.
 */
class mod_mylkpd_mod_form extends moodleform_mod {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('filemanager', 'worksheet', get_string('worksheetfile', 'mod_mylkpd'), null,
            mylkpd_filemanager_options());
        $mform->addRule('worksheet', null, 'required');
        $mform->addHelpButton('worksheet', 'worksheetfile', 'mod_mylkpd');

        $mform->addElement('select', 'workmode', get_string('workmode', 'mod_mylkpd'), [
            'annotate' => get_string('workmodeannotate', 'mod_mylkpd'),
            'edit' => get_string('workmodeedit', 'mod_mylkpd'),
        ]);
        $mform->setDefault('workmode', 'annotate');
        $mform->addHelpButton('workmode', 'workmode', 'mod_mylkpd');

        $mform->addElement('advcheckbox', 'teamsubmission', get_string('teamsubmission', 'mod_mylkpd'));
        $mform->addHelpButton('teamsubmission', 'teamsubmission', 'mod_mylkpd');

        $mform->addElement('select', 'teamsubmitmode', get_string('teamsubmitmode', 'mod_mylkpd'), [
            0 => get_string('teamsubmitany', 'mod_mylkpd'),
            1 => get_string('teamsubmitall', 'mod_mylkpd'),
        ]);
        $mform->setDefault('teamsubmitmode', 0);
        $mform->addHelpButton('teamsubmitmode', 'teamsubmitmode', 'mod_mylkpd');
        $mform->hideIf('teamsubmitmode', 'teamsubmission', 'notchecked');

        $mform->addElement('select', 'allowresubmit', get_string('allowresubmit', 'mod_mylkpd'), [
            1 => get_string('allowresubmituntilgraded', 'mod_mylkpd'),
            2 => get_string('allowresubmitalways', 'mod_mylkpd'),
            0 => get_string('allowresubmitno', 'mod_mylkpd'),
        ]);
        $mform->setDefault('allowresubmit', 1);
        $mform->addHelpButton('allowresubmit', 'allowresubmit', 'mod_mylkpd');

        // AI essay checking (only offered when the administrator switched it on in the plugin settings).
        if (\mod_mylkpd\ai\client::site_enabled()) {
            $mform->addElement('advcheckbox', 'aienabled', get_string('aienabled', 'mod_mylkpd'));
            $mform->addHelpButton('aienabled', 'aienabled', 'mod_mylkpd');
        } else {
            $mform->addElement('static', 'aidisabledinfo', get_string('aienabled', 'mod_mylkpd'),
                get_string('aisiteoff', 'mod_mylkpd'));
        }

        // Changing these after students started would make existing answers unusable.
        if ($this->has_submissions()) {
            $mform->hardFreeze(['workmode', 'teamsubmission', 'teamsubmitmode']);
            $mform->addElement('static', 'settingslocked', '', get_string('settingslocked', 'mod_mylkpd'));
        }

        $mform->addElement('header', 'availability', get_string('availability', 'mod_mylkpd'));
        $mform->setExpanded('availability', true);
        $mform->addElement('date_time_selector', 'timeopen', get_string('timeopen', 'mod_mylkpd'), ['optional' => true]);
        $mform->addHelpButton('timeopen', 'timeopen', 'mod_mylkpd');
        $mform->addElement('date_time_selector', 'duedate', get_string('duedate', 'mod_mylkpd'), ['optional' => true]);
        $mform->addHelpButton('duedate', 'duedate', 'mod_mylkpd');
        $mform->addElement('date_time_selector', 'cutoffdate', get_string('cutoffdate', 'mod_mylkpd'), ['optional' => true]);
        $mform->addHelpButton('cutoffdate', 'cutoffdate', 'mod_mylkpd');

        $mform->addElement('float', 'latepenalty', get_string('latepenalty', 'mod_mylkpd'));
        $mform->setDefault('latepenalty', 0);
        $mform->addHelpButton('latepenalty', 'latepenalty', 'mod_mylkpd');
        $mform->hideIf('latepenalty', 'duedate[enabled]', 'notchecked');
        $mform->addElement('select', 'latepenaltymode', get_string('latepenaltymode', 'mod_mylkpd'), [
            0 => get_string('latepenaltyonce', 'mod_mylkpd'),
            1 => get_string('latepenaltyperday', 'mod_mylkpd'),
        ]);
        $mform->hideIf('latepenaltymode', 'duedate[enabled]', 'notchecked');

        $this->standard_grading_coursemodule_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Whether students already started this activity.
     *
     * @return bool
     */
    protected function has_submissions(): bool {
        global $DB;
        return !empty($this->current->instance)
            && $DB->record_exists('mylkpd_submissions', ['mylkpdid' => $this->current->instance]);
    }

    /**
     * Edit mode needs a .docx file.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $open = (int) ($data['timeopen'] ?? 0);
        $due = (int) ($data['duedate'] ?? 0);
        $cutoff = (int) ($data['cutoffdate'] ?? 0);
        if ($open && $due && $due < $open) {
            $errors['duedate'] = get_string('duebeforeopen', 'mod_mylkpd');
        }
        if ($cutoff && $due && $cutoff < $due) {
            $errors['cutoffdate'] = get_string('cutoffbeforedue', 'mod_mylkpd');
        } else if ($cutoff && $open && $cutoff < $open) {
            $errors['cutoffdate'] = get_string('cutoffbeforeopen', 'mod_mylkpd');
        }
        $penalty = (float) ($data['latepenalty'] ?? 0);
        if ($penalty < 0) {
            $errors['latepenalty'] = get_string('latepenaltynegative', 'mod_mylkpd');
        } else if ($penalty > 0 && isset($data['grade']) && is_numeric($data['grade']) && $data['grade'] > 0 &&
                $penalty > $data['grade']) {
            $errors['latepenalty'] = get_string('latepenaltytoolarge', 'mod_mylkpd', $data['grade']);
        }
        if (($data['workmode'] ?? '') === 'edit' && !empty($data['worksheet'])) {
            $draft = file_get_drafarea_files($data['worksheet']);
            $name = $draft->list ? reset($draft->list)->filename : '';
            if (core_text::strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'docx') {
                $errors['workmode'] = get_string('editneedsdocx', 'mod_mylkpd');
            }
        }
        return $errors;
    }

    /**
     * Loads the existing worksheet into the file manager.
     *
     * @param array $defaults
     */
    public function data_preprocessing(&$defaults) {
        $draftitemid = file_get_submitted_draft_itemid('worksheet');
        $contextid = $this->current->instance ? $this->context->id : null;
        file_prepare_draft_area($draftitemid, $contextid, 'mod_mylkpd', 'worksheet', 0, mylkpd_filemanager_options());
        $defaults['worksheet'] = $draftitemid;
    }
}
