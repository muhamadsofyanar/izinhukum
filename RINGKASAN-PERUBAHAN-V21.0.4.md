# IzinHukum V21.0.4 · Rekonsiliasi Skema Campaign Lengkap

V21.0.4 mengganti perbaikan satu-kolom dengan rekonsiliasi seluruh struktur `marketing_campaigns` yang dibutuhkan aplikasi.

Perubahan utama:

- mendeteksi seluruh kolom campaign yang tersedia pada database produksi;
- menambahkan hanya kolom yang hilang;
- dapat membuat ulang tabel campaign bila migrasi lama tercatat tetapi tabel tidak tersedia;
- memastikan relasi campaign pada inquiry tersedia;
- aman untuk retry setelah percobaan migrasi `000029` gagal sebagian;
- mempertahankan seluruh fungsi landing dan promo Yayasan;
- healthcheck dinaikkan ke versi `21.0.4`.
