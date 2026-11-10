<?php
// This file is part of Moodle - http://moodle.org/

/**
 * فایل مشخصات نسخه و نیازمندی‌های پلاگین احراز هویت سادا (auth_sadaa).
 *
 * این پلاگین نقش پل ارتباطی ورود یکپارچه (Single Sign-On SSO Bridge) را میان سرویس FastAPI
 * و هسته مودل ایفا می‌کند و بلیت‌های دیجیتال امضاشده JWT را به نشست محلی امن تبدیل می‌نماید.
 *
 * @package    auth_sadaa
 * @copyright  2026 Sadaa Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'auth_sadaa';    // نام یکتای شناسه کامپوننت پلاگین در مودل
$plugin->version   = 2026010100;      // شماره نسخه افزونه (YYYYMMDDXX)
$plugin->requires  = 2024100700;      // حداقل نسخه مورد نیاز مودل (Moodle 4.5+)
$plugin->maturity  = MATURITY_STABLE; // درجه پایداری نرم‌افزاری (نسخه پایدار و نهایی)
$plugin->release   = '1.0.0';          // شماره برچسب انتشار افزونه
