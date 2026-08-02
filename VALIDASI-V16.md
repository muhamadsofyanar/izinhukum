# Validasi IzinHukum V16

Tanggal pemeriksaan: 2 Agustus 2026 (Asia/Jakarta)

## Berhasil

- Arsip V15 dikembangkan langsung menjadi V16 tanpa menjalankan seeder ulang.
- `npm run build` berhasil: 113 modul ditransformasi dan aset produksi dibuat pada `public/build`.
- Pemeriksaan parser statis berhasil untuk 271 file PHP pada `app`, `bootstrap`, `config`, `database`, `routes`, dan `tests` tanpa kegagalan sintaks.
- Alur campaign → inquiry → pipeline menggunakan snapshot UTM terpisah dari referral dan kupon.
- Persetujuan penawaran bersifat terkunci/idempoten dan membuat invoice melalui transaksi database.
- Bukti pembayaran memakai storage privat, validasi tipe/ukuran, checksum anti-duplikat, pemeriksaan admin, serta service pembayaran lama.
- Lima modul baru dapat dikendalikan melalui feature flags setelah satu kali redeploy.
- Aturan print diperbaiki agar invoice/penawaran tidak tertutup oleh aturan print simulator.

## Tes yang disediakan

`tests/Feature/V16OneDeploySuiteTest.php` mencakup:

- penyimpanan UTM dan pembuatan lead pipeline otomatis;
- penerbitan/persetujuan penawaran dan pencegahan invoice ganda;
- unggah bukti pembayaran serta pembuatan pembayaran/kwitansi setelah persetujuan admin.

Seluruh tes V15 dan versi sebelumnya tetap disertakan.

## Keterbatasan lingkungan pemeriksaan

`php artisan test` belum dapat dijalankan di lingkungan penyusunan paket karena executable PHP, Docker, dan Podman tidak tersedia. Jalankan `php artisan test` pada CI atau server pengembangan PHP 8.4 sebelum mempromosikan deployment ke produksi.
