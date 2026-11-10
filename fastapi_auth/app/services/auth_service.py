import logging
import re
import time
from typing import Any, Dict, Optional
from app.config import get_settings
from app.database import fetch_one, execute
from app.security import verify_moodle_password, hash_moodle_password

logger = logging.getLogger(__name__)


async def find_user_by_identifier(identifier: str) -> Optional[Dict[str, Any]]:
    """
    Find user in Moodle database by username, idnumber, email, or national ID.
    """
    settings = get_settings()
    prefix = settings.DB_PREFIX
    identifier = identifier.strip()
    if not identifier:
        return None

    # 1. Search by username (case-insensitive)
    query_username = f"""
        SELECT * FROM {prefix}user
        WHERE LOWER(username) = LOWER(%s) AND deleted = 0
        LIMIT 1
    """
    user = await fetch_one(query_username, (identifier,))
    if user:
        return user

    # 2. Search by idnumber (Student ID / Staff ID)
    query_idnumber = f"""
        SELECT * FROM {prefix}user
        WHERE idnumber = %s AND deleted = 0
        LIMIT 1
    """
    user = await fetch_one(query_idnumber, (identifier,))
    if user:
        return user

    # 3. Search by email
    if "@" in identifier:
        query_email = f"""
            SELECT * FROM {prefix}user
            WHERE LOWER(email) = LOWER(%s) AND deleted = 0
            LIMIT 1
        """
        user = await fetch_one(query_email, (identifier,))
        if user:
            return user

    # 4. Search by National ID in custom profile field
    clean_national_id = re.sub(r"\D", "", identifier)
    if clean_national_id:
        user = await find_user_by_national_id(clean_national_id)
        if user:
            return user

    return None


async def find_user_by_national_id(national_id: str) -> Optional[Dict[str, Any]]:
    """
    Find user by national ID custom profile field in Moodle.
    """
    settings = get_settings()
    prefix = settings.DB_PREFIX
    clean_id = re.sub(r"\D", "", national_id)
    if not clean_id:
        return None

    query = f"""
        SELECT u.* FROM {prefix}user u
        JOIN {prefix}user_info_data d ON d.userid = u.id
        JOIN {prefix}user_info_field f ON f.id = d.fieldid
        WHERE f.shortname = %s AND d.data = %s AND u.deleted = 0
        LIMIT 1
    """
    user = await fetch_one(query, (settings.NATIONAL_ID_FIELD, clean_id))
    if user:
        return user

    # Fallback: check if username or idnumber equals the national ID
    query_fallback = f"""
        SELECT * FROM {prefix}user
        WHERE (username = %s OR idnumber = %s) AND deleted = 0
        LIMIT 1
    """
    return await fetch_one(query_fallback, (clean_id, clean_id))


