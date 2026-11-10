import logging
import time
from typing import Optional, Dict, Any, List
from fastapi import APIRouter, BackgroundTasks, HTTPException, status, Response
from app.config import get_settings
from app.models import (
    LoginRequest,
    ForgotPasswordRequest,
    ResetPasswordRequest,
    AuthResponse,
    SendOtpRequest,
    SendOtpResponse,
    VerifyOtpRequest,
)
from app.database import fetch_one, fetch_all
from app.security import (
    create_sso_ticket,
    create_password_reset_token,
    verify_password_reset_token,
    verify_moodle_password,
)
from app.services.auth_service import (
    authenticate_user,
    find_user_by_identifier,
    update_user_password,
    get_user_phone,
    bridge_moodle_session,
)
from app.services.sms_service import send_otp_sms, mask_phone_number
from app.services.otp_service import create_and_store_otp, verify_otp_code
from app.services.email_service import send_password_reset_email

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/api", tags=["Authentication API"])


# In-memory audit of recent login attempts for debugging
RECENT_LOGIN_ATTEMPTS: List[Dict[str, Any]] = []


@router.get("/debug-recent-logins")
async def debug_recent_logins():
    return {"recent_attempts": RECENT_LOGIN_ATTEMPTS[-20:]}


@router.get("/debug-test-full-login-flow")
async def debug_test_full_login_flow():
    import httpx
    from app.services.auth_service import find_user_by_identifier, bridge_moodle_session

    user = await find_user_by_identifier("admin")
    if not user:
        return {"error": "admin user not found in DB"}

    cookie = await bridge_moodle_session(user, "Admin@Moodle123!")

    # Test accessing /my/ with this cookie
    moodle_resp_status = None
    moodle_resp_location = None
    moodle_has_admin_text = False
    moodle_body_preview = ""

    parsed_moodle = urllib.parse.urlparse(settings.MOODLE_URL)
    moodle_host = parsed_moodle.netloc or "siad.hu.jz.ac.ir"

    if cookie:
        async with httpx.AsyncClient(timeout=10.0, follow_redirects=False) as client:
            resp = await client.get(
                "http://moodle:8080/my/",
                cookies={"MoodleSession": cookie},
                headers={"Host": moodle_host, "User-Agent": "Mozilla/5.0"}
            )
            moodle_resp_status = resp.status_code
            moodle_resp_location = resp.headers.get("location")
            moodle_has_admin_text = "Admin User" in resp.text or "داشبورد" in resp.text
            moodle_body_preview = resp.text[:1500]

    return {
        "step1_user_found": bool(user),
        "step2_cookie_obtained": cookie,
        "step3_moodle_status": moodle_resp_status,
        "step3_moodle_location": moodle_resp_location,
        "step3_is_logged_in": moodle_has_admin_text,
        "step3_preview": moodle_body_preview,
    }


@router.get("/debug-redis")
async def debug_redis():
    import socket
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(2.0)
        s.connect(("redis", 6379))
        s.sendall(b"PING\r\n")
        data = s.recv(1024)
        s.sendall(b"KEYS *\r\n")
        keys_data = s.recv(4096)
        s.close()
        return {"ping": data.decode(errors="ignore"), "keys": keys_data.decode(errors="ignore")}
    except Exception as e:
        return {"error": str(e)}


@router.get("/debug-moodle-session-config")
async def debug_moodle_session_config():
    settings = get_settings()
    prefix = settings.DB_PREFIX
    try:
        cfg = await fetch_all(f"SELECT name, value FROM {prefix}config WHERE name LIKE %s", ("%session%",))
        active_sessions = await fetch_all(f"SELECT id, userid, timemodified FROM {prefix}sessions ORDER BY timemodified DESC LIMIT 5")
        return {"session_config": cfg, "active_sessions": active_sessions}
    except Exception as e:
        return {"error": str(e)}


