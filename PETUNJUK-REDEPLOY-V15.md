# Redeploy IzinHukum V15

Pembaruan ini dapat dipasang melalui satu kali unggah source dan satu kali redeploy. Migrasi menambahkan struktur baru tanpa menghapus tabel atau menjalankan ulang seeder katalog.

## Sebelum redeploy

1. Cadangkan database dan volume `storage`.
2. Jangan mengganti `APP_KEY`.
3. Pertahankan `SEED_DATABASE=false` pada instalasi yang sudah berjalan.
4. Pastikan `RUN_MIGRATIONS=true`.
5. Unggah seluruh isi paket V15 ke repository yang digunakan Coolify.

Tidak ada environment variable baru yang wajib. Konfigurasi email, WhatsApp, antrean, dan referral lama tetap digunakan.

## Redeploy

Klik **Redeploy** satu kali setelah commit V15 terlihat di Coolify. Entrypoint menjalankan migrasi sebelum cache produksi dibangun. Migrasi V15:

- membuat tabel kupon dan penggunaan kupon;
- menambah snapshot kupon pada inquiry, order, dan invoice;
- menerbitkan lima layanan tambahan dengan harga awal berbasis penawaran;
- mempertahankan data serta harga katalog lama.

## Pemeriksaan setelah deploy

1. Buka `/healthz`; pastikan `status=healthy` dan `version=15.0.0`.
2. Buka `/layanan`; pastikan PKP, RUPS, Laporan Keuangan, Brand Identity, dan Perizinan Lainnya tampil tanpa harga Rp0.
3. Masuk sebagai admin, buka **Kupon & promo**, lalu buat kupon uji untuk satu layanan.
4. Kirim proposal menggunakan kupon dan, bila tersedia, tautan referral mitra.
5. Pastikan inquiry dan order menampilkan kupon, sementara referral tetap menampilkan mitra/kode referral secara terpisah.
6. Buat invoice end user dari inquiry tersebut; periksa subtotal, potongan promo, dan total.
7. Nonaktifkan atau hapus kupon uji jika tidak diperlukan. Kupon yang sudah dipakai tidak dapat dihapus agar histori tetap utuh.
8. Uji `/alat/generator-nama` untuk PT PMA, Perkumpulan, dan Koperasi.

## Catatan harga layanan baru

Harga layanan baru sengaja tidak ditentukan di source. Admin dapat menetapkan harga website, harga minimum end user, dan harga mitra setelah ruang lingkup komersial disepakati. Kupon baru dapat menghasilkan potongan nominal setelah harga paket lebih besar dari nol.
