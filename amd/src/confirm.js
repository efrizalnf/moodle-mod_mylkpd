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
 * Asks for confirmation before a form marked with data-mylkpd-confirm is sent (e.g. "Edit again").
 *
 * @module     mod_mylkpd/confirm
 * @copyright  2026 Zalira
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {getString} from 'core/str';

/**
 * Adds the confirmation to every such form on the page.
 */
export const init = () => {
    document.querySelectorAll('form[data-mylkpd-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            Notification.saveCancelPromise(getString('confirm', 'core'), form.getAttribute('data-mylkpd-confirm'),
                getString('yes', 'core'))
                .then(() => form.submit())
                .catch(() => null);
        });
    });
};
