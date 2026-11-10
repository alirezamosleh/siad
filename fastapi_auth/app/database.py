"""
================================================================================
مدیریت اتصالات غیرهمزمان پایگاه داده مودل (Asynchronous Database Client & Pool)
سامانه یادگیری الکترونیکی و احراز هویت هوشمند المصطفی
================================================================================

توضیحات:
این ماژول ارتباط فوق‌سریع و غیرمسدودکننده (Non-blocking I/O) با پایگاه داده MariaDB/MySQL
مودل را با استفاده از کتابخانه aiomysql پیاده‌سازی می‌کند.
ویژگی‌ها:
  - استخر اتصالات هوشمند (Connection Pool) جهت بازیافت اتصالات و کاهش سربار TCP Handshake.
  - مکانیزم تلاش مجدد (Retry Pattern) در زمان راه‌اندازی جهت مقاومت در برابر تاخیر بالا آمدن کانتینر دیتابیس.
  - استفاده از DictCursor جهت بازگرداندن نتایج به صورت دیکشنری کلید-مقدار منطبق بر ستون‌ها.
  - آزادسازی خودکار کانکشن به استخر بلافاصله پس از اتمام اجرای کوئری (Context Manager).
"""

import asyncio
import logging
from typing import Any, Dict, List, Optional
import aiomysql
from app.config import get_settings

logger = logging.getLogger(__name__)

# استخر اتصالات سراسری پایگاه داده
db_pool: Optional[aiomysql.Pool] = None
_init_lock: Optional[asyncio.Lock] = None


async def init_db(retries: int = 3, delay: float = 1.0) -> None:
    """
    راه‌اندازی و مقداردهی اولیه استخر اتصالات با قابلیت تلاش مجدد خودکار.
    
    پارامترها:
      - retries: تعداد دفعات تلاش در صورت عدم دسترسی اولیه به پایگاه داده
      - delay: فاصله زمانی بین هر تلاش مجدد (ثانیه)
    """
    global db_pool, _init_lock
    settings = get_settings()

    if _init_lock is None:
        _init_lock = asyncio.Lock()

    async with _init_lock:
        if db_pool is not None and not db_pool._closed:
            return

        for attempt in range(1, retries + 1):
            try:
                db_pool = await aiomysql.create_pool(
                    host=settings.DB_HOST,
                    port=settings.DB_PORT,
                    user=settings.DB_USER,
                    password=settings.DB_PASSWORD,
                    db=settings.DB_NAME,
                    charset="utf8mb4",
                    autocommit=True,
                    minsize=settings.DB_POOL_MIN_SIZE,
                    maxsize=settings.DB_POOL_MAX_SIZE,
                    connect_timeout=5,
                    cursorclass=aiomysql.DictCursor,
                )
                logger.info("استخر اتصالات پایگاه داده مودل (MariaDB) با موفقیت ایجاد گردید.")
                return
            except Exception as e:
                if attempt < retries:
                    logger.warning(f"تلاش {attempt}/{retries} جهت اتصال به پایگاه داده مودل ناموفق بود: {e}. تلاش مجدد تا {delay} ثانیه دیگر...")
                    await asyncio.sleep(delay)
                else:
                    logger.error(f"عدم امکان اتصال به پایگاه داده مودل پس از {retries} بار تلاش: {e}")
                    raise e


async def get_db_pool() -> aiomysql.Pool:
    """
    دریافت استخر اتصالات فعال. در صورت بسته بودن یا عدم مقداردهی، خودکار راه‌اندازی می‌شود.
    """
    global db_pool
    if db_pool is None or db_pool._closed:
        await init_db(retries=2, delay=0.5)
    if not db_pool:
        raise RuntimeError("استخر اتصالات پایگاه داده در دسترس نمی‌باشد.")
    return db_pool


async def close_db() -> None:
    """
    بستن تمیز و بدون نشت تمامی اتصالات باز پایگاه داده هنگام خاموش شدن وب‌سرور.
    """
    global db_pool
    if db_pool:
        db_pool.close()
        await db_pool.wait_closed()
        logger.info("تمامی کانکشن‌های استخر پایگاه داده با موفقیت بسته شدند.")


async def fetch_one(query: str, params: tuple = ()) -> Optional[Dict[str, Any]]:
    """
    اجرای کوئری SELECT و بازگرداندن دقیقاً یک سطر به صورت دیکشنری.
    کانکشن دریافتی بلافاصله پس از اجرا به استخر بازگردانده می‌شود.
    """
    pool = await get_db_pool()
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(query, params)
            return await cur.fetchone()


async def fetch_all(query: str, params: tuple = ()) -> List[Dict[str, Any]]:
    """
    اجرای کوئری SELECT و بازگرداندن تمامی سطرهای نتیجه به صورت لیستی از دیکشنری‌ها.
    """
    pool = await get_db_pool()
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(query, params)
            return await cur.fetchall()


async def execute(query: str, params: tuple = ()) -> int:
    """
    اجرای کوئری‌های تغییر داده (INSERT, UPDATE, DELETE) و بازگرداندن تعداد سطرهای تحت تاثیر.
    """
    pool = await get_db_pool()
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(query, params)
            return cur.rowcount