async def sync_or_create_oracle_user_in_moodle(oracle_user: Dict[str, Any], plain_password: str) -> Optional[Dict[str, Any]]:
    """
    همگام‌سازی یا ثبت‌نام در لحظه (JIT Provisioning) کاربر تاییدشده در اوراکل به دیتابیس مودل (mdl_user).
    
    فرآیند کار:
      ۱. ابتدا بررسی می‌شود که آیا این دانشجو یا استاد قبلاً در مودل لاگین کرده و رکوردی دارد؟
         (بررسی بر اساس username یا idnumber/کد دانشجویی).
      ۲. اگر کاربر از قبل در مودل وجود داشت:
         - هش رمز عبور او در جدول mdl_user با رمز عبور معتبر اوراکل بروزرسانی می‌شود تا
           همیشه بین اوراکل و مودل سینک باشد.
      ۳. اگر کاربر برای اولین بار وارد می‌شود (کاربر جدید):
         - یک رکورد کامل در mdl_user با فیلدهای استاندارد هسته مودل ثبت می‌شود:
           * auth='manual' : نوع احراز هویت محلی
           * confirmed=1 : فعال و تاییدشده بدون نیاز به ایمیل فعال‌سازی
           * mnethostid=1 : شبکه لوکال مودل
           * username : شماره دانشجویی یا نام کاربری به حروف کوچک
           * password : هش امن سازگار با مودل ($2y$)
           * idnumber : کد دانشجویی/استادی جهت اتصال به درس‌ها و نمرات
           * email : ایمیل دانشگاهی یا ایمیل تولیدشده
      ۴. رکورد ساخته‌شده یا بروزرسانی‌شده جهت صدور سشن مودل بازگردانده می‌شود.
    """
    settings = get_settings()
    prefix = settings.DB_PREFIX
    username = str(oracle_user["username"]).strip().lower()
    idnumber = str(oracle_user.get("idnumber") or oracle_user.get("code") or "").strip()

    # ۱. جستجوی کاربر در مودل بر اساس نام کاربری یا شماره دانشجویی
    existing = await find_user_by_identifier(username)
    if not existing and idnumber:
        existing = await find_user_by_identifier(idnumber)

    if existing:
        # کاربر وجود دارد -> همگام‌سازی هش کلمه عبور در مودل
        new_hash = hash_moodle_password(plain_password)
        now = int(time.time())
        await execute(
            f"UPDATE {prefix}user SET password = %s, timemodified = %s WHERE id = %s",
            (new_hash, now, existing["id"])
        )
        existing["password"] = new_hash
        logger.info(f"رمز عبور مودل برای کاربر اوراکل '{username}' (شناسه: {existing['id']}) همگام‌سازی شد.")
        return existing

    # ۲. کاربر در مودل وجود ندارد -> ایجاد کاربر جدید در لحظه (JIT Provisioning)
    now = int(time.time())
    new_hash = hash_moodle_password(plain_password)
    email = oracle_user.get("email") or f"{username}@edu.local"
    firstname = oracle_user.get("firstname") or "دانشجو"
    lastname = oracle_user.get("lastname") or username

    insert_query = f"""
        INSERT INTO {prefix}user (
            auth, confirmed, mnethostid, username, password,
            idnumber, firstname, lastname, email,
            timecreated, timemodified
        ) VALUES (
            'manual', 1, 1, %s, %s,
            %s, %s, %s, %s,
            %s, %s
        )
    """
    await execute(
        insert_query,
        (username, new_hash, idnumber, firstname, lastname, email, now, now)
    )

    created_user = await fetch_one(
        f"SELECT * FROM {prefix}user WHERE username = %s AND deleted = 0 LIMIT 1",
        (username,)
    )
    logger.info(f"کاربر جدید از اوراکل با موفقیت در مودل ایجاد گردید: '{username}' (شناسه جدید: {created_user['id'] if created_user else 'نامشخص'}).")
    return created_user


async def authenticate_user(identifier: str, plain_password: str) -> Optional[Dict[str, Any]]:
    """
    خط لوله احراز هویت آبشاری سه‌لایه (3-Tier Waterfall Authentication Pipeline):
    
    لایه ۱: دانشجویان در دیتابیس دانشگاهی اوراکل (جدول SHARIFI.STU_INFO_REMOTE)
    لایه ۲: اساتید در دیتابیس دانشگاهی اوراکل (جدول SHARIFI.TEACHER_INFO_REMOTE)
    لایه ۳: کارمندان، کارشناسان آموزش و مدیران در دیتابیس محلی مودل (جدول mdl_user)
    
    منطق کارکرد:
      - ابتدا لاگین در پایگاه داده اوراکل استعلام می‌شود. اگر کاربر دانشجو یا استاد معتبری
        باشد، هویت او تایید و در دیتابیس مودل سینک/ثبت‌نام می‌شود.
      - اگر کاربر در اوراکل نبود (یا ارتباط با اوراکل موقتاً قطع بود)، لایه ۳ اجرا می‌شود:
        کاربر در جدول mdl_user جستجو شده و هش رمز او با الگوریتم رمزنگاری مودل بررسی می‌شود.
      - این فرآیند باعث می‌شود کارمندان و ادمین‌های سیستم بدون هیچ وابستگی به اوراکل لاگین کنند.
    """
    # -------------------------------------------------------------------------
    # لایه‌های ۱ و ۲: استعلام در پایگاه داده دانشگاهی اوراکل (دانشجویان و اساتید)
    # -------------------------------------------------------------------------
    try:
        from app.services.oracle_service import authenticate_oracle_user
        oracle_user = await authenticate_oracle_user(identifier, plain_password)
        if oracle_user:
            return await sync_or_create_oracle_user_in_moodle(oracle_user, plain_password)
    except Exception as e:
        logger.error(f"خطا در ارتباط با سرویس احراز هویت اوراکل: {e}")

    # -------------------------------------------------------------------------
    # لایه ۳: استعلام در دیتابیس محلی مودل (کارمندان، کارشناسان آموزش و ادمین‌ها)
    # -------------------------------------------------------------------------
    local_user = await find_user_by_identifier(identifier)
    if local_user:
        hashed_password = local_user.get("password") or ""
        if verify_moodle_password(plain_password, hashed_password):
            logger.info(f"احراز هویت موفق در لایه ۳ (مودل محلی): کاربر کارمند/مدیر '{identifier}' تایید شد.")
            return local_user

    logger.warning(f"احراز هویت برای کاربر '{identifier}' در هر ۳ لایه با شکست مواجه شد.")
    return None


