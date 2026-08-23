FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev libzip-dev libmagickwand-dev \
    && docker-php-ext-install bcmath intl pcntl pdo_pgsql zip \
    && pecl install imagick redis \
    && docker-php-ext-enable imagick redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY apps/api ./
RUN composer dump-autoload --optimize

CMD ["php-fpm"]

