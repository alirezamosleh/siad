"""
ماژول خدمات ارسال پیامک و اعتبارسنجی شماره همراه (SMS Gateway Service).

این ماژول وظایف زیر را بر عهده دارد:
  ۱. استانداردسازی و اعتبارسنجی شماره‌های تلفن همراه ایران (پیش‌شماره‌های 0098، +98، 98 و شماره‌های بدون صفر)
     به ساختار استاندارد ۱۱ رقمی (09XXXXXXXXX).
  ۲. ماسک‌کردن هوشمند شماره همراه جهت حفظ حریم خصوصی کاربر در رابط کاربری (مانند: 0912***6789).
  ۳. ارسال پیامک کد یکبار مصرف (OTP) از طریق وب‌سرویس قاصدک (Ghasedak) یا کاوه‌نگار (Kavenegar).
  ۴. پشتیبانی از حالت شبیه‌ساز و توسعه (Mock / Debug Mode) برای محیط‌های محلی بدون شارژ پیامکی.
"""

import logging
import re
from typing import Optional, Tuple
from app.config import get_settings

logger = logging.getLogger(__name__)


def normalize_iranian_phone(raw_phone: Optional[str]) -> Optional[str]:
    """
    استانداردساز شماره همراه ایران به قالب یکنواخت ۱۱ رقمی (09XXXXXXXXX).
    
    پشتیبانی از قالب‌های مختلف ورودی:
      +989123456789   -> 09123456789
      00989123456789 -> 09123456789
      989123456789   -> 09123456789
      9123456789     -> 09123456789
      09123456789    -> 09123456789

    پارامترها:
      raw_phone: رشته شماره تلفن خام دریافتی از دیتابیس یا فرم ورود.
    
    خروجی:
      شماره ۱۱ رقمی استاندارد در صورت معتبر بودن، در غیر این صورت None.
    """
    if not raw_phone:
        return None

    # حذف تمام کاراکترهای غیرعددی (خط تیره، فاصله، علامت مثبت و پرانتز)
    digits = re.sub(r"\D", "", raw_phone.strip())
    if not digits:
        return None

    # یکسان‌سازی پیش‌شماره بین‌المللی ایران به صفر محلی
    if digits.startswith("0098"):
        digits = "0" + digits[4:]
    elif digits.startswith("98") and len(digits) == 12:
        digits = "0" + digits[2:]
    elif digits.startswith("9") and len(digits) == 10:
        digits = "0" + digits

    # بررسی تطابق با الگوی معتبر شماره‌های همراه کشور ایران (09XXXXXXXXX)
    if re.match(r"^09\d{9}$", digits):
        return digits

    return None


def mask_phone_number(phone: str) -> str:
    """
    ماسک‌کردن شماره تلفن همراه جهت نمایش امن در صفحه ورود بدون افشای کل شماره.
    
    نمونه تبدیل:
      09123456789 -> 0912***6789

    پارامترها:
      phone: شماره همراه کاربر.
    
    خروجی:
      رشته ماسک‌شده شماره تلفن.
    """
    normalized = normalize_iranian_phone(phone) or phone
    if len(normalized) == 11:
        return f"{normalized[:4]}***{normalized[7:]}"
    return f"{normalized[:2]}***{normalized[-2:]}" if len(normalized) > 4 else "***"


