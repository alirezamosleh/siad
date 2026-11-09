<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Contains class for the helper functions to show the LAD table.
 *
 * @package    local_bbb_lad
 * @copyright  2026, think modular
 * @author     think modular (stefan.weber@think-modular.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_bbb_lad;

/**
 * Class for the helper functions to show the LAD table.
 *
 * @package    local_bbb_lad
 * @copyright  2026, think modular
 * @author     think modular (stefan.weber@think-modular.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class table {
    /**
     * Format username.
     *
     * @param array $attendee
     * @return string formatted username
     */
    public static function userlink($attendee) {

        global $OUTPUT;

        // Print user link.
        $userid = $attendee['ext_user_id'];
        $user = \core_user::get_user($userid);
        $username = $attendee['name'];
        $url = new \moodle_url('/user/profile.php', ['id' => $userid]);
        $html = \html_writer::link($url, $username);
        $html = self::joins_and_leaves($html, $attendee);

        return $html;
    }

    /**
     * Adds join and leave times to the username.
     *
     * @param string $html
     * @param array $attendee
     * @return string
     */
    public static function joins_and_leaves($html, $attendee) {
        // Prepare join/leave info.
        $types = [
            'joins' => 'fa fa-sign-in',
            'leaves' => 'fa fa-sign-out',
        ];
        $joinleaves = [];
        foreach ($types as $key => $icon) {
            foreach ($attendee[$key] as $entry) {
                $timestamp = strtotime($entry);
                $date = userdate($timestamp);
                $joinleaves[$timestamp] = [
                    'key' => $key,
                    'icon' => $icon,
                    'date' => $date,
                ];
            }
        }
        ksort($joinleaves);

        // Append join/leave info to link.
        foreach ($joinleaves as $jl) {
            $icon = \html_writer::tag(
                'i',
                '',
                [
                    'class' => $jl['icon'] . ' me-1',
                    'aria-hidden' => 'true',
                    'role' => 'img',
                    'title' => get_string($jl['key'], 'local_bbb_lad'),
                ]
            );
            $text = $jl['date'];
            $html .= \html_writer::tag('small', $icon . $text, ['class' => 'd-block']);
        }
        return $html;
    }

    /**
     * Formats joins or leaves for download.
     *
     * @param array $dates joins or leaves
     * @return string formatted dates
     */
    public static function format_jl_for_download($dates) {
        $formatteddates = [];
        foreach ($dates as $date) {
            $timestamp = strtotime($date);
            $formatteddates[] = userdate($timestamp);
        }
        return implode(', ', $formatteddates);
    }

    /**
     * Lists an array of dates.
     *
     * @param array $dates
     * @return string formatted datelist
     */
    public static function list_dates($dates) {
        $formatteddates = [];
        foreach ($dates as $date) {
            $timestamp = strtotime($date);
            $formatteddates[] = userdate($timestamp);
        }
        return implode(', ', $formatteddates);
    }

    /**
     * Formats time, but returns empty string for 0.
     *
     * @param int $timeinseconds
     * @return string formatted time or empty string
     */
    public static function format_time($timeinseconds) {
        if ($timeinseconds == 0) {
            return get_string('none');
        }
        return format_time($timeinseconds);
    }

    /**
     * Gets attendee name.
     *
     * @param array $attendee
     * @return string name
     */
    public static function get_attendee_name($attendee) {
        global $DB;

        $userid = $attendee['ext_user_id'];
        $userrec = $DB->get_record('user', ['id' => $userid]);
        if (!$userrec) {
            $attendee['name'] .= ' (' . get_string('guest') . ')';
        } elseif ($userrec->deleted) {
            $attendee['name'] = ' [' . get_string('deleteduser', 'local_bbb_lad') . ']';
        }
        return $attendee['name'];
    }

    /**
     * Gets attendee roles.
     *
     * @param array $attendee
     * @param context $context
     * @return string roles
     */
    public static function user_roles($attendee, $context) {
        global $DB;

        // Find user.
        $userid = $attendee['ext_user_id'];
        $userrec = $DB->get_record('user', ['id' => $userid]);
        if (!$userrec) {
            return get_string('guest');
        }

        // Get roles.
        $roles = get_user_roles($context, $userid);

        $names = array_map(function($ra) use ($context) {
            return role_get_name($ra, $context, ROLENAME_ALIAS);
        }, $roles);

        $rolenames = implode(', ', array_unique($names));
        if (empty($rolenames)) {
            $rolenames = get_string('none');
        } else {
            // Remove trailing comma and space if present.
            $rolenames = rtrim($rolenames, ', ');
        }

        return $rolenames;
    }

}
