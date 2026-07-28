# LMS dan Portal Mitra IzinHukum

## Fitur admin

- Kelas tanpa batas, kategori, level, status terbit, kelas wajib, dan auto-enrol.
- Bab serta materi teks, video, PDF, tautan, tugas, dan kuis.
- Penugasan kepada seluruh mitra aktif atau mitra terpilih.
- Laporan progres, kelulusan, dan nomor sertifikat.
- Pengumuman, materi pemasaran, tiket bantuan, komisi, dan audit log.
- Pengaturan level mitra: starter, professional, dan priority.
- Status akun: pending, active, suspended, dan inactive.

## Fitur mitra

- Dashboard kelas, invoice, pengumuman, dan komisi.
- Menyelesaikan materi dan memperoleh sertifikat.
- Mengunduh materi pemasaran.
- Membuat serta memantau tiket bantuan.
- Melihat status komisi dan melengkapi rekening pembayaran.

## Deployment

Migration dijalankan otomatis oleh `docker/entrypoint.sh` ketika:

```env
RUN_MIGRATIONS=true
```

Konfigurasi database SQLite yang sekarang tetap didukung karena image memasang
`pdo_sqlite`. Dukungan MySQL juga tetap tersedia melalui `pdo_mysql`.

Untuk produksi, pasang persistent storage ke file database SQLite agar data tidak
hilang ketika container diganti:

```text
/var/www/html/database/database.sqlite
```

Alternatif jangka panjang adalah membuat resource MySQL di Coolify dan memakai
Internal Host database tersebut.