async def send_otp_sms(phone: str, code: str) -> Tuple[bool, str]:
    """
    ارسال پیامک حاوی کد تایید ورود به تلفن همراه کاربر.
    
    این تابع ابتدا شماره را اعتبارسنجی نموده و سپس بر اساس تنظیمات درگاه‌های پیامک
    (قاصدک، کاوه‌نگار یا حالت تست محلی) پیام را ارسال می‌کند.

    پارامترها:
      phone: شماره تلفن همراه گیرنده.
      code: کد تایید عددی تولید شده (OTP).

    خروجی:
      تاپل شامل (وضعیت_موفقیت: bool, پیام_توضیحی: str).
    """
    settings = get_settings()
    normalized_phone = normalize_iranian_phone(phone)
    if not normalized_phone:
        return False, "شماره تلفن همراه نامعتبر است."

    # متن استاندارد پیامک تایید هویت
    sms_text = f"کد تایید ورود به سامانه: {code}\nاین کد تا ۲ دقیقه معتبر است."

    # اگر کلید وب‌سرویس پیامک تعریف نشده باشد یا در حالت دیباگ باشیم:
    if not settings.GHASEDAK_API_KEY and not settings.KAVENEGAR_API_KEY:
        logger.info(f"===> [SMS DEBUG/MOCK] ارسال کد ورود آزمایشی '{code}' به شماره '{normalized_phone}'")
        return True, "کد تایید ارسال شد (حالت شبیه‌ساز/تست فعال است)."

    # ۱. ارسال از طریق وب‌سرویس پیامک قاصدک (Ghasedak SMS)
    if settings.SMS_PROVIDER == "ghasedak" or settings.GHASEDAK_API_KEY:
        try:
            import httpx
            url = "http://api.ghasedaksms.com/v2/sms/send/simple"
            headers = {
                "apikey": settings.GHASEDAK_API_KEY,
                "Content-Type": "application/x-www-form-urlencoded",
            }
            payload = {
                "message": sms_text,
                "receptor": normalized_phone,
                "sender": settings.GHASEDAK_SENDER or "",
            }
            async with httpx.AsyncClient(timeout=8.0) as client:
                resp = await client.post(url, data=payload, headers=headers)
                data = resp.json()
                # وب‌سرویس قاصدک در پاسخ موفق فرمت {"result": "success", "messageids": ...} برمی‌گرداند
                if resp.status_code == 200 and data.get("result") == "success":
                    logger.info(f"پیامک قاصدک با موفقیت ارسال شد به {normalized_phone}, شناسه پیام: {data.get('messageids')}")
                    return True, "پیامک با موفقیت ارسال شد."
                else:
                    logger.error(f"خطای وب‌سرویس قاصدک: {data}")
                    return False, f"خطا در ارسال پیامک: {data.get('messageids', 'ناشناخته')}"
        except Exception as e:
            logger.error(f"خطای سیستمی در فراخوانی وب‌سرویس قاصدک: {e}")
            if settings.SMS_DEBUG_MODE:
                return True, "کد تایید ارسال شد (حالت خطا/دیباگ)."
            return False, "خطا در برقراری ارتباط با وب‌سرویس پیامک."

    # ۲. ارسال از طریق وب‌سرویس پیامک کاوه‌نگار (Kavenegar SMS)
    if settings.SMS_PROVIDER == "kavenegar" or settings.KAVENEGAR_API_KEY:
        try:
            import httpx
            url = f"https://api.kavenegar.com/v1/{settings.KAVENEGAR_API_KEY}/sms/send.json"
            payload = {
                "receptor": normalized_phone,
                "message": sms_text,
                "sender": settings.KAVENEGAR_SENDER or "",
            }
            async with httpx.AsyncClient(timeout=8.0) as client:
                resp = await client.post(url, data=payload)
                data = resp.json()
                entries = data.get("entries", [])
                if entries and entries[0].get("status") in [1, 2, 4, 5]:
                    logger.info(f"پیامک کاوه‌نگار با موفقیت به شماره {normalized_phone} ارسال شد.")
                    return True, "پیامک با موفقیت ارسال شد."
                logger.error(f"پاسخ ناموفق وب‌سرویس کاوه‌نگار: {data}")
                return False, "خطا در ارسال پیامک کاوه‌نگار."
        except Exception as e:
            logger.error(f"خطای ارتباط با سرور کاوه‌نگار: {e}")
            return False, "خطا در ارتباط با کاوه‌نگار."

    return True, "پیامک ارسال گردید."
