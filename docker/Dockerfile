# PHP 8.5.12 is the minimum safe runtime. It fixes CVE-2026-109618 (RCE via OOM
# corruption) and CVE-2026-109619 (RCE via stale global state), both CVSS 9.2 and
# both reachable without authentication. See docs/src/content/docs/reference/security-advisories.mdx.
ARG PHP_VERSION=8.5.12
ARG FRANKENPHP_VERSION=1.13
ARG COMPOSER_VERSION=2.8
ARG APP_NAME=OpenSinop
ARG APP_ENV=production
ARG APP_DEBUG=false
ARG APP_URL=http://localhost:8000

# -----------------------------------------------------------------------------
# Stage 1: Install PHP Dependencies
# -----------------------------------------------------------------------------
FROM composer:${COMPOSER_VERSION} AS vendor
ENV COMPOSER_IGNORE_PLATFORM_REQS=1
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-autoloader \
    --no-ansi \
    --no-scripts \
    --no-progress \
    --ignore-platform-reqs

COPY . .
RUN rm -rf bootstrap/cache/* database/*.sqlite* *.sqlite* \
    && mkdir -p \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts --ignore-platform-reqs

# -----------------------------------------------------------------------------
# Stage 2: Build Frontend Assets
# -----------------------------------------------------------------------------
FROM oven/bun:1 AS build-assets
ENV COMPOSER_IGNORE_PLATFORM_REQS=1
WORKDIR /app

# Install PHP for Wayfinder type generation
RUN apt-get update && apt-get install -y --no-install-recommends \
    php-cli \
    php-xml \
    php-mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY package.json package-lock.json ./
RUN bun install --no-save
COPY . .
RUN rm -rf bootstrap/cache/* database/*.sqlite* *.sqlite*

# Copy vendor directory for Wayfinder and other Laravel packages
COPY --from=vendor /app/vendor ./vendor

# Copy necessary Laravel files for Artisan commands
COPY --from=vendor /app/bootstrap ./bootstrap
COPY --from=vendor /app/storage ./storage

# Ensure platform check cannot block asset generation CLI if present
RUN if [ -f ./vendor/composer/platform_check.php ]; then echo "<?php" > ./vendor/composer/platform_check.php; fi

RUN bun run build

# -----------------------------------------------------------------------------
# Stage 3: Final Runtime Image
# -----------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}

LABEL maintainer="Yukazakiri <yukazaki@koamishin.org>"
LABEL org.opencontainers.image.title="OpenSinop Docker Setup"
LABEL org.opencontainers.image.description="Production-ready Docker Setup for OpenSinop"
LABEL org.opencontainers.image.source=https://github.com/koamishin/OpenSinop
LABEL org.opencontainers.image.licenses=MIT

ARG USER_ID=1000
ARG GROUP_ID=1000
ARG ROOT=/app
ENV ROOT=/app
ARG TZ=UTC

ARG APP_NAME=OpenSinop
ARG APP_ENV=production
ARG APP_DEBUG=false
ARG APP_URL=http://localhost:8000

ENV APP_NAME=${APP_NAME}
ENV APP_ENV=${APP_ENV}
ENV APP_DEBUG=${APP_DEBUG}
ENV APP_URL=${APP_URL}
ENV CONTAINER_MODE=http
ENV DB_CONNECTION=sqlite
ENV DB_DATABASE=${ROOT}/storage/database.sqlite

ENV TERM=xterm-color
ENV OCTANE_SERVER=frankenphp
ENV TZ=${TZ}
ENV USER=laravel
ENV COMPOSER_FUND=0
ENV COMPOSER_MAX_PARALLEL_HTTP=48
ENV XDG_CONFIG_HOME=${ROOT}/.config
ENV XDG_DATA_HOME=${ROOT}/.data

WORKDIR ${ROOT}

# Install system dependencies and Google Chrome Stable
RUN apt-get update && apt-get install -y --no-install-recommends \
    gnupg \
    curl \
    ca-certificates \
    zip \
    unzip \
    git \
    supervisor \
    libcap2-bin \
    libpng-dev \
    libzip-dev \
    libicu-dev \
    libpq-dev \
    sqlite3 && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN install-php-extensions \
    apcu \
    bz2 \
    pcntl \
    mbstring \
    bcmath \
    sockets \
    pdo_pgsql \
    pgsql \
    pdo_sqlite \
    sqlite3 \
    pdo_mysql \
    opcache \
    exif \
    zip \
    intl \
    gd \
    redis \
    soap

# Install Supercronic
RUN curl -fsSLO "https://github.com/aptible/supercronic/releases/download/v0.2.33/supercronic-linux-amd64" \
    && chmod +x supercronic-linux-amd64 \
    && mv supercronic-linux-amd64 /usr/local/bin/supercronic \
    && mkdir -p /etc/supercronic \
    && echo "*/1 * * * * php ${ROOT}/artisan schedule:run --no-interaction" > /etc/supercronic/laravel

# PHP Config
RUN cp ${PHP_INI_DIR}/php.ini-production ${PHP_INI_DIR}/php.ini
COPY docker/php.ini ${PHP_INI_DIR}/conf.d/99-custom.ini

# Copy Supervisor configs
COPY --link docker/supervisord.conf /etc/supervisord.conf
COPY --link docker/supervisord.frankenphp.conf /etc/supervisor/conf.d/
COPY --link docker/supervisord.horizon.conf /etc/supervisor/conf.d/
COPY --link docker/supervisord.scheduler.conf /etc/supervisor/conf.d/
COPY --link docker/supervisord.pulse.conf /etc/supervisor/conf.d/
COPY --link docker/supervisord.worker.conf /etc/supervisor/conf.d/

# Copy scripts
COPY --link docker/start-container /usr/local/bin/start-container
COPY --link docker/healthcheck /usr/local/bin/healthcheck
RUN chmod +x /usr/local/bin/start-container /usr/local/bin/healthcheck

# Create user and directories
RUN groupadd --force -g ${GROUP_ID} ${USER} \
    && useradd -ms /bin/bash --no-user-group -g ${GROUP_ID} -u ${USER_ID} ${USER} \
    && rm -rf /var/www/html \
    && ln -s ${ROOT} /var/www/html \
    && mkdir -p \
    database \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/framework/testing \
    storage/logs \
    storage/app/public \
    bootstrap/cache \
    .config \
    .data \
    /tmp/opcache-file-cache

# Copy application code from build stages
COPY --chown=${USER}:${USER} . .
RUN rm -rf bootstrap/cache/* database/*.sqlite* *.sqlite*
COPY --chown=${USER}:${USER} --from=vendor /app/vendor ./vendor
COPY --chown=${USER}:${USER} --from=build-assets /app/public/build ./public/build

# Set ownership
RUN chown -R ${USER}:${USER} ${ROOT}/storage ${ROOT}/bootstrap/cache ${ROOT}/database ${ROOT}/.config ${ROOT}/.data \
    && chmod -R 777 /tmp/opcache-file-cache \
    && chmod -R 775 ${ROOT}/database ${ROOT}/storage ${ROOT}/bootstrap/cache

# Allow FrankenPHP to bind to port 80 and 443 (if needed) and run as non-root
RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp

USER root

EXPOSE 8000
EXPOSE 2019

ENTRYPOINT ["start-container"]

HEALTHCHECK --start-period=60s --interval=10s --timeout=5s --retries=12 CMD healthcheck || exit 1
