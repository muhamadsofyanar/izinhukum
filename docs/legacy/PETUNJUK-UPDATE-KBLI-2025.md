# Update Cek Risiko KBLI 2025

Paket ini hanya berisi file baru dan file yang perlu diganti pada repository IzinHukum.

## Cakupan data

- 1.559 kode KBLI 2025 dari Peraturan BPS Nomor 7 Tahun 2025.
- 2.401 ruang lingkup kegiatan dari halaman publik OSS.
- 17.346 profil risiko berdasarkan ruang lingkup dan skala usaha.
- Empat tingkat risiko: Rendah, Menengah Rendah, Menengah Tinggi, dan Tinggi.
- Perizinan, persyaratan, kewajiban, jangka waktu, luas lahan, dan kewenangan ditampilkan apabila dicantumkan oleh OSS.
- Kode yang belum mempunyai profil risiko publik di OSS tetap ditampilkan dengan pemberitahuan yang jujur.

## File baru

- `app/Models/KbliRiskProfile.php`
- `app/Models/KbliScope.php`
- `database/data/kbli-2025.json`
- `database/migrations/2026_07_28_000005_upgrade_kbli_codes_for_2025.php`
- `resources/views/kbli-detail.blade.php`
- `scripts/sync-kbli-2025.mjs`

## File yang diganti

- `.env.docker.example`
- `.env.example`
- `Dockerfile`
- `README.md`
- `app/Http/Controllers/KbliController.php`
- `app/Models/KbliCode.php`
- `composer.json`
- `database/seeders/KbliSeeder.php`
- `docker/entrypoint.sh`
- `resources/css/app.scss`
- `resources/views/kbli.blade.php`
- `routes/web.php`
- `tests/Feature/PublicPagesTest.php`

Tidak ada file yang perlu dihapus.

## Cara memasang

1. Ekstrak isi ZIP ke root repository dan izinkan file dengan nama yang sama diganti.
2. Commit dan push seluruh perubahan ke branch `main`.
3. Di Environment Variables Coolify, pertahankan:

   ```dotenv
   SEED_DATABASE=false
   ```

4. Redeploy dan tunggu sampai container berstatus `healthy`. Entrypoint menjalankan `php artisan kbli:ensure` otomatis.
5. Periksa pencarian `/cek-risiko-kbli` dan buka beberapa halaman detail.
6. Dataset tidak disemai ulang jika tabel sudah berisi tepat 1.559 kode KBLI 2025.

Jangan mengaktifkan `SEED_DATABASE=true` pada pembaruan ini karena opsi tersebut menjalankan seluruh seeder dan dapat menimpa data layanan yang pernah diubah melalui admin.

## Pemeriksaan setelah deploy

- Cari `restoran`, lalu buka KBLI `56101`.
- Pastikan pilihan skala usaha dan tingkat risiko muncul.
- Pastikan tautan **Bandingkan di OSS** membuka halaman sumber resmi.
- Pastikan `/up` tetap memberikan status sehat.
- Jalankan `php artisan kbli:ensure` dan pastikan keluar dengan status berhasil.

Hasil pada website merupakan pemeriksaan awal. Penetapan perizinan resmi tetap mengikuti data proyek dan proses pada OSS, termasuk ruang lingkup, skala usaha, lokasi, luas lahan, persyaratan dasar, dan ketentuan sektoral.
