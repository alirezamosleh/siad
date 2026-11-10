<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Manual sync and API diagnostics admin page with granular filters.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_morvaridsync_run');

$run = optional_param('run', 0, PARAM_BOOL);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$diagnose = optional_param('diagnose', 0, PARAM_BOOL);

$PAGE->set_url(new moodle_url('/local/morvaridsync/index.php'));
$PAGE->set_title(get_string('manualsync', 'local_morvaridsync'));
$PAGE->set_heading(get_string('manualsync', 'local_morvaridsync'));

echo $OUTPUT->header();

$client = new \local_morvaridsync\client\morvarid_rest();

// --- Diagnostics Block ---
if ($diagnose) {
    require_sesskey();
    echo html_writer::tag('h3', get_string('diagnostics', 'local_morvaridsync'));

    if (!$client->is_configured()) {
        echo $OUTPUT->notification(get_string('error_notconfigured', 'local_morvaridsync'), 'error');
    } else {
        $diagterm = (string)(get_config('local_morvaridsync', 'activeterm') ?: '14042');
        $tests = [
            'Stu_Lms_Rest_TermList' => fn() => $client->term_list(),
            'Stu_Lms_Rest_DepratInfo' => fn() => $client->department_info($diagterm),
            'Stu_Rest_GetFacultyList' => fn() => $client->faculty_list(),
            'Stu_Rest_GetCourseStudyList' => fn() => $client->course_study_list(),
            'Stu_Lms_Rest_TeacherInfo' => fn() => $client->teacher_info($diagterm),
            'Teacher_Rest_GetProfessorList' => fn() => $client->professor_list(),
            'Stu_Lms_Rest_StuInfo' => fn() => $client->student_info($diagterm),
            'Stu_Lms_Rest_OfferTeach' => fn() => $client->offer_teach('', '', $diagterm, ''),
            'Stu_Lms_Rest_OfferGroup' => fn() => $client->offer_group('', $diagterm),
        ];

        $table = new html_table();
        $table->head = [
            get_string('endpoint_tested', 'local_morvaridsync'),
            get_string('status', 'local_morvaridsync'),
            get_string('records_count', 'local_morvaridsync'),
            get_string('sample_data', 'local_morvaridsync'),
        ];

        foreach ($tests as $name => $fn) {
            $status = 'success';
            $count = 0;
            $sample = '';
            try {
                $res = $fn();
                $count = count($res);
                if ($count > 0) {
                    $sample = json_encode(array_slice($res, 0, 1), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                } else {
                    $sample = get_string('no_data_returned', 'local_morvaridsync');
                }
            } catch (\Throwable $e) {
                $status = 'error';
                $sample = $e->getMessage();
            }

            $badge = $status === 'success'
                ? html_writer::span('OK', 'badge badge-success text-success font-weight-bold')
                : html_writer::span('خطا', 'badge badge-danger text-danger font-weight-bold');

            $table->data[] = [
                html_writer::tag('code', s($name)),
                $badge,
                $count,
                html_writer::tag('pre', s($sample), ['style' => 'max-height: 120px; overflow-y: auto; font-size: 11px; direction: ltr; text-align: left;']),
            ];
        }

        echo html_writer::table($table);
    }
}

// --- Execution Block ---
if ($run) {
    require_sesskey();

    $options = [
        'term' => optional_param('term', '', PARAM_ALPHANUMEXT),
        'sync_scope' => optional_param('sync_scope', 'all', PARAM_ALPHAEXT),
        'entry_year_min' => optional_param('entry_year_min', '', PARAM_RAW_TRIMMED),
        'entry_year_max' => optional_param('entry_year_max', '', PARAM_RAW_TRIMMED),
        'filter_programs' => optional_param('filter_programs', '', PARAM_RAW_TRIMMED),
        'suspend_missing_enrol' => optional_param('suspend_missing_enrol', 0, PARAM_BOOL),
        'auto_create_activities' => optional_param('auto_create_activities', 1, PARAM_BOOL),
    ];

    if (!$confirm) {
        $confirmurl = new moodle_url('/local/morvaridsync/index.php', array_merge($options, [
            'run' => 1,
            'confirm' => 1,
            'sesskey' => sesskey(),
        ]));
        echo $OUTPUT->confirm(
            get_string('runsync_confirm', 'local_morvaridsync'),
            $confirmurl,
            new moodle_url('/local/morvaridsync/index.php')
        );
        echo $OUTPUT->footer();
        exit;
    }

    @set_time_limit(0);
    raise_memory_limit(MEMORY_EXTRA);

    $orchestrator = new \local_morvaridsync\sync\orchestrator();
    $log = $orchestrator->run((int)$USER->id, $options);

    echo $OUTPUT->notification(
        get_string('syncrunning', 'local_morvaridsync') .
        ' — ' . s($log->status) . ' / term=' . s((string)$log->termcode) .
        ' / ایجادی: ' . (int)$log->createdcount .
        ' / به‌روزرسانی: ' . (int)$log->updatedcount .
        ' / ثبت‌نام: ' . (int)$log->enrolledcount .
        ' / خطا: ' . (int)$log->errorcount,
        $log->status === 'success' ? 'success' : 'error'
    );

    echo html_writer::tag('pre', s($log->message), [
        'class' => 'local-morvaridsync-msg alert alert-info',
        'style' => 'white-space: pre-wrap; direction: rtl; text-align: right; font-size: 13px; line-height: 1.6;',
    ]);
}

// --- Manual Sync Parameters Form ---
$defaultterm = (string)get_config('local_morvaridsync', 'activeterm');
$defaultscope = (string)(get_config('local_morvaridsync', 'sync_scope') ?: 'all');
$defaultentrymin = (string)get_config('local_morvaridsync', 'entry_year_min');
$defaultentrymax = (string)get_config('local_morvaridsync', 'entry_year_max');
$defaultprograms = (string)get_config('local_morvaridsync', 'filter_programs');
$defaultsuspend = (bool)get_config('local_morvaridsync', 'suspend_missing_enrol');
$defaultautocreate = get_config('local_morvaridsync', 'auto_create_activities');
$defaultautocreate = ($defaultautocreate === false || !empty($defaultautocreate));

echo html_writer::start_div('card mb-4 shadow-sm');
echo html_writer::start_div('card-header bg-primary text-white font-weight-bold d-flex justify-content-between align-items-center');
echo html_writer::tag('span', 'تنظیمات و اجرای همگام‌سازی دستی با مروارید (سادا)');
echo html_writer::end_div();
echo html_writer::start_div('card-body');

$formaction = new moodle_url('/local/morvaridsync/index.php');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formaction->out_omit_querystring(), 'class' => 'mform']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'run', 'value' => '1']);

