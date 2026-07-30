# IzinHukum

Website legaltech **PT Praktisi Izin Hukum** berbasis Laravel 12, Bootstrap 5, MariaDB, dan Docker. Proyek ini disiapkan untuk disimpan di GitHub dan dideploy melalui Coolify.

## Fitur

- Homepage responsif dengan mega menu.
- Katalog 14 layanan dan seluruh paket harga.
- Label oranye **Harga Perkiraan** untuk harga yang belum final.
- Form permintaan proposal yang tersimpan ke database.
- Pencarian 1.559 kode resmi KBLI 2025.
- Detail risiko OSS-RBA per ruang lingkup dan skala usaha, termasuk perizinan, persyaratan, kewajiban, kewenangan, dan jangka waktu.
- Dashboard admin fokus untuk pesanan, tenggat, pembayaran, dan mitra.
- Harga tiga tingkat: website, minimum end user, dan Mitra LegaOne.
- Portal mitra dengan aktivasi akun, katalog harga khusus, profil, dan invoice end user.
- Invoice untuk mitra/end user, email transaksi, tautan publik, dan tampilan cetak/PDF.
- Branding dokumen terpusat untuk logo, kontak, rekening, tanda tangan, dan stempel.
- Pembayaran sebagian/penuh dengan status invoice otomatis dan kwitansi unik.
- Laporan keuangan operasional basis kas: pemasukan, pengeluaran, kategori, piutang, arus kas, cetak, dan CSV.
- LMS internal dengan video YouTube tertanam, PDF privat, progres belajar, dan sertifikat khusus.
- Bank konten untuk materi iklan dan promosi mitra.
- Pendaftaran kemitraan dengan alur persetujuan admin.
- Tiga paket mitra, tautan referral, atribusi proposal, dan komisi berbasis pembayaran aktif.
- Rekonsiliasi otomatis invoice lama berstatus lunas tanpa kwitansi.
- Notifikasi pesanan baru ke WhatsApp dan email admin melalui antrean terpisah.
- Pengaturan SMTP Mailketing dan sender untuk email transaksi.
- Pelacakan status permintaan, kebijakan privasi, dan syarat layanan.
- Sitemap, robots.txt, health check, konfigurasi Nginx/PHP-FPM, dan container MariaDB.

WhatsApp CRM, inbox, campaign, sequence, autoresponder, webhook masuk, community, dan inbox internal dinonaktifkan pada mode fokus. Tabel lama tidak dihapus agar pembaruan aman dan dapat diaudit.

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
2. Di Coolify, hubungkan repository menggunakan resource Dockerfile atau Docker Compose yang sudah dikonfigurasi.
3. Masukkan environment variables berdasarkan `.env.docker.example`.
   Untuk domain HTTPS, set `APP_URL=https://izinhukum.com` dan `SESSION_SECURE_COOKIE=true`.
4. Gunakan domain `izinhukum.com` dan arahkan ke service `app` port `8080`.
5. Pada deployment pertama, set `SEED_DATABASE=true`.
6. Pastikan halaman publik, `/up`, dan `/admin/masuk` dapat dibuka.
7. Ubah `SEED_DATABASE=false`, lalu redeploy.

### Memasang pembaruan mode fokus V13

Untuk website yang sudah berjalan, pertahankan:

```dotenv
SEED_DATABASE=false
```

Commit pembaruan lalu lakukan satu kali redeploy. Entrypoint menjalankan migrasi mode fokus, rekonsiliasi keuangan, backfill order, dan `php artisan kbli:ensure` secara otomatis. Migrasi tidak menghapus data CRM lama, tidak menjalankan ulang `ServiceSeeder`, dan tidak menimpa harga admin.

Environment minimal untuk notifikasi:

```dotenv
ORDER_NOTIFICATION_EMAIL_ENABLED=true
ORDER_NOTIFICATION_EMAIL=operasional@izinhukum.com
ORDER_NOTIFICATION_WHATSAPP_ENABLED=true
ORDER_NOTIFICATION_WHATSAPP=628xxxxxxxxxx

STARSENDER_ENABLED=true
STARSENDER_TRANSACTION_DEVICE_KEY=isi-device-api-key
QUEUE_CONNECTION=database
```

`ORDER_NOTIFICATION_EMAIL` dapat dikosongkan untuk memakai `ADMIN_EMAIL`. `ORDER_NOTIFICATION_WHATSAPP` dapat dikosongkan untuk memakai `COMPANY_WHATSAPP`. Gateway satu arah tidak memerlukan account API key atau webhook secret StarSender.

Sesudah login, buka **Notifikasi email** untuk menyimpan kredensial SMTP, memastikan sender berstatus approved, dan mengirim email tes.

### Memasang integrasi referral dan keuangan V8

1. Cadangkan database SQLite.
2. Pastikan `RUN_MIGRATIONS=true` dan `SEED_DATABASE=false`.
3. Deploy source terbaru.
4. Startup menjalankan migrasi dan `finance:reconcile-legacy-paid-invoices`.
5. Baca `PETUNJUK-UPGRADE-REFERRAL-KEUANGAN-V8.md` untuk pengujian referral, invoice, pembayaran, laporan, dan komisi.

Password SMTP disimpan terenkripsi menggunakan `APP_KEY`. Jangan mengganti `APP_KEY` setelah password SMTP tersimpan.

MariaDB, folder storage privat, dan unggahan publik menggunakan persistent volume. Jangan menghapus volume saat melakukan redeploy.

## Pengembangan lokal tanpa Docker

Persyaratan: PHP 8.4+, Composer 2, Node.js 22+, dan MySQL/MariaDB atau SQLite.

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

Dataset memuat 1.559 kelompok KBLI 2025 dari Peraturan BPS Nomor 7 Tahun 2025/publikasi KBLI 2025 dan profil risiko publik OSS-RBA berdasarkan PP Nomor 28 Tahun 2025. Data menyimpan tanggal pembaruan dan URL detail OSS pada setiap kode.

Untuk memeriksa dan memperbaiki dataset pada server, jalankan:

```bash
php artisan kbli:ensure
```

Perintah tersebut tidak mengubah data jika 1.559 kode sudah tersedia. Pada Coolify, pertahankan `SEED_DATABASE=false` setelah pemasangan awal agar data layanan yang pernah diubah admin tidak disemai ulang.

## Modul keuangan dan dokumen

Admin dapat membuka:

- **Logo & branding** untuk identitas invoice, kwitansi, dan sertifikat.
- **Invoice** untuk mencatat pembayaran serta membuat kwitansi.
- **Laporan keuangan** untuk pemasukan lain, pengeluaran, kategori, arus kas, piutang, laporan cetak, dan CSV.

Laporan menggunakan basis kas. Nilai invoice belum dihitung sebagai pemasukan sampai pembayaran dicatat.

## Keamanan file portal

Materi PDF LMS disimpan pada disk privat. File hanya dikirim melalui controller setelah pengguna melewati middleware portal dan pemeriksaan enrollment. Saat deployment, `php artisan portal:secure-files` memindahkan file lama dari disk publik apabila file tersebut masih tersedia.

Skrip `scripts/sync-kbli-2025.mjs` digunakan untuk membangun ulang snapshot dari sumber resmi. Hasil pemeriksaan pada website tetap merupakan informasi awal karena penetapan resmi dipengaruhi data proyek, lokasi, persyaratan dasar, dan ketentuan sektoral pada OSS.

## Pengujian

```bash
php artisan test
npm run build
```

Panduan satu kali redeploy tersedia pada `PETUNJUK-REDEPLOY-V13.md`.
