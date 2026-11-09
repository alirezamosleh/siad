<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');

global $DB;

// چک کردن چند BBB مختلف
$bbbs = $DB->get_records('bigbluebuttonbn', null, 'id DESC', '*', 0, 5);

echo "📋 Sample BBB records:\n";
echo "=====================\n\n";

foreach ($bbbs as $bbb) {
    echo "ID: {$bbb->id}\n";
    echo "Course: {$bbb->course}\n";
    echo "Name: {$bbb->name}\n";
    echo "Meeting ID: " . (empty($bbb->meetingid) ? '(empty)' : $bbb->meetingid) . "\n";
    echo "Moderator Pass: " . (empty($bbb->moderatorpass) ? '(empty)' : $bbb->moderatorpass) . "\n";
    echo "Viewer Pass: " . (empty($bbb->viewerpass) ? '(empty)' : $bbb->viewerpass) . "\n";
    echo "Type: {$bbb->type}\n";
    echo "Presentation: " . (isset($bbb->presentation) ? "'{$bbb->presentation}'" : 'NULL') . "\n";
    echo "Participants: " . (empty($bbb->participants) ? '(empty)' : $bbb->participants) . "\n";
    echo "---\n\n";
}

// چک کردن یک BBB که کار می‌کند
$working_bbb = $DB->get_record_sql("
    SELECT b.*, c.shortname, c.fullname 
    FROM {bigbluebuttonbn} b
    JOIN {course} c ON c.id = b.course
    WHERE b.course != 0
    ORDER BY b.timemodified DESC
    LIMIT 1
");

if ($working_bbb) {
    echo "🟢 Latest working BBB:\n";
    echo "=====================\n";
    echo "ID: {$working_bbb->id}\n";
    echo "Course: {$working_bbb->shortname}\n";
    echo "Meeting ID: " . (empty($working_bbb->meetingid) ? '(empty)' : $working_bbb->meetingid) . "\n";
    echo "Moderator Pass: " . (empty($working_bbb->moderatorpass) ? '(empty)' : $working_bbb->moderatorpass) . "\n";
    echo "Viewer Pass: " . (empty($working_bbb->viewerpass) ? '(empty)' : $working_bbb->viewerpass) . "\n";
}
