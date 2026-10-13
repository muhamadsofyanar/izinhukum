# Redeploy IzinHukum V21 Sekali Jalan

Paket V21 sudah mencakup V17, V18, V19, V20, dan penyempurnaan seluruh landing layanan. Cukup unggah source lengkap lalu lakukan satu kali redeploy.

## Sebelum redeploy

1. Cadangkan database dan persistent volume `storage`.
2. Jangan mengganti `APP_KEY`.
3. Pertahankan `SEED_DATABASE=false` agar harga dan data admin tidak tertimpa.
4. Pastikan `RUN_MIGRATIONS=true`.
5. Unggah seluruh isi paket V21 ke repository yang dipakai Coolify.

## Redeploy

Klik **Redeploy** satu kali. Startup akan menjalankan migrasi V21 secara otomatis. Migrasi menambah field konten dan SEO pada tabel layanan, mengisi layanan lama berdasarkan data paket/persyaratan, serta mengaktifkan feature flag `service_landing_pages`.

Tidak ada environment variable baru yang wajib.

## Pemeriksaan setelah deploy

1. Buka `/healthz`; pastikan `status=healthy` dan `version=21.0.1`.
2. Buka homepage dan pastikan logo, tagline, serta aksen navy–gold tampil.
3. Buka minimal tiga URL `/layanan/{slug}` dari kategori berbeda.
4. Pastikan hero khusus, paket, proses, persyaratan, FAQ, dan form konsultasi tampil.
5. Pilih satu paket; pastikan halaman menggulir ke form dan paket otomatis terpilih.
6. Kirim lead uji; pastikan nomor referensi dibuat dan WhatsApp terbuka tanpa mengirim pesan otomatis.
7. Buka **Admin → Landing layanan**, ubah satu headline, simpan, lalu cek pratinjau.
8. Buka **Admin → Fitur aplikasi** dan pastikan **Landing page setiap layanan** aktif.

## Jika perlu kembali sementara

Matikan **Landing page setiap layanan** melalui **Admin → Fitur aplikasi**. Halaman layanan kembali memakai template lama, sedangkan konten V21 tetap tersimpan. Perbaiki masalah, lalu aktifkan kembali tanpa redeploy.

Jangan menjalankan `migrate:rollback` di produksi tanpa cadangan karena rollback menghapus kolom konten V21.
