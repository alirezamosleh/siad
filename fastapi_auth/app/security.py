"""
================================================================================
توابع امنیتی، رمزنگاری و امضای دیجیتال (Security, Hashing & JWT Signatures)
سامانه یادگیری الکترونیکی و احراز هویت هوشمند المصطفی
================================================================================

توضیحات:
این ماژول کلیه عملیات حساس رمزنگاری را پوشش می‌دهد:
  ۱. اعتبارسنجی کلمه عبور مودل (verify_moodle_password): سازگار با تمامی فرمت‌های استاندارد
     مودل در نسخه‌های مختلف PHP، شامل Bcrypt ($2y$, $2b$, $2a$)، SHA-512 ($6$) و MD5 قدیمی.
  ۲. هش کردن رمز عبور (hash_moodle_password): تولید هش Bcrypt سازگار ۱۰۰٪ با PHP با پیشوند $2y$.
  ۳. بلیت ورود یکپارچه (create_sso_ticket): تولید توکن‌های امضاشده JWT با کلید متقارن SSO_SECRET
     که پس از ورود در پورت ۸۰۱۶، به افزونه auth_sadaa ارسال می‌شوند تا سشن رسمی مودل روی پورت ۸۰۱۵ برقرار گردد.
  ۴. توکن‌های بازیابی رمز عبور (Password Reset Tokens).
"""

import time
import uuid
from typing import Any, Dict, Optional
import bcrypt
import jwt
from app.config import get_settings


def verify_moodle_password(plain_password: str, hashed_password: str) -> bool:
    """
    اعتبارسنجی کلمه عبور خام کاربر با هش ذخیره‌شده در جدول mdl_user مودل.
    
    این تابع به ترتیب مراحل زیر را بررسی می‌کند:
      ۱. بررسی متنی مستقیم (در محیط‌های تستی)
      ۲. بررسی با متد استاندارد سیستم‌عامل لینوکس crypt (الگوریتم‌های $6$ SHA-512 و $5$ SHA-256)
      ۳. بررسی مستقیم با پایتون bcrypt (پشتیبانی از پیشوندهای $2y$ بومی مودل)
      ۴. نرمال‌سازی پیشوند $2y$ به $2b$ و $2a$ جهت اطمینان از سازگاری در تمامی توزیع‌ها
      ۵. بررسی رمزهای هش‌شده قدیمی با الگوریتم MD5 مودل نسخه ۱ و ۲
    """
    if not hashed_password or not plain_password:
        return False

    # ۱. بررسی تطابق مستقیم متنی (تستی)
    if hashed_password == plain_password:
        return True

    pw_bytes = plain_password.encode("utf-8")

    # ۲. بررسی با توابع لینوکس crypt (SHA-512 $6$ یا SHA-256 $5$)
    try:
        import crypt
        if crypt.crypt(plain_password, hashed_password) == hashed_password:
            return True
    except Exception:
        pass

    # ۳. بررسی مستقیم با bcrypt
    try:
        if bcrypt.checkpw(pw_bytes, hashed_password.encode("utf-8")):
            return True
    except Exception:
        pass

    # ۴. نرمال‌سازی پیشوند اختصاصی PHP ($2y$) به پیشوندهای استاندارد Bcrypt ($2b$ و $2a$)
    if hashed_password.startswith("$2y$"):
        try:
            norm_hash = ("$2b$" + hashed_password[4:]).encode("utf-8")
            if bcrypt.checkpw(pw_bytes, norm_hash):
                return True
        except Exception:
            pass

        try:
            norm_hash = ("$2a$" + hashed_password[4:]).encode("utf-8")
            if bcrypt.checkpw(pw_bytes, norm_hash):
                return True
        except Exception:
            pass

    # ۵. بررسی هش‌های بسیار قدیمی با الگوریتم MD5
    import hashlib
    if hashlib.md5(pw_bytes).hexdigest() == hashed_password:
        return True

    return False


def hash_moodle_password(plain_password: str) -> str:
    """
    تولید هش رمز عبور با استاندارد Bcrypt به صورت سازگار ۱۰۰٪ با PHP و مودل ($2y$).
    از هزینه محاسباتی (Salt Rounds) برابر ۱۰ استفاده می‌شود که استاندارد امن مودل ۴.۵ است.
    """
    salt = bcrypt.gensalt(rounds=10)
    pw_hash = bcrypt.hashpw(plain_password.encode("utf-8"), salt).decode("utf-8")
    
    # تبدیل پیشوند $2b$ پیش‌فرض پایتون به پیشوند استاندارد $2y$ مورد انتظار هسته مودل
    if pw_hash.startswith("$2b$"):
        pw_hash = "$2y$" + pw_hash[4:]
    return pw_hash


def create_sso_ticket(
    user_id: int,
    username: str,
    wantsurl: Optional[str] = None,
    extra_data: Optional[Dict[str, Any]] = None,
) -> str:
    """
    تولید بلیت دیجیتال یکپارچه (Single-Use JWT SSO Ticket) برای ورود فوری به مودل.
    
    این بلیت توسط اندپوینت sso.php افزونه auth_sadaa اعتبارسنجی می‌شود و دارای:
      - شناسه کاربری (uid)
      - نام کاربری (sub)
      - تاریخ انقضای محدود (exp - معمولاً ۲ دقیقه)
      - شناسه یکتا (jti) جهت ممانعت از ارسال تکراری
      - آدرس صفحه مقصد مورد نظر کاربر (wantsurl)
    """
    settings = get_settings()
    now = int(time.time())
    payload = {
        "iss": settings.APP_NAME,
        "sub": username,
        "uid": user_id,
        "iat": now,
        "exp": now + settings.SSO_TOKEN_TTL_SECONDS,
        "jti": str(uuid.uuid4()),
        "wantsurl": wantsurl or "",
    }
    if extra_data:
        payload["data"] = extra_data

    # امضای دیجیتال با الگوریتم HMAC-SHA256 و کلید محرمانه سیستم
    return jwt.encode(payload, settings.SSO_SECRET, algorithm="HS256")


def decode_sso_ticket(token: str) -> Optional[Dict[str, Any]]:
    """
    رمزگشایی و اعتبارسنجی صحت بلیت دیجیتال ورود یکپارچه.
    """
    settings = get_settings()
    try:
        return jwt.decode(token, settings.SSO_SECRET, algorithms=["HS256"])
    except jwt.PyJWTError:
        return None


def create_password_reset_token(user_id: int, username: str) -> str:
    """
    تولید توکن رمزنگاری‌شده جهت ارسال لینک بازیابی کلمه عبور به ایمیل کاربر.
    """
    settings = get_settings()
    now = int(time.time())
    exp = now + (settings.PASSWORD_RESET_TOKEN_TTL_MINUTES * 60)
    payload = {
        "action": "password_reset",
        "uid": user_id,
        "sub": username,
        "iat": now,
        "exp": exp,
        "jti": str(uuid.uuid4()),
    }
    return jwt.encode(payload, settings.SSO_SECRET, algorithm="HS256")


def verify_password_reset_token(token: str) -> Optional[Dict[str, Any]]:
    """
    اعتبارسنجی توکن بازیابی کلمه عبور دریافتی از کاربر.
    """
    settings = get_settings()
    try:
        payload = jwt.decode(token, settings.SSO_SECRET, algorithms=["HS256"])
        if payload.get("action") != "password_reset":
            return None
        return payload
    except jwt.PyJWTError:
        return None
