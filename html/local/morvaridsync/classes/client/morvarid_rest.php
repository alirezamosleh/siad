<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Morvarid REST client.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_morvaridsync\client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Calls Morvarid LMS REST methods as documented in lms-sadaaa.pdf and وب سرويس سادا.pdf.
 */
class morvarid_rest {

    /** @var string */
    protected $baseurl;

    /** @var string */
    protected $username;

    /** @var string */
    protected $password;

    /** @var string */
    protected $httpmethod;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->baseurl = rtrim((string)get_config('local_morvaridsync', 'apiurl'), '/');
        $this->username = (string)get_config('local_morvaridsync', 'apiuser');
        $this->password = (string)get_config('local_morvaridsync', 'apipassword');
        $this->httpmethod = strtoupper((string)(get_config('local_morvaridsync', 'httpmethod') ?: 'GET'));
    }

    /**
     * @return bool
     */
    public function is_configured(): bool {
        return $this->baseurl !== '' && $this->username !== '' && $this->password !== '';
    }

    /**
     * Resolve actual REST endpoint URL.
     *
     * @param string|null $overridepath
     * @return string
     */
    public function get_endpoint_url(?string $overridepath = null): string {
        $url = $this->baseurl;
        if ($overridepath !== null) {
            $parsed = parse_url($url);
            $scheme = $parsed['scheme'] ?? 'http';
            $host = $parsed['host'] ?? '';
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            return "{$scheme}://{$host}{$port}/" . ltrim($overridepath, '/');
        }

        if (!preg_match('#/rest(\.aspx)?$#i', $url)) {
            $url = $url . '/rest';
        }
        return $url;
    }

    /**
     * Call a Morvarid method.
     *
     * @param string $method
     * @param array $params Indexed or associative InParam values
     * @param bool $omit_empty Omit empty/null InParam keys
     * @return array List of row arrays (normalized)
     * @throws \moodle_exception
     */
    public function call(string $method, array $params = [], bool $omit_empty = true): array {
        if (!$this->is_configured()) {
            throw new \moodle_exception('error_notconfigured', 'local_morvaridsync');
        }

        $query = [
            'inUserName' => $this->username,
            'inPassword' => $this->password,
            'inMethodName' => $method,
        ];

        $i = 1;
        foreach ($params as $key => $value) {
            $paramname = is_int($key) ? ('InParam' . $i) : (string)$key;
            if (!preg_match('/^InParam\d+$/i', $paramname)) {
                $paramname = 'InParam' . $i;
            }

            if ($value !== null && $value !== '') {
                $query[$paramname] = (string)$value;
            } else if (!$omit_empty) {
                $query[$paramname] = '';
            }

            $i++;
        }

        $curl = new \curl([
            'timeout' => 120,
            'connecttimeout' => 30,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_SSL_VERIFYPEER' => false,
            'CURLOPT_SSL_VERIFYHOST' => false,
        ]);
        $curl->setHeader([
            'Accept: application/json, text/plain, text/xml, application/xml, */*',
            'User-Agent: Moodle-MorvaridSync/1.0',
        ]);

        $endpoint = $this->get_endpoint_url();
        $raw = null;
        $httpcode = 0;

        if ($this->httpmethod === 'POST') {
            $raw = $curl->post($endpoint, $query);
            $info = $curl->get_info();
            $httpcode = (int)($info['http_code'] ?? 0);
        }

        // Default or GET fallback.
        if ($this->httpmethod !== 'POST' || ($httpcode && ($httpcode < 200 || $httpcode >= 300))) {
            $url = $endpoint . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $raw = $curl->get($url);
            $info = $curl->get_info();
            $httpcode = (int)($info['http_code'] ?? 0);

            // If 404 and endpoint was /rest, attempt /rest.aspx fallback.
            if ($httpcode === 404 && str_ends_with($endpoint, '/rest')) {
                $altendpoint = $this->get_endpoint_url('rest.aspx');
                $alturl = $altendpoint . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                $raw = $curl->get($alturl);
                $info = $curl->get_info();
                $httpcode = (int)($info['http_code'] ?? 0);
            }
        }

        if ($httpcode && ($httpcode < 200 || $httpcode >= 300)) {
            $msg = "Morvarid HTTP {$httpcode} for {$method}: " . substr((string)$raw, 0, 400);
            throw new \moodle_exception('generalexceptionmessage', 'error', '', $msg);
        }

        return $this->normalize_response((string)$raw, $method);
    }

    /**
     * Normalize JSON / XML-ish / CSV-ish responses into list of assoc rows.
     *
     * @param string $raw
     * @param string $method Context for error messages
     * @return array
     * @throws \moodle_exception
     */
    public function normalize_response(string $raw, string $method = ''): array {
        $raw = trim($raw);
        if ($raw === '' || strcasecmp($raw, 'null') === 0) {
            return [];
        }

        // Remove UTF-8 Byte Order Mark (BOM) if present.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        // If response is wrapped in XML string: <string xmlns="...">...</string>
        if (str_starts_with($raw, '<')) {
            if (preg_match('/<string[^>]*>(.*?)<\/string>/is', $raw, $matches)) {
                $raw = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
            } else if (function_exists('simplexml_load_string')) {
                $xml = @simplexml_load_string($raw);
                if ($xml !== false) {
                    $json = json_decode(json_encode($xml), true);
                    return $this->rows_from_json($json, $method);
                }
            }
        }

        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Check for double-json encoding.
            if (is_string($json)) {
                $inner = json_decode($json, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $json = $inner;
                }
            }
            return $this->rows_from_json($json, $method);
        }

        debugging("local_morvaridsync unparsed response for {$method}: " . substr($raw, 0, 500), DEBUG_DEVELOPER);
        return [];
    }

    /**
     * Extract rows from decoded JSON and validate Morvarid error codes.
     *
     * @param mixed $json
     * @param string $method
     * @return array
     * @throws \moodle_exception
     */
    protected function rows_from_json($json, string $method = ''): array {
        if ($json === null) {
            return [];
        }
        if (!is_array($json)) {
            return [['value' => $json]];
        }

        // Check for Morvarid standard error response: { "outNumber": -1, "outMessage": "..." }
        if (isset($json['outNumber']) && (int)$json['outNumber'] < 0) {
            $msg = (string)($json['outMessage'] ?? 'Unknown Morvarid API error');
            throw new \moodle_exception('generalexceptionmessage', 'error', '', "Morvarid [{$method}] error ({$json['outNumber']}): {$msg}");
        }

        // Already a list of rows.
        if ($this->is_list($json)) {
            $rows = [];
            foreach ($json as $row) {
                if (is_array($row)) {
                    if (isset($row['outNumber']) && (int)$row['outNumber'] < 0) {
                        $msg = (string)($row['outMessage'] ?? 'Unknown error');
                        throw new \moodle_exception('generalexceptionmessage', 'error', '', "Morvarid [{$method}] row error: {$msg}");
                    }
                    $normalized = $this->normalize_keys($row);
                    if (isset($normalized['outnumber']) && count($normalized) <= 3 && !isset($normalized['term_code']) && !isset($normalized['student_code']) && !isset($normalized['teacher_code']) && !isset($normalized['course_code'])) {
                        continue;
                    }
                    $rows[] = $normalized;
                } else {
                    $rows[] = ['value' => $row];
                }
            }
            return $rows;
        }

        // CRITICAL FOR MORVARID:
        // Morvarid wraps responses in a single key named after the method!
        // E.g. { "Stu_Lms_Rest_OfferTeach": [ ... ] } or { "Stu_Lms_Rest_StuInfo": [ ... ] }
        if (count($json) === 1) {
            $onlykey = array_key_first($json);
            $onlyval = $json[$onlykey];
            if (is_array($onlyval)) {
                return $this->rows_from_json($onlyval, $method);
            }
        }

        // Known standard wrappers:
        foreach (['data', 'Data', 'result', 'Result', 'Table', 'table', 'rows', 'Items', 'items', 'stu_lms_rest_term_list', 'stu_lms_rest_deprat_info', 'stu_lms_rest_course_info', 'stu_lms_rest_offer_teach', 'stu_lms_rest_stu_info', 'stu_lms_rest_offer_group', 'stu_lms_rest_work_book_info2', 'stu_lms_rest_work_book_info', 'stu_rest_medical_stu_info'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return $this->rows_from_json($json[$key], $method);
            }
        }

        // If the method name key is present (case-insensitive search):
        if ($method !== '') {
            foreach ($json as $k => $v) {
                if (strcasecmp((string)$k, $method) === 0 && is_array($v)) {
                    return $this->rows_from_json($v, $method);
                }
            }
        }

        // Single associative row.
        return [$this->normalize_keys($json)];
    }

    /**
     * @param array $arr
     * @return bool
     */
    protected function is_list(array $arr): bool {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * Normalize Persian text by replacing Arabic Yeh, Kaf, Teh Marbuta, etc. with standard Persian characters.
     *
     * @param string|null $str
     * @return string
     */
    public static function normalize_persian_text(?string $str): string {
        if ($str === null || $str === '') {
            return '';
        }
        $search = [
            "\xEF\xBB\xBF", // BOM
            "ي",             // Arabic Yeh (\u064A)
            "ك",             // Arabic Kaf (\u0643)
            "ة",             // Arabic Teh Marbuta (\u0629)
            "ى",             // Arabic Alef Maqsura (\u0649)
            "\xC2\xA0",      // Non-breaking space
        ];
        $replace = [
            "",
            "ی",
            "ک",
            "ه",
            "ی",
            " ",
        ];
        $str = str_replace($search, $replace, $str);
        // Collapse redundant spaces but preserve Persian characters.
        $str = preg_replace('/[ \t]+/u', ' ', $str);
        return trim($str);
    }

    /**
     * Convert Eastern Arabic / Persian digits to standard ASCII digits.
     *
     * @param string|null $str
     * @return string
     */
    public static function normalize_digits(?string $str): string {
        if ($str === null || $str === '') {
            return '';
        }
        $farsi = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $str = str_replace($farsi, $english, $str);
        return str_replace($arabic, $english, $str);
    }

    /**
     * Normalize Persian, Arabic, and English field names to stable snake_case keys.
     *
     * @param array $row
     * @return array
     */
    public function normalize_keys(array $row): array {
        static $map = [
            // Term
            'کد ترم' => 'term_code',
            'عنوان ترم' => 'term_title',
            'نام ترم' => 'term_title',
            'تاريخ شروع' => 'start_date',
            'تاریخ شروع' => 'start_date',
            'تاريخ پايان' => 'end_date',
            'تاریخ پایان' => 'end_date',
            'ترم' => 'term_code',
            'term' => 'term_code',
            'c_term' => 'term_code',
            'codeterm' => 'term_code',
            'termcode' => 'term_code',
            'term_code' => 'term_code',
            'begindate' => 'start_date',
            'begin_date' => 'start_date',
            'enddate' => 'end_date',
            'end_date' => 'end_date',
            'termtitle' => 'term_title',
            'term_title' => 'term_title',

            // Faculty
            'کد دانشکده' => 'faculty_code',
            'کد هافمن' => 'hoffman_code',
            'کد هاهمن' => 'hoffman_code',
            'کد هافمن دانشکده' => 'hoffman_code',
            'کد_هافمن_دانشکده' => 'hoffman_code',
            'عنوان دانشکده' => 'faculty_name',
            'نام دانشکده' => 'faculty_name',
            'دانشکده' => 'faculty_code',
            'c_daneshkade' => 'faculty_code',
            'facultycode' => 'faculty_code',
            'faculty_code' => 'faculty_code',
            'facultyname' => 'faculty_name',
            'faculty_name' => 'faculty_name',
            'facultylist' => 'faculty_name',
            'departcode' => 'faculty_code',
            'depart_code' => 'faculty_code',
            'departhafman' => 'hoffman_code',
            'depart_hafman' => 'hoffman_code',
            'departname' => 'faculty_name',
            'depart_name' => 'faculty_name',
            'daneshkadeh' => 'faculty_code',
            'daneshkadeh_name' => 'faculty_name',
            'daneshkadehname' => 'faculty_name',
            'hoffmancode' => 'hoffman_code',
            'hoffman_code' => 'hoffman_code',

            // Level / Ctype
            'کد مقطع' => 'level_code',
            'عنوان مقطع' => 'level_title',
            'نام مقطع' => 'level_title',
            'مقطع' => 'level_code',
            'c_maghta' => 'level_code',
            'levelcode' => 'level_code',
            'level_code' => 'level_code',
            'leveltitle' => 'level_title',
            'level_title' => 'level_title',
            'maghta' => 'level_code',
            'maghtacode' => 'level_code',
            'maghta_code' => 'level_code',
            'maghtaname' => 'level_title',
            'maghta_name' => 'level_title',
            'ctypecode' => 'level_code',
            'ctype_code' => 'level_code',

            // Program / Study Course
            'کد رشته' => 'program_code',
            'نام رشته' => 'program_name',
            'عنوان رشته' => 'program_name',
            'رشته' => 'program_code',
            'c_reshte' => 'program_code',
            'reshte' => 'program_code',
            'reshtecode' => 'program_code',
            'reshte_code' => 'program_code',
            'reshtename' => 'program_name',
            'reshte_name' => 'program_name',
            'programcode' => 'program_code',
            'program_code' => 'program_code',
            'programname' => 'program_name',
            'program_name' => 'program_name',
            'coursestudycode' => 'program_code',
            'coursestudylist' => 'program_name',
            'coursestudyhafman' => 'hoffman_code',
            'grouphafmancode' => 'hoffman_code',
            'groupname' => 'program_name',

            // Course / Lecture / Offer
            'کد درس' => 'course_code',
            'نام درس' => 'course_name',
            'عنوان درس' => 'course_name',
            'درس' => 'course_code',
            'c_dars' => 'course_code',
            'coursecode' => 'course_code',
            'course_code' => 'course_code',
            'coursename' => 'course_name',
            'course_name' => 'course_name',
            'lecture' => 'course_code',
            'lecturecode' => 'course_code',
            'lecture_code' => 'course_code',
            'lectname' => 'course_name',
            'lecturename' => 'course_name',
            'lecture_name' => 'course_name',
            'کد مشخصه ارائه درس' => 'offer_id',
            'کد مشخصه درس ارائه شده' => 'offer_id',
            'کد مشخصه درس' => 'offer_id',
            'کد مشخصه' => 'offer_id',
            'مشخصه' => 'offer_id',
            'c_moshakhase' => 'offer_id',
            'offerid' => 'offer_id',
            'offer_id' => 'offer_id',
            'groupcode' => 'offer_id',
            'group_code' => 'offer_id',
            'کد درس ارائه شده' => 'offered_course_code',
            'کد درس ارائه' => 'offered_course_code',
            'offeredcoursecode' => 'offered_course_code',
            'offered_course_code' => 'offered_course_code',
            'گروه ارائه درس' => 'group_no',
            'گروه ارائه' => 'group_no',
            'شماره گروه' => 'group_no',
            'گروه' => 'group_no',
            'c_grooh' => 'group_no',
            'groupno' => 'group_no',
            'group_no' => 'group_no',
            'کد درس اخذ شده' => 'taken_course_code',
            'takencoursecode' => 'taken_course_code',

            // Students
            'کد دانشجو' => 'student_code',
            'کد دانشجويي' => 'student_code',
            'کد دانشجویی' => 'student_code',
            'شماره دانشجویی' => 'student_code',
            'شماره دانشجو' => 'student_code',
            'c_daneshjoo' => 'student_code',
            'studentcode' => 'student_code',
            'student_code' => 'student_code',
            'stdcode' => 'student_code',
            'code' => 'student_code',

            // Teachers
            'کد استاد' => 'teacher_code',
            'شماره استادی' => 'teacher_code',
            'استاد' => 'teacher_name',
            'استاد 1' => 'teacher_name',
            'استاد1' => 'teacher_name',
            'c_ostad' => 'teacher_code',
            'teachcode' => 'teacher_code',
            'teach_code' => 'teacher_code',
            'teachercode' => 'teacher_code',
            'teacher_code' => 'teacher_code',
            'profcode' => 'teacher_code',
            'professorcode' => 'teacher_code',

            // Person details
            'نام' => 'firstname',
            'firstname' => 'firstname',
            'first_name' => 'firstname',
            'name' => 'firstname',
            'نام خانوادگي' => 'lastname',
            'نام خانوادگی' => 'lastname',
            'lastname' => 'lastname',
            'last_name' => 'lastname',
            'family' => 'lastname',
            'familyname' => 'lastname',
            'family_name' => 'lastname',
            'نام و نام خانوادگی' => 'fullname',
            'fullname' => 'fullname',
            'کد ملي' => 'national_id',
            'کد ملی' => 'national_id',
            'شماره ملی' => 'national_id',
            'nationalid' => 'national_id',
            'national_id' => 'national_id',
            'nationalcode' => 'national_id',
            'c_melli' => 'national_id',
            'c_meli' => 'national_id',
            'mellicode' => 'national_id',
            'شماره شناسنامه' => 'id_card_no',
            'شماره موبايل' => 'mobile',
            'شماره موبایل' => 'mobile',
            'تلفن همراه' => 'mobile',
            'موبایل' => 'mobile',
            'cellphone' => 'mobile',
            'mobile' => 'mobile',
            'phone' => 'mobile',
            'tel' => 'mobile',
            'نام کاربري' => 'username',
            'نام کاربری' => 'username',
            'username' => 'username',
            'جنسيت' => 'gender',
            'جنسیت' => 'gender',
            'sex' => 'gender',
            'gender' => 'gender',
            'ظرفيت' => 'capacity',
            'capacity' => 'capacity',
            'تعداد ساعت' => 'hours',
            'hours' => 'hours',
            'totalunit' => 'units',
            'ایمیل' => 'email',
            'پست الکترونیک' => 'email',
            'email' => 'email',
            'timename' => 'schedule_info',
        ];

        $out = [];
        foreach ($row as $key => $value) {
            $keyorig = trim((string)$key);

            // Strip trailing colons (Morvarid often outputs keys like "TeachCode:", "Name:", "Lecture:")
            $keycleaned = rtrim($keyorig, ':');

            // Clean Arabic characters (ي -> ی, ك -> ک, remove ZWNJ)
            $keycleaned = str_replace(
                ["\xEF\xBB\xBF", "ي", "ك", "\xE2\x80\x8C"],
                ["", "ی", "ک", " "],
                $keycleaned
            );
            $keycleaned = trim(preg_replace('/\s+/', ' ', $keycleaned));

            $norm = $map[strtolower($keycleaned)] ?? $map[$keycleaned] ?? $map[$keyorig] ?? null;

            if ($norm === null) {
                // CamelCase / PascalCase to snake_case.
                $snake = strtolower(preg_replace(
                    ["/([a-z\d])([A-Z])/", "/([^_])([A-Z][a-z])/"],
                    "$1_$2",
                    $keycleaned
                ));
                $ascii = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $snake));
                $trimmed = trim($ascii, '_');
                $norm = $map[$trimmed] ?? ($trimmed !== '' ? $trimmed : $keyorig);
            }

            if (is_array($value) && count($value) === 1) {
                $value = reset($value);
            }

            if (is_string($value)) {
                $value = self::normalize_persian_text($value);
                static $idfields = [
                    'student_code', 'teacher_code', 'national_id', 'mobile',
                    'term_code', 'group_no', 'offer_id', 'course_code',
                    'level_code', 'faculty_code', 'program_code', 'id_card_no',
                    'units', 'capacity', 'hours', 'offered_course_code',
                ];
                if (in_array($norm, $idfields, true)) {
                    $value = self::normalize_digits($value);
                }
            }

            if ($norm === 'mobile' && !empty($out['mobile']) && preg_match('/^0?9\d{9}$/', (string)$out['mobile'])) {
                $out['tel'] = $value;
            } else {
                $out[$norm] = $value;
            }
            $out['_raw'][$keyorig] = $value;
        }
        return $out;
    }

    // --- REST Endpoint Wrappers ---

    public function term_list(): array {
        return $this->call('Stu_Lms_Rest_TermList');
    }

    public function department_info(string $term): array {
        return $this->call('Stu_Lms_Rest_DepratInfo', [$term]);
    }

    public function faculty_list(): array {
        return $this->call('Stu_Rest_GetFacultyList');
    }

    public function ctype_info(string $term): array {
        return $this->call('Stu_Lms_Rest_CtypeInfo', [$term]);
    }

    public function course_info(string $hoffman): array {
        return $this->call('Stu_Lms_Rest_CourseInfo', [$hoffman]);
    }

    public function course_study_list(): array {
        return $this->call('Stu_Rest_GetCourseStudyList');
    }

    public function lecture_info(string $program, string $course = '', string $term = ''): array {
        $params = [$program];
        if ($course !== '' || $term !== '') {
            $params[] = $course;
        }
        if ($term !== '') {
            $params[] = $term;
        }
        return $this->call('Stu_Lms_Rest_LectureInfo', $params);
    }

    public function offer_group(string $program, string $term, string $course = '', string $group = '', string $faculty = ''): array {
        $params = [$program, $term];
        if ($course !== '' || $group !== '' || $faculty !== '') {
            $params[] = $course;
        }
        if ($group !== '' || $faculty !== '') {
            $params[] = $group;
        }
        if ($faculty !== '') {
            $params[] = $faculty;
        }
        return $this->call('Stu_Lms_Rest_OfferGroup', $params, true);
    }

    public function offer_group_course(string $offerid, string $term = '', string $course = ''): array {
        $params = [$offerid];
        if ($term !== '' || $course !== '') {
            $params[] = $term;
        }
        if ($course !== '') {
            $params[] = $course;
        }
        return $this->call('Stu_Lms_Rest_OfferGroup_Course', $params);
    }

    public function workbook_info(string $term, string $studentcode = '', string $offerid = ''): array {
        $params = [$term];
        if ($studentcode !== '' || $offerid !== '') {
            $params[] = $studentcode;
        }
        if ($offerid !== '') {
            $params[] = $offerid;
        }
        return $this->call('Stu_Lms_Rest_WorkBookInfo', $params);
    }

    public function workbook_info2(string $course, string $group, string $term, string $offerid = ''): array {
        $params = [$course, $group, $term];
        if ($offerid !== '') {
            $params[] = $offerid;
        }
        return $this->call('Stu_Lms_Rest_WorkBookInfo2', $params);
    }

    public function offer_teach(string $course, string $group, string $term, string $offerid = ''): array {
        $params = [$course, $group, $term];
        if ($offerid !== '') {
            $params[] = $offerid;
        }
        return $this->call('Stu_Lms_Rest_OfferTeach', $params);
    }

    public function student_info(string $term, string $studentcode = ''): array {
        $params = [$term];
        if ($studentcode !== '') {
            $params[] = $studentcode;
        }
        return $this->call('Stu_Lms_Rest_StuInfo', $params);
    }

    public function students_person_info(string $year = ''): array {
        $params = [];
        if ($year !== '') {
            $params = ['InParam5' => $year];
        }
        return $this->call('Stu_Rest_GetStudentsPersonInfo', $params);
    }

    public function teacher_info(string $term, string $teachercode = ''): array {
        $params = [$term];
        if ($teachercode !== '') {
            $params[] = $teachercode;
        }
        return $this->call('Stu_Lms_Rest_TeacherInfo', $params);
    }

    public function professor_list(): array {
        return $this->call('Teacher_Rest_GetProfessorList');
    }

    public function professor_list2(): array {
        return $this->call('Teacher_Rest_GetProfessorList2');
    }
}
