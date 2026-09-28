# AIDataPlatform — Laravel image
# Dev/prod parity: PHP 8.3-FPM base. For prod PHP 8.4, change first line to:
#   FROM php:8.4-fpm-alpine
FROM php:8.3-fpm-alpine

LABEL maintainer="AIDataPlatform" \
      org.opencontainers.image.source="aidataplatform-laravel"

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PHP_UPLOAD_MAX_FILESIZE=500M \
    PHP_POST_MAX_SIZE=550M \
    PHP_MEMORY_LIMIT=1G

# System deps + PHP extensions (pdo_pgsql, pgsql, redis, zip, gd, intl, bcmath)
RUN apk add --no-cache \
      bash curl git unzip icu-dev libzip-dev postgresql-dev redis \
      freetype-dev libjpeg-turbo-dev libpng-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
      pdo pdo_pgsql pgsql bcmath intl zip gd opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis opcache \
    && rm -rf /tmp/pear \
    # Composer
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    # PHP upload limits for 500M datasets
    && printf "upload_max_filesize=%s\npost_max_size=%s\nmemory_limit=%s\nmax_execution_time=300\n" \
         "$PHP_UPLOAD_MAX_FILESIZE" "$PHP_POST_MAX_SIZE" "$PHP_MEMORY_LIMIT" \
         > /usr/local/etc/php/conf.d/uploads.ini \
    && apk del --no-cache ${BUILD_DEPS:-} || true

WORKDIR /var/www/html

# Install PHP deps first (better layer cache). application/ is mounted over this in compose,
# so this mainly bakes vendor/ into the image for prod copies without bind-mount.
COPY application/composer.json application/composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader || true

# Copy app source
COPY application/ /var/www/html/

# Permissions for storage/bootstrap cache
RUN addgroup -S www-data 2>/dev/null || true \
    && chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true \
    && chmod -R 775 storage bootstrap/cache 2>/dev/null || true \
    && composer dump-autoload --optimize || true

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --retries=5 --start-period=30s \
  CMD curl -fsS http://localhost:8000/up || curl -fsS http://localhost:8000/health || exit 1

# Serve via artisan (simple + nginx-compatible). For high-throughput prod, swap to
# php-fpm + dedicated nginx fastcgi block (see docs/deployment.md).
ENTRYPOINT ["sh", "-c", "php artisan storage:link 2>/dev/null || true; php artisan migrate --force 2>/dev/null || true; exec php artisan serve --host=0.0.0.0 --port=8000"]
