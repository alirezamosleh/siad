#!/bin/bash
# ==============================================================================
# اسکریپت اجرای دوره‌ای کرون مودل (Moodle Cron Daemon)
# [FIX - INFRASTRUCTURE & CRON]:
# اجرای تسک‌های پس‌زمینه، ارسال نوتیفیکیشن‌ها، همگام‌سازی ضبط‌های بیگ‌بلوباتن
# و پردازش صف ادهاک مودل بدون سربار بر وب‌سرور آپاچی به صورت حلقه دیمن هر ۶۰ ثانیه.
# ==============================================================================
set -e

echo "Waiting for Moodle app and database to be ready..."
sleep 15

echo "Starting Moodle cron daemon..."
while true; do
    echo "=== Running Moodle cron: $(date) ==="
    /opt/bitnami/php/bin/php /bitnami/moodle/admin/cli/cron.php || true
    sleep 60
done