@router.get("/debug-test-auth-manual")
async def debug_test_auth_manual():
    settings = get_settings()
    prefix = settings.DB_PREFIX
    from app.database import execute
    await execute(f"UPDATE {prefix}config SET value = 'manual' WHERE name = 'auth'", ())
    parsed_moodle = urllib.parse.urlparse(settings.MOODLE_URL)
    moodle_host = parsed_moodle.netloc or "siad.hu.jz.ac.ir"
    import httpx
    async with httpx.AsyncClient(timeout=8.0, follow_redirects=False) as client:
        resp = await client.get("http://moodle:8080/login/index.php", headers={"Host": moodle_host})
        return {
            "status": resp.status_code,
            "location": resp.headers.get("location"),
            "has_logintoken": 'name="logintoken"' in resp.text,
            "body": resp.text[:500]
        }


@router.get("/debug-test-moodle-login-variants")
async def debug_test_moodle_login_variants():
    import httpx
    settings = get_settings()
    parsed_moodle = urllib.parse.urlparse(settings.MOODLE_URL)
    moodle_host = parsed_moodle.netloc or "siad.hu.jz.ac.ir"
    variants = [
        "http://moodle:8080/login/index.php",
        "http://moodle:8080/login/index.php?saml=off",
        "http://moodle:8080/login/index.php?auth=manual",
        "http://moodle:8080/login/index.php?nomoodlecookie=1",
    ]
    results = {}
    async with httpx.AsyncClient(timeout=8.0, follow_redirects=False) as client:
        for url in variants:
            resp = await client.get(url, headers={"Host": moodle_host})
            results[url] = {
                "status": resp.status_code,
                "location": resp.headers.get("location"),
                "has_logintoken": 'name="logintoken"' in resp.text,
            }
    return results


@router.get("/debug-moodle-login-page")
async def debug_moodle_login_page():
    import httpx
    settings = get_settings()
    parsed_moodle = urllib.parse.urlparse(settings.MOODLE_URL)
    moodle_host = parsed_moodle.netloc or "siad.hu.jz.ac.ir"
    async with httpx.AsyncClient(timeout=10.0, follow_redirects=False) as client:
        resp = await client.get(
            "http://moodle:8080/login/index.php",
            headers={"Host": moodle_host, "User-Agent": "Mozilla/5.0"}
        )
        return {
            "status_code": resp.status_code,
            "headers": dict(resp.headers),
            "body": resp.text
        }


@router.get("/debug-find-a-tag")
async def debug_find_a_tag():
    settings = get_settings()
    prefix = settings.DB_PREFIX
    try:
        cfg = await fetch_all(
            f"SELECT name, value FROM {prefix}config WHERE value LIKE %s OR value LIKE %s OR value LIKE %s",
            ("%<a>%", "%</a>%", "%<a %")
        )
        cfg_plugins = await fetch_all(
            f"SELECT plugin, name, value FROM {prefix}config_plugins WHERE value LIKE %s OR value LIKE %s OR value LIKE %s",
            ("%<a>%", "%</a>%", "%<a %")
        )
        return {"config": cfg, "config_plugins": cfg_plugins}
    except Exception as e:
        return {"error": str(e)}


@router.get("/debug-all-config")
async def debug_all_config():
    settings = get_settings()
    prefix = settings.DB_PREFIX
    try:
        a_tags = await fetch_all(
            f"SELECT plugin, name, value FROM {prefix}config_plugins WHERE value LIKE %s",
            ("%<a%",)
        )
        return {"a_tags": a_tags}
    except Exception as e:
        return {"error": str(e)}


@router.get("/fix-alternate-login-url")
async def fix_alternate_login_url(url: str = ""):
    settings = get_settings()
    prefix = settings.DB_PREFIX
    from app.database import execute
    # Clear alternateloginurl so login/index.php can issue sessions!
    await execute(f"UPDATE {prefix}config SET value = '' WHERE name = 'alternateloginurl'", ())
    # Set alternatelogouturl so logout redirects to FastAPI 8016!
    await execute(f"UPDATE {prefix}config SET value = 'http://localhost:8016' WHERE name = 'alternatelogouturl'", ())
    await execute(f"UPDATE {prefix}config SET value = '' WHERE name = 'forgottenpasswordurl'", ())
    await execute(f"UPDATE {prefix}config SET value = 'manual,oauth2' WHERE name = 'auth'", ())
    await execute(f"UPDATE {prefix}config SET value = '1' WHERE name = 'showloginform'", ())
    return {"success": True, "alternateloginurl": "", "alternatelogouturl": "http://localhost:8016"}


