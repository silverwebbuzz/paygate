# PayGate local development image: FrankenPHP (Caddy + PHP 8.5).
# Used by the app, horizon and scheduler services in compose.yaml.
FROM dunglas/frankenphp:php8.5

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip postgresql-client \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    redis \
    intl \
    bcmath \
    pcntl \
    zip \
    gd \
    exif \
    opcache \
    sockets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node is needed for Vite; the Wayfinder Vite plugin also calls `php artisan`,
# so PHP and Node live in the same image.
COPY --from=node:24-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY php.ini /usr/local/etc/php/conf.d/zz-paygate.ini

# Run as a non-root user whose UID/GID match the host user, so files created
# inside the container (migrations, vendor/, logs) stay owned by you.
RUN groupadd -g ${GID} app 2>/dev/null || true \
    && useradd -m -u ${UID} -g ${GID} -s /bin/bash app \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R ${UID}:${GID} /config/caddy /data/caddy

ENV NPM_CONFIG_UPDATE_NOTIFIER=false

USER app
WORKDIR /app
