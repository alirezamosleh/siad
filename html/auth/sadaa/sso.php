<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * ============================================================================
 * اندپوینت دریافت بلیت یکپارچه (SSO Bridge Receiver Endpoint)
 * ============================================================================
 *
 * شرح فرآیند:
 * این فایل به عنوان نقطه ورود کاربر از سرویس FastAPI (پورت ۸۰۱۶) به مودل عمل می‌کند:
 *   ۱. کلاینت با یک بلیت امضاشده دیجیتالی به فرمت JWT (حاوی sub, uid, exp, wantsurl) به این آدرس می‌آید:
 *      http://localhost:8015/auth/sadaa/sso.php?ticket=<JWT>
 *   ۲. با استفاده از کلید محرمانه مشترک (SSO_SECRET)، امضای دیجیتال و تاریخ انقضای توکن بررسی می‌شود.
 *   ۳. کاربر مربوطه از جدول mdl_user بر اساس شناسه عددی (id) یا نام کاربری (username) استخراج می‌گردد.
 *   ۴. تابع رسمی و بومی مودل complete_user_login($user) اجرا می‌شود:
 *      - این تابع کوکی رسمی MoodleSession را روی دامنه و پورت خود مودل ست می‌کند.
 *      - شناسه نشست را در کش/دیتابیس مودل ذخیره می‌نماید.
 *      - رخداد استاندارد user_loggedin را در لاگ‌های سیستمی ثبت می‌کند.
 *   ۵. کاربر بلافاصله و بدون هیچ تاخیری به صفحه مقصد (wantsurl یا /my/) هدایت می‌گردد.
 *
 * @package    auth_sadaa
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/jwt_helper.php');

// ۱. دریافت پارامتر خام بلیت از کوئری‌استرینگ
$ticket = required_param('ticket', PARAM_RAW);

// ۲. واکشی کلید مشترک رمزنگاری متقارن از تنظیمات افزونه یا متغیرهای محیطی
$secret = get_config('auth_sadaa', 'sso_secret') ?: (getenv('SSO_SECRET') ?: 'siad-hu-jz-ac-ir-super-secret-sso-key-2026-xyz');

// ۳. اعتبارسنجی ریاضی امضای دیجیتال و تاریخ انقضای توکن JWT
try {
    $payload = \auth_sadaa\jwt_helper::verify($ticket, $secret);
} catch (\Throwable $e) {
    throw new \moodle_exception('error', '', '', null, 'اعتبارسنجی بلیت ورود یکپارچه با شکست مواجه شد: ' . $e->getMessage());
}

$username = $payload['sub'] ?? '';
$userid = isset($payload['uid']) ? (int)$payload['uid'] : 0;

// ۴. جستجوی کاربر در پایگاه داده مودل (ابتدا با شناسه عددی و سپس با نام کاربری)
$user = null;
if ($userid > 0) {
    $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
}
if (!$user && $username !== '') {
    $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
}

if (!$user) {
    throw new \moodle_exception('error', '', '', null, "کاربر با مشخصات '{$username}' در پایگاه داده مودل یافت نشد.");
}

// ۵. صدور رسمی و بومی نشست مودل برای کاربر تاییدشده
complete_user_login($user);

// ۶. هدایت کاربر به صفحه درخواستی (wantsurl) یا داشبورد میزکار (/my/)
$wantsurl = !empty($payload['wantsurl']) ? $payload['wantsurl'] : new \moodle_url('/my/');
redirect($wantsurl);
