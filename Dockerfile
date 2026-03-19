FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --prefer-dist

FROM php:8.3-cli-alpine AS runtime

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    curl \
    git \
    libzip-dev \
    oniguruma-dev \
    postgresql-dev \
    sqlite-dev \
    unzip \
    zip \
    && docker-php-ext-install \
    bcmath \
    mbstring \
    pcntl \
    pdo \
    pdo_pgsql \
    pdo_sqlite \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN cp .env.example .env \
    && php artisan key:generate --force \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && rm -f .env \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
