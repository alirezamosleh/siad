<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');

global $DB;

echo "🔧 Starting BigBlueButton Fix & Add Script (FINAL)\n";
echo "=====================================================================\n\n";

/**
 * تولید meetingid تصادفی (40 کاراکتر hex)
 */
function bbb_generate_meeting_id() {
    return bin2hex(random_bytes(20));
}

/**
 * تولید پسورد تصادفی (12 کاراکتر hex)
 */
function bbb_generate_password() {
    return bin2hex(random_bytes(6));
}

/**
 * تولید پسورد قابل خواندن (8 کاراکتر)
 */
function bbb_generate_readable_password() {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < 8; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// ============================================
// بخش 1: اصلاح رکوردهای قبلی ناقص
// ============================================
echo "📝 Step 1: Fixing existing incomplete BBB records...\n";

$existing_bbbs = $DB->get_records('bigbluebuttonbn');
$fixed_count = 0;

foreach ($existing_bbbs as $bbb) {
    $needs_update = false;
    $updates = new stdClass();
    $updates->id = $bbb->id;
    
    // ✅ اصلاح meetingid
    if (empty($bbb->meetingid)) {
        $updates->meetingid = bbb_generate_meeting_id();
        $needs_update = true;
    }
    
    // ✅ اصلاح moderatorpass
    if (empty($bbb->moderatorpass)) {
        $updates->moderatorpass = bbb_generate_password();
        $needs_update = true;
    }
    
    // ✅ اصلاح viewerpass
    if (empty($bbb->viewerpass)) {
        $updates->viewerpass = bbb_generate_password();
        $needs_update = true;
    }
    
    // ✅ اصلاح timecreated
    if (empty($bbb->timecreated) || $bbb->timecreated == 0) {
        $updates->timecreated = time();
        $needs_update = true;
    }
    
    // ✅ اصلاح guestlinkuid
    if (!isset($bbb->guestlinkuid) || $bbb->guestlinkuid === null || empty($bbb->guestlinkuid)) {
        $updates->guestlinkuid = bbb_generate_meeting_id();
        $needs_update = true;
    }
    
    // ✅ اصلاح guestpassword
    if (!isset($bbb->guestpassword) || $bbb->guestpassword === null || empty($bbb->guestpassword)) {
        $updates->guestpassword = bbb_generate_readable_password();
        $needs_update = true;
    }
    
    // ✅ اصلاح recordings_preview
    if (!isset($bbb->recordings_preview) || $bbb->recordings_preview != 1) {
        $updates->recordings_preview = 1;
        $needs_update = true;
    }
    
    // فیلدهای دیگر
    $fields_to_check = [
        'type' => 0,
        'wait' => 0,
        'userlimit' => 0,
        'record' => 1,
        'recordallfromstart' => 0,
        'recordhidebutton' => 0,
        'welcome' => '',
        'presentation' => '',
        'voicebridge' => 0,
        'openingtime' => 0,
        'closingtime' => 0,
        'recordings_html' => 0,
        'recordings_deleted' => 1,
        'recordings_imported' => 0,
        'clienttype' => 0,
        'guestallowed' => 0,
        'mustapproveuser' => 1,
        'muteonstart' => 0,
        'disablecam' => 0,
        'disablemic' => 0,
        'disableprivatechat' => 0,
        'disablepublicchat' => 0,
        'disablenote' => 0,
        'hideuserlist' => 0,
        'completionattendance' => 0,
        'completionengagementchats' => 0,
        'completionengagementtalks' => 0,
        'completionengagementraisehand' => 0,
        'completionengagementpollvotes' => 0,
        'completionengagementemojis' => 0,
        'showpresentation' => 1,
    ];
    
    foreach ($fields_to_check as $field => $default_value) {
        if (!isset($bbb->$field) || $bbb->$field === null) {
            $updates->$field = $default_value;
            $needs_update = true;
        }
    }
    
    // اصلاح participants
    if (!isset($bbb->participants) || empty($bbb->participants)) {
        $updates->participants = '[{"selectiontype":"all","selectionid":"all","role":"viewer"}]';
        $needs_update = true;
    }
    
    if ($needs_update) {
        try {
            $DB->update_record('bigbluebuttonbn', $updates);
            $fixed_count++;
            echo "  ✅ Fixed BBB ID: {$bbb->id} (Course: {$bbb->course})\n";
        } catch (Exception $e) {
            echo "  ❌ Error fixing BBB ID {$bbb->id}: {$e->getMessage()}\n";
        }
    }
}

echo "\n✅ Fixed {$fixed_count} existing BBB records\n\n";

// ============================================
// بخش 2: افزودن BBB جدید
// ============================================
echo "📝 Step 2: Adding BBB to courses without it...\n";

$courses = $DB->get_records('course', array(), '', 'id, shortname, fullname');
$added_count = 0;
$skipped_count = 0;

foreach ($courses as $course) {
    if ($course->id == SITEID) {
        continue;
    }
    
    if ($DB->record_exists('bigbluebuttonbn', ['course' => $course->id])) {
        $skipped_count++;
        continue;
    }

    try {
        $module = $DB->get_record('modules', array('name' => 'bigbluebuttonbn'));
        
        if (!$module) {
            echo "❌ BigBlueButton module not found!\n";
            break;
        }

        // ساخت course_modules
        $cm = new stdClass();
        $cm->course = $course->id;
        $cm->module = $module->id;
        $cm->instance = 0;
        $cm->section = 0;
        $cm->visible = 1;
        $cm->added = time();
        
        $cm->id = $DB->insert_record('course_modules', $cm);

        // ساخت bigbluebuttonbn کامل
        $bbb = new stdClass();
        $bbb->course = $course->id;
        $bbb->name = 'کلاس آنلاین';
        $bbb->intro = '';
        $bbb->introformat = FORMAT_HTML;
        $bbb->timecreated = time();
        $bbb->timemodified = time();
        
        // ✅ تنظیمات اصلی با ID و پسوردهای تصادفی
        $bbb->type = 0;
        $bbb->meetingid = bbb_generate_meeting_id();
        $bbb->moderatorpass = bbb_generate_password();
        $bbb->viewerpass = bbb_generate_password();
        $bbb->wait = 0;
        $bbb->userlimit = 0;
        $bbb->record = 1;
        $bbb->recordallfromstart = 0;
        $bbb->recordhidebutton = 0;
        $bbb->welcome = '';
        $bbb->voicebridge = 0;
        $bbb->presentation = '';
        $bbb->participants = '[{"selectiontype":"all","selectionid":"all","role":"viewer"}]';
        
        // تنظیمات دسترسی
        $bbb->openingtime = 0;
        $bbb->closingtime = 0;
        $bbb->guestallowed = 0;
        $bbb->mustapproveuser = 1;
        
        // ✅ تنظیمات guest
        $bbb->guestlinkuid = bbb_generate_meeting_id();
        $bbb->guestpassword = bbb_generate_readable_password();
        
        // تنظیمات recordings
        $bbb->recordings_html = 0;
        $bbb->recordings_deleted = 1;
        $bbb->recordings_imported = 0;
        $bbb->recordings_preview = 1;
        
        // تنظیمات نمایش
        $bbb->clienttype = 0;
        $bbb->muteonstart = 0;
        $bbb->disablecam = 0;
        $bbb->disablemic = 0;
        $bbb->disableprivatechat = 0;
        $bbb->disablepublicchat = 0;
        $bbb->disablenote = 0;
        $bbb->hideuserlist = 0;
        $bbb->showpresentation = 1;
        
        // Completion
        $bbb->completionattendance = 0;
        $bbb->completionengagementchats = 0;
        $bbb->completionengagementtalks = 0;
        $bbb->completionengagementraisehand = 0;
        $bbb->completionengagementpollvotes = 0;
        $bbb->completionengagementemojis = 0;
        
        // درج
        $bbb->id = $DB->insert_record('bigbluebuttonbn', $bbb);

        // آپدیت instance
        $DB->set_field('course_modules', 'instance', $bbb->id, array('id' => $cm->id));

        // اضافه به section
        course_add_cm_to_section($course->id, $cm->id, 0);

        // ریبیلد کش
        rebuild_course_cache($course->id, true);

        $added_count++;
        echo "  ✅ Added BBB to: {$course->id} ({$course->shortname})\n";

    } catch (Exception $e) {
        echo "  ❌ Error in course {$course->id}: {$e->getMessage()}\n";
    }
}

echo "\n";
echo "=====================================================================\n";
echo "🎉 Completed!\n";
echo "  • Fixed: {$fixed_count} existing records\n";
echo "  • Added: {$added_count} new BBB activities\n";
echo "  • Skipped: {$skipped_count} courses (already have BBB)\n";
echo "\n⚠️  Don't forget to purge caches:\n";
echo "  php admin/cli/purge_caches.php\n";
echo "=====================================================================\n";
