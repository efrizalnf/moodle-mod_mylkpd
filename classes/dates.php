<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_mylkpd;

use core\activity_dates;

/**
 * Dates shown on the course page and in the activity header: opens, due, closes.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dates extends activity_dates {

    /**
     * Returns the dates for this activity.
     *
     * @return array
     */
    protected function get_dates(): array {
        $data = $this->cm->customdata ?? [];
        $now = time();
        $dates = [];
        if (!empty($data['timeopen'])) {
            $dates[] = ['dataid' => 'timeopen', 'timestamp' => (int) $data['timeopen'],
                'label' => get_string($data['timeopen'] > $now ? 'activitydate:opens' : 'activitydate:opened', 'course')];
        }
        if (!empty($data['duedate'])) {
            $dates[] = ['dataid' => 'duedate', 'timestamp' => (int) $data['duedate'],
                'label' => get_string('activitydate:due', 'course')];
        }
        if (!empty($data['cutoffdate'])) {
            $dates[] = ['dataid' => 'cutoffdate', 'timestamp' => (int) $data['cutoffdate'],
                'label' => get_string($data['cutoffdate'] > $now ? 'activitydate:closes' : 'activitydate:closed', 'course')];
        }
        return $dates;
    }
}