@router.get("/fix-moodle-oauth")
async def fix_moodle_oauth(scheme: str = "https"):
    """
    Automatically configures the Moodle OAuth2 issuer, endpoints, and field mappings.
    Moodle core requires https:// for OAuth2 endpoints.
    """
    import traceback
    settings = get_settings()
    prefix = settings.DB_PREFIX
    now = int(time.time())

    try:
        from app.database import execute, fetch_one
        base = f"{scheme}://localhost:8016"
        fastapi_internal = f"{scheme}://fastapi_auth:8000"

        # 1. Update existing issuer
        await execute(f"""
            UPDATE {prefix}oauth2_issuer
            SET baseurl = %s,
                enabled = 1,
                showonloginpage = 1,
                clientid = 'moodle_client',
                clientsecret = 'moodle_secret',
                timemodified = %s
            WHERE id = 1
        """, (base, now))

        # 2. Clear old endpoints and insert standard endpoints (with https:// required by Moodle core)
        await execute(f"DELETE FROM {prefix}oauth2_endpoint WHERE issuerid = 1", ())

        endpoints_to_insert = [
            ("authorization_endpoint", f"{base}/oauth/authorize"),
            ("token_endpoint", f"{fastapi_internal}/oauth/token"),
            ("userinfo_endpoint", f"{fastapi_internal}/oauth/userinfo"),
        ]
        for name, url in endpoints_to_insert:
            await execute(f"""
                INSERT INTO {prefix}oauth2_endpoint
                (timecreated, timemodified, usermodified, name, url, issuerid)
                VALUES (%s, %s, 2, %s, %s, 1)
            """, (now, now, name, url))

        # 3. Clear old mappings and insert user field mappings
        await execute(f"DELETE FROM {prefix}oauth2_user_field_mapping WHERE issuerid = 1", ())

        mappings_to_insert = [
            ("given_name", "firstname"),
            ("family_name", "lastname"),
            ("email", "email"),
            ("preferred_username", "username"),
            ("sub", "idnumber"),
        ]
        for ext_f, int_f in mappings_to_insert:
            await execute(f"""
                INSERT INTO {prefix}oauth2_user_field_mapping
                (timecreated, timemodified, usermodified, issuerid, externalfield, internalfield)
                VALUES (%s, %s, 2, 1, %s, %s)
            """, (now, now, ext_f, int_f))

        # 4. Ensure auth plugins list includes manual and oauth2
        await execute(f"UPDATE {prefix}config SET value = 'manual,oauth2' WHERE name = 'auth'", ())

        return {
            "success": True,
            "message": f"Moodle OAuth2 issuer, endpoints ({scheme}://), and field mappings configured successfully!"
        }
    except Exception as e:
        return {"success": False, "error": str(e), "trace": traceback.format_exc()}


@router.get("/debug-sadaa")
async def debug_sadaa():
    settings = get_settings()
    prefix = settings.DB_PREFIX
    try:
        auth_types = await fetch_all(f"SELECT DISTINCT auth, COUNT(*) as cnt FROM {prefix}user GROUP BY auth")
        all_plugins = await fetch_all(f"SELECT DISTINCT plugin FROM {prefix}config_plugins WHERE plugin LIKE %s", ("%sadaa%",))
        all_config = await fetch_all(f"SELECT name, value FROM {prefix}config WHERE name IN ('alternateloginurl', 'auth', 'auth_instructions') OR name LIKE %s OR value LIKE %s", ("%login%", "%8016%"))
        morvarid_configs = await fetch_all(f"SELECT name, value FROM {prefix}config_plugins WHERE plugin = 'local_morvaridsync'")
        sync_logs = await fetch_all(f"SELECT * FROM {prefix}local_morvaridsync_log ORDER BY id DESC LIMIT 10")
        return {
            "all_config": all_config,
            "morvarid_configs": morvarid_configs,
            "sync_logs": sync_logs,
            "user_auth_types": auth_types,
            "plugins": all_plugins,
        }
    except Exception as e:
        return {"error": str(e)}


