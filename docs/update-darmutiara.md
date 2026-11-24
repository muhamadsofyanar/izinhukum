# Update satu klik STIFIn Mulia

Paket pertama diunggah manual ke root repo `muhamadsofyanar/darmutiarakajian`. Pertahankan posisi folder setiap file. Compose dalam paket ditujukan untuk resource Git berbasis Docker Compose pada Coolify, dengan proxy menuju service `app` pada port internal 80.

## Pengaturan sekali saja

1. Di Coolify, buka resource **darmutiarakajian** lalu menu **Webhooks**. Salin **Deploy Webhook (auth required)** untuk resource Kajian. Gunakan URL HTTPS yang dapat dijangkau dari container aplikasi.
2. Di Coolify **Keys & Tokens → API Tokens**, buat token dengan izin **deploy**. Bila API belum aktif, aktifkan API Access terlebih dahulu di pengaturan Coolify. Simpan nilai token secara privat.
3. Di Environment Variables resource Kajian, masukkan URL ke `DARMUTIARA_DEPLOY_WEBHOOK` dan token ke `DARMUTIARA_DEPLOY_TOKEN`. Aktifkan Available at Runtime dan matikan Available at Buildtime untuk keduanya. Simpan, lalu klik Deploy satu kali di Coolify. Jangan commit token ke GitHub.
4. Buka `/admin/update` sebagai admin. Klik **Cek Update**. Tombol **Pasang Update** tampil bila versi di GitHub lebih tinggi dan pengaturan deploy sudah terisi.

## Menerbitkan pembaruan berikutnya

1. Pastikan perubahan siap dipasang. Naikkan `APP_VERSION` di `version.php`, misalnya dari `1.2.262` menjadi `1.2.263`, pada commit yang memuat rilis di branch `main`. Untuk instalasi yang akan dikendalikan dari tombol, matikan auto deploy saat push jika aktif.
2. Untuk rilis yang mengubah struktur database, buat backup dan siapkan prosedur migrasi sebelum deploy. Tombol ini tidak menjalankan migrasi SQL.
3. Buka **Update Sistem → Cek Update → Pasang Update**. Coolify akan antrekan deploy terhadap branch yang dikonfigurasi pada resource. Pertahankan branch resource di `main` dan hindari mengubah branch saat proses berjalan.
4. Setelah deploy selesai, muat ulang halaman. Versi baru menunjukkan kode baru telah terpasang. Jika versi belum berubah, lihat log **Deployments** di Coolify. Tombol ini menggunakan izin deploy saja, sehingga tidak membaca progres atau status kegagalan dari API.
