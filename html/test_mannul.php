<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');

global $DB;

// یک BBB دستی را پیدا کنید
$manual_bbb = $DB->get_record_sql("
    SELECT * FROM {bigbluebuttonbn} 
    WHERE meetingid IS NOT NULL AND meetingid != ''
    LIMIT 1
");

if ($manual_bbb) {
    echo "📋 Sample manual BBB record:\n";
    echo "ID: {$manual_bbb->id}\n";
    echo "Course: {$manual_bbb->course}\n";
    echo "Meeting ID: {$manual_bbb->meetingid}\n";
    echo "Moderator Pass: {$manual_bbb->moderatorpass}\n";
    echo "Viewer Pass: {$manual_bbb->viewerpass}\n";
    echo "Participants: {$manual_bbb->participants}\n";
} else {
    echo "❌ No manual BBB found with meetingid\n";
}
