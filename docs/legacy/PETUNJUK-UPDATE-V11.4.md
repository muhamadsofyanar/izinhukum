# IzinHukum V11.4

Patch ini dipasang di atas V11.3.

## Fitur

1. Pilihan grup disimpan per admin dan per alias perangkat setelah pesan berhasil masuk antrean.
2. Pilihan tersimpan otomatis dicentang kembali pada pengiriman berikutnya.
3. Tersedia tombol untuk menghapus pilihan grup tersimpan.
4. Batas aplikasi 50 grup dihapus. Semua grup aktif yang dicentang diproses.
5. ID grup dikirim sebagai JSON sehingga tidak bergantung pada batas `max_input_vars` PHP.
6. Form mendukung unggah JPG, JPEG, PNG, atau WEBP maksimal 10 MB.
7. Teks dapat dikirim bersamaan dengan gambar sebagai caption.
8. File unggahan disimpan pada `database/uploads/whatsapp/groups/YYYY/MM` dan diakses melalui `/storage/...`.
9. Jeda antargrup diterapkan secara bertingkat pada antrean StarSender.
10. Healthcheck menjadi V11.4.0.

## Pemasangan

Ekstrak ZIP ke root repository dan izinkan overwrite, kemudian:

```bash
git add .
git commit -m "V11.4 saved group selection and image upload"
git push origin main
```

Redeploy dari Coolify. Migration dijalankan otomatis apabila `RUN_MIGRATIONS=true`.

Sesudah deployment sehat, jalankan:

```bash
cd /var/www/html
php artisan optimize:clear
php artisan migrate --force
php artisan queue:restart
php artisan whatsapp:diagnose
```

Healthcheck yang diharapkan:

```json
{"status":"healthy","version":"11.4.0"}
```

## Cara penggunaan

1. Buka Admin > WhatsApp & CRM > Grup.
2. Pilih grup tujuan.
3. Pilih `Teks saja` atau `Teks + gambar`.
4. Isi teks. Untuk gambar, pilih file pada kolom `Unggah gambar`.
5. Tentukan jeda antargrup.
6. Klik `Kirim ke semua grup terpilih`.
7. Pilihan grup akan tetap dicentang pada pengiriman berikutnya.

## Catatan operasional

- Tidak ada batas 50 grup pada aplikasi, tetapi jumlah aktual tetap bergantung pada jumlah grup aktif, kapasitas server, antrean, akun WhatsApp, dan kebijakan StarSender/WhatsApp.
- Gunakan jeda yang wajar. Untuk banyak grup, 10 sampai 30 detik lebih aman daripada 5 detik.
- Gambar harus dapat diakses StarSender melalui URL HTTPS IzinHukum.
- Pastikan `public/storage` tetap terhubung ke `database/uploads` pada container.
- Uji dahulu pada 2 sampai 3 grup sebelum pengiriman besar.
