#!/bin/sh
set -eu
mkdir -p /data /var/www/html/assets/uploads /var/www/html/storage/downloads /var/www/html/storage/ticket-attachments /var/www/html/storage/update-tmp
ln -sfn /data/config.php /var/www/html/config.php
ln -sfn /data/install.lock /var/www/html/installer/install.lock
chown -h www-data:www-data /var/www/html/config.php /var/www/html/installer/install.lock
chown -R www-data:www-data /data /var/www/html/assets/uploads /var/www/html/storage
install -d -m 0700 -o www-data -g www-data /data/php-sessions
printf '%s\n' 'session.save_path = "/data/php-sessions"' > /usr/local/etc/php/conf.d/darmutiara-sessions.ini
if [ "${1:-}" = apache2-foreground ]; then
    if [ -s /data/config.php ] && [ -f /data/install.lock ]; then
        if ! runuser -u www-data -- /usr/local/bin/php /var/www/html/scripts/setup-stifin-office.php; then
            printf '%s\n' '[STIFIn] Migrasi kantor belum berhasil; periksa database dan log.' >&2
        fi
    fi
    export DARMUTIARA_CONTAINER_CRON=1
    export STIFIN_CONTAINER_VOUCHER_WORKER=1
    run_stifin_worker() {
        while :; do
            if [ -s /data/config.php ] && [ -f /data/install.lock ]; then
                runuser -u www-data -- /usr/local/bin/php /var/www/html/scripts/process-stifin-background.php "$1" ||
                    printf '%s\n' "[STIFIn] Worker $1 gagal." >&2
            fi
            sleep "$2"
        done
    }
    run_stifin_worker voucher 30 &
    run_stifin_worker results 15 &
    (
        while :; do
            if [ -s /data/config.php ] && [ -f /data/install.lock ]; then
                runuser -u www-data -- /usr/local/bin/php /var/www/html/cron/process.php >/dev/null ||
                    printf '%s\n' '[Darmutiara] Proses cron gagal.' >&2
            fi
            sleep 300
        done
    ) &
fi
exec docker-php-entrypoint "$@"
