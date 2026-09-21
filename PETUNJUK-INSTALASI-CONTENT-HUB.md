# Instalasi Content & StarSender Hub

Dokumen ini untuk pemasangan manual di `app.izinhukum.com`. Integrasi memakai satu nomor/perangkat StarSender dan tidak memakai API STIFIn.

## Persyaratan server

- PHP 8.4 atau lebih baru beserta ekstensi Laravel umum
- Composer 2, Node.js 22, MySQL/MariaDB, dan HTTPS aktif
- Satu worker queue yang selalu berjalan
- Cron Laravel setiap menit

## 1. Unggah source

Ekstrak ZIP ke folder aplikasi. Arahkan document root subdomain ke folder `public`, bukan ke root source. Pastikan folder `storage` dan `bootstrap/cache` dapat ditulis oleh user web server.

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
npm ci
npm run build
php artisan storage:link
```

## 2. Konfigurasi `.env`

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.izinhukum.com
CONTENT_HUB_DOMAIN=app.izinhukum.com
SESSION_DOMAIN=.izinhukum.com

STARSENDER_ENABLED=true
STARSENDER_BASE_URL=https://api.starsender.online
STARSENDER_DEVICE_KEY=isi-device-api-key-satu-nomor
STARSENDER_ACCOUNT_API_KEY=
STARSENDER_WEBHOOK_SECRET=ganti-dengan-rahasia-panjang
QUEUE_CONNECTION=database
```

Pengiriman personal, grup, dan broadcast memakai `STARSENDER_DEVICE_KEY` yang sama. Isi `STARSENDER_ACCOUNT_API_KEY` bila ingin sinkronisasi status lanjutan dan alat akun provider; pengiriman dasar tetap memakai satu device key.

## 3. Database dan cache

Cadangkan database, lalu jalankan:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

User admin yang sudah ada otomatis menjadi `owner`. Setelah login, owner dapat membuat pengguna lain melalui **Pengaturan & Sistem → Pengguna tim**.

## 4. Worker dan scheduler

Jalankan worker melalui Supervisor/systemd:

```bash
php artisan queue:work --queue=whatsapp,default --sleep=2 --tries=1 --timeout=120
```

Tambahkan cron:

```cron
* * * * * cd /path/ke/aplikasi && php artisan schedule:run >> /dev/null 2>&1
```

## 5. Webhook StarSender

Masukkan URL berikut di dashboard StarSender:

```text
https://app.izinhukum.com/webhooks/starsender/NILAI_STARSENDER_WEBHOOK_SECRET
```

## 6. Pemeriksaan setelah unggah

1. Buka `/healthz` dan pastikan respons sukses.
2. Login, lalu buka **Pengaturan utama** dan kirim pesan uji.
3. Sinkronkan grup.
4. Tambahkan satu konten berstatus **Siap dipakai**.
5. Kirim pesan personal ke nomor sendiri.
6. Buat broadcast kecil ke kontak yang memiliki consent promosi.
7. Pastikan status muncul di **Riwayat pengiriman**.

Jangan mengaktifkan worker dari dua rilis/folder berbeda secara bersamaan. Itu dapat membuat antrean diproses oleh kode versi lama dan baru.
