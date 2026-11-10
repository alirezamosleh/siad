"""
================================================================================
ارائه‌دهنده استاندارد احراز هویت باز و اوپن‌آی‌دی (OAuth 2.0 / OpenID Connect Provider)
سامانه یادگیری الکترونیکی و احراز هویت هوشمند المصطفی
================================================================================

توضیحات پروتکل:
این روتر وظیفه پیاده‌سازی پروتکل استاندارد RFC 6749 و OpenID Connect Core 1.0 را بر عهده دارد
تا مودل بتواند بدون نیاز به هیچ‌گونه پلاگین اختصاصی PHP، مستقیماً از طریق افزونه پیش‌فرض و رسمی
هسته خود (auth_oauth2) با سامانه لاگین ما ارتباط برقرار کند.

چرخه ارتباطی (OAuth 2.0 Authorization Code Flow):
  ۱. کاربر در مودل روی دکمه ورود کلیک می‌کند یا خودکار ریدایرکت می‌شود.
  ۲. مودل کاربر را به اندپوینت `/oauth/authorize` هدایت می‌نماید.
  ۳. کاربر اطلاعات خود را وارد کرده و فرآیند احراز هویت سه‌لایه (اوراکل + مودل) انجام می‌شود.
  ۴. یک کد کوتاه‌مدت یک‌بارمصرف (auth_code) صادر شده و کاربر با پارامترهای state و code به
     کال‌بک مودل (`admin/oauth2callback.php`) بازگردانده می‌شود.
  ۵. سرور مودل به صورت پشت‌صحنه (Server-to-Server) به اندپوینت `/oauth/token` وصل شده و کد را با
     یک توکن امنیتی (access_token) مبادله می‌کند.
  ۶. سرور مودل توکن را به اندپوینت `/oauth/userinfo` ارسال کرده، مشخصات کاربر را می‌گیرد و سشن
     رسمی مودل را برای او برقرار می‌سازد.
"""

import logging
import secrets
import time
from typing import Dict, Any, Optional
from urllib.parse import quote
from fastapi import APIRouter, Request, Form, Query, HTTPException, status
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from pathlib import Path
import jwt

from app.config import get_settings
from app.services.auth_service import authenticate_user

logger = logging.getLogger(__name__)

router = APIRouter(tags=["OAuth 2.0 / OpenID Connect Provider"])

BASE_DIR = Path(__file__).resolve().parent.parent
templates = Jinja2Templates(directory=str(BASE_DIR / "templates"))

# حافظه نگهداری موقت کدهای تایید یکبار مصرف و توکن‌های دسترسی
# (در محیط‌های خوشه‌ای/چند سروری می‌توان این مقادیر را به Redis منتقل کرد)
AUTH_CODES: Dict[str, Dict[str, Any]] = {}
ACCESS_TOKENS: Dict[str, Dict[str, Any]] = {}


@router.get("/.well-known/openid-configuration")
async def openid_configuration():
    """
    سند کشف و پیکربندی خودکار اوپن‌آی‌دی (OpenID Connect Discovery Document).
    این اندپوینت به مودل و سایر کلاینت‌ها اجازه می‌دهد آدرس تمامی سرویس‌ها،
    الگوریتم‌های رمزنگاری و فیلدهای پشتیبانی‌شده را خودکار شناسایی کنند.
    """
    settings = get_settings()
    base_url = settings.AUTH_SERVICE_URL.rstrip("/")
    return {
        "issuer": base_url,
        "authorization_endpoint": f"{base_url}/oauth/authorize",
        "token_endpoint": f"{base_url}/oauth/token",
        "userinfo_endpoint": f"{base_url}/oauth/userinfo",
        "jwks_uri": f"{base_url}/oauth/jwks",
        "response_types_supported": ["code"],
        "subject_types_supported": ["public"],
        "id_token_signing_alg_values_supported": ["HS256"],
        "scopes_supported": ["openid", "profile", "email"],
        "token_endpoint_auth_methods_supported": ["client_secret_post", "client_secret_basic"],
        "claims_supported": [
            "sub", "iss", "name", "given_name", "family_name",
            "preferred_username", "email", "picture"
        ]
    }


