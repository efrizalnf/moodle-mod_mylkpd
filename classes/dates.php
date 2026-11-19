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

namespace mod_mylkpd;

use core\activity_dates;

/**
 * Dates shown on the course page and in the activity header: opens, due, closes.
 *
 * @package    mod_mylkpd
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
