# Redeploy IzinHukum V21.0.4 Sekali Jalan

V21.0.4 memperbaiki schema drift lengkap pada tabel `marketing_campaigns`. Deploy sebelumnya berhasil menutup `service_id`, tetapi kemudian menemukan bahwa `landing_headline` dan kemungkinan kolom campaign lain juga belum ada pada database produksi.

Coolify telah rollback otomatis sehingga website lama tetap aman.

## Sebelum deploy

1. Cadangkan database dan persistent volume `storage`.
2. Jangan mengganti `APP_KEY`.
3. Pertahankan `SEED_DATABASE=false`.
4. Pastikan `RUN_MIGRATIONS=true`.
5. Unggah seluruh isi source V21.0.4 ke branch yang dipakai Coolify.

## Deploy

Klik **Redeploy** satu kali. Jangan menjalankan rollback migration dan jangan menjalankan seeder.

Migrasi `000029` sekarang akan memeriksa seluruh struktur campaign dan menambahkan hanya bagian yang belum tersedia:

- relasi layanan dan kupon;
- source dan medium;
- headline, subheadline, CTA, serta status landing;
- penghitung kunjungan;
- tanggal campaign;
- budget dan spend;
- status, notes, pembuat, serta timestamp;
- relasi campaign pada inquiry bila belum tersedia.

Operasi data tetap idempoten. Data layanan, paket, kupon, atau pivot yang sempat terbentuk dari percobaan sebelumnya diperbarui, bukan diduplikasi.

## Verifikasi

Setelah status container sehat, jalankan satu per satu:

```bash
php artisan migrate:status | grep 000029
php artisan optimize:clear
```

Kemudian pastikan:

1. migrasi `000029` berstatus `Ran`;
2. `/healthz` menampilkan `healthy` dan versi `21.0.4`;
3. `/layanan/pendirian-yayasan` menampilkan `YAYASAN300` dan harga Rp3.700.000;
4. `/promo/yayasan-agustus-2026` dapat dibuka;
5. campaign Yayasan tersedia pada **Admin → Campaign pemasaran**.

## Larangan

Jangan jalankan:

```text
php artisan migrate:fresh
php artisan migrate:rollback
php artisan db:wipe
```

Perintah tersebut berisiko menghapus atau membalikkan data produksi.
