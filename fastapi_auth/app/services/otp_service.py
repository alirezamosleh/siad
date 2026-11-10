"""
ماژول مدیریت کدهای ورود یکبار مصرف (OTP - One-Time Password Service).

این ماژول وظایف زیر را بر عهده دارد:
  ۱. تولید کدهای عددی امن با کتابخانه رمزنگاری secrets (پیش‌فرض ۵ رقم).
  ۲. مدیریت انقضای زمانی کدها (TTL - به عنوان مثال ۲ دقیقه).
  ۳. اعمال محدودیت زمانی جهت جلوگیری از ارسال مکرر (Cooldown / Rate Limiting).
  ۴. کنترل تعداد تلاش‌های ناموفق (حداکثر ۳ تلاش) جهت جلوگیری از حملات Brute Force.
  ۵. پاکسازی خودکار و ابطال کد پس از مصرف یا انقضا.
"""

import asyncio
import logging
import secrets
import time
from typing import Dict, Any, Optional, Tuple
from app.config import get_settings

logger = logging.getLogger(__name__)

# حافظه موقت (In-Memory Store) برای نگهداری وضعیت کدهای یکبار مصرف فعال:
# ساختار: کلید (نام کاربری به حروف کوچک) -> مشخصات کد و محدودیت‌ها
_otp_store: Dict[str, Dict[str, Any]] = {}
_store_lock: Optional[asyncio.Lock] = None


def _get_lock() -> asyncio.Lock:
    """دریافت یا ایجاد قفل همزمانی ناهمگام (Asyncio Lock) جهت جلوگیری از Race Condition در حافظه موقت."""
    global _store_lock
    if _store_lock is None:
        _store_lock = asyncio.Lock()
    return _store_lock


def generate_otp_code(length: int = 5) -> str:
    """
    تولید یک کد تصادفی عددی و امن با طول مشخص (پیش‌فرض ۵ رقمی بین ۱۰۰۰۰ تا ۹۹۹۹۹).
    
    از secrets.randbelow استفاده می‌شود که از نظر کریپتوگرافی کاملاً امن و غیرقابل پیش‌بینی است.
    """
    return f"{secrets.randbelow(90000) + 10000}"


async def create_and_store_otp(
    username: str,
    user_id: int,
    phone: str,
) -> Tuple[bool, str, Optional[str], int]:
    """
    ایجاد و ذخیره‌سازی کد تایید یکبار مصرف برای یک کاربر.
    
    این متد زمان ارسال قبلی را چک می‌کند و در صورتی که زمان استراحت (Cooldown)
    هنوز به پایان نرسیده باشد، درخواست را رد می‌کند.

    پارامترها:
      username: نام کاربری (شماره دانشجویی / کد ملی / نام کاربری کارمند).
      user_id: شناسه کاربر در مودل.
      phone: شماره همراه ثبت‌شده کاربر.

    خروجی:
      تاپل شامل (موفقیت: bool, پیام: str, کد_تولیدشده: Optional[str], زمان_باقیمانده_استراحت: int).
    """
    settings = get_settings()
    lock = _get_lock()
    key = username.strip().lower()
    now = time.time()

    async with lock:
        existing = _otp_store.get(key)
        if existing:
            elapsed = now - existing.get("last_sent_at", 0)
            cooldown = settings.OTP_COOLDOWN_SECONDS
            if elapsed < cooldown:
                remaining = int(cooldown - elapsed)
                return False, f"لطفاً {remaining} ثانیه دیگر مجدداً تلاش نمایید.", None, remaining

        # تولید کد جدید ۵ رقمی و محاسبه زمان انقضا
        code = generate_otp_code(5)
        ttl = settings.OTP_TTL_SECONDS

        _otp_store[key] = {
            "code": code,
            "username": username,
            "user_id": user_id,
            "phone": phone,
            "expires_at": now + ttl,
            "last_sent_at": now,
            "attempts": 0,
        }

        logger.info(f"کد تایید ورود برای کاربر '{username}' (تلفن: {phone}) با اعتبار {ttl} ثانیه ایجاد گردید.")
        return True, "کد تایید با موفقیت ایجاد شد.", code, settings.OTP_COOLDOWN_SECONDS


async def verify_otp_code(
    username: str,
    submitted_code: str,
) -> Tuple[bool, str, Optional[Dict[str, Any]]]:
    """
    اعتبارسنجی کد وارد شده توسط کاربر در برابر کد ثبت‌شده در حافظه.
    
    بررسی‌های امنیتی:
      ۱. وجود رکورد کد فعال برای نام کاربری.
      ۲. عدم اتمام مهلت زمانی (Expiration Check).
      ۳. شمارش تعداد تلاش‌ها (حداکثر ۳ بار مجاز است؛ تلاش چهارم باعث باطل‌شدن فوری کد می‌شود).
      ۴. تطابق دقیق کد ارسالی با مقدار ذخیره‌شده.
      ۵. حذف آنی کد از حافظه پس از ورود موفق (یکبار مصرف واقعی).

    پارامترها:
      username: نام کاربری درخواست‌دهنده.
      submitted_code: کد وارد شده در فرم ورود.

    خروجی:
      تاپل شامل (معتبر_است: bool, پیام: str, اطلاعات_کاربر: Optional[Dict]).
    """
    lock = _get_lock()
    key = username.strip().lower()
    now = time.time()

    async with lock:
        entry = _otp_store.get(key)
        if not entry:
            return False, "کد تاییدی برای این حساب یافت نشد یا منقضی شده است. لطفاً مجدداً درخواست کد دهید.", None

        # بررسی انقضای زمانی
        if now > entry.get("expires_at", 0):
            _otp_store.pop(key, None)
            return False, "کد تایید منقضی شده است. لطفاً مجدداً درخواست کد نمایید.", None

        # بررسی فراتر رفتن از حد مجاز خطا
        if entry.get("attempts", 0) >= 3:
            _otp_store.pop(key, None)
            return False, "به دلیل تکرار ورود کد اشتباه، این کد باطل شد. لطفاً مجدداً درخواست ارسال کد نمایید.", None

        stored_code = str(entry.get("code", "")).strip()
        cleaned_submitted = str(submitted_code).strip()

        # تطبیق کد
        if cleaned_submitted != stored_code:
            entry["attempts"] = entry.get("attempts", 0) + 1
            remaining_attempts = 3 - entry["attempts"]
            if remaining_attempts <= 0:
                _otp_store.pop(key, None)
                return False, "کد تایید اشتباه است. مهلت ورود کد به پایان رسید. لطفاً مجدداً درخواست ارسال کد نمایید.", None
            return False, f"کد تایید اشتباه است. ({remaining_attempts} تلاش دیگر باقی مانده است)", None

        # اعتبارسنجی موفق: کد از حافظه پاک می‌شود تا امکان استفاده مجدد نباشد
        valid_user_data = dict(entry)
        _otp_store.pop(key, None)
        logger.info(f"کد تایید کاربر '{username}' با موفقیت اعتبارسنجی و ابطال شد.")
        return True, "کد تایید با موفقیت تایید شد.", valid_user_data
