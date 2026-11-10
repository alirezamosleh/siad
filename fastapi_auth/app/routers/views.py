"""
================================================================================
روتر صفحات و رابط کاربری (Views & HTML Rendering Router)
سامانه یادگیری الکترونیکی و احراز هویت هوشمند المصطفی
================================================================================

توضیحات:
این روتر وظیفه رندر کردن صفحات HTML (قالب Jinja2) را بر عهده دارد:
  - صفحه اصلی / و صفحه ورود مستقیم login/
  - نمایش پیام‌های خطای سیستم و خطاهای بازگشتی از پنجره دولت من (USW)
  - حفظ پارامتر wantsurl جهت بازگشت به صفحه درخواستی دانشجو پس از لاگین
"""

from pathlib import Path
from typing import Optional
from fastapi import APIRouter, Request, Query
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from app.config import get_settings

router = APIRouter(include_in_schema=False)
BASE_DIR = Path(__file__).resolve().parent.parent
templates = Jinja2Templates(directory=str(BASE_DIR / "templates"))


@router.get("/", response_class=HTMLResponse)
@router.get("/login", response_class=HTMLResponse)
async def login_page(
    request: Request,
    wantsurl: Optional[str] = Query(None, description="آدرس صفحه‌ای که کاربر قبل از لاگین قصد مشاهده آن را داشته است"),
    error: Optional[str] = Query(None, description="کد خطای احتمالی جهت نمایش به کاربر"),
):
    """
    نمایش صفحه رابط کاربری ورود مستقیم سامانه.
    
    این صفحه شامل:
      - دکمه ورود از طریق پنجره واحد خدمات هوشمند دولت من (USW)
      - تب ورود با نام کاربری و رمز عبور (دانشجویان، اساتید و کارمندان)
      - تب ورود با کد تایید یکبار مصرف پیامکی (OTP)
    """
    settings = get_settings()
    error_message = None
    if error == "usw_denied":
        error_message = "ورود از طریق دولت من توسط کاربر لغو شد."
    elif error == "usw_token_failed":
        error_message = "ارتباط با سرور دولت من (USW) برقرار نشد. لطفاً مجدداً تلاش کنید."
    elif error == "usw_userinfo_failed":
        error_message = "خطا در دریافت مشخصات کاربری از پنجره واحد دولت."
    elif error == "usw_user_not_found":
        error_message = "حساب کاربری منطبق با کد ملی شما در سامانه یافت نشد."

    return templates.TemplateResponse(
        request=request,
        name="login.html",
        context={
            "app_name": settings.APP_NAME,
            "wantsurl": wantsurl or "",
            "usw_enabled": settings.USW_ENABLED,
            "error_message": error_message,
        }
    )


@router.get("/forgot-password")
async def forgot_password_page():
    """هدایت صفحه فراموشی رمز به صفحه اصلی لاگین."""
    return RedirectResponse(url="/login", status_code=302)


@router.get("/reset-password")
async def reset_password_page():
    """هدایت صفحه تغییر رمز به صفحه اصلی لاگین."""
    return RedirectResponse(url="/login", status_code=302)