echo html_writer::start_div('row mb-3');

// Term input
echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::tag('label', get_string('activeterm', 'local_morvaridsync'), ['class' => 'font-weight-bold d-block']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'term',
    'value' => $defaultterm,
    'placeholder' => 'مثال: 14042 (خالی = ترم خودکار)',
    'class' => 'form-control',
]);
echo html_writer::tag('small', 'کد ترم سادا/مروارید جهت همگام‌سازی.', ['class' => 'text-muted']);
echo html_writer::end_div();

// Scope select
echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::tag('label', get_string('sync_scope', 'local_morvaridsync'), ['class' => 'font-weight-bold d-block']);
$scopeoptions = [
    'all' => get_string('scope_all', 'local_morvaridsync'),
    'users_only' => get_string('scope_users_only', 'local_morvaridsync'),
    'courses_only' => get_string('scope_courses_only', 'local_morvaridsync'),
    'enrolments_only' => get_string('scope_enrolments_only', 'local_morvaridsync'),
    'suspend_only' => get_string('scope_suspend_only', 'local_morvaridsync'),
];
echo html_writer::select($scopeoptions, 'sync_scope', $defaultscope, false, ['class' => 'custom-select form-control']);
echo html_writer::tag('small', 'حوزه داده‌هایی که در مودل اعمال می‌شوند.', ['class' => 'text-muted']);
echo html_writer::end_div();

// Options checkboxes
echo html_writer::start_div('col-md-4 mb-2 d-flex flex-column justify-content-center');
echo html_writer::tag('label', 'تنظیمات تکمیلی', ['class' => 'font-weight-bold d-block']);

echo html_writer::start_div('custom-control custom-checkbox mt-1');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'name' => 'auto_create_activities',
    'value' => '1',
    'id' => 'id_auto_create_activities',
    'class' => 'custom-control-input',
    'checked' => $defaultautocreate ? 'checked' : null,
]);
echo html_writer::tag('label', 'ایجاد ۱۶ جلسه بیگ‌بلوباتن + میان‌ترم و پایان‌ترم', ['for' => 'id_auto_create_activities', 'class' => 'custom-control-label font-weight-normal']);
echo html_writer::end_div();

