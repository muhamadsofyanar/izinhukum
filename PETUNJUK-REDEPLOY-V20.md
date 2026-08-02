# Redeploy IzinHukum V17–V20 Sekali Jalan

Paket V20 menggabungkan pengembangan V17, V18, V19, dan V20 dalam satu source dan satu kali redeploy. Fokusnya adalah mengubah lebih banyak lead menjadi deal tanpa mengaktifkan automation WhatsApp baru.

## Hasil setiap versi

- **V17 — Prioritas lead:** skor 0–100, kategori panas/hangat/dingin, waktu respons pertama, dan urutan kerja berdasarkan peluang.
- **V18 — Playbook manual:** template pesan sesuai tahap pipeline dan tautan WhatsApp siap isi. Tidak ada pesan, follow-up, atau broadcast yang dikirim otomatis.
- **V19 — Penawaran dan pemulihan:** template ruang lingkup/ketentuan penawaran, alasan tidak lanjut terstruktur, dan jadwal menghubungi kembali lead.
- **V20 — Funnel dan ROI:** funnel lead sampai selesai, rekomendasi tindakan harian, pencatatan biaya campaign, biaya per lead, pembayaran teratribusi, dan ROI.

## Sebelum redeploy

1. Cadangkan database dan persistent volume `storage`.
2. Jangan mengganti `APP_KEY`.
3. Pertahankan `SEED_DATABASE=false` agar harga dan data yang diubah melalui admin tidak tertimpa.
4. Pastikan `RUN_MIGRATIONS=true`.
5. Unggah seluruh isi paket V20 ke repository yang digunakan Coolify.

Tidak ada environment variable baru yang wajib. Konfigurasi notifikasi transaksi yang sudah ada tidak diubah.

## Satu kali redeploy

Setelah source V20 sudah masuk ke repository, klik **Redeploy** satu kali di Coolify. Entrypoint akan menjalankan migrasi tertunda secara otomatis.

Empat migrasi V17–V20:

- menambah skor, suhu lead, waktu respons, alasan tidak lanjut, dan jadwal aktivasi ulang;
- membuat serta mengisi template pesan manual;
- membuat template penawaran dan menghubungkannya ke histori penawaran;
- membuat campaign terukur dan menghubungkan inquiry lama berdasarkan UTM;
- mengaktifkan lima feature flag baru tanpa menghapus data lama.

Data lead lama diberi skor. Aktivitas kontak, penawaran, dan status deal lama yang tersedia ikut dipakai untuk mengisi funnel awal.

## Pemeriksaan setelah deploy

1. Buka `/healthz`; pastikan `status=healthy` dan `version=20.0.0`.
2. Masuk ke **Admin → Fitur aplikasi**. Pastikan Prioritas lead, Playbook manual, Template penawaran, Pemulihan lead, serta Biaya dan ROI campaign aktif.
3. Buka **Pipeline penjualan**. Pastikan lead memiliki skor dan label panas/hangat/dingin.
4. Klik salah satu tombol playbook. Pastikan tab WhatsApp terbuka dengan pesan terisi dan pesan belum terkirim.
5. Buat draf penawaran. Pilih template dan pastikan ruang lingkup, ketentuan, masa berlaku, serta jatuh tempo terisi.
6. Ubah satu lead uji ke **Tidak lanjut**, isi alasan dan tanggal hubungi kembali. Pastikan lead muncul pada daftar pemulihan saat waktunya tiba.
7. Buat campaign pada **Campaign & ROI**, salin tautan UTM, lalu kirim proposal uji melalui tautan tersebut.
8. Buka **Funnel & Pertumbuhan**. Pastikan campaign, funnel, prioritas harian, biaya per lead, pembayaran, dan ROI tampil.

## Peluncuran bertahap tanpa redeploy

Source lengkap cukup dideploy satu kali. Sesudah itu modul dapat dinyalakan atau dimatikan melalui **Admin → Fitur aplikasi**. Menonaktifkan fitur hanya menutup menu/rute terkait; tabel dan histori tetap tersimpan.

## Jika terjadi kendala

- Periksa log startup dan pastikan seluruh migrasi berstatus selesai.
- Jalankan `php artisan migrate:status` dari terminal container untuk melihat migrasi yang belum berjalan.
- Bersihkan cache aplikasi dengan `php artisan optimize:clear`, lalu muat ulang halaman admin.
- Utamakan mematikan feature flag jika satu modul perlu dihentikan sementara.
- Jangan menjalankan `migrate:rollback` di produksi tanpa cadangan karena rollback dapat menghapus kolom dan tabel V17–V20.
