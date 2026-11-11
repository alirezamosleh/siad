"""
================================================================================
تنظیمات سراسری سامانه احراز هویت (Application Configuration & Settings)
سامانه یادگیری الکترونیکی و احراز هویت هوشمند المصطفی
================================================================================
"""

from functools import lru_cache
from typing import Optional
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """
    کلاس مدیریت متمرکز متغیرهای تنظیماتی و محیطی برنامه (Pydantic Settings).
    تمام مقادیر به صورت خودکار از فایل .env یا متغیرهای محیطی سیستم‌عامل بارگذاری می‌شوند.
    """
    
    # -------------------------------------------------------------------------
    # ۱. تنظیمات عمومی و هسته وب‌سرور (Core Web Server Settings)
    # -------------------------------------------------------------------------
    APP_NAME: str = "سامانه ورود هوشمند المصطفی"
    DEBUG: bool = False
    PORT: int = 8000
    HOST: str = "0.0.0.0"

    # -------------------------------------------------------------------------
    # ۲. آدرس‌های سرویس‌ها و کلید رمزنگاری ورود یکپارچه (SSO & Service URLs)
    # -------------------------------------------------------------------------
    MOODLE_URL: str = "https://siad.hu.jz.ac.ir"            # آدرس عمومی پنل مودل
    MOODLE_INTERNAL_URL: str = "http://moodle:8080"        # آدرس شبکه داخلی داکر مودل
    AUTH_SERVICE_URL: str = "https://auth.hu.jz.ac.ir" # آدرس سرویس احراز هویت ما
    SSO_SECRET: str = "siad-hu-jz-ac-ir-super-secret-sso-key-2026-xyz"  # کلید مشترک امضای بلیت‌های JWT
    SSO_TOKEN_TTL_SECONDS: int = 120                       # مدت اعتبار بلیت ورود (ثانیه)

    # -------------------------------------------------------------------------
    # ۳. تنظیمات پایگاه داده مودل (MySQL / MariaDB Connection)
    # -------------------------------------------------------------------------
    DB_HOST: str = "mariadb"                               # هاست کانتینر پایگاه داده
    DB_PORT: int = 3306                                    # پورت اتصال دیتابیس
    DB_NAME: str = "siad"                                  # نام پایگاه داده مودل
    DB_USER: str = "alirezabb"                             # نام کاربری دیتابیس
    DB_PASSWORD: str = "SEDO3482389D4"                     # کلمه عبور دیتابیس
    DB_POOL_MIN_SIZE: int = 5                              # حداقل کانکشن‌های استخر اتصالات
    DB_POOL_MAX_SIZE: int = 50                             # حداکثر کانکشن‌های استخر اتصالات
    DB_PREFIX: str = "mdl_"                                # پیشوند استاندارد جداول مودل

    # -------------------------------------------------------------------------
    # ۴. ورود از طریق پنجره واحد خدمات دولت من (USW OAuth2)
    # -------------------------------------------------------------------------
    USW_ENABLED: bool = True                               # فعال/غیرفعال بودن دکمه دولت من
    USW_CLIENT_ID: str = ""                                # شناسه کلاینت سامانه دولت من
    USW_CLIENT_SECRET: str = ""                            # کلید امنیتی سامانه دولت من
    USW_AUTHORIZE_URL: str = "https://usw.msrt.ir/oauth2/authorize"
    USW_TOKEN_URL: str = "https://usw.msrt.ir/oauth2/token"
    USW_USERINFO_URL: str = "https://usw.msrt.ir/api/v1/User/userinfo"
    USW_REDIRECT_URI: str = "http://localhost:8016/auth/usw/callback"
    USW_AUTO_CREATE_USER: bool = True                      # ثبت‌نام خودکار با مشخصات دولت من

    # -------------------------------------------------------------------------
    # ۵. فیلدهای هویتی کاربر (User Profile Fields)
    # -------------------------------------------------------------------------
    NATIONAL_ID_FIELD: str = "nationalid"                  # فیلد اختصاصی کدملی در مودل

    # -------------------------------------------------------------------------
    # ۶. تنظیمات پایگاه داده دانشگاهی اوراکل (Oracle Database ERP Settings)
    # -------------------------------------------------------------------------
    ORACLE_ENABLED: bool = True                            # فعال/غیرفعال بودن احراز هویت اوراکل
    ORACLE_HOST: str = "172.25.0.1"                        # آی‌پی هاست یا سرور اوراکل
    ORACLE_PORT: int = 1521                                # پورت شنود سرویس اوراکل
    ORACLE_SERVICE_NAME: str = "orc1"                      # نام سرویس دیتابیس (Service Name)
    ORACLE_USER: str = "ALIREZA_HODA"                      # نام کاربری دیتابیس اوراکل
    ORACLE_PASSWORD: str = "s.harasani1377"                # رمز عبور دیتابیس اوراکل
    ORACLE_SCHEMA: str = "SHARIFI"                         # نام اسکیما جداول دانشجویان/اساتید

    # -------------------------------------------------------------------------
    # ۷. تنظیمات ارسال ایمیل بازیابی رمز عبور (SMTP Settings)
    # -------------------------------------------------------------------------
    SMTP_ENABLED: bool = False
    SMTP_HOST: str = "smtp.gmail.com"
    SMTP_PORT: int = 587
    SMTP_USER: str = ""
    SMTP_PASSWORD: str = ""
    SMTP_FROM_EMAIL: str = ""
    SMTP_USE_TLS: bool = True
    PASSWORD_RESET_TOKEN_TTL_MINUTES: int = 30

    # -------------------------------------------------------------------------
    # ۸. تنظیمات وب‌سرویس پیامک و ورود یکبار مصرف (SMS OTP Settings)
    # -------------------------------------------------------------------------
    SMS_PROVIDER: str = "ghasedak"                         # ارائه‌دهنده پیامک: ghasedak یا kavenegar یا mock
    GHASEDAK_API_KEY: str = ""
    GHASEDAK_SENDER: str = ""
    KAVENEGAR_API_KEY: str = ""
    KAVENEGAR_SENDER: str = ""
    SMS_DEBUG_MODE: bool = True                            # در حالت تستی، کد تایید را در پاسخ بازمی‌گرداند
    OTP_TTL_SECONDS: int = 120                             # طول عمر کد پیامکی (۲ دقیقه)
    OTP_COOLDOWN_SECONDS: int = 60                         # فاصله مجاز بین دو درخواست ارسال پیامک (ثانیه)

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore"
    )


@lru_cache()
def get_settings() -> Settings:
    """
    نمونه پایدار تنظیمات با کش شدن در حافظه (Singleton Pattern).
    """
    return Settings()
