# IzinHukum — Paket Final Gabungan

Paket ini sudah menggabungkan:

1. Proyek dasar IzinHukum Laravel untuk Coolify.
2. Pembaruan KBLI 2025 beserta dataset, migration, seeder, model, controller, dan halaman publik.
3. Sistem Mitra LegaOne, invoice, artikel, SMTP, profil, pendaftaran kemitraan, pelacakan permintaan, serta halaman legal dan persyaratan badan usaha.

## Pemasangan

Ekstrak seluruh isi ZIP ini langsung ke root repository `izinhukum`, lalu pilih **Replace/Timpa semua file**. Setelah itu commit dan push ke branch `main`.

## Deployment pertama

Pertahankan nilai rahasia yang sudah aktif dan gunakan:

```env
RUN_MIGRATIONS=true
SEED_DATABASE=false
```

Simpan lalu redeploy di Coolify. Entrypoint menjalankan `php artisan kbli:ensure` pada setiap deployment. Perintah ini hanya menyinkronkan dataset jika data KBLI 2025 belum berjumlah 1.559.

Jangan mengosongkan atau mengganti `APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, dan `ADMIN_PASSWORD` tanpa kebutuhan.

Untuk pembaruan dari V5, ikuti `PETUNJUK-UPGRADE-KEUANGAN-LMS-KBLI.md` agar database dan file unggahan lama dicadangkan sebelum container diganti.
