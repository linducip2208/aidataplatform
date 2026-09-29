# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — front-end assets.
#
# Vite is compiled here and nowhere else. docker-compose bind-mounts
# ./application over /var/www/html, so anything baked into the app directory is
# invisible at runtime; the build therefore lands in /opt/aidata/vite and
# laravel-entrypoint.sh installs it into public/build on every start.
#
# This stage is the only place Node is needed. It never reaches the runtime
# image, so the PHP container stays small and needs no Node on the host.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /build

# `npm ci` is deliberate: it installs exactly the locked tree and fails rather
# than silently resolving different versions. It cannot run without a lockfile,
# and `npm install` is not an acceptable substitute here because it would make
# the built asset hashes non-reproducible.
RUN test -f application/package-lock.json || ( \
        echo >&2 "FATAL: application/package-lock.json is missing."; \
        echo >&2 "       Run 'npm install' in application/ and commit the lockfile."; \
        echo >&2 "       'npm ci' cannot be swapped for 'npm install' here without losing reproducible builds."; \
        exit 1; \
    )

COPY application/package.json application/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY application/vite.config.js ./
# resources/css/app.css declares @source globs for vendor/ and storage/. Those
# trees are not copied into this stage, so only the rules reachable from
# resources/ end up in the compiled stylesheet.
COPY application/resources/ ./resources/

RUN mkdir -p public \
    && npm run build \
    && test -f public/build/manifest.json \
    || ( \
        echo >&2 "FATAL: vite build produced no public/build/manifest.json."; \
        echo >&2 "       Illuminate\\Foundation\\Vite reads exactly that path, so an image without"; \
        echo >&2 "       it returns HTTP 500 on every page (ViteManifestNotFoundException)."; \
        echo >&2 "       If Vite emitted public/build/.vite/manifest.json instead, laravel-vite-plugin"; \
        echo >&2 "       is not setting build.manifest to 'build/manifest.json' for this version."; \
        exit 1; \
    )

# ---------------------------------------------------------------------------
# Stage 2 — PHP runtime.
# Dev/prod parity: PHP 8.3-FPM base. For prod PHP 8.4, change first line to:
#   FROM php:8.4-fpm-alpine
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine

LABEL maintainer="AIDataPlatform" \
      org.opencontainers.image.source="aidataplatform-laravel"

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    APP_DIR=/var/www/html \
    APP_HOST=0.0.0.0 \
    APP_PORT=8000 \
    VITE_BUILD_DIR=/opt/aidata/vite \
    PHP_UPLOAD_MAX_FILESIZE=500M \
    PHP_POST_MAX_SIZE=550M \
    PHP_MEMORY_LIMIT=1G

# System deps + PHP extensions (pdo_pgsql, pgsql, redis, zip, gd, intl, bcmath).
# The -dev packages are not removed afterwards: postgresql-dev/icu-dev/libzip-dev
# and the gd stack each pull the shared library the loaded extension needs, and
# dropping them would leave the extensions present but unloadable.
#
# $PHPIZE_DEPS is installed explicitly for the pecl step and then removed.
# `docker-php-ext-install` brings it in only for the duration of its own run and
# takes it back out, so `pecl install redis` right after it failed with
# "phpize: not found" and no laravel image could be built at all.
RUN apk add --no-cache \
      bash curl git unzip icu-dev libzip-dev postgresql-dev redis \
      freetype-dev libjpeg-turbo-dev libpng-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
      pdo pdo_pgsql pgsql bcmath intl zip gd opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis opcache \
    && rm -rf /tmp/pear \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    # PHP upload limits for MAX_UPLOAD_MB=500 datasets (see .env.example)
    && printf "upload_max_filesize=%s\npost_max_size=%s\nmemory_limit=%s\nmax_execution_time=300\n" \
         "$PHP_UPLOAD_MAX_FILESIZE" "$PHP_POST_MAX_SIZE" "$PHP_MEMORY_LIMIT" \
         > /usr/local/etc/php/conf.d/uploads.ini \
    # Everything the *runtime* image needs stays; only the build toolchain goes.
    && apk del --no-network $PHPIZE_DEPS

WORKDIR ${APP_DIR}

# Install PHP deps first (better layer cache). docker-compose bind-mounts
# ./application over this directory, so the vendor/ built here is what runs only
# when the image is started without that mount (e.g. `docker run` / CI).
COPY application/composer.json application/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts

COPY application/ ${APP_DIR}/

# The bind-mounted laravel-storage volume hides storage/framework on first boot
# and the root .dockerignore strips storage/framework/{views,cache,sessions}
# contents, so the skeleton has to exist in the image too.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs storage/app/public \
             bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction

COPY --from=assets /build/public/build ${VITE_BUILD_DIR}
COPY infrastructure/docker/laravel-entrypoint.sh /usr/local/bin/laravel-entrypoint.sh
RUN chmod 0755 /usr/local/bin/laravel-entrypoint.sh

EXPOSE 8000

# /up proves the framework booted; the manifest check proves the frontend can
# actually be served; the marker file is written by the entrypoint when
# `artisan migrate --force` fails, so a broken schema never looks healthy.
# start-period covers the Postgres wait plus the first migration run.
HEALTHCHECK --interval=15s --timeout=5s --retries=5 --start-period=60s \
  CMD curl -fsS -o /dev/null http://localhost:8000/up \
      && test -f ${APP_DIR}/public/build/manifest.json \
      && test ! -f ${APP_DIR}/storage/framework/migrate_failed \
      || exit 1

ENTRYPOINT ["laravel-entrypoint.sh"]
