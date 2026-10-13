# IzinHukum 23.0.0 Simple

Alur klien: katalog → detail layanan/persyaratan → pilih paket → isi data → pesanan awal tercatat → chat WhatsApp → kesepakatan manual → admin membuat invoice → pembayaran diverifikasi/dicatat → kwitansi.

Admin: Ringkasan, Pesanan, Invoice & pembayaran, Keuangan, Paket & harga, Detail layanan, Logo & identitas, Profil.

CRM/AI/gateway WhatsApp, campaign, kupon, mitra/referral/LMS, tools, dan portal pelanggan tidak mempunyai route aktif. Tidak ada queue worker atau scheduler otomatis. Kode historis/model/migrasi dipertahankan untuk kompatibilitas database lama; ini bukan penghapusan total seluruh kode historis.

Pembayaran invoice masuk laporan kas hanya setelah dicatat admin. Jangan catat pembayaran yang sama lagi sebagai pemasukan manual. Pembayaran kepada notaris langsung bukan kas IzinHukum; pilih penerima yang sesuai.

## Upload GitHub dan Coolify
1. Backup database dan file upload dari deployment lama. Simpan APP_KEY lama. Jangan mengganti key saat upgrade.
2. Ekstrak ZIP; unggah isi folder ke root repository GitHub, bukan ZIP-nya. Pilih semua file, termasuk dotfiles. Jangan unggah .env, vendor, atau node_modules.
3. Coolify: gunakan Docker Compose untuk file docker-compose.yml; aplikasi mendengarkan port 8080. Pakai database/volume deployment lama saat upgrade. Jangan membuat database kosong atau volume baru secara tidak sengaja.
4. Isi APP_KEY, ADMIN_EMAIL, ADMIN_PASSWORD, DB_PASSWORD, DB_ROOT_PASSWORD. Untuk instalasi baru buat key: openssl rand -base64 32, lalu awali dengan base64:.
5. Isi APP_URL=https://izinhukum.com. Gunakan SESSION_SECURE_COOKIE=true dan TRUSTED_PROXIES sesuai reverse proxy Coolify.
6. Jika menggunakan admin.izinhukum.com, arahkan DNS kedua host ke server dan daftarkan keduanya di Coolify pada aplikasi yang sama. Isi PUBLIC_HOST=izinhukum.com dan ADMIN_HOST=admin.izinhukum.com. Panel berada di https://admin.izinhukum.com/admin/masuk. Kedua host harus cocok persis. Jika belum memakai pemisahan host, kosongkan kedua variabel.
7. RUN_MIGRATIONS=true. SEED_DATABASE=false saat upgrade; true hanya saat instalasi baru. Setelah instalasi awal, ubah kembali false. Volume storage, uploads, dan MariaDB wajib persisten.
8. Redeploy. Periksa /healthz dan log migration, kemudian jalankan smoke test di bawah.

## Validasi sebelum produksi
- PHP 8.4, composer install, npm ci, npm run build.
- php artisan test --testsuite=Simple
- php artisan test --filter='FinancialReportTest|PaymentReceiptTest|InvoiceSchemaCompatibilityTest|FlexiblePaymentFlowTest'
- php artisan route:list dan php artisan view:cache.
- Buka beranda/detail/checkout pada mobile; kirim pesanan dan pastikan hanya satu order dibuat tanpa invoice otomatis.
- Buka panel, buat invoice dari order, bagikan WhatsApp, catat DP/pelunasan, periksa kwitansi dan laporan/CSV.
- Redeploy ulang dan pastikan data serta file upload tetap ada.

## Hasil verifikasi paket
- 21 tes aktif lulus, 118 assertions: checkout, paket nonaktif, nomor invalid, pembatasan fitur dan host, tampilan halaman inti, invoice, pembayaran dan laporan keuangan.
- Frontend Vite berhasil dibuild; Blade dan route berhasil dicache; composer.json/lock valid.
- Tes dijalankan pada PHP 8.3.6 sementara. Target deployment tetap PHP 8.4 dan composer.lock memakai persyaratan tersebut. Docker build/PHP 8.4 belum diuji lokal; CI memakai PHP 8.4.
- Tes modul yang dilepas disimpan di tests/Legacy dan tidak termasuk suite aktif. Jalankan php artisan test untuk seluruh suite produk sederhana.
- Model/migrasi historis tetap ada. Komisi baru tidak dibuat; kewajiban komisi yang sudah ada tetap direkonsiliasi ketika pembayaran historis dikoreksi/dibatalkan. Invoice historis dengan mitra/referral/kupon tidak dapat diedit; pembayaran tetap dapat dicatat.
- Paket tidak berisi .env, vendor atau node_modules. Instalasi dependency dilakukan saat build.
