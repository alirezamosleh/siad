<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Sync orchestrator for Morvarid → Moodle.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_morvaridsync\sync;

defined('MOODLE_INTERNAL') || die();

use local_morvaridsync\client\morvarid_rest;

/**
 * Runs the sync pipeline with multi-tier discovery, granular filtering, and scope support.
 */
class orchestrator {

    /** @var morvarid_rest */
    protected $client;

    /** @var moodle_writer */
    protected $writer;

    /**
     * Constructor.
     */
    public function __construct(?morvarid_rest $client = null, ?moodle_writer $writer = null) {
        $this->client = $client ?? new morvarid_rest();
        $this->writer = $writer ?? new moodle_writer();
    }

    /**
     * Resolve and merge execution options.
     *
     * @param array $options
     * @return array
     */
    public function resolve_options(array $options = []): array {
        $entrymin = isset($options['entry_year_min']) && $options['entry_year_min'] !== ''
            ? (int)morvarid_rest::normalize_digits((string)$options['entry_year_min'])
            : (int)(get_config('local_morvaridsync', 'entry_year_min') ?: 0);

        $entrymax = isset($options['entry_year_max']) && $options['entry_year_max'] !== ''
            ? (int)morvarid_rest::normalize_digits((string)$options['entry_year_max'])
            : (int)(get_config('local_morvaridsync', 'entry_year_max') ?: 0);

        $filterprog = isset($options['filter_programs'])
            ? trim((string)$options['filter_programs'])
            : trim((string)get_config('local_morvaridsync', 'filter_programs'));

        $scope = !empty($options['sync_scope'])
            ? (string)$options['sync_scope']
            : (string)(get_config('local_morvaridsync', 'sync_scope') ?: 'all');

        $suspendmissing = isset($options['suspend_missing_enrol'])
            ? (bool)$options['suspend_missing_enrol']
            : (bool)get_config('local_morvaridsync', 'suspend_missing_enrol');

        $term = !empty($options['term'])
            ? trim(morvarid_rest::normalize_digits((string)$options['term']))
            : trim((string)get_config('local_morvaridsync', 'activeterm'));

        return [
            'term' => $term,
            'sync_scope' => $scope,
            'entry_year_min' => $entrymin,
            'entry_year_max' => $entrymax,
            'filter_programs' => $filterprog,
            'suspend_missing_enrol' => $suspendmissing,
        ];
    }

    /**
     * Extract 4-digit Persian calendar entry year from a student code.
     * Supports 9-digit, 10-digit, and 12-digit student codes.
     *
     * @param string|null $studentcode
     * @return int|null 4-digit Persian year (e.g. 1403, 1404, 1398) or null
     */
    public static function extract_entry_year(?string $studentcode): ?int {
        if (empty($studentcode)) {
            return null;
        }
        $code = preg_replace('/\D+/', '', morvarid_rest::normalize_digits($studentcode));
        if ($code === '') {
            return null;
        }

        // 1) 4-digit prefix matching 13xx or 14xx (e.g. 140112240035, 1399...)
        if (preg_match('/^(13\d{2}|14\d{2})/', $code, $m)) {
            return (int)$m[1];
        }
        // 2) 3-digit prefix matching 4xx (e.g. 403339003 -> 1403, 404236022 -> 1404)
        if (preg_match('/^4(\d{2})/', $code, $m)) {
            return (int)('14' . $m[1]);
        }
        // 3) 3-digit prefix matching 3xx (e.g. 398... -> 1398, 399... -> 1399)
        if (preg_match('/^3(\d{2})/', $code, $m)) {
            return (int)('13' . $m[1]);
        }
        // 4) 2-digit prefix matching 8x or 9x (e.g. 9811222419 -> 1398, 99... -> 1399)
        if (preg_match('/^(8\d|9\d)/', $code, $m)) {
            return (int)('13' . $m[1]);
        }
        // 5) 2-digit prefix matching 0x or 1x or 2x (e.g. 02... -> 1402, 03... -> 1403)
        if (preg_match('/^(0\d|1\d|2\d)/', $code, $m)) {
            return (int)('14' . $m[1]);
        }

        return null;
    }

