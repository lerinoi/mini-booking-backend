FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev libonig-dev libxml2-dev libicu-dev && docker-php-ext-install pdo_sqlite mbstring zip dom xml xmlwriter intl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY . .
EXPOSE 8000
CMD ["sh", "docker-entrypoint.sh"]
