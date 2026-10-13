# Checklist Uji IzinHukum V11

## A. Deployment

- [ ] Backup database selesai.
- [ ] Backup storage selesai.
- [ ] Commit V10.1 dicatat.
- [ ] `SEED_DATABASE=false`.
- [ ] `RUN_MIGRATIONS=true`.
- [ ] `QUEUE_CONNECTION=database`.
- [ ] Build Docker berhasil.
- [ ] Migration berhasil.
- [ ] Healthcheck `version=11.0.0`.
- [ ] Database, storage, dan queue berstatus `ok`.

## B. Regression V10.1

- [ ] Homepage dapat dibuka.
- [ ] Login admin berhasil.
- [ ] Sidebar lama tetap berfungsi.
- [ ] Order layanan dapat dibuka dan diperbarui.
- [ ] Invoice dapat dihubungkan ke order.
- [ ] Pembayaran dan kwitansi tetap berfungsi.
- [ ] Laporan keuangan V10.1 tetap benar.
- [ ] Referral dan portal pelanggan tetap berfungsi.

## C. Konfigurasi

- [ ] Halaman WhatsApp terbuka.
- [ ] API key hanya tampil sebagai status tersedia, bukan isi key.
- [ ] Webhook URL hanya dapat dilihat admin.
- [ ] Semua feature flag WhatsApp awalnya OFF.
- [ ] `php artisan whatsapp:diagnose` tidak menemukan error kritis.

## D. Perangkat

- [ ] Sinkronisasi perangkat berhasil.
- [ ] Nomor dan status perangkat tampil.
- [ ] Daily limit dapat diubah.
- [ ] Create/scan tidak tersedia sebelum provider tools aktif.
- [ ] QR memakai halaman no-store.
- [ ] Delete membutuhkan teks `HAPUS PERANGKAT`.

## E. Pengiriman

- [ ] Pesan uji masuk antrean.
- [ ] Worker memproses pesan.
- [ ] Provider message ID tersimpan bila diberikan provider.
- [ ] Status pesan berubah sesuai respons provider.
- [ ] Pesan gagal masuk retry tanpa menggagalkan transaksi bisnis.
- [ ] Retry manual tidak mengirim ulang pesan yang sudah accepted/sent.
- [ ] Pembatalan hanya berlaku sebelum provider menerima pesan.

## F. Webhook dan inbox

- [ ] Secret salah menghasilkan 404.
- [ ] Payload lebih dari 1 MB ditolak.
- [ ] Duplicate webhook tidak membuat pesan ganda.
- [ ] Pesan masuk tampil di Inbox setelah feature flag aktif.
- [ ] Nomor cocok dengan lead, pelanggan, order, atau mitra yang benar.
- [ ] Admin dapat membalas.
- [ ] Assignment, label, status, dan unread count berfungsi.
- [ ] `ADMIN` mengaktifkan human handoff.
- [ ] `STATUS` tidak membocorkan order milik nomor lain.
- [ ] `INVOICE` tidak membocorkan invoice milik nomor lain.

## G. Consent dan opt-out

- [ ] Consent transaksi dan promosi dapat dicatat terpisah.
- [ ] Campaign menolak nomor tanpa consent promosi.
- [ ] `STOP` menonaktifkan promosi meski Inbox OFF.
- [ ] Konfirmasi opt-out tetap terkirim sebagai pesan transaksi.
- [ ] Opt-out tidak otomatis menghentikan notifikasi transaksi layanan aktif.

## H. Otomasi transaksi

- [ ] Semua automation awalnya OFF.
- [ ] Proposal baru hanya mengirim setelah automation aktif.
- [ ] Order baru mengirim satu pesan idempoten.
- [ ] Perubahan status order mengirim sesuai status terbaru.
- [ ] Invoice dikirim setelah status menjadi `sent`.
- [ ] Pembayaran aktif mengirim satu konfirmasi.
- [ ] Komisi approved dan paid mengirim ke nomor mitra.
- [ ] H-3, H-1, dan overdue mengikuti due date.

## I. Campaign

- [ ] Template wajib bertanda promosi.
- [ ] Maksimal 500 baris diterapkan.
- [ ] Nomor duplikat dihapus.
- [ ] Delay minimal 30 detik.
- [ ] Batas harian diterapkan.
- [ ] Rotator tidak berjalan sebelum dua lapis konfigurasi aktif.
- [ ] Campaign cancelled tidak berubah kembali menjadi completed.
- [ ] Pesan accepted provider tidak diklaim dapat ditarik.

## J. Security dan retensi

- [ ] API key tidak ada di repository.
- [ ] Secret webhook tidak tampil di access log Nginx.
- [ ] URL HTTP, localhost, dan private IP ditolak untuk media.
- [ ] Audit log mencatat operasi admin penting.
- [ ] `php artisan whatsapp:prune` berhasil.
- [ ] Tidak ada dokumen pelanggan yang memakai URL publik permanen.
