# Validasi IzinHukum V21.0.2

Tanggal validasi: 3 Agustus 2026 (Asia/Jakarta)

## Hasil

- Parser sintaks PHP: **291 file** berhasil diparsing tanpa error.
- Build produksi Vite: **berhasil**, 113 modul ditransformasi.
- Aset CSS produksi baru: `app-DXu9vL_o.css`.
- Health version dan pengujian fondasi: **21.0.2**.
- Migration baru memiliki nomor urut unik `2026_08_03_000029`.
- Landing layanan Yayasan, landing campaign, kupon, paket, admin campaign, dan atribusi inquiry terhubung secara statis.
- Harga paket yang sudah ada dipertahankan oleh migration.
- Kupon tidak dapat dipakai setelah periode/kuota habis atau pada paket di bawah minimum subtotal.

## Pengujian regresi yang ditambahkan

`tests/Feature/V2102YayasanClosingTest.php` memeriksa:

1. halaman layanan Yayasan menampilkan konten khusus, kode promo, dan harga Rp3.700.000;
2. paket tidak lagi memuat manfaat generik `Kartu nama direktur`;
3. landing campaign otomatis membawa `YAYASAN300`;
4. lead tercatat pada campaign dan memperoleh potongan Rp300.000;
5. jawaban kualifikasi Yayasan tersimpan pada catatan lead;
6. admin dapat melihat hubungan campaign dengan kupon.

## Batas lingkungan validasi

Environment pengerjaan tidak menyediakan executable PHP, Composer, atau Docker. PHPUnit dan eksekusi migration database harus dijalankan oleh container Coolify. Pemeriksaan runtime produksi dicantumkan pada `PETUNJUK-REDEPLOY-V21.0.2.md`.
