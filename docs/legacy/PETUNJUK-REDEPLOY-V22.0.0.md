# Redeploy IzinHukum V22.0.0

Rilis ini memisahkan **lead** dari **proyek**, menahan spam/duplikat, mendukung pembayaran fleksibel, serta mencegah halaman invoice gagal total ketika tabel bukti pembayaran belum termigrasi.

## Sebelum deploy

1. Cadangkan database dan folder `storage/app`.
2. Pastikan server memakai PHP 8.4 atau lebih baru.
3. Jangan jalankan seeder pada database produksi (`SEED_DATABASE=false`).

## Perintah deploy

```bash
php artisan down --retry=60
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan app:health-check
php artisan queue:restart
php artisan up
```

`app:health-check` harus menampilkan: `Database, struktur tabel, dan storage privat dalam kondisi siap.` Jika yang muncul adalah tabel/kolom belum tersedia, jangan lanjutkan penggunaan admin sebelum `php artisan migrate --force` berhasil.

## Pemeriksaan singkat

1. Kirim satu form konsultasi. Pastikan data muncul di **Admin → Permintaan**, tetapi belum muncul sebagai proyek.
2. Ubah hasil pemeriksaan menjadi **Terverifikasi**, lalu klik **Jadikan proyek**.
3. Buat invoice dan pilih pola pembayaran serta tujuan dana sesuai penawaran.
4. Catat satu pembayaran ke IzinHukum; pastikan kwitansi tersedia.
5. Catat pembayaran langsung ke notaris; pastikan tidak ada tautan kwitansi IzinHukum dan nilai tersebut tidak masuk kas IzinHukum.
6. Buka `/healthz` dan pastikan status `healthy`, versi `22.0.0`, serta pemeriksaan skema `ok`.

## Aturan operasional

- IzinHukum bukan notaris. Akta dan tindakan kenotariatan tetap dikerjakan notaris mitra.
- Harga jual dapat berbeda per penawaran; biaya notaris, ongkir, kuota, dan biaya lain dicatat terpisah sebagai biaya/pengeluaran.
- Kwitansi IzinHukum hanya untuk uang yang masuk ke IzinHukum. Pembayaran langsung ke notaris memakai bukti dari notaris.
- Proyek tidak dihapus permanen setelah memiliki transaksi. Gunakan status batal dan alasan agar jejak audit tetap ada.
