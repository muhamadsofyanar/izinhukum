#!/bin/sh

set -eu

cd /var/www/html

mkdir -p database/migrations database/seeders database/data database/uploads
touch database/database.sqlite
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

fail_config() {
    echo "ERROR: $1"
    exit 1
}

if [ -z "${APP_KEY:-}" ]; then
    fail_config "APP_KEY wajib diisi. Buat dengan: printf 'base64:%s\\n' \"\$(openssl rand -base64 32)\""
fi

case "${APP_KEY}" in
    *GANTI*|*ganti*|*CHANGE*|*change*|*CONTOH*|*contoh*|*EXAMPLE*|*example*)
        fail_config "APP_KEY masih memakai nilai contoh. Ganti sebelum aplikasi dijalankan."
        ;;
esac

if [ "${#APP_KEY}" -lt 32 ]; then
    fail_config "APP_KEY terlalu pendek. Gunakan key Laravel yang valid."
fi

for value in "${ADMIN_PASSWORD:-}" "${DB_PASSWORD:-}"; do
    case "$value" in
        *GANTI*|*ganti*|*CHANGE*|*change*|*CONTOH*|*contoh*|*EXAMPLE*|*example*|password|admin123|12345678)
            fail_config "Terdapat password environment yang masih memakai nilai contoh atau terlalu mudah."
            ;;
    esac
done

php artisan optimize:clear

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
    php artisan finance:reconcile-legacy-paid-invoices --no-interaction
    php artisan app:backfill-orders --no-interaction
fi

if [ "${SEED_DATABASE:-false}" = "true" ]; then
    php artisan db:seed --force
fi

php artisan kbli:ensure
php artisan portal:secure-files
php artisan optimize

exec "$@"