@router.get("/oauth/authorize", response_class=HTMLResponse)
async def oauth_authorize(
    request: Request,
    response_type: str = Query(..., description="حتماً باید برابر 'code' باشد"),
    client_id: str = Query(..., description="شناسه کلاینت ثبت‌شده در مودل"),
    redirect_uri: str = Query(..., description="آدرس کال‌بک بازگشت به مودل"),
    state: Optional[str] = Query(None, description="پارامتر حفظ وضعیت و امنیت نشست مودل"),
    scope: str = Query("openid profile email", description="دامنه‌های دسترسی درخواستی"),
    error: Optional[str] = Query(None),
):
    """
    اندپوینت نمایش صفحه لاگین استاندارد OAuth 2.0 (مرورگر کاربر).
    این متد قالب HTML ورود را بارگذاری کرده و متغیرهای لازم را به فرم تزریق می‌کند.
    """
    settings = get_settings()

    if response_type != "code":
        raise HTTPException(status_code=400, detail="تنها نوع پاسخ response_type='code' پشتیبانی می‌شود.")

    error_message = None
    if error == "invalid_credentials":
        error_message = "نام کاربری یا رمز عبور اشتباه است."

    return templates.TemplateResponse(
        request=request,
        name="login.html",
        context={
            "app_name": settings.APP_NAME,
            "wantsurl": "",
            "usw_enabled": settings.USW_ENABLED,
            "error_message": error_message,
            "oauth_mode": True,
            "client_id": client_id,
            "redirect_uri": redirect_uri,
            "state": state or "",
            "scope": scope,
        }
    )


@router.post("/oauth/authorize")
async def oauth_authorize_post(
    request: Request,
    username: str = Form(...),
    password: str = Form(...),
    client_id: str = Form(...),
    redirect_uri: str = Form(...),
    state: Optional[str] = Form(None),
    scope: str = Form("openid profile email"),
):
    """
    پردازش فرم ورود در حالت پروتکل OAuth 2.0.
    
    مراحل اجرا:
      ۱. بررسی نام کاربری و رمز در خط لوله سه‌لایه (دانشجویان و اساتید در اوراکل -> کارمندان و مدیران در مودل).
      ۲. در صورت عدم تطابق، نمایش پیام خطا در همان فرم.
      ۳. در صورت تایید هویت، صدور کد احراز هویت کوتاه‌مدت (با اعتبار ۶۰ ثانیه).
      ۴. ریدایرکت مرورگر به کال‌بک مودل همراه با کد صادره و پارامتر وضعیت رمزگذاری‌شده.
    """
    settings = get_settings()

    # ۱. احراز هویت آبشاری در لایه‌های اوراکل و دیتابیس مودل
    user = await authenticate_user(username, password)
    if not user:
        logger.warning(f"ورود OAuth ناموفق بود: اعتبارسنجی کاربر '{username}' در تمام لایه‌ها رد شد.")
        return templates.TemplateResponse(
            request=request,
            name="login.html",
            context={
                "app_name": settings.APP_NAME,
                "error_message": "نام کاربری یا رمز عبور اشتباه است.",
                "usw_enabled": settings.USW_ENABLED,
                "oauth_mode": True,
                "client_id": client_id,
                "redirect_uri": redirect_uri,
                "state": state or "",
                "scope": scope,
            }
        )

    # ۲. تولید کد تایید یکبار مصرف و بسیار کوتاه‌مدت (۶۰ ثانیه اعتبار)
    auth_code = secrets.token_urlsafe(32)
    AUTH_CODES[auth_code] = {
        "user_id": user["id"],
        "username": user["username"],
        "email": user.get("email") or f"{user['username']}@local",
        "firstname": user.get("firstname") or "User",
        "lastname": user.get("lastname") or user["username"],
        "client_id": client_id,
        "redirect_uri": redirect_uri,
        "expires_at": time.time() + 60,
    }

    # ۳. بازگرداندن کاربر به کال‌بک مودل با انکود صحیح پارامتر state
    # نکته حیاتی: مقدار state حاوی کوئری‌های داخلی مودل (مانند sesskey) است؛
    # لذا باید با quote به طور کامل URL Encode شود تا نشست در مودل باطل نگردد.
    sep = "&" if "?" in redirect_uri else "?"
    callback_url = f"{redirect_uri}{sep}code={auth_code}"
    if state:
        callback_url += f"&state={quote(state, safe='')}"

    logger.info(f"کاربر '{username}' با موفقیت تایید شد. در حال ریدایرکت به مودل: {callback_url}")
    return RedirectResponse(url=callback_url, status_code=status.HTTP_302_FOUND)


