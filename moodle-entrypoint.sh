#!/bin/bash 
set -e 

if ! /opt/bitnami/php/bin/php -r "extension_loaded('redis') or exit(1);" 2>/dev/null; then 
    rm -rf /var/lib/apt/lists/* 

    cat > /etc/apt/sources.list <<SOURCES
deb http://deb.debian.org/debian bookworm main 
deb http://deb.debian.org/debian bookworm-updates main 
deb http://security.debian.org/debian-security bookworm-security main 
SOURCES

    apt update 
    apt install -y --no-install-recommends \
        gcc make autoconf libc-dev pkg-config libssl-dev php-redis 

    /opt/bitnami/php/bin/pecl install redis 
    echo "extension=redis.so" > /opt/bitnami/php/etc/conf.d/redis.ini 
fi 

exec /opt/bitnami/scripts/moodle/entrypoint.sh \
     /opt/bitnami/scripts/moodle/run.sh
