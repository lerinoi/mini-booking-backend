#!/bin/sh
set -eu
if [ ! -f .env ]; then
    cp .env.example .env
    if [ "$(id -u)" = 0 ]; then
        chown "$(stat -c '%u:%g' .)" .env
    fi
fi
composer install --no-interaction --prefer-dist
if ! grep -Eq '^APP_KEY=.+$' .env; then
    php artisan key:generate --no-interaction
fi
mkdir -p database storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
    if [ "$(id -u)" = 0 ]; then
        chown "$(stat -c '%u:%g' database)" database/database.sqlite
    fi
fi
php artisan migrate --force --no-interaction
exec php artisan serve --host=0.0.0.0 --port=8000
