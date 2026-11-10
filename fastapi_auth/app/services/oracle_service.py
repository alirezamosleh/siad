"""
================================================================================
سرویس احراز هویت پایگاه داده اوراکل (Oracle Database Authentication Service)
سامانه یادگیری الکترونیکی و احراز هویت هوشمند المصطفی
================================================================================

توضیحات معماری:
این سرویس به عنوان لایه ۱ و ۲ در خط لوله احراز هویت آبشاری (3-Tier Waterfall) عمل می‌کند:
  - لایه ۱: اعتبارسنجی دانشجویان در جدول SHARIFI.STU_INFO_REMOTE
  - لایه ۲: اعتبارسنجی اساتید در جدول SHARIFI.TEACHER_INFO_REMOTE

نکات کلیدی فنی:
۱. اتصال بدون وابستگی (Thin Mode):
   از درایور رسمی `oracledb` در حالت Thin استفاده شده است که ۱۰۰٪ پایتونی بوده و نیازی
   به نصب باینری‌های سنگین Oracle Instant Client روی سرور ندارد.

۲. الگوریتم رمزنگاری و رفتار توابع:
   - تابع SHARIFI.GENERAL_MYCRYPT(text): رمز عبور را با الگوریتم متقارن هگز ۳۲ کاراکتری
     رمزگذاری می‌کند. این تابع از مقدار اولیه تصادفی (Random IV/Salt) استفاده می‌نماید؛
     بنابراین اجرای آن روی یک رمز یکسان در دفعات مختلف، خروجی‌های متفاوتی تولید می‌کند.
   - تابع SHARIFI.GENERAL_MYDECRYPT(ramz): مقدار ستون RAMZ را رمزگشایی کرده و رمز اولیه را
     بازمی‌گرداند.
   - به دلیل تصادفی بودن IV در رمزنگاری، تطبیق رمز با دستور `RAMZ = GENERAL_MYCRYPT(:p)`
     امکان‌پذیر نیست؛ لذا با استفاده از ایندکس کد/نام کاربری، مقدار رمزگشایی‌شده از
     پایگاه داده استخراج و با رمز واردشده توسط کاربر مقایسه می‌گردد.

۳. تاب‌آوری در برابر قطعی (High Availability & Fail-Safe):
   تمامی متدها دارای بلوک‌های مدیریت خطای جامع هستند. در صورت عدم دسترسی موقت به
   پایگاه داده اوراکل، سرویس متوقف نشده و فرآیند احراز هویت فوراً به لایه ۳
   (کارمندان و مدیران مودل) هدایت می‌گردد.
"""

import logging
from typing import Optional, Dict, Any
import oracledb
from app.config import get_settings

logger = logging.getLogger(__name__)


def get_oracle_connection():
    """
    ایجاد کانکشن فعال به پایگاه داده اوراکل با استفاده از تنظیمات بارگذاری‌شده.
    جهت بیشترین پایداری، در صورت در دسترس نبودن پورت پیش‌فرض، پورت‌های متداول ۱۵۲۱ و ۱۱۵۲۱
    به صورت خودکار امتحان می‌شوند تا تغییر پورت تونل SSH مانع اتصال نشود.
    """
    settings = get_settings()
    if not settings.ORACLE_ENABLED:
        logger.info("اتصال به اوراکل بر اساس تنظیمات (ORACLE_ENABLED=False) غیرفعال است.")
        return None

    ports_to_try = [settings.ORACLE_PORT]
    for alt in [1521, 11521]:
        if alt not in ports_to_try:
            ports_to_try.append(alt)

    for port in ports_to_try:
        try:
            dsn = oracledb.makedsn(
                host=settings.ORACLE_HOST,
                port=port,
                service_name=settings.ORACLE_SERVICE_NAME,
            )
            return oracledb.connect(
                user=settings.ORACLE_USER,
                password=settings.ORACLE_PASSWORD,
                dsn=dsn,
            )
        except Exception as e:
            logger.debug(f"تلاش اتصال به اوراکل روی پورت {port} با خطا مواجه شد: {e}")
            continue

    logger.warning("برقراری ارتباط با پایگاه داده اوراکل روی هیچ‌یک از پورت‌های ۱۵۲۱ و ۱۱۵۲۱ میسر نشد.")
    return None


def encrypt_text(value: str) -> Optional[str]:
    """
    رمزنگاری متن ورودی با استفاده از تابع اختصاصی اسکیما (GENERAL_MYCRYPT).
    
    نکته: خروجی این تابع ۳۲ کاراکتر هگز است و به دلیل استفاده از IV رندوم،
    در هر اجرا مقدار متفاوتی بازمی‌گرداند.
    """
    settings = get_settings()
    schema = settings.ORACLE_SCHEMA
    try:
        with get_oracle_connection() as conn:
            with conn.cursor() as cur:
                cur.execute(f"SELECT {schema}.GENERAL_MYCRYPT(:val) FROM DUAL", val=value)
                row = cur.fetchone()
                return row[0] if row else None
    except Exception as e:
        logger.error(f"خطا در رمزنگاری متن توسط اوراکل (encrypt_text): {e}")
        return None


