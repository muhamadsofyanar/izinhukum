# Changelog IzinHukum V10

## Pusat order layanan

- Menambahkan nomor order dan token portal pelanggan.
- Menyatukan proposal, paket, pelanggan, referral, invoice, pembayaran, kwitansi, dokumen, petugas, deadline, checklist, progres, dan aktivitas.
- Membuat order otomatis ketika proposal publik dikirim.
- Membuat order dari seluruh permintaan lama melalui backfill idempoten.
- Menghubungkan invoice lama ke order berdasarkan permintaan asal.
- Menyinkronkan status permintaan dan status order dua arah.
- Menambahkan pencarian, filter, prioritas, deadline, indikator terlambat, dan ringkasan order.
- Menambahkan order manual.

## Keuangan

- Mempertahankan perbaikan V9 untuk invoice, pembayaran, kwitansi, laporan kas, rekonsiliasi invoice lunas lama, komisi, ekspor CSV, dan cetak.
- Menambahkan `service_order_id` pada invoice.
- Menyinkronkan status pembayaran order dari seluruh pembayaran aktif.
- Menampilkan invoice dan kwitansi pada portal pelanggan.
- Mencatat peristiwa invoice dan pembayaran pada jalur referral.

## Referral seluruh layanan

- Menangkap referral pada halaman publik, katalog, halaman layanan, KBLI, artikel, kontak, dan proposal.
- Menyimpan atribusi pada session dan cookie.
- Menggunakan first valid referral selama masa atribusi.
- Mencegah pencatatan referral diri sendiri ketika mitra sedang login.
- Tidak menangkap referral pada admin, portal mitra, invoice publik, kwitansi publik, portal pelanggan, dan healthcheck.
- Mencatat klik, proposal, order, invoice, dan pembayaran sebagai aktivitas referral.
- Menambahkan tautan referral per layanan dan metrik order pada dashboard mitra.

## Portal pelanggan

- Menambahkan portal berbasis token acak 64 karakter.
- Menampilkan status, progres, checklist, deadline, invoice, pembayaran, kwitansi, dokumen, dan riwayat aktivitas.
- Menambahkan unggah dokumen privat maksimal 10 MB.
- Menambahkan catatan pelanggan.
- Menambahkan reset token untuk mencabut tautan lama.
- Menghapus nomor telepon dari query string pelacakan. Pelacakan sekarang memakai POST dan rate limit.

## Admin dan sidebar

- Menjadikan order sebagai ringkasan utama dashboard admin.
- Menambahkan tautan order pada daftar permintaan.
- Menambahkan pencarian permintaan.
- Mengelompokkan sidebar menjadi Penjualan, Keuangan, Mitra, Konten dan bantuan, serta Pengaturan.
- Memperbaiki scroll dan footer sidebar.

## Feature switch

Admin dapat mengaktifkan atau menonaktifkan tanpa redeploy:

- portal pelanggan;
- unggah dokumen pelanggan;
- proposal publik;
- pendaftaran mitra;
- pelacakan referral;
- akademi mitra;
- community mitra;
- inbox mitra;
- artikel publik.

## Stabilitas dan keamanan

- Menambahkan healthcheck JSON untuk database dan storage.
- Mengubah Docker healthcheck ke `/healthz`.
- Menambahkan command `app:health-check`.
- Memvalidasi APP_KEY dan menolak password contoh saat startup.
- Menjalankan clear cache, migrasi, rekonsiliasi, backfill, dan optimize secara terstruktur.
- Membatasi akses publik dengan rate limit.
- Menyimpan dokumen order pada storage privat.
- Menambahkan pengujian fondasi V10 untuk healthcheck, helper order, dan default feature flag.
