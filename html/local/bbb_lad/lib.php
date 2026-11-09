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
 * Library functions for the local_bbb_lap plugin.
 *
 * @package    local_bbb_lad
 * @copyright  2026, think modular
 * @author     think modular (stefan.weber@think-modular.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extend settings navigation
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 */
function local_bbb_lad_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $DB, $PAGE;

    // Only on module pages.
    if ($PAGE->cm === null) {
        return;
    }

    // Only for BigBlueButtonBN.
    if ($PAGE->cm->modname !== 'bigbluebuttonbn') {
        return;
    }

    // Check capability.
    if (!has_capability('local/bbb_lad:viewlad', $context)) {
        return;
    }

    // Check if enabled or data exists.
    $instanceid = $PAGE->cm->instance;
    $enabled = $DB->get_record(
        'bbbext_lad',
        [
            'bigbluebuttonbnid' => $instanceid,
            'enabled' => 1,
        ]
    );
    $data = $DB->get_records('local_bbb_lad', ['bigbluebuttonbnid' => $PAGE->cm->instance]);

    if (!$enabled || empty($data)) {
        return;
    }

    $url = new moodle_url('/local/bbb_lad/viewlad.php', ['cmid' => $PAGE->cm->id]);

    // Find the activity settings node and add to it.
    if ($node = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING)) {
        $node->add(
            get_string('viewlad', 'local_bbb_lad'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'local_bbb_lad_viewlad',
            new pix_icon('i/settings', '')
        );
    }
}