def decrypt_text(value: str) -> Optional[str]:
    """
    رمزگشایی مقدار ستون RAMZ با استفاده از تابع اختصاصی اسکیما (GENERAL_MYDECRYPT).
    این تابع متن رمزشده ۳۲ کاراکتری را به متن اصلی تبدیل می‌کند.
    """
    settings = get_settings()
    schema = settings.ORACLE_SCHEMA
    try:
        with get_oracle_connection() as conn:
            with conn.cursor() as cur:
                cur.execute(f"SELECT {schema}.GENERAL_MYDECRYPT(:val) FROM DUAL", val=value)
                row = cur.fetchone()
                return row[0] if row else None
    except Exception as e:
        logger.error(f"خطا در رمزگشایی متن توسط اوراکل (decrypt_text): {e}")
        return None


async def authenticate_oracle_user(identifier: str, plain_password: str) -> Optional[Dict[str, Any]]:
    """
    احراز هویت دو لایه دانشجویان و اساتید در پایگاه داده دانشگاهی اوراکل.
    
    مراحل اجرا:
      ۱. لایه اول (دانشجویان): جستجو بر اساس CODE (شماره دانشجویی) یا USERNAME
         در جدول STU_INFO_REMOTE. با توجه به ایندکس، تنها سطر مربوطه استخراج
         و با تابع GENERAL_MYDECRYPT رمز بررسی می‌شود.
      ۲. لایه دوم (اساتید): در صورت عدم یافتن در جدول دانشجویان، بررسی در جدول
         TEACHER_INFO_REMOTE بر اساس TEACHCODE (کد استاد) یا USERNAME.
      ۳. در صورت تطابق رمز عبور، یک دیکشنری ساخت‌یافته حاوی مشخصات کاربر
         جهت ثبت‌نام خودکار (JIT Provisioning) و صدور نشست به لایه بعد ارسال می‌شود.
    
    ورودی:
      - identifier: شماره دانشجویی، کد استاد یا نام کاربری واردشده در فرم لاگین
      - plain_password: کلمه عبور واردشده به صورت متنی
    
    خروجی:
      - دیکشنری حاوی مشخصات کاربر در صورت احراز هویت موفق، یا None در صورت نامعتبر بودن
    """
    settings = get_settings()
    if not settings.ORACLE_ENABLED:
        return None

    identifier = identifier.strip()
    if not identifier or not plain_password:
        return None

    schema = settings.ORACLE_SCHEMA

    try:
        conn = get_oracle_connection()
        if not conn:
            return None

        with conn:
            with conn.cursor() as cur:
                # -------------------------------------------------------------
                # لایه اول: جستجو و اعتبارسنجی در جدول دانشجویان (STU_INFO_REMOTE)
                # -------------------------------------------------------------
                query_stu = f"""
                    SELECT CODE, USERNAME, ACTIVE_IS, {schema}.GENERAL_MYDECRYPT(RAMZ) as DECRYPTED_RAMZ
                    FROM {schema}.STU_INFO_REMOTE
                    WHERE (LOWER(USERNAME) = LOWER(:u) OR CODE = :u)
                      AND RAMZ IS NOT NULL
                      AND ROWNUM = 1
                """
                cur.execute(query_stu, u=identifier)
                row = cur.fetchone()
                if row:
                    code, username, active_is, decrypted_ramz = row
                    # مقایسه ایمن رمز عبور پس از رمزگشایی ستون RAMZ
                    if decrypted_ramz and str(decrypted_ramz).strip() == plain_password.strip():
                        logger.info(f"احراز هویت اوراکل موفق: دانشجو '{identifier}' با شماره دانشجویی '{code}' تایید شد.")
                        return {
                            "user_type": "student",
                            "username": str(username or code),
                            "code": str(code),
                            "idnumber": str(code),
                            "firstname": "دانشجو",
                            "lastname": str(username or code),
                            "email": f"{username or code}@edu.local",
                            "active": active_is,
                        }

                # -------------------------------------------------------------
                # لایه دوم: جستجو و اعتبارسنجی در جدول اساتید (TEACHER_INFO_REMOTE)
                # -------------------------------------------------------------
                query_teach = f"""
                    SELECT TEACHCODE, USERNAME, ACTIVE_IS, {schema}.GENERAL_MYDECRYPT(RAMZ) as DECRYPTED_RAMZ
                    FROM {schema}.TEACHER_INFO_REMOTE
                    WHERE (LOWER(USERNAME) = LOWER(:u) OR TEACHCODE = :u)
                      AND RAMZ IS NOT NULL
                      AND ROWNUM = 1
                """
                cur.execute(query_teach, u=identifier)
                row = cur.fetchone()
                if row:
                    teachcode, username, active_is, decrypted_ramz = row
                    # مقایسه ایمن رمز عبور استاد پس از رمزگشایی
                    if decrypted_ramz and str(decrypted_ramz).strip() == plain_password.strip():
                        logger.info(f"احراز هویت اوراکل موفق: استاد '{identifier}' با کد استادی '{teachcode}' تایید شد.")
                        return {
                            "user_type": "teacher",
                            "username": str(username or teachcode),
                            "code": str(teachcode),
                            "idnumber": str(teachcode),
                            "firstname": "استاد",
                            "lastname": str(username or teachcode),
                            "email": f"{username or teachcode}@edu.local",
                            "active": active_is,
                        }

        logger.debug(f"کاربر '{identifier}' در جداول دانشجویان/اساتید اوراکل یافت نشد یا کلمه عبور نادرست بود.")
        return None

    except Exception as e:
        # ثبت هشدار بدون کرش کردن سامانه؛ به این ترتیب کارمندان و ادمین‌ها در لایه ۳ لاگین خواهند شد
        logger.warning(f"عدم برقراری ارتباط با پایگاه داده اوراکل (خطا نادیده گرفته شد و لایه ۳ اجرا می‌شود): {e}")
        return None
