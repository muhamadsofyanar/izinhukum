# Redeploy IzinHukum V21.0.2 Sekali Jalan

Paket ini lengkap dan cukup dideploy satu kali. Tidak perlu menjalankan ulang seeder.

## Sebelum deploy

1. Cadangkan database dan persistent volume `storage`.
2. Jangan mengganti `APP_KEY`.
3. Pertahankan `SEED_DATABASE=false`.
4. Pastikan `RUN_MIGRATIONS=true`.
5. Unggah seluruh isi paket ke branch repository yang digunakan Coolify.

## Deploy

Klik **Redeploy** satu kali. Entrypoint menjalankan migration `2026_08_03_000029_add_v21_0_2_yayasan_closing_campaign` secara otomatis.

Migration akan:

1. menghubungkan campaign pemasaran dengan kupon;
2. memperbarui konten landing dan ruang lingkup Yayasan;
3. membuat atau memperbarui kupon `YAYASAN300`;
4. membuat atau memperbarui campaign `yayasan-agustus-2026`;
5. mempertahankan harga paket produksi yang pernah diubah admin.

Tidak ada environment variable baru.

## Pemeriksaan setelah deploy

Jalankan dari terminal container:

```bash
php artisan migrate:status | grep 000029
php artisan optimize:clear
```

Baris migration harus berstatus `Ran`. Setelah itu:

1. Buka `/healthz`; versi harus `21.0.2` dan status `healthy`.
2. Buka `/layanan/pendirian-yayasan`.
3. Pastikan headline khusus Yayasan dan banner `YAYASAN300` tampil.
4. Pastikan harga dasar bawaan Rp4.000.000 tampil menjadi Rp3.700.000 setelah promo.
5. Pastikan manfaat paket tidak memuat `Kartu nama direktur`.
6. Buka `/promo/yayasan-agustus-2026`.
7. Isi satu lead uji; kode kupon harus sudah terisi dan tidak perlu diketik.
8. Di halaman berhasil, pastikan WhatsApp terbuka secara manual dengan nomor referensi dan kode promo.
9. Buka **Admin → Campaign pemasaran** dan pastikan campaign terhubung ke `YAYASAN300`.
10. Buka **Admin → Analitik pertumbuhan** setelah tes untuk melihat kunjungan dan lead campaign.

Gunakan hard refresh (`Ctrl+Shift+R`) bila browser masih menampilkan CSS lama.

## Tautan broadcast

Salin tautan dari **Admin → Campaign pemasaran**. URL dasarnya:

```text
https://DOMAIN-ANDA/promo/yayasan-agustus-2026
```

Pembuat tautan admin menambahkan UTM secara otomatis.

## Jika promo tidak terlihat

Periksa:

- tanggal server berada dalam 3–17 Agustus 2026;
- status campaign `active` dan landing aktif;
- kupon `YAYASAN300` aktif serta belum mencapai 20 penggunaan;
- harga paket minimal Rp4.000.000;
- feature `campaign_landing_pages`, `campaign_tracking`, `service_landing_pages`, dan `public_proposal` aktif.

## Setelah 17 Agustus 2026

Perpanjang tanggal campaign dan kupon melalui admin jika promo akan dilanjutkan. Jangan hanya mengubah tanggal campaign: tanggal kupon juga harus diperbarui agar potongan tetap dapat dihitung.
