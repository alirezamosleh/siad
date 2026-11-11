<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Write users/courses/enrolments via Moodle internal APIs.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_morvaridsync\sync;

defined('MOODLE_INTERNAL') || die();

use local_morvaridsync\client\morvarid_rest;

global $CFG;
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Moodle entity writer used by the orchestrator.
 */
class moodle_writer {

    /** @var object Counters */
    public $stats;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->stats = (object)[
            'created' => 0,
            'updated' => 0,
            'enrolled' => 0,
            'suspended' => 0,
            'errors' => 0,
            'messages' => [],
        ];
    }

    /**
     * Ensure a course category exists under parent.
     *
     * @param string $name
     * @param string $idnumber
     * @param int $parentid
     * @return int Category id
     */
    public function ensure_category(string $name, string $idnumber, int $parentid = 0): int {
        global $DB;

        $name = morvarid_rest::normalize_persian_text($name);
        $idnumber = substr($idnumber, 0, 100);
        if ($idnumber !== '') {
            $existing = $DB->get_record('course_categories', ['idnumber' => $idnumber]);
            if ($existing) {
                if ($existing->name !== $name && $name !== '') {
                    $existing->name = $name;
                    $DB->update_record('course_categories', $existing);
                    $this->stats->updated++;
                }
                return (int)$existing->id;
            }
        }

        $data = (object)[
            'name' => $name !== '' ? $name : ($idnumber !== '' ? $idnumber : 'مروارید'),
            'idnumber' => $idnumber,
            'parent' => $parentid,
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
        ];
        $cat = \core_course_category::create($data);
        $this->stats->created++;
        return (int)$cat->id;
    }

    /**
     * Ensure course exists for an offer group.
     *
     * @param array $offer Normalized offer row
     * @param int $categoryid
     * @param string $termcode
     * @return int Course id
     */
    public function ensure_course(array $offer, int $categoryid, string $termcode): int {
        global $DB;

        $prefix = (string)(get_config('local_morvaridsync', 'course_idnumber_prefix') ?: 'mv-');
        $offerid = (string)($offer['offer_id'] ?? $offer['offered_course_code'] ?? '');
        $coursecode = (string)($offer['course_code'] ?? $offer['offered_course_code'] ?? '');
        $group = (string)($offer['group_no'] ?? '1');
        $coursename = morvarid_rest::normalize_persian_text((string)($offer['course_name'] ?? ($coursecode !== '' ? ('درس ' . $coursecode) : 'درس ارائه شده')));

        if ($offerid === '') {
            $offerid = "{$coursecode}-{$group}";
        }

        // Scope idnumber and shortname with term to prevent collisions across academic terms.
        $scopedid = (strpos($offerid, $termcode) !== false) ? $offerid : "{$termcode}-{$offerid}";
        $idnumber = substr($prefix . $scopedid, 0, 100);
        $shortname = substr('MV-' . $scopedid, 0, 100);
        $fullname = morvarid_rest::normalize_persian_text(trim($coursename . ' - گروه ' . $group . ' - ' . $termcode));

        $course = $DB->get_record('course', ['idnumber' => $idnumber]);
        if (!$course) {
            $course = $DB->get_record('course', ['shortname' => $shortname]);
        }

        if ($course) {
            $update = false;
            if ($course->fullname !== $fullname) {
                $course->fullname = $fullname;
                $update = true;
            }
            if ((int)$course->category !== $categoryid && $categoryid > 0) {
                $course->category = $categoryid;
                $update = true;
            }
            if ($course->idnumber !== $idnumber) {
                $course->idnumber = $idnumber;
                $update = true;
            }
            if ($update) {
                update_course($course);
                $this->stats->updated++;
            }
            mapper::set(mapper::TYPE_COURSE, $offerid, (int)$course->id, $termcode, $offer);

            // If activity provisioning is enabled, check if this course needs initial activities
            // (provision_course_activities immediately returns false if activities exist, preventing any alteration).
            $autocreate = get_config('local_morvaridsync', 'auto_create_activities');
            if ($autocreate === false || !empty($autocreate)) {
                $this->provision_course_activities((int)$course->id);
            }

            return (int)$course->id;
        }

        $data = (object)[
            'fullname' => $fullname,
            'shortname' => $shortname,
            'category' => $categoryid,
            'idnumber' => $idnumber,
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'format' => 'topics',
            'visible' => 1,
            'startdate' => time(),
        ];
        $created = create_course($data);
        $this->stats->created++;
        mapper::set(mapper::TYPE_COURSE, $offerid, (int)$created->id, $termcode, $offer);

        $autocreate = get_config('local_morvaridsync', 'auto_create_activities');
        if ($autocreate === false || !empty($autocreate)) {
            $this->provision_course_activities((int)$created->id);
        }

        return (int)$created->id;
    }

    /**
     * Auto-provision 16 BigBlueButton sessions, Midterm and Final exams for a course.
     * Guaranteed safe: if any BBB activity already exists, it aborts immediately to protect recorded sessions.
     *
     * @param int $courseid
     * @return bool True if provisioned, false if skipped
     */
    public function provision_course_activities(int $courseid): bool {
        global $DB, $CFG;

        // 1. Safety check: NEVER touch or duplicate existing activities!
        $existingbbb = $DB->count_records('bigbluebuttonbn', ['course' => $courseid]);
        if ($existingbbb > 0) {
            return false;
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return false;
        }

        // Ensure session user context for file and capability checks.
        if (empty($GLOBALS['USER']) || empty($GLOBALS['USER']->id)) {
            if (class_exists('\core\cron')) {
                \core\cron::setup_user();
            } else if (function_exists('cron_setup_user')) {
                cron_setup_user();
            }
        }

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        // 2. Ensure 18 sections exist (0: general, 1-8: session 1-8, 9: midterm, 10-17: session 9-16, 18: final).
        course_create_sections_if_missing($course, range(0, 18));

        // Name the sections:
        $sectionnames = [
            1 => 'جلسه اول',
            2 => 'جلسه دوم',
            3 => 'جلسه سوم',
            4 => 'جلسه چهارم',
            5 => 'جلسه پنجم',
            6 => 'جلسه ششم',
            7 => 'جلسه هفتم',
            8 => 'جلسه هشتم',
            9 => 'آزمون میان‌ترم',
            10 => 'جلسه نهم',
            11 => 'جلسه دهم',
            12 => 'جلسه یازدهم',
            13 => 'جلسه دوازدهم',
            14 => 'جلسه سیزدهم',
            15 => 'جلسه چهاردهم',
            16 => 'جلسه پانزدهم',
            17 => 'جلسه شانزدهم',
            18 => 'آزمون پایان‌ترم',
        ];

        foreach ($sectionnames as $secnum => $secname) {
            $sec = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $secnum]);
            if ($sec && empty($sec->name)) {
                $sec->name = $secname;
                $DB->update_record('course_sections', $sec);
            }
        }

        $bbbmod = $DB->get_record('modules', ['name' => 'bigbluebuttonbn']);
        $quizmod = $DB->get_record('modules', ['name' => 'quiz']);
        $recordsetting = get_config('local_morvaridsync', 'bbb_record_enabled');
        $recordenabled = ($recordsetting === false || !empty($recordsetting)) ? 1 : 0;

        // 3. Create BigBlueButton sessions 1 to 8:
        if ($bbbmod) {
            for ($s = 1; $s <= 8; $s++) {
                $minfo = new \stdClass();
                $minfo->modulename = 'bigbluebuttonbn';
                $minfo->module = (int)$bbbmod->id;
                $minfo->course = $courseid;
                $minfo->section = $s;
                $minfo->name = "جلسه {$s}";
                $minfo->introeditor = [
                    'text' => "کلاس آنلاین جلسه {$s}",
                    'format' => FORMAT_HTML,
                    'itemid' => 0,
                ];
                $minfo->visible = 1;
                $minfo->visibleoncoursepage = 1;
                $minfo->type = 0; // Room with recordings.
                $minfo->record = $recordenabled;
                $minfo->recordallfromstart = 0;
                $minfo->wait = 0;
                $minfo->participants = '[]';
                $minfo->cmidnumber = '';

                try {
                    add_moduleinfo($minfo, $course);
                } catch (\Throwable $e) {
                    $this->stats->messages[] = "BBB session {$s} error: " . $e->getMessage();
                }
            }
        }

        // 4. Create Midterm Exam in section 9:
        if ($quizmod && !$DB->record_exists('quiz', ['course' => $courseid, 'name' => 'آزمون میان‌ترم'])) {
            $qinfo = new \stdClass();
            $qinfo->modulename = 'quiz';
            $qinfo->module = (int)$quizmod->id;
            $qinfo->course = $courseid;
            $qinfo->section = 9;
            $qinfo->name = 'آزمون میان‌ترم';
            $qinfo->introeditor = [
                'text' => 'آزمون میان‌ترم نیمسال تحصیلی',
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ];
            $qinfo->visible = 1;
            $qinfo->visibleoncoursepage = 1;
            $qinfo->timeopen = 0;
            $qinfo->timeclose = 0;
            $qinfo->timelimit = 0;
            $qinfo->attempts = 1;
            $qinfo->grademethod = 1;
            $qinfo->decimalpoints = 2;
            $qinfo->questiondecimalpoints = -1;
            $qinfo->preferredbehaviour = 'deferredfeedback';
            $qinfo->quizpassword = '';
            $qinfo->grade = 20;
            $qinfo->feedbacktext = [['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0]];
            $qinfo->feedbackboundaries = [''];
            $qinfo->cmidnumber = '';

            try {
                add_moduleinfo($qinfo, $course);
            } catch (\Throwable $e) {
                $this->stats->messages[] = 'Midterm quiz error: ' . $e->getMessage();
            }
        }

        // 5. Create BigBlueButton sessions 9 to 16 in sections 10 to 17:
        if ($bbbmod) {
            for ($s = 9; $s <= 16; $s++) {
                $secnum = $s + 1; // Section 10 to 17.
                $minfo = new \stdClass();
                $minfo->modulename = 'bigbluebuttonbn';
                $minfo->module = (int)$bbbmod->id;
                $minfo->course = $courseid;
                $minfo->section = $secnum;
                $minfo->name = "جلسه {$s}";
                $minfo->introeditor = [
                    'text' => "کلاس آنلاین جلسه {$s}",
                    'format' => FORMAT_HTML,
                    'itemid' => 0,
                ];
                $minfo->visible = 1;
                $minfo->visibleoncoursepage = 1;
                $minfo->type = 0;
                $minfo->record = $recordenabled;
                $minfo->recordallfromstart = 0;
                $minfo->wait = 0;
                $minfo->participants = '[]';
                $minfo->cmidnumber = '';

                try {
                    add_moduleinfo($minfo, $course);
                } catch (\Throwable $e) {
                    $this->stats->messages[] = "BBB session {$s} error: " . $e->getMessage();
                }
            }
        }

        // 6. Create Final Exam in section 18:
        if ($quizmod && !$DB->record_exists('quiz', ['course' => $courseid, 'name' => 'آزمون پایان‌ترم'])) {
            $qinfo = new \stdClass();
            $qinfo->modulename = 'quiz';
            $qinfo->module = (int)$quizmod->id;
            $qinfo->course = $courseid;
            $qinfo->section = 18;
            $qinfo->name = 'آزمون پایان‌ترم';
            $qinfo->introeditor = [
                'text' => 'آزمون پایان‌ترم نیمسال تحصیلی',
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ];
            $qinfo->visible = 1;
            $qinfo->visibleoncoursepage = 1;
            $qinfo->timeopen = 0;
            $qinfo->timeclose = 0;
            $qinfo->timelimit = 0;
            $qinfo->attempts = 1;
            $qinfo->grademethod = 1;
            $qinfo->decimalpoints = 2;
            $qinfo->questiondecimalpoints = -1;
            $qinfo->preferredbehaviour = 'deferredfeedback';
            $qinfo->quizpassword = '';
            $qinfo->grade = 20;
            $qinfo->feedbacktext = [['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0]];
            $qinfo->feedbackboundaries = [''];
            $qinfo->cmidnumber = '';

            try {
                add_moduleinfo($qinfo, $course);
            } catch (\Throwable $e) {
                $this->stats->messages[] = 'Final quiz error: ' . $e->getMessage();
            }
        }

        rebuild_course_cache($courseid, true);
        return true;
    }

    /**
     * Ensure user exists (student or teacher).
     *
     * @param array $person
     * @param string $role student|teacher
     * @param string $termcode
     * @return int|null User id
     */
    public function ensure_user(array $person, string $role, string $termcode): ?int {
        global $CFG, $DB;

        $codekey = $role === 'teacher' ? 'teacher_code' : 'student_code';
        $code = (string)($person[$codekey] ?? $person['username'] ?? '');
        $nationalid = preg_replace('/\D+/', '', (string)($person['national_id'] ?? ''));
        $firstname = morvarid_rest::normalize_persian_text(trim((string)($person['firstname'] ?? '')));
        $lastname = morvarid_rest::normalize_persian_text(trim((string)($person['lastname'] ?? '')));

        // Handle single-field full name if first/last are missing.
        if ($firstname === '' && $lastname === '' && !empty($person['fullname'])) {
            $parts = explode(' ', trim(morvarid_rest::normalize_persian_text($person['fullname'])), 2);
            $firstname = $parts[0];
            $lastname = $parts[1] ?? $parts[0];
        }

        // Moodle requires both firstname and lastname.
        if ($firstname === '' && $lastname === '') {
            $firstname = $role === 'teacher' ? 'استاد' : 'دانشجو';
            $lastname = $code !== '' ? $code : ($nationalid !== '' ? $nationalid : 'کاربر');
        } else if ($lastname === '') {
            $lastname = $firstname;
        } else if ($firstname === '') {
            $firstname = $role === 'teacher' ? 'استاد' : 'دانشجو';
        }

        $firstname = morvarid_rest::normalize_persian_text($firstname);
        $lastname = morvarid_rest::normalize_persian_text($lastname);

        $mobile = preg_replace('/\D+/', '', (string)($person['mobile'] ?? ''));
        if (preg_match('/^98(9\d{9})$/', $mobile, $m)) {
            $mobile = '0' . $m[1];
        }
        if (preg_match('/(09\d{9})/', $mobile, $m)) {
            $mobile = $m[1];
        } else {
            $mobile = substr($mobile, 0, 20);
        }
        $firstname = substr($firstname, 0, 100);
        $lastname = substr($lastname, 0, 100);
        $usernamehint = trim((string)($person['username'] ?? ''));

        if ($code === '' && $nationalid === '' && $usernamehint === '') {
            $this->stats->errors++;
            $this->stats->messages[] = 'User without identifiers skipped';
            return null;
        }

        $username = $usernamehint !== '' ? \core_text::strtolower($usernamehint)
            : ($code !== '' ? \core_text::strtolower($code) : $nationalid);

        $user = null;
        if ($code !== '') {
            $maptype = $role === 'teacher' ? mapper::TYPE_USER_TEACHER : mapper::TYPE_USER_STUDENT;
            $mapped = mapper::get_moodle_id($maptype, $code);
            if ($mapped) {
                $user = $DB->get_record('user', ['id' => $mapped, 'deleted' => 0]);
            }
        }
        if (!$user) {
            $user = $DB->get_record('user', [
                'username' => $username,
                'mnethostid' => $CFG->mnet_localhost_id,
                'deleted' => 0,
            ]);
        }
        if (!$user && $nationalid !== '') {
            $user = $DB->get_record('user', [
                'idnumber' => $nationalid,
                'mnethostid' => $CFG->mnet_localhost_id,
                'deleted' => 0,
            ]);
        }
        if (!$user && $nationalid !== '') {
            $user = $this->find_by_national_id($nationalid);
        }

        if ($user) {
            $changed = false;
            if ($firstname !== '' && $user->firstname !== $firstname) {
                $user->firstname = $firstname;
                $changed = true;
            }
            if ($lastname !== '' && $user->lastname !== $lastname) {
                $user->lastname = $lastname;
                $changed = true;
            }
            if ($mobile !== '' && (string)$user->phone2 !== $mobile) {
                $user->phone2 = $mobile;
                $changed = true;
            }
            if ($nationalid !== '' && (string)$user->idnumber !== $nationalid) {
                $user->idnumber = $nationalid;
                $changed = true;
            }
            if ($changed) {
                user_update_user($user, false, false);
                $this->stats->updated++;
            }
            if ($nationalid !== '') {
                $this->set_national_id((int)$user->id, $nationalid);
            }
            if ($code !== '') {
                $maptype = $role === 'teacher' ? mapper::TYPE_USER_TEACHER : mapper::TYPE_USER_STUDENT;
                mapper::set($maptype, $code, (int)$user->id, $termcode, $person);
            }
            return (int)$user->id;
        }

        if (!get_config('local_morvaridsync', 'create_missing_users')) {
            return null;
        }

        // Generate unique username.
        $base = $username;
        $i = 1;
        while ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
            $username = $base . $i;
            $i++;
        }

        $email = !empty($person['email']) && validate_email($person['email'])
            ? $person['email']
            : ($username . '@morvarid.local');

        $newuser = (object)[
            'auth' => file_exists($CFG->dirroot . '/auth/sadaa/auth.php') ? 'sadaa' : 'manual',
            'confirmed' => 1,
            'policyagreed' => 1,
            'deleted' => 0,
            'suspended' => 0,
            'mnethostid' => $CFG->mnet_localhost_id,
            'username' => $username,
            'password' => generate_password(12),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'emailstop' => 1,
            'phone2' => $mobile,
            'idnumber' => $nationalid !== '' ? $nationalid : $code,
            'lang' => current_language(),
            'calendartype' => $CFG->calendartype ?? 'gregorian',
        ];

        $userid = user_create_user($newuser, true, false);
        $this->stats->created++;
        if ($nationalid !== '') {
            $this->set_national_id($userid, $nationalid);
        }
        if ($code !== '') {
            $maptype = $role === 'teacher' ? mapper::TYPE_USER_TEACHER : mapper::TYPE_USER_STUDENT;
            mapper::set($maptype, $code, $userid, $termcode, $person);
        }
        return $userid;
    }

    /**
     * Enrol user into course with role.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $roleshortname student|editingteacher|teacher
     */
    public function enrol(int $courseid, int $userid, string $roleshortname = 'student'): void {
        global $DB;

        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            $this->stats->errors++;
            $this->stats->messages[] = 'manual enrol plugin missing';
            return;
        }

        $instances = enrol_get_instances($courseid, true);
        $instance = null;
        foreach ($instances as $inst) {
            if ($inst->enrol === 'manual') {
                $instance = $inst;
                break;
            }
        }
        if (!$instance) {
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $fields = [
                'status' => ENROL_INSTANCE_ENABLED,
                'roleid' => $DB->get_field('role', 'id', ['shortname' => 'student']),
            ];
            $enrol->add_instance($course, $fields);
            $instances = enrol_get_instances($courseid, true);
            foreach ($instances as $inst) {
                if ($inst->enrol === 'manual') {
                    $instance = $inst;
                    break;
                }
            }
        }
        if (!$instance) {
            $this->stats->errors++;
            return;
        }

        $roleid = $DB->get_field('role', 'id', ['shortname' => $roleshortname]);
        if (!$roleid) {
            $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        }

        $ue = $DB->get_record('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid]);
        if ($ue) {
            // Reactivate if previously suspended.
            if ((int)$ue->status !== ENROL_USER_ACTIVE) {
                $enrol->update_user_enrol($instance, $userid, ENROL_USER_ACTIVE);
                $this->stats->updated++;
            }
        } else {
            $enrol->enrol_user($instance, $userid, $roleid);
            $this->stats->enrolled++;
        }
    }

    /**
     * Suspend user enrolment in a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    public function suspend_enrol(int $courseid, int $userid): bool {
        global $DB;

        $enrol = enrol_get_plugin('manual');
        if (!$enrol) {
            return false;
        }

        $instances = enrol_get_instances($courseid, true);
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $ue = $DB->get_record('user_enrolments', [
                    'enrolid' => $instance->id,
                    'userid' => $userid,
                ]);
                if ($ue && (int)$ue->status !== ENROL_USER_SUSPENDED) {
                    $enrol->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
                    $this->stats->suspended++;
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get active enrolled user IDs in a course under manual enrolment.
     *
     * @param int $courseid
     * @return array List of user IDs
     */
    public function get_active_enrolled_user_ids(int $courseid): array {
        global $DB;

        $instances = enrol_get_instances($courseid, true);
        $manualid = null;
        foreach ($instances as $inst) {
            if ($inst->enrol === 'manual') {
                $manualid = (int)$inst->id;
                break;
            }
        }
        if (!$manualid) {
            return [];
        }

        $records = $DB->get_records('user_enrolments', [
            'enrolid' => $manualid,
            'status' => ENROL_USER_ACTIVE,
        ], '', 'userid');
        return array_map('intval', array_keys($records));
    }

    /**
     * @param string $nationalid
     * @return \stdClass|null
     */
    protected function find_by_national_id(string $nationalid): ?\stdClass {
        global $CFG, $DB;

        $shortname = get_config('local_morvaridsync', 'nationalidfield') ?: 'nationalid';
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if (!$field) {
            return null;
        }
        $sql = "SELECT u.*
                  FROM {user} u
                  JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = :fieldid
                 WHERE d.data = :data AND u.deleted = 0 AND u.mnethostid = :m";
        $user = $DB->get_record_sql($sql, [
            'fieldid' => $field->id,
            'data' => $nationalid,
            'm' => $CFG->mnet_localhost_id,
        ]);
        return $user ?: null;
    }

    /**
     * @param int $userid
     * @param string $nationalid
     */
    protected function set_national_id(int $userid, string $nationalid): void {
        global $DB;

        $shortname = get_config('local_morvaridsync', 'nationalidfield') ?: 'nationalid';
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if (!$field) {
            return;
        }
        $existing = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $field->id]);
        if ($existing) {
            if ($existing->data !== $nationalid) {
                $existing->data = $nationalid;
                $DB->update_record('user_info_data', $existing);
            }
            return;
        }
        $DB->insert_record('user_info_data', (object)[
            'userid' => $userid,
            'fieldid' => $field->id,
            'data' => $nationalid,
            'dataformat' => 0,
        ]);
    }
}