@router.get("/debug-oauth")
async def debug_oauth():
    settings = get_settings()
    prefix = settings.DB_PREFIX
    try:
        issuers = await fetch_all(f"SELECT * FROM {prefix}oauth2_issuer")
        endpoints = await fetch_all(f"SELECT * FROM {prefix}oauth2_endpoint")
        auth_enabled = await fetch_one(f"SELECT value FROM {prefix}config WHERE name = 'auth'")
        oauth2_plugins = await fetch_all(f"SELECT * FROM {prefix}config_plugins WHERE plugin LIKE %s", ("%oauth2%",))
        return {
            "auth_enabled_list": auth_enabled.get("value") if auth_enabled else None,
            "issuers": issuers,
            "endpoints": endpoints,
            "oauth2_plugins": oauth2_plugins,
        }
    except Exception as e:
        import traceback
        return {"error": repr(e), "traceback": traceback.format_exc()}


@router.get("/debug-admin-phone")
async def debug_admin_phone(phone: Optional[str] = None):
    settings = get_settings()
    prefix = settings.DB_PREFIX
    try:
        from app.database import execute
        if phone:
            await execute(f"UPDATE {prefix}user SET phone2 = %s WHERE username = 'admin'", (phone,))
        elif not phone:
            # Set default test phone if empty
            current = await fetch_one(f"SELECT phone2 FROM {prefix}user WHERE username = 'admin'")
            if not current.get("phone2"):
                await execute(f"UPDATE {prefix}user SET phone2 = '09123456789' WHERE username = 'admin'", ())

        user = await fetch_one(f"SELECT id, username, phone1, phone2, email FROM {prefix}user WHERE username = 'admin'")
        return {"admin": user}
    except Exception as e:
        import traceback
        return {"error": repr(e), "traceback": traceback.format_exc()}


@router.post("/login", response_model=AuthResponse)
async def api_login(req: LoginRequest, response: Response):
    """
    پردازش ورود مستقیم از طریق کلمه عبور (Direct Password Authentication API).
    
    مراحل کار:
      ۱. استعلام هویت از خط لوله آبشاری سه‌لایه:
         - لایه ۱: دانشجویان در دیتابیس دانشگاهی اوراکل
         - لایه ۲: اساتید در دیتابیس دانشگاهی اوراکل
         - لایه ۳: کارمندان و مدیران در جدول mdl_user مودل
      ۲. در صورت تایید هویت، یک بلیت رمزگذاری‌شده دیجیتال (SSO Ticket) تولید می‌شود.
      ۳. آدرس ریدایرکت حاوی بلیت (auth/sadaa/sso.php?ticket=...) به کلاینت جاوااسکریپت
         ارسال می‌گردد تا مرورگر به پورت ۸۰۱۵ منتقل شده و نشست مودل مستقیماً فعال شود.
    """
    settings = get_settings()

    # ۱. احراز هویت سه‌لایه (اوراکل دانشجویان/اساتید -> مودل کارمندان/ادمین)
    user = await authenticate_user(req.username, req.password)
    if not user:
        logger.warning(f"ورود مستقیم ناموفق: مشخصات واردشده برای '{req.username}' صحیح نیست.")
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="نام کاربری یا رمز عبور اشتباه است."
        )

    logger.info(f"مشخصات کاربر '{req.username}' با موفقیت در خط لوله احراز هویت تایید گردید.")

    # ۲. ایجاد سشن محلی و صدور بلیت ورود یکپارچه (SSO Ticket) به مودل
    target_redirect = req.wantsurl or f"{settings.MOODLE_URL}/my/"
    ticket = create_sso_ticket(user["id"], user["username"], wantsurl=target_redirect)
    sso_redirect = f"{settings.MOODLE_URL}/auth/sadaa/sso.php?ticket={ticket}"

    return AuthResponse(
        success=True,
        message="ورود موفقیت‌آمیز بود.",
        redirect_url=sso_redirect
    )


