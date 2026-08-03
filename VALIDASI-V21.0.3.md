# Validasi IzinHukum V21.0.3

Tanggal validasi: 3 Agustus 2026 (Asia/Jakarta)

## Hasil

- Log produksi diperiksa dan kegagalan terlokalisasi pada migrasi `000029` ketika campaign memakai kolom `marketing_campaigns.service_id` yang tidak tersedia.
- Migrasi diperbaiki agar menambahkan `service_id` secara kondisional sebelum `coupon_id` dan sebelum data campaign ditulis.
- Jalur retry aman untuk percobaan sebelumnya yang mungkin sudah menambah `coupon_id`, layanan Yayasan, paket, kupon, atau pivot kupon.
- Parser sintaks PHP: **391 file** berhasil diparsing tanpa error.
- Health version dan pengujian fondasi diperbarui ke **21.0.3**.
- Tidak ada perubahan environment variable dan tidak diperlukan rollback migration atau seeder.

## Validasi runtime produksi

Eksekusi migrasi dan healthcheck final harus terjadi di container Coolify karena environment penyusunan paket tidak menyediakan executable PHP, Composer, atau Docker. Ikuti `PETUNJUK-REDEPLOY-V21.0.3.md` dan pastikan migrasi `000029` berstatus `Ran`.
