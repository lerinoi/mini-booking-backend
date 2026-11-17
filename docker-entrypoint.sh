#!/bin/sh
set -eu
if [ ! -f .env ]; then
    cp .env.example .env
fi
composer install --no-interaction --prefer-dist
if ! grep -Eq '^APP_KEY=.+$' .env; then
    php artisan key:generate --no-interaction
fi
mkdir -p database storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
touch database/database.sqlite
php artisan migrate --force --no-interaction
exec php artisan serve --host=0.0.0.0 --port=8000
