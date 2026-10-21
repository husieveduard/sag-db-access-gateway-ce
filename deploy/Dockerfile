# syntax=docker/dockerfile:1.7

FROM composer:2 AS vendor

WORKDIR /app

COPY control-plane/composer.json control-plane/composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY control-plane/ ./

RUN composer dump-autoload \
    --no-dev \
    --optimize \
    --no-scripts


FROM node:22-alpine AS frontend

WORKDIR /app

COPY control-plane/package.json ./

RUN npm install --ignore-scripts

COPY control-plane/ ./

RUN npm run build


FROM golang:1.22-bookworm AS gateway-builder

WORKDIR /src

COPY db-gateway/go.mod ./

RUN go mod download

COPY db-gateway/ ./

RUN go test ./...

RUN CGO_ENABLED=0 go build \
    -trimpath \
    -ldflags="-s -w" \
    -o /out/sag-db-gateway .


FROM php:8.3-fpm-bookworm AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    PHP_OPCACHE_ENABLE=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libonig-dev \
        libldap2-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure ldap --with-libdir=lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH) \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        ldap \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Compile from the upstream phpredis 6.3.0 source at a verified commit.
# PECL download endpoints may return 504, making otherwise healthy builds fail.
# Keep this in a separate Docker layer so PHP core extensions remain cached.
RUN set -eux; \
    for attempt in 1 2 3; do \
        rm -rf /tmp/phpredis; \
        if git clone --quiet --depth 1 --branch 6.3.0 \
            https://github.com/phpredis/phpredis.git /tmp/phpredis; then \
            break; \
        fi; \
        sleep 5; \
    done; \
    test "$(git -C /tmp/phpredis rev-parse HEAD)" = "df4fab2de7fc327c54c94a13af2b9542e4fbd720"; \
    cd /tmp/phpredis; \
    phpize; \
    ./configure --enable-redis; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable redis; \
    php --ri redis; \
    cd /; \
    rm -rf /tmp/phpredis

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY --from=frontend /app/public/build /var/www/html/public/build
COPY --from=gateway-builder /out/sag-db-gateway /usr/local/bin/sag-db-gateway

RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        /var/lib/sag/db-gateway/logs \
    && chown -R www-data:www-data \
        /var/www/html \
        /var/lib/sag

COPY deploy/worker-entrypoint.sh /usr/local/bin/sag-ce-worker

RUN chmod 0755 /usr/local/bin/sag-ce-worker

USER www-data

EXPOSE 9000

CMD ["php-fpm"]


FROM nginx:1.27-alpine AS nginx

COPY --from=runtime /var/www/html/public /var/www/html/public
COPY deploy/nginx/default.conf.template /etc/nginx/templates/default.conf.template

RUN rm -f /etc/nginx/conf.d/default.conf

EXPOSE 80
