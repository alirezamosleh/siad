"""
================================================================================
نقطه ورود و پیکربندی اصلی برنامه FastAPI (Application Entrypoint & Lifespan)
سامانه یادگیری الکترونیکی و احراز هویت هوشمند المصطفی
================================================================================

شرح ساختار معماری سرویس:
این ماژول نقطه ورودی مرکزی سرور وب (FastAPI) است که مسئولیت‌های زیر را بر عهده دارد:
  ۱. چرخه حیات سرویس (Lifespan): راه‌اندازی استخر اتصالات غیرهمزمان دیتابیس (aiomysql) در بدو
     شروع برنامه و بستن ایمن و بدون نشت (Graceful Shutdown) اتصالات هنگام توقف سرویس.
  ۲. میان‌افزارها (Middleware): پیکربندی CORS جهت تبادلات مجاز میان مرورگر و مودل.
  ۳. فایل‌های ایستا (Static Assets): اتصال دایرکتوری فایلهای CSS، فونت‌ها و اسکریپت‌های لاگین.
  ۴. ثبت روترها (Routers):
     - views: رندر صفحات HTML قالب Jinja2
     - auth: اندپوینت‌های ورود مستقیم (/api/login)، ارسال پیامک و اعتبارسنجی کد OTP
     - usw: درگاه پنجره واحد خدمات دولت من (وزارت عتف)
     - oauth: ارائه‌دهنده پروتکل استاندارد جهانی OAuth 2.0 و OpenID Connect مودل
"""

import logging
from contextlib import asynccontextmanager
from pathlib import Path
from fastapi import FastAPI
from fastapi.staticfiles import StaticFiles
from fastapi.middleware.cors import CORSMiddleware
from app.config import get_settings
from app.database import init_db, close_db
from app.routers import auth, usw, views, oauth

# پیکربندی استاندارد ثبت وقایع و لاگ‌های سیستم
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
)
logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):
    """
    مدیریت چرخه حیات سرویس (FastAPI Lifespan Context Manager).
    این تابع قبل از پذیرش اولین درخواست HTTP اجرا می‌شود تا منابع پایگاه داده
    آماده‌سازی شوند و هنگام خاموش شدن سرور، اتصالات را با رعایت سلامت سیستم می‌بندد.
    """
    settings = get_settings()
    logger.info(f"در حال راه‌اندازی {settings.APP_NAME}...")
    try:
        # ایجاد و مقداردهی اولیه استخر اتصالات پایگاه داده MySQL/MariaDB
        await init_db()
    except Exception as e:
        logger.warning(
            f"اتصال به دیتابیس در زمان راه‌اندازی اولیه میسر نشد: {e}. "
            "لطفاً متغیرهای اتصال به MariaDB در فایل تنظیمات را بررسی فرمایید."
        )
    yield
    # خاموش‌سازی تمیز و بستن تمام کانکشن‌های باز دیتابیس
    logger.info("در حال متوقف‌سازی سرویس احراز هویت...")
    await close_db()


settings = get_settings()

# ساخت نمونه اصلی برنامه فست‌اِی‌پی‌آی
app = FastAPI(
    title=settings.APP_NAME,
    description="سرویس ناهمگام و پرسرعت احراز هویت یکپارچه مودل، اوراکل و پنجره دولت من",
    version="1.0.0",
    lifespan=lifespan,
)

# پیکربندی میان‌افزار امنیت دسترسی بین دامنه‌ای (CORS Middleware)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

BASE_DIR = Path(__file__).resolve().parent

# اتصال مسیر فایل‌های ایستا شامل استایل‌ها، فونت‌های وزیرمتن و اسکریپت‌های JS
app.mount("/static", StaticFiles(directory=str(BASE_DIR / "static")), name="static")

# ثبت روترها و ماژول‌های سرویس‌دهنده
app.include_router(views.router)    # صفحات وب و رابط کاربری فارسی
app.include_router(auth.router)     # وب‌سرویس‌های لاگین، پیامک و اعتبارسنجی
app.include_router(usw.router)      # درگاه ارتباط با پنجره دولت من
app.include_router(oauth.router)    # سرور استاندارد OAuth 2.0 هسته مودل


@app.get("/health", tags=["Health"])
async def health_check():
    """
    اندپوینت پایش سلامت سرویس (Health Check).
    توسط داکر یا سیستم‌های نظارتی جهت بررسی زنده بودن کانتینر صدا زده می‌شود.
    """
    return {"status": "ok", "service": settings.APP_NAME}
