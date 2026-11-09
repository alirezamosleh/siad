<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
global $DB, $CFG;

require_once($CFG->dirroot . '/enrol/manual/lib.php');

$categoryid = 3; // Only this category

$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
if (!$studentroleid) {
    die("Student role not found.\n");
}

// Get all courses in category 3 only.
$courses = $DB->get_records('course', ['category' => $categoryid]);
if (!$courses) {
    die("No courses found in category {$categoryid}.\n");
}

$manualplugin = enrol_get_plugin('manual');

$unenrolledCount = 0;
$skippedCount = 0;

foreach ($courses as $course) {
    if ($course->id == SITEID) {
        continue;
    }

    $context = context_course::instance($course->id);

    // Get all users with the student role in this course.
    $students = get_role_users(
        $studentroleid,
        $context,
        false,
        'u.id,u.firstname,u.lastname'
    );

    if (empty($students)) {
        continue;
    }

    // Find the manual enrol instance for this course.
    $instances = enrol_get_instances($course->id, false);
    $manualinstance = null;
    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            $manualinstance = $instance;
            break;
        }
    }

    if (!$manualinstance) {
        echo "No manual enrol instance for {$course->fullname}, skipping (students may be enrolled via another method).\n";
        continue;
    }

    foreach ($students as $student) {
        // Only unenrol if the enrolment was via the manual method.
        $isManuallyEnrolled = $DB->record_exists('user_enrolments', [
            'enrolid' => $manualinstance->id,
            'userid' => $student->id,
        ]);

        if (!$isManuallyEnrolled) {
            $skippedCount++;
            continue;
        }

        $manualplugin->unenrol_user($manualinstance, $student->id);
        $unenrolledCount++;
        echo "Unenrolled {$student->firstname} {$student->lastname} from {$course->fullname}\n";
    }
}

echo "Total unenrolled: {$unenrolledCount}\n";
echo "Skipped (not manually enrolled): {$skippedCount}\n";
echo "Done.\n";