async def update_user_password(user_id: int, new_password: str) -> bool:
    """
    Update user's password in Moodle database.
    """
    settings = get_settings()
    prefix = settings.DB_PREFIX
    new_hash = hash_moodle_password(new_password)
    now = int(time.time())

    query = f"""
        UPDATE {prefix}user
        SET password = %s, timemodified = %s
        WHERE id = %s AND deleted = 0
    """
    rows = await execute(query, (new_hash, now, user_id))
    return rows > 0


def get_user_phone(user: Dict[str, Any]) -> Optional[str]:
    """
    Extracts and normalizes user's mobile phone number from Moodle user record.
    Checks phone2 (primary mobile in Iranian/Morvarid setups) then phone1.
    """
    from app.services.sms_service import normalize_iranian_phone

    phone2 = user.get("phone2")
    norm2 = normalize_iranian_phone(phone2)
    if norm2:
        return norm2

    phone1 = user.get("phone1")
    norm1 = normalize_iranian_phone(phone1)
    if norm1:
        return norm1

    return None


async def bridge_moodle_session(user: Dict[str, Any], plain_password: Optional[str] = None) -> Optional[str]:
    """
    Establishes an authentic Moodle session and returns the MoodleSession cookie value.
    If plain_password is provided, authenticates directly.
    If plain_password is None (e.g. OTP or SSO login), generates a temporary complex password,
    authenticates to Moodle, and safely restores the original password and auth plugin in a finally block.
    Zero custom PHP plugins required on Moodle!
    """
    import secrets
    import httpx
    import urllib.parse
    settings = get_settings()
    prefix = settings.DB_PREFIX
    user_id = user["id"]
    username = user["username"]

    moodle_internal_url = getattr(settings, "MOODLE_INTERNAL_URL", "http://moodle:8080")
    moodle_host = urllib.parse.urlparse(settings.MOODLE_URL).netloc or "siad.hu.jz.ac.ir"
    is_ssl = settings.MOODLE_URL.startswith("https")
    headers = {
        "Host": moodle_host,
        "Referer": f"{settings.MOODLE_URL}/login/index.php",
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        "X-Internal-Bridge": "1",
        "X-Forwarded-Proto": "https" if is_ssl else "http",
        "X-Forwarded-Port": "443" if is_ssl else "80",
    }

    original_hash = user.get("password") or ""
    original_auth = user.get("auth") or "manual"
    active_password = plain_password
    needs_restore = False

    try:
        if not active_password:
            active_password = secrets.token_urlsafe(32) + "A1!"
            temp_hash = hash_moodle_password(active_password)
            needs_restore = True
            await execute(
                f"UPDATE {prefix}user SET password = %s, auth = 'manual' WHERE id = %s",
                (temp_hash, user_id)
            )

        async with httpx.AsyncClient(timeout=10.0, follow_redirects=False) as client:
            # 1. Fetch login page to retrieve logintoken & initial MoodleSession
            get_resp = await client.get(f"{moodle_internal_url}/login/index.php?internal_bridge=1", headers=headers)
            m = re.search(r'name="logintoken" value="([a-zA-Z0-9]+)"', get_resp.text)
            if not m:
                logger.error(f"Could not find logintoken on Moodle login page for user '{username}'")
                return None

            logintoken = m.group(1)
            init_cookie = client.cookies.get("MoodleSession")

            # 2. Post login credentials
            post_data = {
                "username": username,
                "password": active_password,
                "logintoken": logintoken,
            }
            await client.post(
                f"{moodle_internal_url}/login/index.php?internal_bridge=1",
                data=post_data,
                headers=headers
            )

            # Get authenticated MoodleSession cookie
            auth_cookie = client.cookies.get("MoodleSession") or init_cookie
            logger.info(f"Moodle session bridged successfully for user '{username}'.")
            return auth_cookie

    except Exception as e:
        logger.error(f"Error bridging Moodle session for user '{username}': {e}")
        return None
    finally:
        if needs_restore:
            try:
                await execute(
                    f"UPDATE {prefix}user SET password = %s, auth = %s WHERE id = %s",
                    (original_hash, original_auth, user_id)
                )
                logger.info(f"Restored original credentials for user '{username}'.")
            except Exception as re_err:
                logger.critical(f"FATAL: Failed to restore credentials for user '{username}': {re_err}")


