import logging
from email.message import EmailMessage
import aiosmtplib
from app.config import get_settings

logger = logging.getLogger(__name__)


async def send_password_reset_email(to_email: str, username: str, reset_url: str) -> bool:
    """
    Send password reset email asynchronously via aiosmtplib.
    If SMTP is disabled, logs the link for development/testing.
    """
    settings = get_settings()

    if not settings.SMTP_ENABLED:
        logger.info(
            f"[PASSWORD RESET LINK] To: {to_email} (User: {username})\n"
            f"Reset URL: {reset_url}\n"
            f"(SMTP is disabled in configuration. Enable SMTP in .env for production emails.)"
        )
        return True

    try:
        msg = EmailMessage()
        msg["From"] = settings.SMTP_FROM_EMAIL or settings.SMTP_USER
        msg["To"] = to_email
        msg["Subject"] = f"بازیابی رمز عبور سامانه آموزشی - {settings.APP_NAME}"

        html_content = f"""
        <div dir="rtl" style="font-family: Tahoma, Arial, sans-serif; line-height: 1.8; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;">
            <h2 style="color: #1e3a8a; margin-top: 0;">بازیابی رمز عبور</h2>
            <p>سلام <strong>{username}</strong> عزیز،</p>
            <p>درخواستی مبنی بر بازیابی رمز عبور حساب کاربری شما در سامانه آموزشی ثبت شده است.</p>
            <p>جهت تعیین رمز عبور جدید، روی دکمه زیر کلیک نمایید:</p>
            <div style="text-align: center; margin: 30px 0;">
                <a href="{reset_url}" style="background-color: #2563eb; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">
                    تغییر رمز عبور
                </a>
            </div>
            <p style="font-size: 13px; color: #64748b;">
                این لینک به مدت {settings.PASSWORD_RESET_TOKEN_TTL_MINUTES} دقیقه معتبر است. اگر شما این درخواست را نداده‌اید، نیازی به انجام کاری نیست و این ایمیل را نادیده بگیرید.
            </p>
            <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;">
            <p style="font-size: 12px; color: #94a3b8; text-align: center;">سامانه مدیریت یادگیری مودل</p>
        </div>
        """
        msg.set_content(f"جهت بازیابی رمز عبور به آدرس زیر بروید:\n{reset_url}")
        msg.add_alternative(html_content, subtype="html")

        await aiosmtplib.send(
            msg,
            hostname=settings.SMTP_HOST,
            port=settings.SMTP_PORT,
            username=settings.SMTP_USER or None,
            password=settings.SMTP_PASSWORD or None,
            start_tls=settings.SMTP_USE_TLS,
        )
        logger.info(f"Password reset email sent successfully to {to_email}")
        return True
    except Exception as e:
        logger.error(f"Failed to send password reset email to {to_email}: {e}")
        return False