@router.post("/otp/send", response_model=SendOtpResponse)
async def api_otp_send(req: SendOtpRequest):
    """
    ارسال ناهمگام کد تایید یکبار مصرف (OTP) به شماره همراه کاربر.
    
    مراحل کار:
      ۱. جستجوی کاربر در پایگاه داده مودل بر اساس شناسه کاربری، کد دانشجویی یا کد ملی.
      ۲. استخراج شماره همراه معتبر کاربر از فیلدهای phone2 یا phone1.
      ۳. اعمال محدودیت‌های ارسال مجدد (Cooldown / Rate Limiting).
      ۴. تولید کد تصادفی رمزنگاری‌شده و ذخیره موقت آن با مهلت اعتبار مشخص (TTL).
      ۵. ارسال پیامک از طریق درگاه پیامک (قاصدک / کاوه‌نگار / شبیه‌ساز تست).
    """
    settings = get_settings()
    username = req.username.strip()
    if not username:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="لطفاً نام کاربری، شماره دانشجویی یا کد ملی خود را وارد نمایید."
        )

    # ۱. جستجوی مشخصات کاربر در پایگاه داده
    user = await find_user_by_identifier(username)
    if not user:
        logger.warning(f"ارسال پیامک ناموفق: کاربر '{username}' در پایگاه داده یافت نشد.")
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="کاربری با این مشخصات در سامانه یافت نشد."
        )

    # ۲. استخراج و اعتبارسنجی شماره همراه ثبت‌شده کاربر
    phone = get_user_phone(user)
    if not phone:
        logger.warning(f"ارسال پیامک ناموفق: برای کاربر '{username}' شماره تلفن همراه ثبت نشده است.")
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="شماره تلفن همراه معتبری برای این حساب کاربری در سامانه ثبت نشده است. لطفاً با رمز عبور وارد شوید یا با پشتیبانی تماس بگیرید."
        )

    # ۳. تولید کد تایید یکبار مصرف و ثبت در حافظه با کنترل محدودیت زمانی
    ok, msg, code, cooldown = await create_and_store_otp(user["username"], user["id"], phone)
    if not ok:
        raise HTTPException(status_code=status.HTTP_429_TOO_MANY_REQUESTS, detail=msg)

    # ۴. ارسال پیامک از طریق وب‌سرویس پیامکی
    sms_ok, sms_msg = await send_otp_sms(phone, code)
    if not sms_ok and not settings.SMS_DEBUG_MODE:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=sms_msg)

    masked = mask_phone_number(phone)
    debug_code = code if settings.SMS_DEBUG_MODE else None

    return SendOtpResponse(
        success=True,
        message=f"کد تایید یکبار مصرف به شماره {masked} پیامک شد.",
        masked_phone=masked,
        cooldown_seconds=cooldown,
        debug_code=debug_code
    )


@router.post("/otp/verify", response_model=AuthResponse)
async def api_otp_verify(req: VerifyOtpRequest, response: Response):
    """
    اعتبارسنجی ناهمگام کد یکبار مصرف (OTP) و صدور مجوز ورود به مودل.
    
    مراحل کار:
      ۱. اعتبارسنجی کد ارسالی کاربر با مقدار ذخیره‌شده و بررسی تعداد تلاش‌ها و انقضای زمانی.
      ۲. در صورتی که درخواست در جریان پروتکل OAuth2 باشد:
         - صدور Authorization Code یکبار مصرف
         - بازگرداندن ریدایرکت به کالبک استاندارد مودل (/auth/oauth2/sadaa_callback.php یا oauth2/callback)
      ۳. در حالت ورود مستقیم:
         - ایجاد کوکی سشن معتبر مودل (MoodleSession)
         - صدور بلیت دیجیتال SSO و هدایت به auth/sadaa/sso.php جهت فعال‌سازی قطعی نشست کاربر.
    """
    settings = get_settings()
    username = req.username.strip()
    code = req.otp_code.strip()

    if not username or not code:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="نام کاربری و کد تایید الزامی است."
        )

    # ۱. جستجوی کاربر در پایگاه داده
    user = await find_user_by_identifier(username)
    if not user:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="کاربر در سامانه یافت نشد."
        )

    # ۲. تطبیق و اعتبارسنجی کد یکبار مصرف
    valid, msg, entry = await verify_otp_code(user["username"], code)
    if not valid:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=msg
        )

    # ۳. در صورت فراخوانی از جریان OAuth2 استاندارد مودل
    if req.redirect_uri and req.client_id:
        from app.routers.oauth import AUTH_CODES
        import secrets
        import time
        from urllib.parse import quote

        auth_code = secrets.token_urlsafe(32)
        AUTH_CODES[auth_code] = {
            "user_id": user["id"],
            "username": user["username"],
            "email": user.get("email") or f"{user['username']}@local",
            "firstname": user.get("firstname") or "User",
            "lastname": user.get("lastname") or user["username"],
            "client_id": req.client_id,
            "redirect_uri": req.redirect_uri,
            "expires_at": time.time() + 60,
        }

        sep = "&" if "?" in req.redirect_uri else "?"
        callback_url = f"{req.redirect_uri}{sep}code={auth_code}"
        if req.state:
            callback_url += f"&state={quote(req.state, safe='')}"

        logger.info(f"کاربر '{username}' با کد یکبار مصرف از طریق OAuth2 وارد شد. ریدایرکت: {callback_url}")
        return AuthResponse(
            success=True,
            message="ورود با کد تایید با موفقیت انجام شد.",
            redirect_url=callback_url
        )

    # ۴. برقراری نشست معتبر و صدور بلیت دیجیتال SSO (ورود مستقیم)
    moodle_session_cookie = await bridge_moodle_session(user)
    if moodle_session_cookie:
        response.set_cookie(
            key="MoodleSession",
            value=moodle_session_cookie,
            path="/",
            httponly=True,
            samesite="lax",
        )
    else:
        logger.warning(f"ایجاد کوکی مستقیم مودل برای کاربر OTP '{username}' با مشکل مواجه شد.")

    target_redirect = req.wantsurl or f"{settings.MOODLE_URL}/my/"
    ticket = create_sso_ticket(user["id"], user["username"], wantsurl=target_redirect)
    sso_redirect = f"{settings.MOODLE_URL}/auth/sadaa/sso.php?ticket={ticket}"

    return AuthResponse(
        success=True,
        message="ورود با کد تایید با موفقیت انجام شد.",
        redirect_url=sso_redirect
    )