async def get_or_create_usw_user(userinfo: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    """
    Match existing user or create a new user from USW profile data.
    """
    settings = get_settings()
    prefix = settings.DB_PREFIX

    national_id = str(userinfo.get("nationalId") or userinfo.get("nationalid") or "").strip()
    national_id = re.sub(r"\D", "", national_id)
    if not national_id:
        logger.error("USW userinfo missing nationalId")
        return None

    user = await find_user_by_national_id(national_id)
    if user:
        # Update names or phone if needed
        firstname = str(userinfo.get("firstName") or userinfo.get("firstname") or "").strip()
        lastname = str(userinfo.get("lastName") or userinfo.get("lastname") or "").strip()
        if firstname or lastname:
            now = int(time.time())
            await execute(
                f"UPDATE {prefix}user SET firstname = %s, lastname = %s, timemodified = %s WHERE id = %s",
                (firstname or user["firstname"], lastname or user["lastname"], now, user["id"])
            )
        return user

    if not settings.USW_AUTO_CREATE_USER:
        return None

    # Create new user in Moodle
    now = int(time.time())
    firstname = str(userinfo.get("firstName") or userinfo.get("firstname") or "دانشجو").strip()
    lastname = str(userinfo.get("lastName") or userinfo.get("lastname") or national_id).strip()
    mobile = str(userinfo.get("mobile") or "").strip()
    email = f"{national_id}@example.com"

    insert_query = f"""
        INSERT INTO {prefix}user (
            auth, confirmed, mnethostid, username, password,
            firstname, lastname, email, phone1, idnumber,
            timecreated, timemodified
        ) VALUES (
            'manual', 1, 1, %s, 'not cached',
            %s, %s, %s, %s, %s,
            %s, %s
        )
    """
    await execute(
        insert_query,
        (national_id, firstname, lastname, email, mobile, national_id, now, now)
    )

    created_user = await fetch_one(
        f"SELECT * FROM {prefix}user WHERE username = %s AND deleted = 0 LIMIT 1",
        (national_id,)
    )

    # Insert national ID into custom profile field if field exists
    if created_user:
        field = await fetch_one(
            f"SELECT id FROM {prefix}user_info_field WHERE shortname = %s LIMIT 1",
            (settings.NATIONAL_ID_FIELD,)
        )
        if field:
            await execute(
                f"""
                INSERT INTO {prefix}user_info_data (userid, fieldid, data, dataformat)
                VALUES (%s, %s, %s, 0)
                """,
                (created_user["id"], field["id"], national_id)
            )

    return created_user
