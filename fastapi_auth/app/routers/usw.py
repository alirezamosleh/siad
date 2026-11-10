"""
================================================================================
روتر اتصال به پنجره واحد خدمات دولت من (USW - Unified State Window OAuth2)
سامانه یادگیری الکترونیکی و احراز هویت هوشمند المصطفی
================================================================================

توضیحات فرآیند:
این ماژول اتصال به پنجره خدمات هوشمند دانشگاهی وزارت عتف و دولت من را پیاده‌سازی می‌کند:
  ۱. ارسال کاربر به صفحه ورود دولت من (/auth/usw/login) با شناسه کلاینت و State امن.
  ۲. دریافت کد تایید در اندپوینت بازگشتی (/auth/usw/callback).
  ۳. تبادل غیرهمزمان (Async) کد با Access Token از سرورهای دولت من.
  ۴. استعلام اطلاعات هویتی و کد ملی کاربر از وب‌سرویس UserInfo.
  ۵. ثبت‌نام خودکار یا تطبیق کاربر در دیتابیس مودل بر اساس کد ملی.
  ۶. صدور بلیت دیجیتال SSO و ورود مستقیم و فوری کاربر به مودل.
"""

import base64
import json
import logging
import secrets
from typing import Optional
from fastapi import APIRouter, HTTPException, Query, status
from fastapi.responses import RedirectResponse
from app.config import get_settings
from app.security import create_sso_ticket
from app.services.auth_service import get_or_create_usw_user
from app.services.usw_service import (
    get_usw_authorize_url,
    exchange_code_for_token,
    fetch_userinfo,
)

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/auth/usw", tags=["USW OAuth2"])


def encode_state(wantsurl: Optional[str] = None) -> str:
    """
    انکود کردن کد تصادفی Nonce و آدرس مقصد کاربر در پارامتر امنیتی State.
    این رشته جهت جلوگیری از حملات CSRF به سرور دولت من ارسال می‌شود.
    """
    state_data = {
        "nonce": secrets.token_hex(8),
        "wantsurl": wantsurl or "",
    }
    raw_json = json.dumps(state_data).encode("utf-8")
    return base64.urlsafe_b64encode(raw_json).decode("utf-8").rstrip("=")


def decode_state(state: str) -> Optional[dict]:
    """رمزگشایی پارامتر State جهت بازیابی آدرس مقصد اولیه کاربر (wantsurl)."""
    try:
        padding = "=" * (4 - (len(state) % 4))
        raw_json = base64.urlsafe_b64decode(state + padding)
        return json.loads(raw_json.decode("utf-8"))
    except Exception:
        return None


@router.get("/login")
async def usw_login(wantsurl: Optional[str] = Query(None)):
    """
    هدایت کاربر به صفحه لاگین پنجره واحد خدمات دولت من (USW).
    """
    settings = get_settings()
    if not settings.USW_ENABLED:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="ورود از طریق دولت من (USW) در حال حاضر در سامانه فعال نیست."
        )

    state = encode_state(wantsurl)
    auth_url = get_usw_authorize_url(state)
    return RedirectResponse(url=auth_url, status_code=status.HTTP_302_FOUND)


@router.get("/callback")
async def usw_callback(
    code: Optional[str] = Query(None),
    state: Optional[str] = Query(None),
    error: Optional[str] = Query(None),
):
    """
    اندپوینت دریافت بازگشت از دولت من (USW Callback Endpoint).
    
    مراحل:
      ۱. بررسی عدم لغو توسط کاربر
      ۲. تبادل کد با توکن دسترسی (Access Token)
      ۳. واکشی پروفایل و کد ملی کاربر
      ۴. تطبیق با کاربران مودل یا ثبت‌نام کاربر جدید
      ۵. صدور بلیت SSO و انتقال مستقیم به پنل مودل
    """
    settings = get_settings()

    if error:
        logger.error(f"دریافت خطا از پنجره دولت من: {error}")
        return RedirectResponse(url="/?error=usw_denied")

    if not code:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="پارامتر کد احراز هویت معتبر نیست.")

    wantsurl = None
    if state:
        state_data = decode_state(state)
        if state_data:
            wantsurl = state_data.get("wantsurl")

    # ۱. مبادله کد احراز هویت با Access Token از وب‌سرویس دولت من
    access_token = await exchange_code_for_token(code)
    if not access_token:
        logger.error("خطا در دریافت توکن دسترسی از سرور دولت من")
        return RedirectResponse(url="/?error=usw_token_failed")

    # ۲. استعلام مشخصات فردی کاربر از سرور دولت من
    userinfo = await fetch_userinfo(access_token)
    if not userinfo:
        logger.error("خطا در واکشی مشخصات کاربری از سرور دولت من")
        return RedirectResponse(url="/?error=usw_userinfo_failed")

    # ۳. تطبیق با حساب موجود در مودل یا ایجاد خودکار حساب کاربری
    user = await get_or_create_usw_user(userinfo)
    if not user:
        logger.error(f"هیچ حساب کاربری منطبقی برای مشخصات دولت من یافت نشد: {userinfo}")
        return RedirectResponse(url="/?error=usw_user_not_found")

    # ۴. تولید بلیت امضاشده SSO و ریدایرکت مستقیم به اندپوینت ورود مودل
    target_redirect = wantsurl or f"{settings.MOODLE_URL}/my/"
    ticket = create_sso_ticket(user["id"], user["username"], wantsurl=target_redirect)
    sso_url = f"{settings.MOODLE_URL}/auth/sadaa/sso.php?ticket={ticket}"
    return RedirectResponse(url=sso_url, status_code=status.HTTP_302_FOUND)
