<?php
/**
 * copy_all_quizzes.php — Bitnami Moodle 4.5 / Docker
 */

define('CLI_SCRIPT', true);
require('/bitnami/moodle/config.php');
require_once($CFG->libdir  . '/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

list($options, $_) = cli_get_params(
    ['from' => false, 'to' => false, 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || !$options['from'] || !$options['to']) {
    echo "\nاستفاده:\n";
    echo "  php copy_all_quizzes.php --from=<shortname> --to=<shortname1,shortname2,...>\n\n";
    exit(0);
}

function find_course_by_shortname(string $shortname): ?stdClass {
    global $DB;
    return $DB->get_record('course', ['shortname' => trim($shortname)]) ?: null;
}

function get_course_quizzes(int $course_id): array {
    global $DB;
    $module_id = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
    return $DB->get_records_sql("
        SELECT cm.id AS cmid, q.id AS quizid, q.name
        FROM {course_modules} cm
        JOIN {quiz} q ON q.id = cm.instance
        WHERE cm.course = :courseid
          AND cm.module = :moduleid
          AND cm.deletioninprogress = 0
        ORDER BY cm.section, cm.id
    ", ['courseid' => $course_id, 'moduleid' => $module_id]);
}

function copy_quiz_to_course(int $cmid, int $target_courseid, int $admin_id): bool {
    global $CFG;

    try {
        // ── BACKUP ──────────────────────────────────────────
        $bc = new backup_controller(
            backup::TYPE_1ACTIVITY,
            $cmid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $admin_id
        );

        // force همه settings — حل مشکل setting_locked_by_permission
        foreach ($bc->get_plan()->get_settings() as $setting) {
            $name = $setting->get_name();
            // بدون داده کاربران
            if ($name === 'users' || $name === 'role_assignments' ||
                $name === 'comments' || $name === 'logs' ||
                $name === 'grade_histories' || $name === 'groups') {
                try { $setting->set_value(0); } catch (Throwable $e) {}
            }
            // unlock هر setting قفل‌شده
            if ($setting->get_status() === base_setting::LOCKED_BY_PERMISSION) {
                $setting->set_status(base_setting::NOT_LOCKED);
                try { $setting->set_value(0); } catch (Throwable $e) {}
            }
        }

        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->destroy();

        // ── RESTORE ─────────────────────────────────────────
        $rc = new restore_controller(
            $backupid,
            $target_courseid,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $admin_id,
            backup::TARGET_EXISTING_ADDING
        );

        // unlock settings در restore هم
        foreach ($rc->get_plan()->get_settings() as $setting) {
            if ($setting->get_status() === base_setting::LOCKED_BY_PERMISSION) {
                $setting->set_status(base_setting::NOT_LOCKED);
            }
        }

        if (!$rc->execute_precheck()) {
            $results = $rc->get_precheck_results();
            if (!empty($results['errors'])) {
                foreach ($results['errors'] as $err) {
                    echo "\n      precheck error: $err";
                }
                $rc->destroy();
                $tmpdir = $CFG->tempdir . '/backup/' . $backupid;
                if (is_dir($tmpdir)) fulldelete($tmpdir);
                return false;
            }
        }

        $rc->execute_plan();
        $rc->destroy();

        $tmpdir = $CFG->tempdir . '/backup/' . $backupid;
        if (is_dir($tmpdir)) fulldelete($tmpdir);

        return true;

    } catch (Throwable $e) {
        echo "\n      خطا: " . $e->getMessage();
        if (!empty($backupid)) {
            $tmpdir = $CFG->tempdir . '/backup/' . $backupid;
            if (is_dir($tmpdir)) fulldelete($tmpdir);
        }
        return false;
    }
}

// ── اجرای اصلی ───────────────────────────────────────────────
$admin = get_admin();
\core\session\manager::set_user($admin);

$source = find_course_by_shortname((string)$options['from']);
if (!$source) cli_error("درس مبدا «{$options['from']}» پیدا نشد.");

$quizzes = get_course_quizzes((int)$source->id);
if (empty($quizzes)) {
    global $DB;
    $modules = $DB->get_records_sql("
        SELECT m.name, COUNT(*) as cnt
        FROM {course_modules} cm
        JOIN {modules} m ON m.id = cm.module
        WHERE cm.course = :cid AND cm.deletioninprogress = 0
        GROUP BY m.name
    ", ['cid' => $source->id]);
    echo "\nدرس «{$source->shortname}» هیچ quiz ای ندارد.\nActivity های موجود:\n";
    foreach ($modules as $m) echo "  - {$m->name}: {$m->cnt}\n";
    exit(1);
}

$targets = [];
foreach (array_map('trim', explode(',', $options['to'])) as $sn) {
    if ($sn === '') continue;
    $c = find_course_by_shortname($sn);
    $c ? $targets[] = $c : print("⚠  «{$sn}» پیدا نشد — رد شد\n");
}
if (empty($targets)) cli_error("هیچ درس مقصد معتبری پیدا نشد.");

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "درس مبدا  : [{$source->shortname}] {$source->fullname}\n";
echo "تعداد quiz: " . count($quizzes) . "\n";
foreach ($quizzes as $q) echo "  • {$q->name} (cmid={$q->cmid})\n";
echo "مقصدها    : " . implode(', ', array_column($targets, 'shortname')) . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$ok = $fail = 0;

foreach ($targets as $target) {
    if ((int)$target->id === (int)$source->id) {
        echo "⚠  [{$target->shortname}] همان مبدا است — رد شد\n\n";
        continue;
    }
    echo "📂 [{$target->shortname}] {$target->fullname}\n";
    foreach ($quizzes as $q) {
        echo "   🔄 {$q->name} ... ";
        if (copy_quiz_to_course((int)$q->cmid, (int)$target->id, (int)$admin->id)) {
            echo "✓\n"; $ok++;
        } else {
            echo " ✗\n"; $fail++;
        }
    }
    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "نتیجه کل : {$ok} موفق،  {$fail} ناموفق\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
