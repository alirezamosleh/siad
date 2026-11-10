"""
================================================================================
مدل‌های داده و اسکیمای اعتبارسنجی ورودی/خروجی (Data Models & Schemas)
سامانه یادگیری الکترونیکی و احراز هویت هوشمند المصطفی
================================================================================

شرح:
این ماژول از کتابخانه Pydantic جهت اعتبارسنجی خودکار، بررسی صحت انواع داده
(Type Checking) و مستندسازی اسکیمای OpenAPI در درخواست‌ها و پاسخ‌های API استفاده می‌کند.
"""

from typing import Optional
from pydantic import BaseModel, Field


class LoginRequest(BaseModel):
    """
    اسکیمای درخواست ورود مستقیم با کلمه عبور (/api/login).
    """
    username: str = Field(
        ...,
        description="نام کاربری، شماره دانشجویی، کد استادی یا کد ملی کاربر"
    )
    password: str = Field(
        ...,
        description="کلمه عبور خام کاربر جهت اعتبارسنجی در اوراکل یا مودل"
    )
    wantsurl: Optional[str] = Field(
        None,
        description="آدرس صفحه درخواستی کاربر در مودل جهت بازگشت به آن پس از ورود موفق"
    )


class ForgotPasswordRequest(BaseModel):
    """
    اسکیمای درخواست بازیابی کلمه عبور (/api/forgot-password).
    """
    identifier: str = Field(
        ...,
        description="شناسه هویتی شامل نام کاربری، شماره دانشجویی، کد ملی یا آدرس ایمیل"
    )


class ResetPasswordRequest(BaseModel):
    """
    اسکیمای تغییر رمز عبور با توکن تاییدیه (/api/reset-password).
    """
    token: str = Field(
        ...,
        description="توکن امضاشده JWT ارسال‌شده به ایمیل کاربر"
    )
    new_password: str = Field(
        ...,
        min_length=6,
        description="کلمه عبور جدید (حداقل ۶ کاراکتر)"
    )


class SendOtpRequest(BaseModel):
    """
    اسکیمای درخواست ارسال کد یکبار مصرف پیامکی (/api/otp/send).
    """
    username: str = Field(
        ...,
        description="شناسه هویتی کاربر جهت استخراج شماره موبایل ثبت‌شده در سیستم"
    )


class SendOtpResponse(BaseModel):
    """
    اسکیمای پاسخ ارسال کد یکبار مصرف پیامکی.
    """
    success: bool = Field(..., description="وضعیت موفقیت‌آمیز بودن ارسال پیامک")
    message: str = Field(..., description="پیام توضیحی جهت نمایش به کاربر")
    masked_phone: Optional[str] = Field(None, description="شماره موبایل ماسک‌شده کاربر (مانند 0912***3326)")
    cooldown_seconds: int = Field(60, description="مدت زمان انتظار اجباری تا امکان ارسال مجدد (ثانیه)")
    debug_code: Optional[str] = Field(None, description="کد دیباگ تستی جهت سهولت آزمایش بدون پنل پیامک")


class VerifyOtpRequest(BaseModel):
    """
    اسکیمای اعتبارسنجی کد یکبار مصرف پیامکی و ورود به سامانه (/api/otp/verify).
    """
    username: str = Field(
        ...,
        description="نام کاربری، شماره دانشجویی یا کد استادی"
    )
    otp_code: str = Field(
        ...,
        min_length=4,
        max_length=10,
        description="کد یکبار مصرف دریافتی کاربر از طریق پیامک"
    )
    wantsurl: Optional[str] = Field(
        None,
        description="صفحه مقصد درخواستی در مودل"
    )
    client_id: Optional[str] = Field(
        None,
        description="شناسه کلاینت OAuth در صورت فعال بودن حالت OAuth 2.0"
    )
    redirect_uri: Optional[str] = Field(
        None,
        description="آدرس کال‌بک OAuth جهت بازگشت به مودل"
    )
    state: Optional[str] = Field(
        None,
        description="پارامتر حفظ وضعیت و امنیت سشن مودل در پروتکل OAuth"
    )
    scope: Optional[str] = Field(
        None,
        description="دامنه‌های دسترسی درخواستی"
    )


class AuthResponse(BaseModel):
    """
    اسکیمای یکپارچه پاسخ احراز هویت در تمامی متدهای ورود.
    """
    success: bool = Field(..., description="نتیجه احراز هویت (true / false)")
    message: Optional[str] = Field(None, description="پیام سیستمی یا خطای مربوطه")
    redirect_url: Optional[str] = Field(None, description="آدرس نهایی انتقال کاربر (به همراه بلیت SSO یا کال‌بک)")