@router.post("/oauth/token")
async def oauth_token(
    grant_type: str = Form(...),
    code: str = Form(...),
    redirect_uri: Optional[str] = Form(None),
    client_id: Optional[str] = Form(None),
    client_secret: Optional[str] = Form(None),
):
    """
    اندپوینت مبادله توکن (Token Endpoint).
    این فراخوانی به صورت پشت‌صحنه (سرور به سرور) توسط مودل انجام می‌شود:
      - مودل کد یکبارمصرف مرحله قبل را تحویل می‌دهد.
      - سرویس ما کد را ابطال کرده و در ازای آن یک access_token معتبر (یک ساعته)
        و یک شناسه استاندارد OpenID (ID Token با امضای HS256) صادر می‌کند.
    """
    settings = get_settings()

    if grant_type != "authorization_code":
        raise HTTPException(
            status_code=400,
            detail={"error": "unsupported_grant_type", "error_description": "تنها grant_type='authorization_code' پشتیبانی می‌شود."}
        )

    # استخراج و ابطال بلافاصله کد (جهت جلوگیری از Replay Attack)
    code_data = AUTH_CODES.pop(code, None)
    if not code_data:
        raise HTTPException(
            status_code=400,
            detail={"error": "invalid_grant", "error_description": "کد احراز هویت نامعتبر است یا قبلاً استفاده شده است."}
        )

    if code_data["expires_at"] < time.time():
        raise HTTPException(
            status_code=400,
            detail={"error": "invalid_grant", "error_description": "کد احراز هویت منقضی گردیده است."}
        )

    # صدور Access Token و ذخیره موقت مشخصات کاربر
    access_token = secrets.token_urlsafe(32)
    now = int(time.time())
    expires_in = 3600

    ACCESS_TOKENS[access_token] = {
        "user_id": code_data["user_id"],
        "username": code_data["username"],
        "email": code_data["email"],
        "firstname": code_data["firstname"],
        "lastname": code_data["lastname"],
        "expires_at": now + expires_in,
    }

    # صدور شناسه دیجیتال JWT منطبق با استاندارد OpenID Connect
    id_token_payload = {
        "iss": settings.AUTH_SERVICE_URL.rstrip("/"),
        "sub": code_data["username"],
        "aud": code_data["client_id"],
        "iat": now,
        "exp": now + expires_in,
        "preferred_username": code_data["username"],
        "email": code_data["email"],
        "name": f"{code_data['firstname']} {code_data['lastname']}".strip(),
        "given_name": code_data["firstname"],
        "family_name": code_data["lastname"],
    }
    id_token = jwt.encode(id_token_payload, settings.SSO_SECRET, algorithm="HS256")

    return {
        "access_token": access_token,
        "token_type": "Bearer",
        "expires_in": expires_in,
        "id_token": id_token,
    }


@router.get("/oauth/userinfo")
async def oauth_userinfo(request: Request):
    """
    اندپوینت استعلام مشخصات کاربر (UserInfo Endpoint).
    این فراخوانی به صورت سرور به سرور توسط مودل انجام می‌شود:
      - مودل توکن Bearer را در هدر Authorization ارسال می‌کند.
      - سرویس مشخصات هویتی کاربر را بازمی‌گرداند.
      - مودل این فیلدها را بر اساس نگاشت تنظیم‌شده (sub, preferred_username, email, given_name, family_name)
        با جدول کاربران خود تطبیق داده و سشن کاربر را ایجاد می‌کند.
    """
    auth_header = request.headers.get("Authorization") or ""
    if not auth_header.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="توکن دسترسی Bearer ارسال نشده یا نامعتبر است.")

    token = auth_header[7:].strip()
    token_data = ACCESS_TOKENS.get(token)
    if not token_data or token_data["expires_at"] < time.time():
        raise HTTPException(status_code=401, detail="توکن دسترسی منقضی شده یا در سیستم موجود نیست.")

    return {
        "sub": token_data["username"],
        "username": token_data["username"],
        "preferred_username": token_data["username"],
        "email": token_data["email"],
        "name": f"{token_data['firstname']} {token_data['lastname']}".strip(),
        "given_name": token_data["firstname"],
        "family_name": token_data["lastname"],
    }
