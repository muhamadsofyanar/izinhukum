# Pembaruan panel lisensi Darmutiara Kajian

Unggah folder `pages` dan `actions` dari ZIP ke root repositori Darmutiara Kajian. Pertahankan struktur dan timpa tiga file yang sesuai. ZIP pembaruan ini hanya memuat tiga file aplikasi.

Panel menampilkan domain yang dipakai saat aktivasi dari `APP_URL`. Halaman detail menampilkan kunci tersamarkan, waktu pemeriksaan terakhir, dan batas berlaku bukti verifikasi. Batas bukti verifikasi berbeda dari masa berlaku paket. Kunci lengkap dapat dibuka oleh admin penerbit di dashboard server lisensi sesuai pengaturan penyimpanan server tersebut.

## Uji sebelum produksi

1. Deploy ke lingkungan uji dengan domain yang sesuai lisensi. Buka **Lisensi**. Pastikan domain cocok dengan `APP_URL` dan kunci tersimpan tampil tersamarkan.
2. Klik **Cek Ulang Lisensi** dengan kunci uji yang valid. Pastikan status aktif dan waktu verifikasi terbaru muncul.
3. Dari penerbit, ganti kunci untuk lisensi uji. Masukkan kunci baru di **Ganti kunci lisensi** dan verifikasi.
4. Buka halaman aktivasi. Untuk instalasi dengan kunci tersimpan, tombol **Cek kunci tersimpan** memakai kunci dari database. Untuk pemasangan baru, tempel kunci DRM yang diterbitkan untuk domain tersebut.

Pembaruan ini tidak membutuhkan migrasi database. Untuk rollback, pulihkan tiga file dari commit sebelumnya dan redeploy.
