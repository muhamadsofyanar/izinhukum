# Validasi IzinHukum V15

Tanggal pemeriksaan: 2 Agustus 2026 (Asia/Jakarta)

## Berhasil

- Arsip sumber V14 berhasil dibuka dan seluruh file proyek tersedia.
- `npm ci` selesai menggunakan dependency lockfile.
- `npm run build` berhasil: 113 modul ditransformasi dan aset produksi dibuat pada `public/build`.
- Pemeriksaan parser statis berhasil untuk 257 file PHP pada `app`, `bootstrap`, `config`, `database`, `routes`, dan `tests` tanpa kegagalan sintaks.
- Migrasi V15 mempertahankan harga admin lama dan tidak menghapus katalog tambahan pada rollback.
- Alur promo menyimpan snapshot terpisah dari atribusi referral.

## Tes yang disediakan

`tests/Feature/V15CouponAndCatalogTest.php` mencakup:

- penerbitan lima layanan baru tanpa harga buatan;
- pemeriksaan kupon per layanan;
- penolakan kupon pada layanan yang tidak dipilih;
- pencegahan konsumsi kupon sebelum paket berbasis penawaran memiliki harga;
- propagasi kupon ke inquiry, redemption, order, dan invoice;
- dukungan generator untuk PT PMA, Perkumpulan, serta Koperasi dan rujukan 2025.

## Keterbatasan lingkungan pemeriksaan

Perintah `php artisan test` belum dapat dijalankan di lingkungan penyusunan paket karena executable PHP, Docker, dan Podman tidak tersedia. Jalankan rangkaian tes tersebut pada CI atau server pengembangan yang menyediakan PHP 8.4 sebelum mempromosikan deployment ke produksi.
