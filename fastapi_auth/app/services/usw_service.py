"""
ماژول یکپارچه‌سازی با درگاه پنجره ملی خدمات دولت هوشمند (USW / MSRT SSO Service).

این ماژول ارتباط پروتکل استاندارد OAuth 2.0 را با درگاه پنجره واحد دولت برقرار می‌سازد:
  ۱. تولید نشانی هدایت به پنجره واحد دولت من (Authorize URL).
  ۲. تبادل ناهمگام کد مجوز (Authorization Code) با توکن دسترسی (Access Token).
  ۳. استعلام پروفایل هویتی کاربر (UserInfo شامل کد ملی، نام، نام خانوادگی، شماره موبایل و نقش دانشگاهی).
"""

import logging
import urllib.parse
from typing import Any, Dict, Optional
import httpx
from app.config import get_settings

logger = logging.getLogger(__name__)


def get_usw_authorize_url(state: str) -> str:
    """
    تولید آدرس انتقال به پنجره واحد خدمات دولت هوشمند جهت احراز هویت متقاضی.
    
    پارامترها:
      state: مقدار رندوم جهت جلوگیری از حملات جعل درخواست میان‌سایتی (CSRF).

    خروجی:
      نشانی کامل ریدایرکت با پارامترهای response_type, client_id, redirect_uri, state.
    """
    settings = get_settings()
    params = {
        "response_type": "code",
        "client_id": settings.USW_CLIENT_ID,
        "redirect_uri": settings.USW_REDIRECT_URI,
        "state": state,
    }
    return f"{settings.USW_AUTHORIZE_URL}?{urllib.parse.urlencode(params)}"


async def exchange_code_for_token(code: str) -> Optional[str]:
    """
    تبادل ناهمگام (Async HTTP) کد مجوز بازگشتی از دولت با توکن دسترسی (Access Token).
    
    پارامترها:
      code: کد اعطا شده توسط پنجره واحد دولت (Authorization Code).

    خروجی:
      رشته توکن دسترسی (access_token) در صورت موفقیت، یا None در صورت بروز خطا.
    """
    settings = get_settings()
    payload = {
        "grant_type": "authorization_code",
        "code": code,
        "client_id": settings.USW_CLIENT_ID,
        "client_secret": settings.USW_CLIENT_SECRET,
        "redirect_uri": settings.USW_REDIRECT_URI,
    }

    try:
        async with httpx.AsyncClient(timeout=10.0, verify=False) as client:
            resp = await client.post(
                settings.USW_TOKEN_URL,
                data=payload,
                headers={"Accept": "application/json"}
            )
            if resp.status_code != 200:
                logger.error(f"خطای تبادل توکن با درگاه دولت من ({resp.status_code}): {resp.text}")
                return None
            data = resp.json()
            return data.get("access_token")
    except Exception as e:
        logger.error(f"خطای سیستمی در برقراری ارتباط با سرویس توکن پنجره واحد دولت: {e}")
        return None


async def fetch_userinfo(access_token: str) -> Optional[Dict[str, Any]]:
    """
    استعلام ناهمگام مشخصات هویتی و پروفایل کاربر از پنجره واحد دولت بر اساس توکن دسترسی.
    
    اطلاعات دریافتی معمولاً شامل:
      - national_code (کد ملی)
      - first_name (نام)
      - last_name (نام خانوادگی)
      - mobile (شماره تلفن همراه احراز شده)

    پارامترها:
      access_token: توکن معتبر دریافتی از مرحله exchange_code_for_token.

    خروجی:
      دیکشنری مشخصات کاربر یا None در صورت خطا.
    """
    settings = get_settings()
    headers = {
        "Authorization": f"Bearer {access_token}",
        "Accept": "application/json",
    }

    try:
        async with httpx.AsyncClient(timeout=10.0, verify=False) as client:
            resp = await client.get(settings.USW_USERINFO_URL, headers=headers)
            if resp.status_code != 200:
                logger.error(f"خطای دریافت اطلاعات کاربر از درگاه دولت من ({resp.status_code}): {resp.text}")
                return None
            return resp.json()
    except Exception as e:
        logger.error(f"خطای سیستمی هنگام دریافت اطلاعات کاربر از درگاه دولت من: {e}")
        return None
