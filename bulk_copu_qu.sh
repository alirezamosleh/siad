#!/bin/bash
# run_all_copies.sh — اجرای دسته‌ای کپی quiz بین درس‌ها
# استفاده: bash run_all_copies.sh

MOODLE_CONTAINER="moodle_app"
SCRIPT="copy_all_quizzes.php"
LOG="copy_log_$(date +%Y%m%d_%H%M%S).txt"

run_copy() {
    local FROM=$1
    local TO=$2
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "▶ from=$FROM  →  to=$TO"
    docker exec $MOODLE_CONTAINER bash -c \
        "cd /bitnami/moodle && php $SCRIPT --from=$FROM --to=$TO" \
        2>/dev/null | grep -v "already loaded"
}

# ══════════════════════════════════════════
# لیست جفت‌های مبدا → مقصد
# ══════════════════════════════════════════

# یک‌به‌یک




run_copy 4076 4090


echo ""
echo "════════════════════════════════════════"
echo "✅ همه کپی‌ها اجرا شدند"
echo "════════════════════════════════════════"

