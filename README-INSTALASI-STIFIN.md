# STIFIn Mulia

Paket ini memakai Darmutiara Kajian 1.2.262 sebagai dasar. Installer dan aktivasi instalasi memakai identitas STIFIn Mulia. Kajian, pendaftaran, pengingat kalender, diskusi anggota, dan moderasi memakai modul Kajian yang sudah ada. Integrasi opsional dari paket dasar, seperti relay dan portal agen, tetap memakai konfigurasi dan penyedia masing-masing. Pembaruan aplikasi dipasang lewat GitHub.

## Pemasangan baru di VPS dengan Docker Compose

1. Siapkan Docker dan plugin Docker Compose di VPS. Ambil repositori `muhamadsofyanar/darmutiarakajian` setelah semua file update di-commit. Jalankan perintah dari folder yang memuat `compose.yaml`.
2. Untuk pemasangan baru, jalankan `cp .env.example .env`. Isi dua password database yang berbeda dan acak. Gunakan `openssl rand -hex 24` dua kali untuk membuat keduanya. Jangan commit `.env`.
3. Setel `DARMUTIARA_APP_URL=https://DOMAIN-KAJIAN-ANDA` ke URL HTTPS final. Isi `DARMUTIARA_ISSUER_PUBLIC_KEY` dengan **nilai** `public_key` dari `python lisensi.py public-key` di terminal penerbit Darmutiara. Simpan `.env`.
4. Jalankan `docker compose up -d --build`. Compose menerbitkan port aplikasi di loopback VPS, secara bawaan `127.0.0.1:8080`. Jika port sudah dipakai, ubah `DARMUTIARA_HTTP_PORT` di `.env`. Arahkan reverse proxy HTTPS domain ke port loopback tersebut. Teruskan header `Host` dan set `X-Forwarded-Proto: https` di proxy. Batasi akses ke `/installer/` pada proxy selama setup agar hanya pengurus yang bisa menjalankannya.
5. Buka `https://DOMAIN-KAJIAN-ANDA/installer/`. Masukkan data berikut, lalu buat akun admin.

   | Kolom | Nilai |
   |---|---|
   | Database host | `db` |
   | Port | `3306` |
   | Database | `darmutiara` |
   | Username | `darmutiara` |
   | Password | Nilai `DARMUTIARA_DB_PASSWORD` dari `.env` |

   Installer ini untuk **database kosong**. Dump bawaan berisi perintah penggantian tabel. Untuk instalasi yang sudah berisi data, pertahankan volume dan config, cadangkan database, dan jangan membuka ulang installer.
6. Login sebagai admin dan buka `/admin/license-activate`. Di terminal **penerbit lisensi**, buat lisensi untuk host yang sama dengan `DARMUTIARA_APP_URL`:

   ```sh
   python lisensi.py issue --domain DOMAIN-KAJIAN-ANDA --customer "Darul Mutaqin Sejahtera" --days 30
   ```

   Tempel nilai `license_key` yang diawali `DRM-`. Aplikasi akan memverifikasi tanda tangan Ed25519, domain, dan masa berlaku bukti dari penerbit. Setelah aktivasi, buka `/admin/kajian` sebagai admin dan `/kajian` sebagai anggota. Aplikasi memeriksa ulang bukti secara berkala. Masa berlaku bukti dan masa berlaku lisensi ditetapkan oleh penerbit.

## Pembaruan instalasi Kajian yang sudah ada

Cadangkan database, data unggahan, dan konfigurasi sebelum mengganti kode. Pertahankan `.env`, project Compose, serta volume yang sudah dipakai. Isi variabel penerbit dan URL pada `.env`. Periksa juga `APP_URL` di `config.php` yang sudah ada dan pastikan menunjuk domain HTTPS final. Variabel URL dipakai oleh installer ketika membuat config baru. Jalankan ulang `docker compose up -d --build` dan aktivasi ulang dengan lisensi DRM untuk domain tersebut. Untuk database lama yang belum punya tabel Kajian, jalankan `migrations/stifin-kajian.sql` setelah pencadangan.

## Aktifkan transfer otomatis voucher CAB

1. Pada instalasi dengan produk voucher yang sudah aktif, jalankan `migrations/stifin-voucher-transfers.sql` di database aplikasi. Untuk pemasangan produk voucher baru, `php scripts/setup-stifin-voucher.php` memasang kedua migrasi voucher. Pastikan akun promotor sudah ditautkan dan kode cabang sudah benar.
2. Setel `STIFIN_BRANCH_CODE=JML-CAB-62` untuk cabang JML, isi `STIFIN_API_USER_ID` dengan UserID resmi pengirim, lalu setel `STIFIN_VOUCHER_TRANSFER=1`. Simpan kredensial API di environment server jika STIFIn mensyaratkannya. Di Compose, buat ulang container app agar konfigurasi terbaca.
3. Antrean dibuat ketika pembayaran lunas. Cron Docker memeriksanya setiap siklus, sekitar lima menit, dan admin dapat memeriksanya langsung melalui **Stok Voucher Tes → Proses transfer tertunda**. Pakai invoice baru untuk uji transfer. Invoice lama tidak dimasukkan ulang oleh migrasi.
4. Untuk status **Perlu dicek**, cocokkan mutasi STIFIn dengan nomor invoice sebelum melakukan transfer manual. Aplikasi hanya mencoba ulang otomatis jika saldo awal gagal dibaca sebelum permintaan transfer dikirim.

## Pemasangan tanpa Docker

Untuk Apache di cPanel, gunakan PHP 8.2 dengan PDO MySQL, cURL dan Sodium. Periksa ekstensi tambahan yang ditampilkan installer, aktifkan dukungan `.htaccess`, gunakan HTTPS, dan pakai database kosong yang dibuat melalui cPanel. Sediakan `DARMUTIARA_ISSUER_PUBLIC_KEY` untuk proses PHP atau definisikan konstanta dengan nama yang sama di `config.php` setelah installer selesai. Mode ini perlu diuji sesuai konfigurasi hosting.

## Uji setelah pemasangan

Terbitkan kajian sebagai admin. Daftar dan unduh pengingat kalender sebagai anggota, kirim komentar, lalu coba sembunyikan dan tampilkan kembali komentar dari akun admin. Untuk kajian terbatas, cek dengan akun tanpa akses produk.
