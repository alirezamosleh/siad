FROM docker.arvancloud.ir/php:8.2-fpm

# Install system libraries needed by Moodle extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libxslt1-dev \
    zlib1g-dev \
    ghostscript \
    poppler-utils \
    graphviz \
    aspell \
    procps \
    && rm -rf /var/lib/apt/lists/*

# Configure & install PHP extensions for Moodle 4.5+
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        intl \
        zip \
        soap \
        xsl \
        mysqli \
        pdo_mysql \
        opcache \
        exif \
        bcmath

# Install Redis extension via PECL
RUN pecl install redis-6.0.2 && docker-php-ext-enable redis

# Set working directory to standard web path
WORKDIR /var/www/html

# Copy custom configs
COPY php.ini /usr/local/etc/php/conf.d/moodle.ini
COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY www.conf /usr/local/etc/php-fpm.d/www.conf
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
