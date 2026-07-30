# Redeploy IzinHukum Mode Fokus V13

Pembaruan ini dirancang untuk satu kali unggah source ke GitHub dan satu kali redeploy di Coolify. Migrasi dijalankan otomatis oleh `docker/entrypoint.sh`.

## Sebelum mengunggah

1. Cadangkan database dan volume storage produksi.
2. Unggah seluruh isi source V13 ke repository GitHub, bukan hanya file yang berubah.
3. Jangan mengganti `APP_KEY`, karena password SMTP tersimpan dengan key tersebut.
4. Pertahankan `SEED_DATABASE=false` agar harga dan konfigurasi yang sudah diubah admin tidak tertimpa.
5. Pastikan `RUN_MIGRATIONS=true`.

## Environment yang perlu dipastikan

```dotenv
SEED_DATABASE=false
RUN_MIGRATIONS=true
QUEUE_CONNECTION=database

ORDER_NOTIFICATION_EMAIL_ENABLED=true
ORDER_NOTIFICATION_EMAIL=alamat-email-admin

ORDER_NOTIFICATION_WHATSAPP_ENABLED=true
ORDER_NOTIFICATION_WHATSAPP=628xxxxxxxxxx
STARSENDER_ENABLED=true
STARSENDER_TRANSACTION_DEVICE_KEY=device-api-key-transaksi
```

Jika `ORDER_NOTIFICATION_EMAIL` kosong, sistem memakai `ADMIN_EMAIL`. Jika `ORDER_NOTIFICATION_WHATSAPP` kosong, sistem memakai `COMPANY_WHATSAPP`.

`STARSENDER_ACCOUNT_API_KEY`, webhook secret, device support, device campaign, dan device partner tidak diperlukan untuk gateway notifikasi satu arah.

## Saat redeploy

Klik **Redeploy** satu kali setelah commit terbaru terbaca oleh Coolify. Startup otomatis:

1. membersihkan cache lama;
2. menjalankan seluruh migrasi;
3. mengaktifkan mode fokus;
4. menonaktifkan feature flag CRM, campaign, inbox, sequence, dan webhook;
5. mempertahankan tabel serta data lama;
6. menjalankan rekonsiliasi keuangan dan backfill order;
7. membangun cache produksi kembali;
8. menjalankan web server, queue worker, dan scheduler.

Jangan menjalankan seeder pada pembaruan ini.

## Pemeriksaan setelah deploy

1. Buka `/healthz` dan pastikan status `healthy`.
2. Login admin dan pastikan menu utama hanya berfokus pada Pesanan, Keuangan, Mitra, LMS, Bank Konten, dan Pengaturan.
3. Buka menu **Notifikasi email**, kirim email tes, dan pastikan email diterima.
4. Kirim satu pesanan uji dari landing page.
5. Pastikan pesanan muncul di admin, email admin diterima, dan WhatsApp admin menerima ringkasan beserta tautan order.
6. Pastikan `/admin/whatsapp` mengembalikan 404.
7. Periksa invoice, kwitansi, laporan keuangan, referral, LMS, dan bank konten.

Jika salah satu kanal notifikasi belum siap, nonaktifkan kanal tersebut lewat environment tanpa membatalkan kanal lainnya:

```dotenv
ORDER_NOTIFICATION_EMAIL_ENABLED=false
ORDER_NOTIFICATION_WHATSAPP_ENABLED=false
```

Perubahan environment membutuhkan restart/redeploy konfigurasi dari Coolify, tetapi tidak memerlukan perubahan kode.
