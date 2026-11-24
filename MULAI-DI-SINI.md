# Deploy Kajian untuk STIFIn Mulia

Paket ini berisi aplikasi Kajian lengkap dengan patch panel lisensi dan Voucher Tes. Gunakan repositori GitHub privat baru, misalnya stifinmulia-kajian. Unggah isi folder paket agar Dockerfile dan compose.yaml langsung terlihat di root. Workflow rilis upstream dikeluarkan dari paket karena deploy menggunakan Coolify.

1. Buat resource baru di Coolify dari repositori Kajian tersebut. Pilih Docker Compose, file compose.yaml, dan arahkan domain uji ke service app port internal 80. Pertahankan resource Branch Manager dan volume WSL selama pengujian.
2. Isi DARMUTIARA_APP_URL dengan URL HTTPS instalasi dan DARMUTIARA_ISSUER_PUBLIC_KEY dengan kunci publik penerbit. Isi DARMUTIARA_DB_PASSWORD dan DARMUTIARA_DB_ROOT_PASSWORD dengan kata sandi baru. Gunakan volume baru milik resource Kajian.
3. Deploy. Untuk database baru dan kosong, ikuti tabel installer di README-INSTALASI-STIFIN.md. Pakai host db, database darmutiara, pengguna darmutiara, serta DARMUTIARA_DB_PASSWORD. Aktifkan DRM yang diterbitkan untuk hostname tersebut.
4. Ikuti README-VOUCHER-STIFIN.md untuk migrasi, produk voucher, konfigurasi STIFIN_VOUCHER_PRODUCT_ID, impor kode, dan uji pembayaran.
5. Pindahkan domain utama setelah staging lolos dan rencana pemindahan pengguna serta akses WSL dipastikan. Domain utama memerlukan konfigurasi URL dan lisensi DRM untuk stifinmulia.com.

Akun serta progres WSL dari Branch Manager belum dimigrasikan. Kredensial dan database produksi tidak disertakan.

## Identitas cabang

Nama bawaan untuk instalasi baru adalah STIFIn Mulia. Ubah nama, logo resmi, favicon, dan warna untuk tiap cabang melalui menu Branding. Pengaturan yang sudah tersimpan dalam database tetap mengungguli nilai bawaan dari paket. Jika memasang pada database lama, ubah identitas dari panel admin setelah deploy dan jangan jalankan ulang installer.

Paket ini menggunakan satu instalasi dan database per cabang. Untuk cabang lain, siapkan domain, volume, database, konfigurasi pembayaran, stok voucher, serta lisensi penerbit tersendiri. Kelayakan penggunaan merek dan distribusi komersial perlu dikonfirmasi kepada pemegang hak terkait. Lihat NOTICE-ASAL-KODE.md untuk atribusi.
