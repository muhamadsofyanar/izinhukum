# Redeploy IzinHukum V16 Sekali Jalan

V16 menggabungkan lima tahap pengembangan ke dalam satu source, satu migrasi, dan satu redeploy. Setelah itu setiap modul dapat dinyalakan/dimatikan melalui **Admin → Fitur aplikasi** tanpa redeploy.

## Sebelum redeploy

1. Cadangkan database dan persistent volume `storage`.
2. Jangan mengganti `APP_KEY`.
3. Untuk instalasi yang sudah berjalan, pertahankan `SEED_DATABASE=false` agar harga/data admin tidak ditimpa.
4. Pastikan `RUN_MIGRATIONS=true`.
5. Unggah seluruh isi paket V16 ke repository yang digunakan Coolify.

Tidak ada environment variable baru yang wajib. Rekening, email, StarSender, queue, referral, dan storage menggunakan konfigurasi yang sudah ada.

## Satu kali redeploy

Klik **Redeploy** satu kali sesudah commit V16 terlihat di Coolify. Entrypoint otomatis menjalankan `php artisan migrate --force`, rekonsiliasi keuangan, backfill order, pemeriksaan KBLI, pengamanan file, dan cache produksi.

Migrasi V16:

- menambah kolom UTM pada inquiry;
- membuat tabel penawaran, item penawaran, dan bukti pembayaran;
- memasukkan inquiry lama ke pipeline bila belum ada;
- menambahkan serta mengaktifkan lima feature flag baru;
- tidak menghapus data lama dan tidak menjalankan ulang seeder katalog.

## Pemeriksaan setelah deploy

1. Buka `/healthz`; pastikan `status=healthy` dan `version=16.0.0`.
2. Masuk admin dan pastikan menu **Permintaan masuk**, **Pipeline penjualan**, **Penawaran digital**, serta **Analitik pertumbuhan** tampil.
3. Buka **Fitur aplikasi** dan pastikan lima modul V16 aktif. Matikan modul yang belum ingin diumumkan—tidak perlu redeploy.
4. Buat URL di **Analitik pertumbuhan**, buka tautan tersebut, lalu kirim proposal uji. Pastikan campaign muncul pada pipeline/analitik.
5. Dari lead proposal, buat dan terbitkan penawaran. Buka tautan publik dan setujui; pastikan satu invoice otomatis dibuat.
6. Dari invoice publik, unggah bukti transfer uji. Di detail invoice admin, unduh lalu setujui; pastikan pembayaran, status invoice, dan tautan kwitansi muncul.
7. Batalkan/hapus data uji yang memang dapat dibatalkan. Jangan menghapus volume database atau storage.

## Jika ingin peluncuran bertahap

Deploy source lengkap sekali. Sesudah deploy, gunakan feature flags untuk menutup atau membuka `campaign_tracking`, `sales_pipeline`, `digital_quotes`, `payment_proof_upload`, dan `growth_analytics`. Menonaktifkan fitur hanya menutup rute/menu; histori data tidak dihapus.

## Rollback source

Utamakan mematikan feature flags sebelum rollback source. Jangan menjalankan `migrate:rollback` di produksi tanpa cadangan karena rollback menghapus tabel penawaran/bukti pembayaran. Backfill pipeline sengaja tidak dihapus pada rollback agar histori lead tetap aman.
