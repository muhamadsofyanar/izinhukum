# Panduan Upgrade IzinHukum V7

Pembaruan ini mempertahankan seluruh fungsi V6 dan menambahkan pembaca LMS terfokus, edit invoice draf, pembatalan invoice beralasan, koreksi kwitansi, serta pembatalan kwitansi dengan jejak audit.

## 1. Cadangkan sebelum mengganti container

Jalankan pencadangan dari deployment yang masih aktif.

```bash
mkdir -p backup-izinhukum
docker compose exec -T db sh -c \
  'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
  > backup-izinhukum/database-before-v6.sql
docker compose cp app:/var/www/html/database/uploads backup-izinhukum/uploads-before-v6
```

Pastikan file SQL tidak kosong dan folder unggahan berhasil disalin. Jangan melanjutkan jika salah satu cadangan gagal.

## 2. Pertahankan environment penting

Nilai berikut tidak boleh dihapus atau diganti:

```dotenv
APP_KEY=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
DB_ROOT_PASSWORD=
ADMIN_EMAIL=
ADMIN_PASSWORD=
```

Gunakan:

```dotenv
RUN_MIGRATIONS=true
SEED_DATABASE=false
TRUSTED_PROXIES=
```

Isi `TRUSTED_PROXIES` hanya dengan alamat IP proxy atau load balancer yang benar. Jangan menggunakan `*`.

## 3. Pasang source V7

Timpa source repository dengan isi paket V7, lalu commit dan push ke branch deployment. Setelah itu jalankan build dan deployment normal.

```bash
docker compose up -d --build
docker compose ps
```

Entrypoint menjalankan:

```bash
php artisan migrate --force
php artisan kbli:ensure
php artisan portal:secure-files
php artisan optimize
```

Migrasi tidak menjalankan `ServiceSeeder`. Harga dan status paket yang telah diedit admin tidak ditimpa. Migrasi `000013` menambahkan metadata audit pembatalan invoice dan pembayaran. Data pembayaran lama tetap berstatus aktif.

## 4. Pulihkan unggahan lama

Volume `app_uploads` mulai digunakan pada V6. Jika deployment sebelumnya menyimpan unggahan di dalam container, salin cadangan setelah container V6 aktif.

```bash
docker compose cp backup-izinhukum/uploads-before-v6/. app:/var/www/html/database/uploads/
docker compose exec app php artisan portal:secure-files
```

Perintah terakhir memindahkan PDF LMS dan lampiran community lama ke penyimpanan privat. Logo dan aset branding tetap berada pada volume unggahan publik.

## 5. Verifikasi KBLI

```bash
docker compose exec app php artisan kbli:ensure
```

Hasil yang benar menyebut katalog KBLI 2025 sudah lengkap dengan 1.559 kode. Uji halaman:

```text
/cek-risiko-kbli?q=dagang
/cek-risiko-kbli?q=restoran
/cek-risiko-kbli/56101
```

## 6. Lengkapi branding dokumen

Masuk sebagai admin, lalu buka **Logo & branding**. Isi:

1. Nama dan tagline.
2. Logo.
3. Alamat, telepon, dan email dokumen.
4. Bank, nomor rekening, dan nama pemilik rekening.
5. Nama serta jabatan penanda tangan.
6. Gambar tanda tangan dan stempel jika digunakan.

Data tersebut digunakan bersama oleh invoice, kwitansi, sertifikat, dan email invoice.

## 7. Uji LMS

1. Buat kelas dan bab.
2. Tambahkan video menggunakan URL YouTube.
3. Unggah PDF maksimal 25 MB.
4. Daftarkan satu akun mitra.
5. Buka kelas dari akun mitra.
6. Pastikan video tampil di portal dan tidak membuka aplikasi YouTube.
7. Pastikan daftar bab dan materi tampil di kiri, sedangkan hanya satu materi aktif tampil di panel utama.
8. Uji tombol materi sebelumnya dan berikutnya.
9. Pastikan PDF dapat dibuka peserta terdaftar.
10. Selesaikan semua materi.
11. Buka sertifikat dan pilih **Cetak / Simpan PDF**.

Uji akun mitra lain yang tidak terdaftar. Permintaan file PDF harus menghasilkan 404.

## 8. Uji pembayaran dan kwitansi

1. Buat invoice draf.
2. Ubah penerima atau item invoice, lalu simpan.
3. Uji hapus permanen pada invoice draf yang tidak digunakan.
4. Tandai invoice sebagai terkirim. Pastikan data invoice terkunci.
5. Catat pembayaran sebagian.
6. Pastikan status berubah menjadi **partial**.
7. Buka kwitansi.
8. Pilih **Koreksi**, ubah data, dan isi alasan koreksi.
9. Pastikan audit log mencatat perubahan.
10. Batalkan satu kwitansi uji dengan alasan.
11. Pastikan dokumen tetap dapat dibuka dengan tanda **DIBATALKAN**.
12. Pastikan pembayaran batal tidak masuk laporan keuangan dan status invoice dihitung ulang.
13. Catat pembayaran aktif sampai penuh.
14. Pastikan status berubah menjadi **paid** dan sisa tagihan menjadi nol.

Nominal pembayaran tidak boleh melebihi sisa invoice. Invoice terkirim hanya dapat dibatalkan dengan alasan dan tanpa pembayaran aktif. Kwitansi tidak pernah dihapus permanen.

## 9. Uji laporan keuangan

Buka **Laporan keuangan**, lalu:

1. Tambah kategori pemasukan dan pengeluaran.
2. Catat satu pemasukan non-invoice.
3. Catat satu pengeluaran.
4. Pilih periode.
5. Cocokkan pemasukan, pengeluaran, surplus atau defisit, dan piutang.
6. Unduh CSV.
7. Buka versi cetak.

Laporan menggunakan basis kas. Invoice belum menjadi pemasukan sampai pembayaran dicatat.

## 10. Pemeriksaan teknis

Pada lingkungan pengembangan yang memiliki PHP 8.4 dan Composer:

```bash
composer install
php artisan test
vendor/bin/pint --test
npm ci
npm run build
```

Jika repository belum memiliki `composer.lock`, jalankan `composer update --lock` pada lingkungan PHP 8.4, tinjau hasil pengujian, lalu commit file tersebut sebelum membekukan rilis produksi.

## 11. Rollback

Jika verifikasi kritis gagal:

1. Hentikan deployment V6.
2. Kembalikan image atau commit V6.
3. Pulihkan database dari `database-before-v6.sql` hanya jika migrasi harus dibatalkan.
4. Pulihkan folder unggahan dari `uploads-before-v6`.

Jangan menghapus volume sebelum cadangan diverifikasi.
