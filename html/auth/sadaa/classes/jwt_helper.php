<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * ============================================================================
 * راهنمای پردازش و اعتبارسنجی JWT (JSON Web Token Helper)
 * ============================================================================
 *
 * شرح کارکرد:
 * این کلاس برای بررسی و رمزگشایی بلیت‌های ورود یکپارچه بدون وابستگی به هیچ پکیج
 * خارجی composer پیاده‌سازی شده و از توابع امنیتی پیش‌فرض PHP استفاده می‌کند:
 *   - hash_hmac برای اعتبارسنجی امضای رمزنگاری با الگوریتم HMAC-SHA256
 *   - hash_equals جهت جلوگیری از حملات مبتنی بر زمان (Timing Attacks)
 *   - اعتبارسنجی خودکار انقضای توکن (exp)
 *
 * @package    auth_sadaa
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_sadaa;

defined('MOODLE_INTERNAL') || die();

class jwt_helper {

    /**
     * دیکود ایمن فرمت base64url بر اساس استاندارد RFC 7515.
     *
     * @param string $data رشته با فرمت base64url
     * @return string بایت‌های دیکود شده
     */
    public static function b64url_decode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * انکود رشته به فرمت استاندارد base64url بدون کاراکترهای = زائد.
     *
     * @param string $data رشته متنی
     * @return string رشته با فرمت base64url
     */
    public static function b64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * اعتبارسنجی امضای دیجیتال و تاریخ انقضای بلیت JWT و بازگرداندن پی‌لود.
     *
     * @param string $ticket بلیت JWT دریافتی (سه بخش مجزا با نقطه)
     * @param string $secret کلید مشترک محرمانه
     * @return array پی‌لود استخراج‌شده به صورت آرایه انجمنی
     * @throws \Exception در صورت نامعتبر بودن ساختار، امضا یا منقضی شدن توکن
     */
    public static function verify(string $ticket, string $secret): array {
        // ۱. تفکیک سه بخش اصلی JWT شامل header, payload, signature
        $parts = explode('.', $ticket);
        if (count($parts) !== 3) {
            throw new \Exception('ساختار توکن JWT نامعتبر است (باید ۳ بخشی باشد).');
        }

        list($h_b64, $p_b64, $sig_b64) = $parts;

        // ۲. محاسبه مجدد امضای مورد انتظار با کلید مشترک
        $expected_sig = hash_hmac('sha256', "{$h_b64}.{$p_b64}", $secret, true);
        $provided_sig = self::b64url_decode($sig_b64);

        // ۳. بررسی تطابق امضا با hash_equals جهت جلوگیری از Timing Attacks
        if (!hash_equals($expected_sig, $provided_sig)) {
            throw new \Exception('امضای دیجیتال توکن نامعتبر است یا کلید محرمانه همخوانی ندارد.');
        }

        // ۴. تبدیل پی‌لود جیسون به آرایه
        $payload_json = self::b64url_decode($p_b64);
        $payload = json_decode($payload_json, true);

        if (!is_array($payload)) {
            throw new \Exception('محتوای داده‌ای (Payload) توکن نامعتبر است.');
        }

        // ۵. بررسی تاریخ انقضای بلیت (TTL)
        if (isset($payload['exp']) && (int)$payload['exp'] < time()) {
            throw new \Exception('مهلت استفاده از بلیت ورود یکپارچه منقضی گردیده است.');
        }

        return $payload;
    }
}
