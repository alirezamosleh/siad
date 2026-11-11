<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Persian strings for local_morvaridsync.
 *
 * @package    local_morvaridsync
 * @copyright  2026 Sadaa
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'همگام‌سازی مروارید (سادا)';
$string['morvaridsync:manage'] = 'مدیریت همگام‌سازی مروارید';
$string['settings_api'] = 'تنظیمات اتصال وب‌سرویس REST مروارید';
$string['apiurl'] = 'آدرس پایه REST';
$string['apiurl_desc'] = 'مثال: https://erp.example.ac.ir/rest یا http://server/rest.aspx';
$string['apiuser'] = 'نام کاربری API (inUserName)';
$string['apiuser_desc'] = 'نام کاربری REST مروارید.';
$string['apipassword'] = 'رمز API (inPassword)';
$string['apipassword_desc'] = 'رمز عبور REST مروارید.';
$string['httpmethod'] = 'متد درخواست HTTP';
$string['httpmethod_desc'] = 'متد ارسال پارامترها به وب‌سرویس سادا (معمولاً GET یا POST).';
$string['settings_sync'] = 'رفتار و استراتژی همگام‌سازی';
$string['sync_scope'] = 'حوزه همگام‌سازی';
$string['sync_scope_desc'] = 'انتخاب کنید چه بخش‌هایی از اطلاعات همگام‌سازی شوند.';
$string['scope_courses_only'] = 'سینک درس‌ها (ایجاد و به‌روزرسانی دروس و دسته‌بندی‌ها)';
$string['scope_teachers_only'] = 'سینک اساتید متناسب با درس‌ها (اتصال اساتید به دروس مربوطه)';
$string['scope_students_only'] = 'سینک دانشجویان متناسب با درس‌ها (ثبت‌نام دانشجویان در دروس مربوطه)';
$string['scope_suspend_only'] = 'سینک تعلیق‌ها (تعلیق دانشجویان حذف شده در مروارید)';
$string['btn_sync_courses'] = '📘 سینک درس‌ها';
$string['btn_sync_teachers'] = '👨‍🏫 سینک اساتید متناسب با درس‌ها';
$string['btn_sync_students'] = '🎓 سینک دانشجویان متناسب با درس‌ها';
$string['btn_sync_suspend'] = '⏸️ سینک تعلیق ثبت‌نام‌ها';
$string['confirm_sync_courses'] = 'آیا از همگام‌سازی درس‌ها و ساختار ترم اطمینان دارید؟ (هیچ داده کاربری در این مرحله لود نمی‌شود)';
$string['confirm_sync_teachers'] = 'آیا از همگام‌سازی و اتصال اساتید به دروس این ترم اطمینان دارید؟';
$string['confirm_sync_students'] = 'آیا از همگام‌سازی و ثبت‌نام دانشجویان در دروس این ترم اطمینان دارید؟';
$string['confirm_sync_suspend'] = 'آیا از بررسی و تعلیق ثبت‌نام‌های حذف شده اطمینان دارید؟';
$string['activeterm'] = 'کد ترم فعال';
$string['activeterm_desc'] = 'در صورت تنظیم، فقط همین ترم همگام می‌شود (مثلاً 14042). خالی = خودکار از Stu_Lms_Rest_TermList.';
$string['use_latest_term'] = 'استفاده خودکار از آخرین ترم';
$string['use_latest_term_desc'] = 'اگر ترم فعال خالی باشد، جدیدترین ترم از وب‌سرویس TermList انتخاب شود.';
$string['direct_user_sync'] = 'همگام‌سازی مستقیم اساتید و دانشجویان (تضمینی)';
$string['direct_user_sync_desc'] = 'علاوه بر دروس، اساتید و دانشجویان به صورت مستقیم از متدهای سادا/مروارید فراخوانی شده و در مودل ثبت می‌شوند.';
$string['settings_filters'] = 'فیلترهای سفارشی‌سازی همگام‌سازی (سال ورود و کد رشته)';
$string['settings_filters_desc'] = 'محدودسازی دانشجویان و دروس بر اساس سال ورود به دانشگاه یا کدهای رشته/گروه تحصیلی.';
$string['entry_year_min'] = 'حداقل سال ورود دانشجو (از سال)';
$string['entry_year_min_desc'] = 'فقط دانشجویان با سال ورود مساوی یا بزرگتر از این سال همگام می‌شوند (مثلاً 1403 برای ورودی‌های ۱۴۰۳ به بعد). خالی = همه ورودی‌ها.';
$string['entry_year_max'] = 'حداکثر سال ورود دانشجو (تا سال)';
$string['entry_year_max_desc'] = 'فقط دانشجویان با سال ورود مساوی یا کوچکتر از این سال همگام می‌شوند (مثلاً 1404). خالی = بدون محدودیت سقف.';
$string['filter_programs'] = 'فیلتر کدهای رشته / گروه تحصیلی';
$string['filter_programs_desc'] = 'کدهای مجاز رشته با کاما (مثلاً 236, 339) یا رنج عددی با خط تیره (مثلاً 200-350). خالی = همه رشته‌ها.';
$string['settings_advanced'] = 'تنظیمات پیشرفته شناسه‌ها و کاربران';
$string['settings_activities'] = 'تنظیمات ایجاد خودکار فعالیت‌ها و جلسات آنلاین';
$string['settings_activities_desc'] = 'پیکربندی ایجاد خودکار ۱۶ جلسه بیگ‌بلوباتن، آزمون میان‌ترم و آزمون پایان‌ترم برای کلاس‌ها.';
$string['auto_create_activities'] = 'ایجاد خودکار ۱۶ جلسه بیگ‌بلوباتن + میان‌ترم و پایان‌ترم';
$string['auto_create_activities_desc'] = 'در زمان ایجاد هر کلاس، ۱۶ جلسه بیگ‌بلوباتن (تحت عنوان جلسه ۱ تا جلسه ۱۶)، یک آزمون میان‌ترم در وسط و یک آزمون پایان‌ترم در آخر به صورت خودکار ایجاد می‌شود. این فعالیت‌ها در به‌روزرسانی‌ها و همگام‌سازی‌های بعدی به هیچ وجه حذف یا تغییر نخواهند کرد تا جلسات ضبط‌شده و نمرات کاملاً محافظت شوند.';
$string['bbb_record_enabled'] = 'فعال‌سازی قابلیت ضبط جلسات بیگ‌بلوباتن';
$string['bbb_record_enabled_desc'] = 'در تمام جلسات آنلاین ایجاد شده، امکان ضبط کلاس فعال باشد.';
$string['course_idnumber_prefix'] = 'پیشوند idnumber درس';
$string['course_idnumber_prefix_desc'] = 'پیشوند idnumber درس مودل بر اساس کد مشخصه ارائه.';
$string['nationalidfield'] = 'فیلد پروفایل کد ملی';
$string['nationalidfield_desc'] = 'shortname فیلد سفارشی کد ملی (پیش‌فرض: nationalid).';
$string['create_missing_users'] = 'ایجاد کاربران ناموجود';
$string['create_missing_users_desc'] = 'برای دانشجو/استاد بدون حساب مودل، کاربر ساخته شود.';
$string['suspend_missing_enrol'] = 'تعلیق ثبت‌نام‌های حذف‌شده از منبع';
$string['suspend_missing_enrol_desc'] = 'در صورت فعال بودن، ثبت‌نام‌های manual که دیگر در مروارید نیستند تعلیق می‌شوند.';
$string['manualsync'] = 'اجرا و عیب‌یابی همگام‌سازی سادا/مروارید';
$string['runsync'] = 'اجرای همگام‌سازی اکنون';
$string['runsync_confirm'] = 'همگام‌سازی مروارید با تنظیمات و فیلترهای مشخص‌شده آغاز شود؟ ممکن است چند دقیقه زمان ببرد.';
$string['syncrunning'] = 'نتیجه اجرای همگام‌سازی';
$string['synclog'] = 'گزارش‌های اخیر';
$string['status'] = 'وضعیت';
$string['created'] = 'ایجاد شده';
$string['updated'] = 'به‌روزرسانی شده';
$string['enrolled'] = 'ثبت‌نام شده';
$string['errors'] = 'خطاها';
$string['term'] = 'ترم';
$string['message'] = 'پیام / شرح عملیات';
$string['nostarted'] = 'هنوز اجرایی ثبت نشده است.';
$string['error_notconfigured'] = 'آدرس یا نام‌کاربری/رمز عبور وب‌سرویس REST مروارید تنظیم نشده است.';
$string['error_noterm'] = 'ترم فعال مشخص نشد. لطفاً در تنظیمات پلاگین فیلد "کد ترم فعال" را مقداردهی کنید (مثلاً 14042).';
$string['privacy:metadata'] = 'نگاشت شناسه‌های مروارید به مودل و لاگ همگام‌سازی ذخیره می‌شود.';
$string['task_sync'] = 'همگام‌سازی شبانه مروارید';
$string['diagnostics'] = 'بررسی سلامت اتصال و عیب‌یابی وب‌سرویس سادا';
$string['run_diagnostics'] = 'تست و عیب‌یابی وب‌سرویس سادا';
$string['diagnostics_desc'] = 'بررسی وضعیت پاسخ‌دهی تمام اندپوینت‌های مروارید (ترم، دانشکده، رشته، اساتید، دانشجویان و دروس).';
$string['endpoint_tested'] = 'متد وب‌سرویس';
$string['records_count'] = 'تعداد رکوردهای دریافتی';
$string['sample_data'] = 'نمونه داده دریافتی';
$string['no_data_returned'] = 'هیچ داده‌ای دریافت نشد یا این متد روی سرور سادا فعال نیست.';
