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
 * Learning analytics dashboard.
 *
 * @package    local_bbb_lad
 * @copyright  2026, think modular
 * @author     think modular (stefan.weber@think-modular.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_bbb_lad\table;

require_once(__DIR__ . '/../../config.php');
require_login();

global $DB;

// Get course module ID.
$cmid = required_param('cmid', PARAM_INT);
$recordid = optional_param('recordid', null, PARAM_INT);

// Get course module and context.
$cm = get_coursemodule_from_id('bigbluebuttonbn', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
$instanceid = $cm->instance;
$instance = $DB->get_record('bigbluebuttonbn', ['id' => $instanceid], '*', MUST_EXIST);

// Check capabilities.
require_capability('local/bbb_lad:viewlad', $context);

// Set page.
$url = new moodle_url(
    '/local/bbb_lad/viewlad.php',
    [
        'instance' => $instanceid,
        'cmid' => $cmid,
        'recordid' => $recordid,
    ]
);
$PAGE->set_pagelayout('admin');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_course($course);
$PAGE->set_cm($cm);

// Get records.
$params = ['bigbluebuttonbnid' => $instanceid];
if ($recordid) {
    $params['id'] = $recordid;
}
$records = $DB->get_records('local_bbb_lad', $params, 'timestamp DESC');

if (!$records) {
    // No records found.
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('norecords', 'local_bbb_lad'));
    echo $OUTPUT->footer();
    die();
}

// Show buttons to select Meeting if no recordid is given yet.
if (!$recordid && count($records) > 1) {
    echo $OUTPUT->header();
    $table = new html_table();
    $table->attributes['class'] = 'table table-striped';
    $table->head = [get_string('selectmeeting', 'local_bbb_lad')];
    foreach ($records as $record) {
        $name = get_string('meeting', 'local_bbb_lad', userdate($record->timestamp));
        $url = new moodle_url('/local/bbb_lad/viewlad.php', ['cmid' => $cmid, 'recordid' => $record->id]);
        $table->data[] = [html_writer::link($url, $name)];
    }
    echo html_writer::table($table);
    echo $OUTPUT->footer();
} else {
    // Create table.
    $download = optional_param('download', '', PARAM_ALPHA);
    $table = new flexible_table('local_bbb_lad');
    $name = 'bbb_learning_analytics_' . $instanceid;
    $table->is_downloading($download, $name, $name);

    // Columns and headers.
    $cols = [];
    $cols[] = 'userid';
    $cols[] = 'roles';
    if ($table->is_downloading()) {
        $cols[] = 'joins';
        $cols[] = 'leaves';
    }
    $cols[] = 'duration';
    $cols[] = 'talk_time';
    $cols[] = 'chats';
    $cols[] = 'talks';
    $cols[] = 'poll_votes';
    $table->define_columns($cols);

    $headers = [];
    $headers[] = get_string('table:participant', 'local_bbb_lad');
    if ($table->is_downloading()) {
        $headers[] = get_string('table:joins', 'local_bbb_lad');
        $headers[] = get_string('table:leaves', 'local_bbb_lad');
    }
    $headers[] = get_string('roles');
    $headers[] = get_string('table:duration', 'local_bbb_lad');
    $headers[] = get_string('table:talk_time', 'local_bbb_lad');
    $headers[] = get_string('table:chats', 'local_bbb_lad');
    $headers[] = get_string('table:talks', 'local_bbb_lad');
    $headers[] = get_string('table:poll_votes', 'local_bbb_lad');
    $table->define_headers($headers);

    $table->column_class('duration', 'text-right');
    $table->column_class('talk_time', 'text-right');
    $table->column_class('chats', 'text-right');
    $table->column_class('talks', 'text-right');
    $table->column_class('poll_votes', 'text-right');

    $table->define_baseurl($PAGE->url);
    $table->sortable(true, 'joined', SORT_DESC);
    $table->pageable(true);
    $table->collapsible(false);
    // [FIX]: حذف فراخوانی تکراری و متناقض is_downloading('payment_log') قبل از setup() که مانع دانلود صحیح اکسل/CSV می‌شد.
    $table->setup();

    if (!$table->is_downloading()) {
        // Only print headers if not asked to download data.
        // Print the page header.
        $title = get_string('lad', 'local_bbb_lad', $instance->name);
        $PAGE->set_title($title);
        $PAGE->set_heading($title);
        echo $OUTPUT->header();
    }

    // Add data.
    $record = reset($records);
    // [FIX]: بررسی امن دکود JSON و آرایه بودن attendees جهت جلوگیری از Fatal Error در جلساتی که داده لاگ خالی یا ناقص است.
    $data = !empty($record->data) ? json_decode($record->data, true) : null;
    $attendees = (is_array($data) && isset($data['data']['attendees']) && is_array($data['data']['attendees']))
        ? $data['data']['attendees']
        : [];

    if (empty($attendees)) {
        if (!$table->is_downloading()) {
            echo $OUTPUT->notification(get_string('norecords', 'local_bbb_lad'), 'info');
        }
    } else {
        foreach ($attendees as $attendee) {
            $attendee['name'] = table::get_attendee_name($attendee);

            $data = [];
            if ($table->is_downloading()) {
                $data[] = $attendee['name'];
                $data[] = table::format_jl_for_download($attendee['joins'] ?? []);
                $data[] = table::format_jl_for_download($attendee['leaves'] ?? []);
            } else {
                $data[] = table::userlink($attendee);
            }
            $data[] = table::user_roles($attendee, $context);
            $data[] = table::format_time((int)($attendee['duration'] ?? 0));
            $data[] = table::format_time((int)($attendee['engagement']['talk_time'] ?? 0));
            $data[] = $attendee['engagement']['chats'] ?? 0;
            $data[] = $attendee['engagement']['talks'] ?? 0;
            $data[] = $attendee['engagement']['poll_votes'] ?? 0;
            $table->add_data($data);
        }
    }
    $table->finish_output();

    if (!$table->is_downloading()) {
        // Back to menu settings link.
        $url = new moodle_url('/mod/bigbluebuttonbn/view.php', ['id' => $cmid]);
        echo html_writer::link($url, get_string('back'), ['class' => 'btn btn-secondary m-1']);

        echo $OUTPUT->footer();
    }
}
