# IzinHukum V12.0.2 Hotfix

Hotfix kumulatif untuk V12.0.0/V12.0.1:

1. Mengembalikan nama tabel eksplisit `whatsapp_messages` pada model `WhatsAppMessage`.
2. Memulihkan izin tulis Laravel setelah startup container.
3. Menjalankan queue worker dan scheduler sebagai `www-data`.
4. Mengubah versi healthcheck menjadi `12.0.2`.

## Penyebab 500

Laravel mengubah nama model `WhatsAppMessage` menjadi tabel `whats_app_messages` bila properti `$table` tidak ditentukan. Database production menggunakan tabel `whatsapp_messages`.

## Instalasi permanen

Salin seluruh isi patch ke root repository, overwrite file lama, lalu commit dan push:

```bash
git add .
git commit -m "V12.0.2 fix WhatsApp table and runtime permissions"
git push origin main
```

Redeploy satu kali melalui Coolify.

## Sesudah deployment

```bash
cd /var/www/html
su -s /bin/sh www-data -c 'php artisan optimize:clear'
su -s /bin/sh www-data -c 'php artisan whatsapp:diagnose'
php artisan tinker --execute='echo (new \\App\\Models\\WhatsAppMessage)->getTable().PHP_EOL;'
```

Hasil pemeriksaan tabel harus:

```text
whatsapp_messages
```
