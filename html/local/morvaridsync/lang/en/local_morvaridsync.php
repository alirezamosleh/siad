<?php
// This file is part of Moodle - http://moodle.org/

/**
 * English strings for local_morvaridsync.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Morvarid (Sadaa) Sync';
$string['morvaridsync:manage'] = 'Manage Morvarid Sync';
$string['settings_api'] = 'Morvarid REST API Settings';
$string['apiurl'] = 'REST Base URL';
$string['apiurl_desc'] = 'Example: https://erp.example.ac.ir/rest or http://server/rest.aspx';
$string['apiuser'] = 'API Username (inUserName)';
$string['apiuser_desc'] = 'Morvarid REST username.';
$string['apipassword'] = 'API Password (inPassword)';
$string['apipassword_desc'] = 'Morvarid REST password.';
$string['httpmethod'] = 'HTTP Request Method';
$string['httpmethod_desc'] = 'Method used for Morvarid REST requests (GET or POST).';
$string['settings_sync'] = 'Sync Behavior & Strategy';
$string['sync_scope'] = 'Sync Scope';
$string['sync_scope_desc'] = 'Select what subset of data to synchronize.';
$string['scope_courses_only'] = 'Courses Only (create/update courses and categories without users)';
$string['scope_teachers_only'] = 'Teachers for Courses (link and enrol teachers into courses)';
$string['scope_students_only'] = 'Students for Courses (enrol registered students into courses)';
$string['scope_suspend_only'] = 'Suspend Only (inspect courses and suspend removed enrolments)';
$string['btn_sync_courses'] = '📘 Sync Courses Only';
$string['btn_sync_teachers'] = '👨‍🏫 Sync Teachers for Courses';
$string['btn_sync_students'] = '🎓 Sync Students for Courses';
$string['btn_sync_suspend'] = '⏸️ Sync Suspensions Only';
$string['confirm_sync_courses'] = 'Are you sure you want to sync courses and term structure? (No heavy user data is fetched)';
$string['confirm_sync_teachers'] = 'Are you sure you want to sync and enrol teachers into courses for this term?';
$string['confirm_sync_students'] = 'Are you sure you want to sync and enrol students into courses for this term?';
$string['confirm_sync_suspend'] = 'Are you sure you want to inspect and suspend missing enrolments?';
$string['activeterm'] = 'Active Term Code';
$string['activeterm_desc'] = 'Sync only this specific term (e.g. 14042). Leave blank to auto-detect from Stu_Lms_Rest_TermList.';
$string['use_latest_term'] = 'Use Latest Term from TermList';
$string['use_latest_term_desc'] = 'If active term is blank, auto-select newest term from Stu_Lms_Rest_TermList.';
$string['direct_user_sync'] = 'Direct Professor & Student Sync (Guaranteed)';
$string['direct_user_sync_desc'] = 'In addition to courses, directly import professors and students from Sadaa REST methods.';
$string['settings_filters'] = 'Sync Granular Filters (Entry Year & Program Codes)';
$string['settings_filters_desc'] = 'Filter students and courses by university entry year or study program codes.';
$string['entry_year_min'] = 'Minimum Entry Year (From year)';
$string['entry_year_min_desc'] = 'Only sync students whose entry year is >= this year (e.g. 1403 for 1403 onwards). Blank = no filter.';
$string['entry_year_max'] = 'Maximum Entry Year (To year)';
$string['entry_year_max_desc'] = 'Only sync students whose entry year is <= this year (e.g. 1404). Blank = no filter.';
$string['filter_programs'] = 'Program / Study Codes Filter';
$string['filter_programs_desc'] = 'Allowed program codes separated by commas (e.g. 236, 339) or ranges with dashes (e.g. 200-350). Blank = all programs.';
$string['settings_advanced'] = 'Advanced Identifiers & User Settings';
$string['settings_activities'] = 'Automatic Activity & Online Session Provisioning';
$string['settings_activities_desc'] = 'Configuration for auto-creating 16 BigBlueButton sessions, Midterm, and Final exams.';
$string['auto_create_activities'] = 'Auto-create 16 BigBlueButton sessions + Midterm & Final';
$string['auto_create_activities_desc'] = 'When a course is created, automatically provision 16 BigBlueButton sessions (named Session 1 to 16), a midterm exam in the middle, and a final exam at the end. These activities will NEVER be deleted or modified on subsequent syncs or updates, fully preserving recorded sessions and grades.';
$string['bbb_record_enabled'] = 'Enable BigBlueButton Session Recording';
$string['bbb_record_enabled_desc'] = 'Enable session recording on all auto-provisioned BigBlueButton rooms.';
$string['course_idnumber_prefix'] = 'Course idnumber Prefix';
$string['course_idnumber_prefix_desc'] = 'Prefix for Moodle course idnumber based on offer id.';
$string['nationalidfield'] = 'National ID Profile Field';
$string['nationalidfield_desc'] = 'Shortname of user profile custom field (default: nationalid).';
$string['create_missing_users'] = 'Create Missing Users';
$string['create_missing_users_desc'] = 'Create accounts for students/teachers not already present in Moodle.';
$string['suspend_missing_enrol'] = 'Suspend Missing Enrolments';
$string['suspend_missing_enrol_desc'] = 'Suspend manual enrolments that no longer exist in Morvarid.';
$string['manualsync'] = 'Run & Diagnose Morvarid Sync';
$string['runsync'] = 'Run Sync Now';
$string['runsync_confirm'] = 'Start Morvarid sync with specified parameters? This may take several minutes.';
$string['syncrunning'] = 'Sync Completed';
$string['synclog'] = 'Recent Execution Logs';
$string['status'] = 'Status';
$string['created'] = 'Created';
$string['updated'] = 'Updated';
$string['enrolled'] = 'Enrolled';
$string['errors'] = 'Errors';
$string['term'] = 'Term';
$string['message'] = 'Message';
$string['nostarted'] = 'No sync has been executed yet.';
$string['error_notconfigured'] = 'Morvarid REST API URL or credentials are not configured.';
$string['error_noterm'] = 'Active term could not be determined. Please specify an active term code in plugin settings (e.g. 14042).';
$string['privacy:metadata'] = 'Stores Morvarid ID to Moodle ID mappings and execution logs.';
$string['task_sync'] = 'Nightly Morvarid Sync';
$string['diagnostics'] = 'API Connection Health & Diagnostics';
$string['run_diagnostics'] = 'Test & Diagnose Morvarid API';
$string['diagnostics_desc'] = 'Tests each Morvarid endpoint (Term, Faculty, Course, Teachers, Students) and shows response status.';
$string['endpoint_tested'] = 'API Endpoint / Method';
$string['records_count'] = 'Received Records Count';
$string['sample_data'] = 'Sample Payload';
$string['no_data_returned'] = 'No data returned or method not permitted on Morvarid server.';
