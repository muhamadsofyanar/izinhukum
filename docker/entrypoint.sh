#!/bin/sh
set -eu

cd /var/www/html

# A persistent volume may be mounted on /var/www/html/database. Docker only
# populates a named volume on its first creation, so later image deployments
# would otherwise keep stale migration/seeder files. Refresh application-owned
# database code while preserving the persistent database.sqlite file.
mkdir -p database/migrations database/seeders database/data database/uploads
cp -R /opt/izinhukum-database/migrations/. database/migrations/
cp -R /opt/izinhukum-database/seeders/. database/seeders/
cp -R /opt/izinhukum-database/data/. database/data/

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache database

if [ ! -L public/storage ]; then
    php artisan storage:link || true
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY wajib diisi. Buat dengan: printf 'base64:%s\\n' \"\$(openssl rand -base64 32)\""
    exit 1
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${SEED_DATABASE:-false}" = "true" ]; then
    php artisan db:seed --force
elif [ "${SEED_KBLI_DATABASE:-false}" = "true" ]; then
    php artisan db:seed --class='Database\Seeders\KbliSeeder' --force
fi

php artisan optimize

exec "$@"