    /**
     * Check if a code matches a comma-separated filter list or range (e.g. "236, 339, 200-350").
     *
     * @param string|null $code
     * @param string|null $filterstring
     * @return bool
     */
    public static function matches_filter_codes(?string $code, ?string $filterstring): bool {
        if (empty($filterstring) || trim($filterstring) === '') {
            return true;
        }
        if (empty($code)) {
            return false;
        }

        $code = trim(morvarid_rest::normalize_digits($code));
        $parts = explode(',', $filterstring);
        foreach ($parts as $part) {
            $part = trim(morvarid_rest::normalize_digits($part));
            if ($part === '') {
                continue;
            }
            if (strpos($part, '-') !== false) {
                [$min, $max] = explode('-', $part, 2);
                $min = (int)trim($min);
                $max = (int)trim($max);
                $val = (int)$code;
                if ($val >= $min && $val <= $max) {
                    return true;
                }
            } else {
                if ($code === $part || (int)$code === (int)$part) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check if a student record passes the configured filters (entry year & program).
     *
     * @param array $student
     * @param array $options
     * @return bool
     */
    public function passes_student_filters(array $student, array $options): bool {
        // 1. Entry Year Filter:
        $stdcode = (string)($student['student_code'] ?? $student['code'] ?? $student['username'] ?? '');
        $entryyear = self::extract_entry_year($stdcode);

        if ($entryyear !== null) {
            if (!empty($options['entry_year_min']) && $entryyear < $options['entry_year_min']) {
                return false;
            }
            if (!empty($options['entry_year_max']) && $entryyear > $options['entry_year_max']) {
                return false;
            }
        } else if (!empty($options['entry_year_min'])) {
            // If entry year min is strictly required and we cannot detect entry year, skip.
            return false;
        }

        // 2. Program Code Filter:
        if (!empty($options['filter_programs'])) {
            $program = (string)($student['program_code'] ?? $student['reshte'] ?? $student['c_reshte'] ?? '');
            if ($program !== '') {
                if (!self::matches_filter_codes($program, $options['filter_programs'])) {
                    return false;
                }
            } else if (strlen($stdcode) === 9 && preg_match('/^\d{3}(\d{3})\d{3}$/', $stdcode, $pm)) {
                // In 9-digit Iranian student codes (e.g. 403339003), middle 3 digits are the study code (339).
                if (!self::matches_filter_codes($pm[1], $options['filter_programs'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if a course offer record passes the configured filters.
     *
     * @param array $offer
     * @param array $options
     * @return bool
     */
    public function passes_course_filters(array $offer, array $options): bool {
        if (empty($options['filter_programs'])) {
            return true;
        }

        $candidates = [
            (string)($offer['program_code'] ?? ''),
            (string)($offer['course_code'] ?? ''),
            (string)($offer['reshte'] ?? ''),
            (string)($offer['depart_code'] ?? ''),
        ];

        foreach ($candidates as $cand) {
            if ($cand !== '' && self::matches_filter_codes($cand, $options['filter_programs'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute sync pipeline.
     *
     * @param int|null $userid Who triggered (null = cron)
     * @param array $options Execution options overrides
     * @return \stdClass Log record
     */
    public function run(?int $userid = null, array $options = []): \stdClass {
        global $DB;

        $opts = $this->resolve_options($options);

        $log = (object)[
            'timestart' => time(),
            'timeend' => null,
            'status' => 'running',
            'termcode' => null,
            'createdcount' => 0,
            'updatedcount' => 0,
            'enrolledcount' => 0,
            'errorcount' => 0,
            'message' => '',
            'userid' => $userid,
        ];
        $log->id = $DB->insert_record('local_morvaridsync_log', $log);

        try {
            if (!$this->client->is_configured()) {
                throw new \moodle_exception('error_notconfigured', 'local_morvaridsync');
            }

            $term = $this->resolve_term($opts);
            $log->termcode = $term;
            $DB->update_record('local_morvaridsync_log', $log);

            $scope = $opts['sync_scope'];
            $summaryparts = [];

            // Add filter info to summary:
            $filterinfo = [];
            if (!empty($opts['entry_year_min']) || !empty($opts['entry_year_max'])) {
                $filterinfo[] = 'سال ورود: ' . ($opts['entry_year_min'] ?: 'همه') . ' تا ' . ($opts['entry_year_max'] ?: 'همه');
            }
            if (!empty($opts['filter_programs'])) {
                $filterinfo[] = 'رشته: ' . $opts['filter_programs'];
            }
            if (!empty($filterinfo)) {
                $summaryparts[] = '[' . implode(' | ', $filterinfo) . ']';
            }

            // --- Scope Branching ---

            // 1. Suspend Only:
            if ($scope === 'suspend_only') {
                $suspmsg = $this->sync_suspensions_only($term, $opts);
                $summaryparts[] = $suspmsg;

            // 2. Users Only:
            } else if ($scope === 'users_only') {
                $usermsg = $this->direct_sync_users_and_workbooks($term, [], 0, $opts);
                $summaryparts[] = "حالت فقط کاربران: " . $usermsg;

            // 3. Courses Only:
            } else if ($scope === 'courses_only') {
                $rootcatid = $this->writer->ensure_category('مروارید / ' . $term, 'mv-term-' . $term, 0);
                mapper::set(mapper::TYPE_TERM, $term, $rootcatid, $term);
                $facultymap = $this->discover_faculties($term, $rootcatid);
                $offers = $this->discover_offers($term, $facultymap, $opts);

                $coursesynced = 0;
                foreach ($offers as $offer) {
                    try {
                        $facext = (string)($offer['_faculty_ext'] ?? $offer['hoffman_code'] ?? $offer['faculty_code'] ?? '');
                        $catid = (isset($facultymap[$facext])) ? $facultymap[$facext]['categoryid'] : $rootcatid;
                        $this->writer->ensure_course($offer, $catid, $term);
                        $coursesynced++;
                    } catch (\Throwable $e) {
                        $this->writer->stats->errors++;
                    }
                }
                $summaryparts[] = "حالت فقط دروس: {$coursesynced} درس و دسته‌بندی ایجاد یا به‌روزرسانی شد.";

            // 4. Enrolments Only or Full Sync (All):
            } else {
                $rootcatid = $this->writer->ensure_category('مروارید / ' . $term, 'mv-term-' . $term, 0);
                mapper::set(mapper::TYPE_TERM, $term, $rootcatid, $term);

                // Discover faculties & offers:
                $facultymap = $this->discover_faculties($term, $rootcatid);
                $offers = $this->discover_offers($term, $facultymap, $opts);

                $offersynced = 0;
                $syncedcoursestudents = [];

                foreach ($offers as $offer) {
                    try {
                        $this->sync_offer($offer, $term, $facultymap, $rootcatid, $opts, $syncedcoursestudents);
                        $offersynced++;
                    } catch (\Throwable $e) {
                        $this->writer->stats->errors++;
                        $this->writer->stats->messages[] = 'Offer sync error: ' . $e->getMessage();
                    }
                }

                // Direct User Sync (Professors and Students):
                $directsyncmsg = '';
                $directsyncenabled = get_config('local_morvaridsync', 'direct_user_sync');
                if ($directsyncenabled === false || !empty($directsyncenabled) || $offersynced === 0) {
                    $directsyncmsg = $this->direct_sync_users_and_workbooks($term, $facultymap, $rootcatid, $opts);
                }

                $summaryparts[] = "همگام‌سازی {$offersynced} درس/مشخصه انجام شد.";
                if ($directsyncmsg !== '') {
                    $summaryparts[] = $directsyncmsg;
                }

                // Missing Enrolment Suspension (if enabled):
                if ($opts['suspend_missing_enrol'] && !empty($syncedcoursestudents)) {
                    $suscount = $this->suspend_missing_enrolments($term, $syncedcoursestudents);
                    if ($suscount > 0) {
                        $summaryparts[] = "تعداد {$suscount} ثبت‌نام ناموجود در مروارید تعلیق شدند.";
                    }
                }
            }

            if (!empty($this->writer->stats->messages)) {
                $summaryparts[] = implode(' | ', array_slice($this->writer->stats->messages, 0, 10));
            }

            $log->status = 'success';
            $log->message = implode(' ', $summaryparts);

        } catch (\Throwable $e) {
            $log->status = 'error';
            $log->message = $e->getMessage();
            $this->writer->stats->errors++;
        }

        $log->timeend = time();
        $log->createdcount = $this->writer->stats->created;
        $log->updatedcount = $this->writer->stats->updated;
        $log->enrolledcount = $this->writer->stats->enrolled;
        $log->errorcount = $this->writer->stats->errors;
        $DB->update_record('local_morvaridsync_log', $log);

        return $log;
    }

    /**
     * Resolve which term to sync.
     *
     * @param array $options
     * @return string
     * @throws \moodle_exception
     */
    protected function resolve_term(array $options = []): string {
        $term = $options['term'] ?? '';
        if ($term !== '') {
            return $term;
        }

        $configured = trim((string)get_config('local_morvaridsync', 'activeterm'));
        if ($configured !== '') {
            return $configured;
        }

        if (!get_config('local_morvaridsync', 'use_latest_term')) {
            throw new \moodle_exception('error_noterm', 'local_morvaridsync');
        }

        $terms = [];
        try {
            $terms = $this->client->term_list();
        } catch (\Throwable $e) {
            $this->writer->stats->messages[] = 'TermList API error: ' . $e->getMessage();
        }

        if ($terms) {
            usort($terms, static function ($a, $b) {
                $ca = (string)($a['term_code'] ?? $a['codeterm'] ?? $a['c_term'] ?? '');
                $cb = (string)($b['term_code'] ?? $b['codeterm'] ?? $b['c_term'] ?? '');
                return strcmp($cb, $ca);
            });

            $best = (string)($terms[0]['term_code'] ?? $terms[0]['codeterm'] ?? $terms[0]['c_term'] ?? '');
            if ($best !== '') {
                return $best;
            }
        }

        throw new \moodle_exception('error_noterm', 'local_morvaridsync');
    }

    /**
     * Discover faculties and build category mapping.
     *
     * @param string $term
     * @param int $rootcatid
     * @return array
     */
    protected function discover_faculties(string $term, int $rootcatid): array {
        $facultymap = [];
        $faculties = [];

        try {
            $faculties = $this->client->department_info($term);
        } catch (\Throwable $e) {
            $this->writer->stats->messages[] = 'DepratInfo error: ' . $e->getMessage();
        }

        if (!$faculties) {
            try {
                $faculties = $this->client->faculty_list();
            } catch (\Throwable $e2) {
                $this->writer->stats->messages[] = 'FacultyList fallback error: ' . $e2->getMessage();
            }
        }

        foreach ($faculties as $fac) {
            $code = (string)($fac['faculty_code'] ?? $fac['c_daneshkade'] ?? $fac['depart_code'] ?? $fac['departcode'] ?? '');
            $hoffman = (string)($fac['hoffman_code'] ?? $fac['departhafman'] ?? $fac['depart_hafman'] ?? $code);
            $name = (string)($fac['faculty_name'] ?? $fac['departname'] ?? $fac['depart_name'] ?? $fac['title'] ?? ($code !== '' ? 'دانشکده ' . $code : ''));

            if ($code === '' && $hoffman === '') {
                continue;
            }
            $ext = $hoffman !== '' ? $hoffman : $code;
            $catname = $name !== '' ? $name : ('دانشکده ' . $ext);
            $catid = $this->writer->ensure_category($catname, 'mv-fac-' . $ext, $rootcatid);
            mapper::set(mapper::TYPE_FACULTY, $ext, $catid, $term, $fac);
            $facultymap[$ext] = [
                'categoryid' => $catid,
                'faculty_code' => $code,
                'hoffman_code' => $hoffman,
                'name' => $catname,
            ];
            if ($code !== '' && $code !== $ext) {
                $facultymap[$code] = $facultymap[$ext];
            }
        }

        if (empty($facultymap)) {
            $defaultcatid = $this->writer->ensure_category('عمومی / دروس ارائه شده', 'mv-fac-general', $rootcatid);
            $facultymap['general'] = [
                'categoryid' => $defaultcatid,
                'faculty_code' => '',
                'hoffman_code' => '',
                'name' => 'عمومی / دروس ارائه شده',
            ];
        }

        return $facultymap;
    }

    /**
     * Discover offer groups for a term using multi-tier cascading strategies and apply course filters.
     * Tier 1: Stu_Lms_Rest_OfferTeach
     * Tier 2: Stu_Lms_Rest_OfferGroup
     * Tier 3: Stu_Lms_Rest_LectureInfo
     *
     * @param string $term
     * @param array $facultymap
     * @param array $options
     * @return array
     */
    protected function discover_offers(string $term, array $facultymap, array $options = []): array {
        $offers = [];
        $seen = [];

        // --- Tier 1: Stu_Lms_Rest_OfferTeach ---
        try {
            $teachoffers = $this->client->offer_teach('', '', $term, '');
            foreach ($teachoffers as $row) {
                if (!$this->passes_course_filters($row, $options)) {
                    continue;
                }

                $offerid = (string)($row['offer_id'] ?? $row['group_code'] ?? $row['offered_course_code'] ?? '');
                $coursecode = (string)($row['course_code'] ?? $row['lecture'] ?? $row['offered_course_code'] ?? '');
                $group = (string)($row['group_no'] ?? '1');
                $coursename = (string)($row['course_name'] ?? $row['lect_name'] ?? $row['title'] ?? '');

                if ($offerid === '' && $coursecode === '') {
                    continue;
                }
                if ($offerid === '') {
                    $offerid = "{$coursecode}-{$group}";
                }

                $key = "{$offerid}|{$term}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $row['offer_id'] = $offerid;
                $row['course_code'] = $coursecode;
                $row['group_no'] = $group;
                if ($coursename !== '') {
                    $row['course_name'] = $coursename;
                }

                $offers[] = $row;
            }
        } catch (\Throwable $e) {
            $this->writer->stats->messages[] = 'OfferTeach discovery error: ' . $e->getMessage();
        }

        if (!empty($offers)) {
            return $offers;
        }

        // --- Tier 2: OfferGroup / LectureInfo ---
        $allprograms = [];
        foreach ($facultymap as $ext => $fac) {
            $hoffman = $fac['hoffman_code'] !== '' ? $fac['hoffman_code'] : $ext;
            if ($hoffman !== '' && $hoffman !== 'general') {
                try {
                    $programs = $this->client->course_info($hoffman);
                    foreach ($programs as $p) {
                        $p['_faculty_ext'] = $ext;
                        $allprograms[] = $p;
                    }
                } catch (\Throwable $e) {}
            }
        }

        if (empty($allprograms)) {
            $allprograms = [['program_code' => '', 'program_name' => '', '_faculty_ext' => 'general']];
        }

        foreach ($allprograms as $program) {
            $programcode = (string)($program['program_code'] ?? '');
            $facext = (string)($program['_faculty_ext'] ?? 'general');
            $fac = $facultymap[$facext] ?? reset($facultymap);
            $facultycode = $fac['faculty_code'] ?? '';

            $candidates = [];
            try {
                $candidates = $this->client->offer_group($programcode, $term, '', '', $facultycode);
            } catch (\Throwable $e) {}

            foreach ($candidates as $row) {
                if (!$this->passes_course_filters($row, $options)) {
                    continue;
                }

                $offerid = (string)($row['offer_id'] ?? $row['group_code'] ?? '');
                $coursecode = (string)($row['course_code'] ?? $row['lecture'] ?? 'x');
                $group = (string)($row['group_no'] ?? '1');

                if ($offerid === '') {
                    $offerid = "{$coursecode}-{$group}";
                }
                $key = "{$offerid}|{$term}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $row['_faculty_ext'] = $facext;
                $row['program_code'] = $row['program_code'] ?? $programcode;
                $offers[] = $row;
            }
        }

        return $offers;
    }

    /**
     * Direct sync of professors and students, respecting filters.
     *
     * @param string $term
     * @param array $facultymap
     * @param int $rootcatid
     * @param array $options
     * @return string Status summary
     */
    protected function direct_sync_users_and_workbooks(string $term, array $facultymap, int $rootcatid, array $options = []): string {
        $syncedteachers = 0;
        $syncedstudents = 0;

        // 1. Direct Professor Sync:
        $professors = [];
        try {
            $professors = $this->client->teacher_info($term);
        } catch (\Throwable $e) {
            try {
                $professors = $this->client->professor_list();
            } catch (\Throwable $e2) {
                $this->writer->stats->messages[] = 'Direct professor sync error: ' . $e2->getMessage();
            }
        }

        foreach ($professors as $prof) {
            try {
                $userid = $this->writer->ensure_user($prof, 'teacher', $term);
                if ($userid) {
                    $syncedteachers++;
                }
            } catch (\Throwable $e) {
                $this->writer->stats->errors++;
            }
        }

        // 2. Direct Student Sync:
        $students = [];
        try {
            $students = $this->client->student_info($term);
        } catch (\Throwable $e) {
            try {
                $students = $this->client->students_person_info();
            } catch (\Throwable $e2) {
                $this->writer->stats->messages[] = 'Direct student sync error: ' . $e2->getMessage();
            }
        }

        foreach ($students as $student) {
            if (!$this->passes_student_filters($student, $options)) {
                continue;
            }
            try {
                $stduserid = $this->writer->ensure_user($student, 'student', $term);
                if ($stduserid) {
                    $syncedstudents++;
                }
            } catch (\Throwable $e) {
                $this->writer->stats->errors++;
            }
        }

        return "همگام‌سازی مستقیم: {$syncedteachers} استاد، {$syncedstudents} دانشجو.";
    }

    /**
     * Sync one offer: course + teachers + students.
     *
     * @param array $offer
     * @param string $term
     * @param array $facultymap
     * @param int $rootcatid
     * @param array $options
     * @param array $syncedstudents Course ID => array of enrolled student user IDs
     */
    protected function sync_offer(array $offer, string $term, array $facultymap, int $rootcatid, array $options, array &$syncedstudents): void {
        $facext = (string)($offer['_faculty_ext'] ?? $offer['hoffman_code'] ?? $offer['faculty_code'] ?? $offer['daneshkadeh'] ?? '');
        $categoryid = $rootcatid;
        if ($facext !== '' && isset($facultymap[$facext])) {
            $categoryid = $facultymap[$facext]['categoryid'];
        } else if (!empty($facultymap)) {
            $categoryid = reset($facultymap)['categoryid'];
        }

        $courseid = $this->writer->ensure_course($offer, $categoryid, $term);
        if (!isset($syncedstudents[$courseid])) {
            $syncedstudents[$courseid] = [];
        }

        $coursecode = (string)($offer['course_code'] ?? $offer['lecture'] ?? $offer['offered_course_code'] ?? '');
        $group = (string)($offer['group_no'] ?? '1');
        $offerid = (string)($offer['offer_id'] ?? $offer['group_code'] ?? '');

        // Teacher: Check embedded teacher info from OfferTeach.
        $teachercode = (string)($offer['teacher_code'] ?? $offer['teach_code'] ?? $offer['username'] ?? '');
        if ($teachercode !== '' || !empty($offer['firstname'])) {
            try {
                $tuserid = $this->writer->ensure_user($offer, 'teacher', $term);
                if ($tuserid) {
                    $this->writer->enrol($courseid, $tuserid, 'editingteacher');
                }
            } catch (\Throwable $e) {
                $this->writer->stats->messages[] = "Embedded teacher {$teachercode}: " . $e->getMessage();
            }
        } else {
            try {
                $teachers = $this->client->offer_teach($coursecode, $group, $term, $offerid);
                foreach ($teachers as $teacher) {
                    $userid = $this->writer->ensure_user($teacher, 'teacher', $term);
                    if ($userid) {
                        $this->writer->enrol($courseid, $userid, 'editingteacher');
                    }
                }
            } catch (\Throwable $e) {
                $this->writer->stats->messages[] = "OfferTeach {$offerid}: " . $e->getMessage();
            }
        }

        // Students: Call WorkBookInfo2 to enrol registered students.
        try {
            $students = $this->client->workbook_info2($coursecode, $group, $term, $offerid);
            foreach ($students as $student) {
                if (!$this->passes_student_filters($student, $options)) {
                    continue;
                }
                $userid = $this->writer->ensure_user($student, 'student', $term);
                if ($userid) {
                    $this->writer->enrol($courseid, $userid, 'student');
                    $syncedstudents[$courseid][] = (int)$userid;
                }
            }
        } catch (\Throwable $e) {
            $this->writer->stats->messages[] = "WorkBookInfo2 {$offerid}: " . $e->getMessage();
        }
    }

    /**
     * Suspend enrolments in Moodle that were not present in Morvarid active roster during sync.
     *
     * @param string $term
     * @param array $syncedcoursestudents Course ID => array of active student user IDs
     * @return int Number of suspended enrolments
     */
    protected function suspend_missing_enrolments(string $term, array $syncedcoursestudents): int {
        global $DB;

        $suspendedcount = 0;
        foreach ($syncedcoursestudents as $courseid => $expecteduserids) {
            $expectedmap = array_fill_keys($expecteduserids, true);
            $currentactiveids = $this->writer->get_active_enrolled_user_ids($courseid);

            foreach ($currentactiveids as $curid) {
                // If user is enrolled as student and NOT in expected roster, suspend them!
                if (!isset($expectedmap[$curid])) {
                    if ($this->writer->suspend_enrol($courseid, $curid)) {
                        $suspendedcount++;
                    }
                }
            }
        }

        return $suspendedcount;
    }

    /**
     * Dedicated execution for scope 'suspend_only'.
     * Inspects all term courses in Moodle, fetches workbook info from Morvarid, and suspends missing students.
     *
     * @param string $term
     * @param array $options
     * @return string Summary message
     */
    protected function sync_suspensions_only(string $term, array $options): string {
        global $DB;

        $prefix = (string)(get_config('local_morvaridsync', 'course_idnumber_prefix') ?: 'mv-');
        $termpattern = $prefix . $term . '-%';

        $courses = $DB->get_records_select('course', 'idnumber LIKE :pat', ['pat' => $termpattern]);
        if (!$courses) {
            return 'حالت فقط تعلیق: هیچ درسی برای ترم ' . $term . ' در مودل یافت نشد.';
        }

        $totalchecked = 0;
        $totalsuspended = 0;

        foreach ($courses as $course) {
            $totalchecked++;
            // Extract offerid from idnumber (e.g. mv-14042-3815 => 3815).
            $scopedpart = substr($course->idnumber, strlen($prefix));
            $offerid = str_replace("{$term}-", '', $scopedpart);

            $activeinmoodle = $this->writer->get_active_enrolled_user_ids((int)$course->id);
            if (empty($activeinmoodle)) {
                continue;
            }

            $morvaridstudents = [];
            try {
                $morvaridstudents = $this->client->workbook_info2('', '', $term, $offerid);
            } catch (\Throwable $e) {
                continue;
            }

            $expecteduserids = [];
            foreach ($morvaridstudents as $std) {
                if (!$this->passes_student_filters($std, $options)) {
                    continue;
                }
                $stdcode = (string)($std['student_code'] ?? $std['code'] ?? '');
                if ($stdcode !== '') {
                    $moodleid = mapper::get_moodle_id(mapper::TYPE_USER_STUDENT, $stdcode);
                    if ($moodleid) {
                        $expecteduserids[$moodleid] = true;
                    }
                }
            }

            foreach ($activeinmoodle as $curuserid) {
                if (!isset($expecteduserids[$curuserid])) {
                    if ($this->writer->suspend_enrol((int)$course->id, $curuserid)) {
                        $totalsuspended++;
                    }
                }
            }
        }

        return "حالت فقط تعلیق: بررسی {$totalchecked} درس برای ترم {$term} انجام شد. تعداد {$totalsuspended} ثبت‌نام تعلیق گردید.";
    }
}
