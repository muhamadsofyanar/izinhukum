FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev util-linux \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli mbstring curl gd zip \
    && php -r 'exit(extension_loaded("sodium") ? 0 : 1);' \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*
COPY . /var/www/html/
RUN php -l /var/www/html/index.php \
    && php -l /var/www/html/scripts/process-stifin-background.php \
    && php -l /var/www/html/pages/admin/stifin-office.php \
    && php -l /var/www/html/pages/admin/stifin-participants.php \
    && php -l /var/www/html/pages/admin/stifin-official-reports.php \
    && php -l /var/www/html/helpers/stifin-official-reports.php \
    && php -l /var/www/html/pages/admin/stifin-reports.php \
    && php -l /var/www/html/pages/admin/stifin-promoters.php \
    && php -l /var/www/html/helpers/stifin-voucher-transfers.php \
    && php -l /var/www/html/helpers/stifin-promoters.php \
    && php -l /var/www/html/pages/user/stifin-vouchers.php \
    && php -l /var/www/html/pages/admin/stifin-vouchers.php \
    && php -l /var/www/html/cron/process.php \
    && php -l /var/www/html/pages/user/stifin-office.php \
    && php -l /var/www/html/scripts/setup-stifin-office.php
COPY docker/entrypoint.sh /usr/local/bin/stifin-entrypoint
RUN chmod +x /usr/local/bin/stifin-entrypoint \
    && mkdir -p /var/www/html/assets/uploads /var/www/html/storage/downloads /var/www/html/storage/ticket-attachments /var/www/html/storage/update-tmp /var/www/html/backups /data \
    && chown -R www-data:www-data /var/www/html /data \
    && printf '%s\n' '<Directory /var/www/html>' 'AllowOverride All' 'Require all granted' '</Directory>' > /etc/apache2/conf-available/stifin.conf \
    && a2enconf stifin
WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["stifin-entrypoint"]
CMD ["apache2-foreground"]
