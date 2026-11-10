<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Admin settings and pages for local_morvaridsync.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_morvaridsync',
        get_string('pluginname', 'local_morvaridsync')
    );

    $settings->add(new admin_setting_heading(
        'local_morvaridsync/apiheading',
        get_string('settings_api', 'local_morvaridsync'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_morvaridsync/apiurl',
        get_string('apiurl', 'local_morvaridsync'),
        get_string('apiurl_desc', 'local_morvaridsync'),
        'https://erp.example.ac.ir/rest',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_morvaridsync/apiuser',
        get_string('apiuser', 'local_morvaridsync'),
        get_string('apiuser_desc', 'local_morvaridsync'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_morvaridsync/apipassword',
        get_string('apipassword', 'local_morvaridsync'),
        get_string('apipassword_desc', 'local_morvaridsync'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'local_morvaridsync/httpmethod',
        get_string('httpmethod', 'local_morvaridsync'),
        get_string('httpmethod_desc', 'local_morvaridsync'),
        'GET',
        ['GET' => 'GET', 'POST' => 'POST']
    ));

    $settings->add(new admin_setting_heading(
        'local_morvaridsync/syncheading',
        get_string('settings_sync', 'local_morvaridsync'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'local_morvaridsync/sync_scope',
        get_string('sync_scope', 'local_morvaridsync'),
        get_string('sync_scope_desc', 'local_morvaridsync'),
        'all',
        [
            'all' => get_string('scope_all', 'local_morvaridsync'),
            'users_only' => get_string('scope_users_only', 'local_morvaridsync'),
            'courses_only' => get_string('scope_courses_only', 'local_morvaridsync'),
            'enrolments_only' => get_string('scope_enrolments_only', 'local_morvaridsync'),
            'suspend_only' => get_string('scope_suspend_only', 'local_morvaridsync'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_morvaridsync/activeterm',
        get_string('activeterm', 'local_morvaridsync'),
        get_string('activeterm_desc', 'local_morvaridsync'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_morvaridsync/use_latest_term',
        get_string('use_latest_term', 'local_morvaridsync'),
        get_string('use_latest_term_desc', 'local_morvaridsync'),
        1
    ));

    $settings->add(new admin_setting_heading(
        'local_morvaridsync/filtersheading',
        get_string('settings_filters', 'local_morvaridsync'),
        get_string('settings_filters_desc', 'local_morvaridsync')
    ));

    $settings->add(new admin_setting_configtext(
        'local_morvaridsync/entry_year_min',
        get_string('entry_year_min', 'local_morvaridsync'),
        get_string('entry_year_min_desc', 'local_morvaridsync'),
        '',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_morvaridsync/entry_year_max',
        get_string('entry_year_max', 'local_morvaridsync'),
        get_string('entry_year_max_desc', 'local_morvaridsync'),
        '',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_morvaridsync/filter_programs',
        get_string('filter_programs', 'local_morvaridsync'),
        get_string('filter_programs_desc', 'local_morvaridsync'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_heading(
        'local_morvaridsync/advancedheading',
        get_string('settings_advanced', 'local_morvaridsync'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_morvaridsync/direct_user_sync',
        get_string('direct_user_sync', 'local_morvaridsync'),
        get_string('direct_user_sync_desc', 'local_morvaridsync'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_morvaridsync/course_idnumber_prefix',
        get_string('course_idnumber_prefix', 'local_morvaridsync'),
        get_string('course_idnumber_prefix_desc', 'local_morvaridsync'),
        'mv-',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_morvaridsync/nationalidfield',
        get_string('nationalidfield', 'local_morvaridsync'),
        get_string('nationalidfield_desc', 'local_morvaridsync'),
        'nationalid',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_morvaridsync/create_missing_users',
        get_string('create_missing_users', 'local_morvaridsync'),
        get_string('create_missing_users_desc', 'local_morvaridsync'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_morvaridsync/suspend_missing_enrol',
        get_string('suspend_missing_enrol', 'local_morvaridsync'),
        get_string('suspend_missing_enrol_desc', 'local_morvaridsync'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_morvaridsync/activitiesheading',
        get_string('settings_activities', 'local_morvaridsync'),
        get_string('settings_activities_desc', 'local_morvaridsync')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_morvaridsync/auto_create_activities',
        get_string('auto_create_activities', 'local_morvaridsync'),
        get_string('auto_create_activities_desc', 'local_morvaridsync'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_morvaridsync/bbb_record_enabled',
        get_string('bbb_record_enabled', 'local_morvaridsync'),
        get_string('bbb_record_enabled_desc', 'local_morvaridsync'),
        1
    ));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_morvaridsync_run',
        get_string('manualsync', 'local_morvaridsync'),
        new moodle_url('/local/morvaridsync/index.php'),
        'local/morvaridsync:manage'
    ));
}