echo html_writer::start_div('custom-control custom-checkbox mt-1');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'name' => 'suspend_missing_enrol',
    'value' => '1',
    'id' => 'id_suspend_missing_enrol',
    'class' => 'custom-control-input',
    'checked' => $defaultsuspend ? 'checked' : null,
]);
echo html_writer::tag('label', 'تعلیق ثبت‌نام‌های حذف شده در مروارید', ['for' => 'id_suspend_missing_enrol', 'class' => 'custom-control-label font-weight-normal']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // End row 1

echo html_writer::start_div('row mb-3');

// Entry year min
echo html_writer::start_div('col-md-3 mb-2');
echo html_writer::tag('label', get_string('entry_year_min', 'local_morvaridsync'), ['class' => 'font-weight-bold d-block']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'entry_year_min',
    'value' => $defaultentrymin,
    'placeholder' => 'مثال: 1403',
    'class' => 'form-control',
]);
echo html_writer::tag('small', 'مثلاً 1403 برای ورودی‌های ۱۴۰۳ به بعد.', ['class' => 'text-muted']);
echo html_writer::end_div();

// Entry year max
echo html_writer::start_div('col-md-3 mb-2');
echo html_writer::tag('label', get_string('entry_year_max', 'local_morvaridsync'), ['class' => 'font-weight-bold d-block']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'entry_year_max',
    'value' => $defaultentrymax,
    'placeholder' => 'مثال: 1404',
    'class' => 'form-control',
]);
echo html_writer::tag('small', 'حداکثر سال ورود مجاز (اختیاری).', ['class' => 'text-muted']);
echo html_writer::end_div();

// Filter programs
echo html_writer::start_div('col-md-6 mb-2');
echo html_writer::tag('label', get_string('filter_programs', 'local_morvaridsync'), ['class' => 'font-weight-bold d-block']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'filter_programs',
    'value' => $defaultprograms,
    'placeholder' => 'مثال: 236, 339 یا رنج 200-350',
    'class' => 'form-control',
]);
echo html_writer::tag('small', 'کدهای رشته با کاما یا رنج با خط تیره (خالی = همه رشته‌ها).', ['class' => 'text-muted']);
echo html_writer::end_div();

echo html_writer::end_div(); // End row 2

// Action buttons
echo html_writer::start_div('d-flex gap-2 pt-2 border-top');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('runsync', 'local_morvaridsync'),
    'class' => 'btn btn-primary px-4',
]);

$diagurl = new moodle_url('/local/morvaridsync/index.php', ['diagnose' => 1, 'sesskey' => sesskey()]);
echo html_writer::link($diagurl, get_string('run_diagnostics', 'local_morvaridsync'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div(); // card-body
echo html_writer::end_div(); // card

// --- Recent Logs Table ---
echo html_writer::tag('h3', get_string('synclog', 'local_morvaridsync'), ['class' => 'mt-4 mb-3']);

$logs = $DB->get_records('local_morvaridsync_log', null, 'timestart DESC', '*', 0, 30);
if (!$logs) {
    echo html_writer::div(get_string('nostarted', 'local_morvaridsync'));
} else {
    $table = new html_table();
    $table->head = [
        get_string('term', 'local_morvaridsync'),
        get_string('status', 'local_morvaridsync'),
        get_string('created', 'local_morvaridsync'),
        get_string('updated', 'local_morvaridsync'),
        get_string('enrolled', 'local_morvaridsync'),
        get_string('errors', 'local_morvaridsync'),
        get_string('message', 'local_morvaridsync'),
        get_string('date'),
    ];
    foreach ($logs as $log) {
        $badge = $log->status === 'success'
            ? html_writer::span('موفق', 'badge badge-success text-success font-weight-bold')
            : html_writer::span('خطا', 'badge badge-danger text-danger font-weight-bold');

        $table->data[] = [
            s((string)$log->termcode),
            $badge,
            (int)$log->createdcount,
            (int)$log->updatedcount,
            (int)$log->enrolledcount,
            (int)$log->errorcount,
            html_writer::tag('div', s(\core_text::substr((string)$log->message, 0, 200)), ['style' => 'direction: rtl; text-align: right;']),
            userdate($log->timestart),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
