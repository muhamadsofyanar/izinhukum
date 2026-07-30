# Redeploy IzinHukum V14 - Perjalanan Calon Klien

Pembaruan ini disiapkan untuk satu kali unggah source ke GitHub dan satu kali redeploy di Coolify. Tidak ada tabel lama yang dihapus dan tidak ada perubahan environment wajib dari V13.

## Isi pembaruan

1. Judul bab LMS dapat diedit.
2. Judul, jenis, URL, durasi, isi, dan PDF materi LMS dapat diperbarui tanpa menghapus materi.
3. Form proposal tetap membuat inquiry dan order, mengantrekan email serta WhatsApp admin, kemudian membuka WhatsApp klien untuk konfirmasi.
4. Sumber calon klien dari generator nama atau simulasi dokumen dicatat pada inquiry.
5. Tersedia pusat alat gratis, generator nama, simulasi edukatif bahan akta/pernyataan pendirian, dan jalur ke pemeriksaan tim.
6. Versi healthcheck menjadi `14.0.0`.

## Sebelum mengunggah

1. Cadangkan `database/database.sqlite` dan volume `storage`.
2. Unduh/cadangkan source produksi yang sedang aktif.
3. Unggah seluruh isi paket V14 ke repository GitHub.
4. Jangan mengganti `APP_KEY`.
5. Pertahankan `SEED_DATABASE=false`.
6. Pertahankan `RUN_MIGRATIONS=true`.

Environment notifikasi V13 tetap digunakan:

```dotenv
QUEUE_CONNECTION=database

ORDER_NOTIFICATION_EMAIL_ENABLED=true
ORDER_NOTIFICATION_EMAIL=izinhukum@gmail.com

ORDER_NOTIFICATION_WHATSAPP_ENABLED=true
ORDER_NOTIFICATION_WHATSAPP=628xxxxxxxxxx
STARSENDER_ENABLED=true
STARSENDER_TRANSACTION_DEVICE_KEY=device-api-key-transaksi
```

Tidak ada environment baru untuk generator nama atau simulasi dokumen.

## Redeploy

Setelah commit V14 sudah terlihat di Coolify, klik **Redeploy** satu kali. Startup akan membersihkan cache, menjalankan migrasi yang belum terpasang, memastikan katalog KBLI, mengamankan file LMS, lalu membangun cache produksi.

## Pemeriksaan setelah deploy

1. Buka `/healthz` dan pastikan `status=healthy` serta `version=14.0.0`.
2. Buka `/alat`; pastikan tiga perjalanan publik tampil.
3. Coba `/alat/generator-nama`; pilih satu hasil dan lanjutkan ke proposal.
4. Coba `/alat/simulasi-akta`; cetak pratinjau dan lanjutkan ke proposal.
5. Kirim proposal percobaan; pastikan:
   - inquiry tercatat;
   - order muncul di dashboard;
   - email admin terkirim;
   - WhatsApp admin menerima ringkasan;
   - browser klien membuka WhatsApp dengan referensi dan nomor order.
6. Buka satu kelas LMS; ubah judul bab dan materi.
7. Jika materi mempunyai PDF, ganti file dan pastikan mitra dapat membuka versi baru.
8. Pastikan `/admin/whatsapp` tetap 404 dan menu CRM tidak muncul.

## Batas penting alat publik

- Generator nama tidak memeriksa atau memesan nama pada AHU.
- Simulasi tidak menghasilkan akta autentik, minuta, atau pernyataan pendirian resmi.
- Perseroan Perorangan ditampilkan sebagai ringkasan Pernyataan Pendirian, bukan akta notaris.
- Jangan meminta calon klien memasukkan NIK atau mengunggah identitas melalui simulasi publik.
