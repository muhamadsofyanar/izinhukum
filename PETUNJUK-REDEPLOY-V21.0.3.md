# Redeploy IzinHukum V21.0.3 Sekali Jalan

V21.0.3 adalah hotfix untuk deploy V21.0.2 yang berhenti pada migrasi `000029` dengan pesan `marketing_campaigns has no column named service_id`.

Coolify telah melakukan rollback otomatis, sehingga website lama tetap berjalan. Paket ini memperbaiki migrasi yang sama agar mendeteksi dan menambahkan kolom `service_id` yang hilang sebelum campaign Yayasan dibuat.

## Sebelum deploy

1. Cadangkan database dan persistent volume `storage`.
2. Jangan mengganti `APP_KEY`.
3. Pertahankan `SEED_DATABASE=false`.
4. Pastikan `RUN_MIGRATIONS=true`.
5. Ganti source repository dengan seluruh isi paket V21.0.3.

## Deploy

Klik **Redeploy** satu kali. Tidak perlu rollback migration secara manual dan tidak perlu menjalankan seeder.

Migrasi `2026_08_03_000029_add_v21_0_2_yayasan_closing_campaign` masih berstatus pending karena eksekusi sebelumnya gagal. Saat container baru dimulai, migrasi akan:

1. menambahkan `marketing_campaigns.service_id` bila belum tersedia;
2. mempertahankan `coupon_id` yang mungkin sudah sempat dibuat oleh percobaan sebelumnya;
3. memperbarui landing dan paket Yayasan secara idempoten;
4. membuat atau memperbarui kupon `YAYASAN300`;
5. membuat campaign `yayasan-agustus-2026`;
6. tidak menimpa harga paket yang pernah diubah admin.

## Pemeriksaan setelah deploy

Jalankan di terminal container, satu per satu:

```bash
php artisan migrate:status | grep 000029
php artisan optimize:clear
```

Baris `000029` harus berstatus `Ran`. Lalu periksa:

1. `/healthz` menampilkan `status: healthy` dan `version: 21.0.3`;
2. `/layanan/pendirian-yayasan` menampilkan promo `YAYASAN300`;
3. harga Rp4.000.000 tampil menjadi Rp3.700.000 selama promo aktif;
4. `/promo/yayasan-agustus-2026` dapat dibuka;
5. **Admin → Campaign pemasaran** menampilkan campaign Yayasan yang terhubung ke kupon;
6. satu lead uji masuk dengan campaign dan kode promo yang benar.

Gunakan hard refresh (`Ctrl+Shift+R`) bila browser masih menampilkan aset lama.

## Jika masih gagal

Jalankan perintah berikut satu per satu dan kirim seluruh hasilnya:

```bash
php artisan migrate:status | grep 000029
php artisan tinker --execute="dump(\Illuminate\Support\Facades\Schema::getColumnListing('marketing_campaigns'));"
```

Jangan menjalankan `migrate:rollback`, `migrate:fresh`, atau menghapus database produksi.
