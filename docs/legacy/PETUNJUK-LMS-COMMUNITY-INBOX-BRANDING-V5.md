# Update LMS V5

Fitur:

- beberapa kelas dengan peserta dan progres terpisah;
- materi teks, video, tautan, dan unggah PDF maksimal 25 MB;
- Community untuk posting, lampiran, dan komentar admin/mitra;
- Inbox dua arah admin dengan mitra;
- pengaturan nama platform, tagline, dan logo;
- file unggahan disimpan dalam volume database agar tetap ada setelah redeploy.

## Pemasangan

1. Salin seluruh isi folder `izinhukum` ke repository tanpa menghapus `.git`.
2. Commit dan push.
3. Redeploy di Coolify.
4. Jangan hapus volume `/var/www/html/database`.

Migration `2026_07_28_000010` dijalankan otomatis oleh entrypoint.

## Pemeriksaan

- Admin: LMS Akademi, Community, Inbox, Logo & branding.
- Mitra: Kelas saya, Community, Inbox.
- Buat minimal dua kelas, publish, lalu daftarkan peserta.
- Tambahkan materi bertipe PDF dan unggah berkas.
