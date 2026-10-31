FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libonig-dev libicu-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql mbstring intl zip pcntl bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/development.ini /usr/local/etc/php/conf.d/ged.ini
WORKDIR /var/www/html
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
