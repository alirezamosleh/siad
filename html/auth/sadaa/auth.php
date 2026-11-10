<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * ============================================================================
 * افزونه احراز هویت اختصاصی سادا (Sadaa Single Sign-On Plugin)
 * ============================================================================
 *
 * شرح کارکرد افزونه:
 * این افزونه پیوند مستقیم میان مودل و سرور احراز هویت FastAPI (پورت ۸۰۱۶) را برقرار می‌کند:
 *   ۱. هوک صفحه لاگین (loginpage_hook):
 *      هر زمان کاربری آدرس پیش‌فرض login/index.php مودل را باز کند، این متد قبل از رندر
 *      فرم پیش‌فرض، کاربر را به آدرس http://localhost:8016 هدایت می‌نماید تا رابط
 *      کاربری مدرن، فارسی و پشتیبانی‌کننده از پیامک و اوراکل نمایش داده شود.
 *   ۲. اعتبارسنجی رمز عبور (user_login):
 *      در صورت نیاز به بررسی پسورد در سطح مودل، از متد استاندارد validate_internal_user_password
 *      استفاده می‌کند.
 *
 * @package    auth_sadaa
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

class auth_plugin_sadaa extends auth_plugin_base {

    /**
     * سازنده کلاس افزونه و بارگذاری تنظیمات اختصاصی.
     */
    public function __construct() {
        $this->authtype = 'sadaa';
        $this->config = get_config('auth_sadaa');
    }

    /**
     * اعتبارسنجی نام کاربری و رمز عبور بر اساس دیتابیس داخلی مودل (mdl_user).
     *
     * @param string $username نام کاربری واردشده
     * @param string $password رمز عبور خام
     * @return bool نتیجه اعتبارسنجی (true / false)
     */
    public function user_login($username, $password) {
        global $DB, $CFG;

        if ($username === '' || $password === '') {
            return false;
        }

        // جستجوی کاربر در جدول کاربران مودل
        $user = $DB->get_record('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);

        if (!$user) {
            return false;
        }

        // اعتبارسنجی هش ذخیره‌شده بر اساس روش بومی مودل (Bcrypt / Argon2)
        return validate_internal_user_password($user, $password);
    }

    /**
     * هوک انتقال خودکار صفحه لاگین مودل به سامانه مرکزی احراز هویت FastAPI.
     *
     * هرگاه کاربری به صفحه ورود مودل (login/index.php) مراجعه کند:
     *   - آدرس صفحه مقصد مورد نظر کاربر (wantsurl) حفظ می‌شود.
     *   - درخواست‌های پل داخلی (internal_bridge) جهت جلوگیری از ایجاد حلقه نادیده گرفته می‌شوند.
     *   - مرورگر مستقیماً به سرویس FastAPI ریدایرکت می‌گردد.
     */
    public function loginpage_hook() {
        global $CFG, $SESSION;

        // دریافت آدرس سرور احراز هویت از تنظیمات افزونه یا مقدار پیش‌فرض
        $authservice = get_config('auth_sadaa', 'auth_service_url') ?: 'http://localhost:8016';
        
        // بررسی عدم تکرار حلقه برای ارتباطات پشت‌صحنه سرویس لاگین
        if (!empty($authservice) && empty($_SERVER['HTTP_X_INTERNAL_BRIDGE']) && empty($_GET['internal_bridge'])) {
            $wantsurl = !empty($SESSION->wantsurl) ? ('?wantsurl=' . urlencode($SESSION->wantsurl)) : '';
            redirect($authservice . $wantsurl);
        }
    }
}
