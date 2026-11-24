# Update Kantor Virtual Cabang

Pembaruan ini menambahkan pusat navigasi operasional dan registrasi tes lokal. Modul voucher, checkout, kajian dan transaksi lama tetap digunakan.

## Pemasangan pada instalasi yang sudah aktif

1. Cadangkan database dan volume aplikasi. Jangan menjalankan ulang installer.
2. Ekstrak paket update. Salin isinya ke root repositori (`index.php`, `Dockerfile`, `pages/`, `scripts/`, bukan subfolder `stifincabang-main/`). Commit pada branch `main`. Pastikan GitHub menampilkan SHA baru, bukan `7361c753d892f1540502855e50e2df9f4cb0629f`.
3. Redeploy SHA baru melalui Coolify. Build memeriksa sintaks PHP; entrypoint menjalankan migrasi pada instalasi aktif. Jika migrasi gagal, periksa log, lalu jalankan `php /var/www/html/scripts/setup-stifin-office.php` dari terminal container aplikasi setelah masalah database diperbaiki (aman jika diulang).
4. Pastikan `STIFIN_BRANCH_CODE` sesuai kode cabang resmi. Tautkan promotor melalui menu Promotor Cabang.
5. Login sebagai admin; buka `/admin/kantor-cabang`, `/admin/peserta-tes`, `/admin/laporan-cabang`. Login sebagai promotor tertaut; buka `/member/stifin-saya`.

## Uji penerimaan (staging)

- Dashboard lama, katalog, transaksi, kajian dan voucher masih terbuka.
- Admin dapat menambah peserta, memilih promotor tertaut, menentukan jadwal, mengubah status dan mencatat referensi hasil resmi.
- Catatan selesai memerlukan referensi; jadwal terjadwal memerlukan tanggal.
- Pengguna non-admin tidak dapat membuka kedua halaman baru.
- Ringkasan berubah mengikuti catatan; tanpa konfigurasi/migrasi, nilai tidak ditampilkan sebagai nol palsu.
- Jalankan pengujian checkout dan voucher dengan sandbox penyedia sebelum mengaktifkan transaksi nyata.

Data tes yang ditambahkan bersifat catatan internal; tidak menyinkronkan dan tidak menerbitkan hasil dari STIFIn Pusat. Integrasi tersebut membutuhkan dokumentasi API dan akses resmi yang sesuai. Plugin WordPress yang disediakan berfokus pada voucher, bukan seluruh modul portal Cabang.
