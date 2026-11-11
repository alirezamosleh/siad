#!/bin/bash
set -e

# Ensure moodledata directory exists and has proper permissions
mkdir -p /var/moodledata
chown -R www-data:www-data /var/moodledata
chmod 777 /var/moodledata

if [ "$1" = "cron" ]; then
    echo "=== Starting Moodle Cron Daemon (Official PHP-FPM image) ==="
    # Wait for database and moodle to be reachable
    sleep 10
    while true; do
        echo "=== [$(date '+%Y-%m-%d %H:%M:%S')] Running Moodle Cron ==="
        su -s /bin/bash www-data -c "php /var/www/html/admin/cli/cron.php" || true
        sleep 60
    done
fi

exec "$@"
