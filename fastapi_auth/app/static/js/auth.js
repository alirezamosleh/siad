/**
 * اسکریپت کلاینت مدیریت ورود و احراز هویت ناهمگام (Async Authentication Client).
 * 
 * ویژگی‌ها و وظایف:
 *   ۱. مدیریت تب‌های فرم ورود (ورود با رمز عبور در مقابل ورود با کد یکبار مصرف پیامکی).
 *   ۲. ارسال ناهمگام (AJAX/Fetch) درخواست ورود به /api/login با انیمیشن Loading و مدیریت خطاها.
 *   ۳. جریان کامل ورود پیامکی (OTP):
 *      - ارسال درخواست کد به /api/otp/send
 *      - شروع تایمر شمارش معکوس (Countdown Timer) جهت ارسال مجدد
 *      - ماسک‌کردن شماره و فعال‌سازی فیلد ورود کد
 *      - ارسال کد به /api/otp/verify و ریدایرکت مستقیم به داشبورد مودل.
 *   ۴. مدیریت هشدارهای کاربری (Alert Box) با زبان فارسی و راست‌چین.
 */

document.addEventListener('DOMContentLoaded', () => {
    // المان نمایش پیام‌های سیستمی و هشدارها
    const alertBox = document.getElementById('alert-box');

    /**
     * نمایش پیام خطا یا موفقیت به کاربر در بالای فرم.
     * @param {string} message متن پیام
     * @param {'error'|'success'|'info'} type نوع پیام
     */
    function showAlert(message, type = 'error') {
        if (!alertBox) return;
        alertBox.textContent = message;
        alertBox.className = `alert alert-${type}`;
        alertBox.classList.remove('hidden');
    }

    /**
     * مخفی‌کردن کادر پیام خطا یا موفقیت.
     */
    function hideAlert() {
        if (!alertBox) return;
        alertBox.classList.add('hidden');
    }

    /**
     * تنظیم وضعیت بارگذاری (Loading / Spinner) دکمه‌های عملیاتی جهت جلوگیری از کلیک‌های تکراری.
     * @param {HTMLButtonElement} button دکمه مورد نظر
     * @param {boolean} isLoading فعال یا غیرفعال بودن حالت لودینگ
     */
    function setButtonLoading(button, isLoading) {
        if (!button) return;
        const textSpan = button.querySelector('.btn-text');
        const spinner = button.querySelector('.btn-spinner');
        button.disabled = isLoading;
        if (isLoading) {
            if (spinner) spinner.classList.remove('hidden');
            if (textSpan) textSpan.style.opacity = '0.7';
        } else {
            if (spinner) spinner.classList.add('hidden');
            if (textSpan) textSpan.style.opacity = '1';
        }
    }

    /**
     * اعتبارسنجی شماره تلفن همراه ایران (فرمت 09XXXXXXXXX).
     * @param {string} phone 
     * @returns {boolean}
     */
    function isValidIranianPhone(phone) {
        return /^09[0-9]{9}$/.test(phone.trim());
    }

    // =========================================================================
    // بخش ۱: مدیریت سوئیچ بین تب‌های ورود (ورود با رمز عبور / ورود با پیامک)
    // =========================================================================
    const tabButtons = document.querySelectorAll('.auth-tab-btn');
    const authPanels = document.querySelectorAll('.auth-panel');

    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-target');

            // غیرفعال کردن تمامی تب‌ها
            tabButtons.forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            authPanels.forEach(p => p.classList.add('hidden'));

            // فعال کردن تب انتخاب‌شده
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');

            const targetPanel = document.getElementById(targetId);
            if (targetPanel) {
                targetPanel.classList.remove('hidden');
                // فوکوس خودکار روی اولین فیلد ورودی تب فعال
                const firstInput = targetPanel.querySelector('input:not([type="hidden"]):not([disabled])');
                if (firstInput) firstInput.focus();
            }
            hideAlert();
        });
    });

    // =========================================================================
    // بخش ۲: پردازش ارسال فرم ورود مستقیم با رمز عبور (Password Login Form)
    // =========================================================================
    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert();

            const submitBtn = document.getElementById('login-btn');
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            const wantsurlInput = document.getElementById('wantsurl');

            const payload = {
                username: usernameInput.value.trim(),
                password: passwordInput.value,
                wantsurl: wantsurlInput ? wantsurlInput.value : null
            };

            setButtonLoading(submitBtn, true);

            try {
                // فراخوانی ناهمگام API ورود
                const response = await fetch('/api/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    showAlert('ورود موفقیت‌آمیز. در حال انتقال به سامانه...', 'success');
                    if (data.redirect_url) {
                        // ریدایرکت مستقیم به مودل با بلیت امن SSO
                        window.location.href = data.redirect_url;
                    }
                } else {
                    showAlert(data.detail || data.message || 'نام کاربری یا رمز عبور اشتباه است.', 'error');
                    setButtonLoading(submitBtn, false);
                }
            } catch (err) {
                showAlert('خطا در برقراری ارتباط با سرور. لطفاً مجدداً تلاش کنید.', 'error');
                setButtonLoading(submitBtn, false);
            }
        });
    }

    // =========================================================================
    // بخش ۳: فرآیند ورود پیامکی با کد یکبار مصرف (SMS OTP Login Logic)
    // =========================================================================
    const sendOtpBtn = document.getElementById('btn-send-otp');
    const otpUsernameInput = document.getElementById('otp-username');
    const otpCodeInput = document.getElementById('otp-code');
    const otpSubmitBtn = document.getElementById('otp-submit-btn');
    const otpTimerSpan = document.getElementById('otp-timer');
    const otpLoginForm = document.getElementById('otp-login-form');
    const otpUserHint = document.getElementById('otp-user-hint');

    let otpCountdownInterval = null;

    /**
     * شروع شمارش معکوس ثانیه‌شمار برای ارسال مجدد کد پیامکی.
     * @param {number} duration مدت زمان به ثانیه (پیش‌فرض ۶۰ ثانیه)
     */
    function startOtpCountdown(duration = 60) {
        if (otpCountdownInterval) clearInterval(otpCountdownInterval);
        let remaining = duration;

        if (sendOtpBtn) {
            sendOtpBtn.disabled = true;
            const btnText = sendOtpBtn.querySelector('.btn-text');
            if (btnText) btnText.textContent = 'ارسال مجدد';
        }

        if (otpTimerSpan) {
            otpTimerSpan.classList.remove('hidden');
            otpTimerSpan.textContent = `ارسال مجدد کد پس از (${remaining} ثانیه)`;
        }

        otpCountdownInterval = setInterval(() => {
            remaining -= 1;
            if (remaining > 0) {
                if (otpTimerSpan) {
                    otpTimerSpan.textContent = `ارسال مجدد کد پس از (${remaining} ثانیه)`;
                }
            } else {
                clearInterval(otpCountdownInterval);
                otpCountdownInterval = null;
                if (otpTimerSpan) {
                    otpTimerSpan.classList.add('hidden');
                }
                if (sendOtpBtn) {
                    sendOtpBtn.disabled = false;
                    const btnText = sendOtpBtn.querySelector('.btn-text');
                    if (btnText) btnText.textContent = 'ارسال مجدد کد';
                }
            }
        }, 1000);
    }

    // الف) کلیک روی دکمه "ارسال کد تایید"
    if (sendOtpBtn && otpUsernameInput) {
        sendOtpBtn.addEventListener('click', async () => {
            hideAlert();
            const username = otpUsernameInput.value.trim();

            if (!username) {
                showAlert('لطفاً نام کاربری، شماره دانشجویی یا کد ملی خود را وارد نمایید.', 'error');
                otpUsernameInput.focus();
                return;
            }

            setButtonLoading(sendOtpBtn, true);

            try {
                // ارسال درخواست تولید و ارسال پیامک OTP
                const response = await fetch('/api/otp/send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username: username })
                });

                const data = await response.json().catch(() => ({}));

                if (response.ok && data.success) {
                    let msg = data.message || 'کد تایید ارسال گردید.';
                    // در حالت شبیه‌ساز/تست محلی کد جهت سهولت دیباگ بازگردانده می‌شود
                    if (data.debug_code) {
                        msg += ` (کد تست/دیباگ: ${data.debug_code})`;
                        if (otpCodeInput) otpCodeInput.value = data.debug_code;
                    }
                    showAlert(msg, 'success');

                    if (otpUserHint && data.masked_phone) {
                        otpUserHint.textContent = `کد تایید به شماره ${data.masked_phone} ارسال شد.`;
                        otpUserHint.style.color = '#10b981';
                    }

                    // فعال‌کردن فیلد ورود کد و دکمه تایید نهایی
                    if (otpCodeInput) {
                        otpCodeInput.disabled = false;
                        otpCodeInput.focus();
                    }
                    if (otpSubmitBtn) {
                        otpSubmitBtn.disabled = false;
                    }

                    startOtpCountdown(data.cooldown_seconds || 60);
                } else {
                    showAlert(data.detail || data.message || 'خطا در ارسال کد تایید.', 'error');
                }
            } catch (err) {
                showAlert('خطا در برقراری ارتباط با سرور. لطفاً مجدداً تلاش نمایید.', 'error');
            } finally {
                setButtonLoading(sendOtpBtn, false);
            }
        });
    }

    // ب) ارسال فرم تایید نهایی کد یکبار مصرف و ورود به سامانه
    if (otpLoginForm) {
        otpLoginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert();

            const username = otpUsernameInput ? otpUsernameInput.value.trim() : '';
            const otpCode = otpCodeInput ? otpCodeInput.value.trim() : '';
            const wantsurlInput = document.getElementById('otp-wantsurl') || document.getElementById('wantsurl');

            if (!username) {
                showAlert('لطفاً نام کاربری خود را وارد کنید.', 'error');
                if (otpUsernameInput) otpUsernameInput.focus();
                return;
            }

            if (!otpCode || otpCode.length < 4) {
                showAlert('لطفاً کد تایید پیامک‌شده را به درستی وارد کنید.', 'error');
                if (otpCodeInput) otpCodeInput.focus();
                return;
            }

            setButtonLoading(otpSubmitBtn, true);

            // استخراج پارامترهای OAuth2 در صورت فراخوانی از مودل
            const clientIdInput = otpLoginForm.querySelector('input[name="client_id"]');
            const redirectUriInput = otpLoginForm.querySelector('input[name="redirect_uri"]');
            const stateInput = otpLoginForm.querySelector('input[name="state"]');
            const scopeInput = otpLoginForm.querySelector('input[name="scope"]');

            const payload = {
                username: username,
                otp_code: otpCode,
                wantsurl: wantsurlInput ? wantsurlInput.value : null,
                client_id: clientIdInput ? clientIdInput.value : null,
                redirect_uri: redirectUriInput ? redirectUriInput.value : null,
                state: stateInput ? stateInput.value : null,
                scope: scopeInput ? scopeInput.value : null
            };

            try {
                // ارسال کد به سرور جهت تایید و صدور مجوز نشست
                const response = await fetch('/api/otp/verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await response.json().catch(() => ({}));

                if (response.ok && data.success) {
                    showAlert('ورود با کد تایید موفقیت‌آمیز بود. در حال انتقال به سامانه...', 'success');
                    if (data.redirect_url) {
                        window.location.href = data.redirect_url;
                    }
                } else {
                    showAlert(data.detail || data.message || 'کد تایید نامعتبر یا منقضی شده است.', 'error');
                    setButtonLoading(otpSubmitBtn, false);
                }
            } catch (err) {
                showAlert('خطا در برقراری ارتباط با سرور. لطفاً مجدداً تلاش فرمایید.', 'error');
                setButtonLoading(otpSubmitBtn, false);
            }
        });
    }
});
