<?php
/**
 * Unenroll ALL students from ALL courses - DRY RUN
 * Usage: php unenroll_all_students.php [--execute]
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');

$execute = in_array('--execute', $argv ?? []);

if ($execute) {
    echo "=== EXECUTE MODE - Changes WILL be made ===\n\n";
} else {
    echo "=== DRY RUN MODE - No changes will be made ===\n";
    echo "=== Run with --execute to actually unenroll ===\n\n";
}

$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
if (!$studentroleid) {
    die("Student role not found!\n");
}

$sql = "SELECT DISTINCT ue.id AS ueid, ue.userid, ue.enrolid, e.courseid, e.enrol,
               u.username, c.fullname AS coursename
        FROM {user_enrolments} ue
        JOIN {enrol} e ON e.id = ue.enrolid
        JOIN {role_assignments} ra ON ra.userid = ue.userid
        JOIN {context} ctx ON ctx.id = ra.contextid
            AND ctx.instanceid = e.courseid
            AND ctx.contextlevel = 50
        JOIN {user} u ON u.id = ue.userid
        JOIN {course} c ON c.id = e.courseid
        WHERE ra.roleid = :roleid
        AND e.courseid != :sitecourse
        ORDER BY e.courseid, u.username";

$enrolments = $DB->get_records_sql($sql, [
    'roleid'     => $studentroleid,
    'sitecourse' => SITEID,
]);

$count = count($enrolments);
echo "Found {$count} student enrolments across all courses.\n\n";

// Summary by course
$bycourse = [];
foreach ($enrolments as $rec) {
    $bycourse[$rec->coursename] = ($bycourse[$rec->coursename] ?? 0) + 1;
}

echo "--- Summary by course ---\n";
foreach ($bycourse as $coursename => $num) {
    echo sprintf("  %-60s %d students\n", mb_substr($coursename, 0, 60), $num);
}
echo "\nTotal courses affected: " . count($bycourse) . "\n";
echo "Total enrolments to remove: {$count}\n\n";

if (!$execute) {
    echo "=== DRY RUN complete. No changes made. ===\n";
    echo "=== Run with --execute to actually unenroll. ===\n";
    exit(0);
}

// --- EXECUTE ---
echo "--- Starting unenrollment ---\n";
$done = 0;
$errors = 0;

foreach ($enrolments as $rec) {
    $enrolplugin   = enrol_get_plugin($rec->enrol);
    $enrolinstance = $DB->get_record('enrol', ['id' => $rec->enrolid]);

    if (!$enrolplugin || !$enrolinstance) {
        echo "  SKIP: plugin '{$rec->enrol}' not available (enrolid={$rec->enrolid})\n";
        $errors++;
        continue;
    }

    try {
        $enrolplugin->unenrol_user($enrolinstance, $rec->userid);
        $done++;
        if ($done % 50 === 0) echo "  Progress: {$done}/{$count}\n";
    } catch (Exception $e) {
        echo "  ERROR userid={$rec->userid} courseid={$rec->courseid}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\nFinished! Removed: {$done} | Errors: {$errors}\n";
