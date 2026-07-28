# IzinHukum

Website legaltech **PT Praktisi Izin Hukum** berbasis Laravel 12, Bootstrap 5, MariaDB, dan Docker. Proyek ini disiapkan untuk disimpan di GitHub dan dideploy melalui Coolify.

## Fitur versi awal

- Homepage responsif dengan mega menu.
- Katalog 14 layanan dan seluruh paket harga.
- Label oranye **Harga Perkiraan** untuk harga yang belum final.
- Form permintaan proposal yang tersimpan ke database.
- Halaman cek KBLI dengan struktur database dan data contoh awal.
- Dashboard admin untuk melihat lead, mengubah status, harga, harga coret, dan label perkiraan.
- Sitemap, robots.txt, health check, konfigurasi Nginx/PHP-FPM, dan container MariaDB.

## Identitas perusahaan

- **Perusahaan:** PT Praktisi Izin Hukum
- **Alamat:** Jl. Kolonel Ahmad Syam No.189, Kecamatan Jatinangor, Kabupaten Sumedang, Provinsi Jawa Barat
- **Telepon:** 0895-4154-36593
- **Email:** izinhukum@gmail.com
- **Rekening resmi:** BCA 7405175363 a.n. Muhamad Sofyan AR

Data tersebut dapat diubah melalui environment variables tanpa mengedit kode.

## Menjalankan dengan Docker

1. Salin file environment:

   ```bash
   cp .env.docker.example .env
   ```

2. Buat `APP_KEY`:

   ```bash
   printf 'base64:%s\n' "$(openssl rand -base64 32)"
   ```

   Salin hasilnya ke `APP_KEY` pada `.env`.

3. Ganti seluruh password contoh, lalu jalankan:

   ```bash
   docker compose up -d --build
   ```

4. Buka `http://localhost:8080`. Admin tersedia di `/admin/masuk`.

5. Setelah data awal berhasil dibuat, ubah `SEED_DATABASE=false`. Hal ini mencegah harga yang diubah melalui admin tertimpa oleh seeder pada deployment berikutnya.

## Deploy melalui Coolify

1. Buat repository GitHub baru dan unggah seluruh isi folder ini.
2. Di Coolify, pilih **New Resource → Docker Compose** lalu hubungkan repository.
3. Masukkan environment variables berdasarkan `.env.docker.example`.
   Untuk domain HTTPS, set `APP_URL=https://izinhukum.com` dan `SESSION_SECURE_COOKIE=true`.
4. Gunakan domain `izinhukum.com` dan arahkan ke service `app` port `8080`.
5. Pada deployment pertama, set `SEED_DATABASE=true`.
6. Pastikan halaman publik, `/up`, dan `/admin/masuk` dapat dibuka.
7. Ubah `SEED_DATABASE=false`, lalu redeploy.

MariaDB dan folder storage menggunakan persistent volume. Jangan menghapus volume saat melakukan redeploy.

## Pengembangan lokal tanpa Docker

Persyaratan: PHP 8.2+, Composer 2, Node.js 22+, dan MySQL/MariaDB atau SQLite.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Untuk SQLite, ubah `.env` menjadi:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/path/absolut/ke/database/database.sqlite
```

## Catatan data KBLI

Tabel KBLI pada versi ini baru berisi data demonstrasi. Sebelum fitur dipublikasikan sebagai rujukan operasional, impor dataset KBLI yang lengkap dan verifikasi tingkat risiko serta perizinannya terhadap OSS dan peraturan terbaru.

## Pengujian

```bash
php artisan test
npm run build
```