@router.post("/forgot-password", response_model=AuthResponse)
async def api_forgot_password(req: ForgotPasswordRequest, background_tasks: BackgroundTasks):
    """
    پردازش ناهمگام درخواست فراموشی رمز عبور.
    
    به دلایل امنیتی (جلوگیری از حملات برشماری نام کاربری / Account Enumeration):
    همواره یک پیام یکسان بازگردانده می‌شود، اما در پس‌زمینه (Background Task)،
    در صورت وجود ایمیل، توکن رمزنگاری‌شده بازیابی تولید و ارسال می‌گردد.
    """
    settings = get_settings()
    user = await find_user_by_identifier(req.identifier)

    success_msg = "در صورتی که اطلاعات واردشده در سامانه موجود باشد، لینک بازیابی رمز ارسال گردید."

    if user and user.get("email"):
        token = create_password_reset_token(user["id"], user["username"])
        reset_link = f"{settings.AUTH_SERVICE_URL}/reset-password?token={token}"

        # ارسال ایمیل در پس‌زمینه بدون مسدودکردن پاسخ شبکه
        background_tasks.add_task(
            send_password_reset_email,
            to_email=user["email"],
            username=user.get("firstname") or user["username"],
            reset_url=reset_link
        )

    return AuthResponse(success=True, message=success_msg)


@router.post("/reset-password", response_model=AuthResponse)
async def api_reset_password(req: ResetPasswordRequest):
    """
    تغییر و تنظیم رمز عبور جدید با توکن دیجیتال تاییدشده (JWT Password Reset).
    
    مراحل کار:
      ۱. اعتبارسنجی امضای دیجیتال و تاریخ انقضای توکن.
      ۲. هش‌کردن امن کلمه عبور جدید مطابق استاندارد Moodle Bcrypt ($2y$).
      ۳. بروزرسانی فیلد password کاربر در پایگاه داده.
    """
    payload = verify_password_reset_token(req.token)
    if not payload:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="لینک بازیابی رمز نامعتبر است یا منقضی شده است."
        )

    user_id = payload.get("uid")
    if not user_id:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="شناسه نامعتبر است.")

    updated = await update_user_password(int(user_id), req.new_password)
    if not updated:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="خطا در ذخیره رمز عبور جدید. لطفاً مجدداً تلاش کنید."
        )

    return AuthResponse(
        success=True,
        message="رمز عبور با موفقیت تغییر یافت. اکنون می‌توانید وارد شوید.",
        redirect_url="/"
    )
