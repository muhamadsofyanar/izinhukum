# Petunjuk Update IzinHukum V10

## Paket

Nama rilis: **IzinHukum V10 - Order, Finance, Referral, and Customer Foundation**

Basis deployment yang dilaporkan aktif sebelum paket ini dibuat:

```text
e88602ae8ed6eccc8e9c11710ca7e3a4b8cf7cef
```

Paket ini berisi file baru dan file pengganti. Unggah seluruh isi hasil ekstraksi ke root repositori GitHub. Jangan mengunggah folder induk ZIP sebagai satu folder baru.

## Sebelum upload

1. Buat backup database.
2. Buat backup volume atau folder `storage`.
3. Catat commit GitHub yang sedang aktif.
4. Periksa Environment Variables di Coolify:

```text
APP_KEY=key Laravel yang sedang dipakai
RUN_MIGRATIONS=true
SEED_DATABASE=false
```

Jangan mengganti `APP_KEY`. Mengganti key dapat membuat data terenkripsi dan sesi lama tidak dapat dibaca.

Pastikan `APP_KEY`, `ADMIN_PASSWORD`, dan `DB_PASSWORD` tidak memakai kata contoh seperti `GANTI`, `CHANGE`, `CONTOH`, `EXAMPLE`, `admin123`, atau `12345678`. Startup V10 akan menolak konfigurasi contoh.

## Cara upload ke GitHub

1. Ekstrak file ZIP di komputer.
2. Buka repositori `muhamadsofyanar/izinhukum`.
3. Pilih branch yang digunakan Coolify, biasanya `main`.
4. Pilih **Add file**, lalu **Upload files**.
5. Tarik seluruh isi hasil ekstraksi ke halaman upload.
6. Pastikan struktur seperti `app`, `bootstrap`, `database`, `docker`, `public`, `resources`, `routes`, dan `tests` tetap berada di root repositori.
7. Commit dengan pesan:

```text
Release IzinHukum V10 order foundation
```

8. Buka Coolify dan tekan **Redeploy**. Jangan hanya menekan Restart.

## Proses otomatis saat redeploy

Container menjalankan urutan berikut:

```text
php artisan optimize:clear
php artisan migrate --force
php artisan finance:reconcile-legacy-paid-invoices --no-interaction
php artisan app:backfill-orders --no-interaction
php artisan kbli:ensure
php artisan portal:secure-files
php artisan optimize
```

Migrasi V10 bersifat additive. Migrasi membuat tabel order, aktivitas order, dokumen order, dan aktivitas referral. Migrasi juga menambahkan relasi order pada invoice. Migrasi tidak menghapus tabel atau kolom lama.

Command rekonsiliasi dan backfill dibuat idempoten. Command aman dijalankan kembali ketika container restart atau redeploy.

## Pemeriksaan setelah deployment

### 1. Healthcheck

Buka:

```text
https://DOMAIN-ANDA/healthz
```

Hasil normal:

```json
{
  "status": "healthy",
  "version": "10.0.0",
  "checks": {
    "database": "ok",
    "storage": "ok"
  }
}
```

### 2. Admin

Buka menu berikut:

```text
Admin > Order layanan
```

Periksa bahwa permintaan lama sudah berubah menjadi order. Jika masih ada data yang belum masuk, tekan **Sinkronkan data lama** satu kali.

Lanjutkan pemeriksaan:

```text
Admin > Permintaan masuk
Admin > Invoice & kwitansi
Admin > Laporan keuangan
Admin > Pengaturan > Fitur aplikasi
```

### 3. Portal pelanggan

Buka salah satu order, lalu salin tautan **Portal pelanggan**. Uji menggunakan mode incognito.

Periksa:

- progres dan status tampil;
- invoice dan kwitansi tampil;
- file hasil yang disetujui dapat diunduh;
- pelanggan dapat mengunggah dokumen privat;
- pelanggan dapat mengirim catatan;
- token lama tidak berlaku setelah tombol Ganti token digunakan.

### 4. Referral mitra

Masuk sebagai mitra. Periksa tautan website, katalog layanan, proposal, dan setiap layanan.

Uji menggunakan mode incognito:

1. Buka tautan mitra A.
2. Buka beberapa halaman layanan.
3. Kirim proposal.
4. Pastikan order, invoice, pembayaran, dan komisi tetap terkait dengan mitra A.

V10 memakai atribusi referral pertama yang valid selama masa cookie. Tautan mitra kedua tidak mengganti atribusi pertama selama cookie masih berlaku. Klik dari sesi mitra yang sedang login tidak dicatat sebagai referral diri sendiri.

### 5. Tampilan

Lakukan hard refresh:

```text
Ctrl + F5
```

Periksa sidebar sampai bagian bawah pada desktop dan ponsel.

## Bila deployment gagal

### APP_KEY atau password ditolak

Perbaiki Environment Variables di Coolify. Jangan membuat APP_KEY baru jika aplikasi sebelumnya sudah memakai key yang valid. Hapus nilai contoh, simpan, lalu redeploy.

### Healthcheck database error

Periksa `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD`. Pastikan database dapat diakses dari container aplikasi.

### Healthcheck storage error

Periksa persistent volume dan izin tulis pada `storage`, `bootstrap/cache`, dan `database`.

### Tampilan masih lama

Jalankan di terminal container:

```bash
php artisan optimize:clear
php artisan optimize
```

Kemudian restart container dan lakukan `Ctrl + F5`.

## Rollback aman

1. Revert commit V10 di GitHub.
2. Redeploy commit sebelumnya melalui Coolify.
3. Jangan menjalankan `php artisan migrate:rollback` di production tanpa backup dan pemeriksaan manual.

Tabel dan kolom baru V10 dapat dibiarkan ketika rollback kode. Kode lama tidak menggunakannya.
