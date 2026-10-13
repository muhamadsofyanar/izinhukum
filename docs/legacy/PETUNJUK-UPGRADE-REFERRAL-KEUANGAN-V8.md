# Petunjuk Upgrade Referral dan Keuangan V8

## Perubahan utama

- Invoice lama berstatus lunas tanpa kwitansi otomatis dibuatkan pembayaran.
- Tanggal konversi memakai `paid_at`. Jika kosong, sistem memakai `updated_at`.
- Pembayaran aktif otomatis masuk laporan keuangan.
- Landing kemitraan memiliki paket Gratis, Berbayar, dan Prioritas.
- Tautan mitra memakai format `/proposal?ref=KODE-MITRA`.
- Proposal, invoice, pembayaran, dan komisi dapat dilacak ke mitra asal.
- Koreksi dan pembatalan kwitansi otomatis menyinkronkan komisi.

## Sebelum deploy

1. Cadangkan `database/database.sqlite`.
2. Pastikan Persistent Storage tetap menuju `/var/www/html/database`.
3. Pastikan Persistent Storage tetap menuju `/var/www/html/storage`.
4. Pastikan `RUN_MIGRATIONS=true`.
5. Pastikan `SEED_DATABASE=false`.
6. Jangan menghapus volume lama.

## Saat deploy

Startup menjalankan:

```text
php artisan migrate --force
php artisan finance:reconcile-legacy-paid-invoices --no-interaction
```

Perintah rekonsiliasi aman dijalankan ulang. Invoice yang sudah memiliki pembayaran tidak diproses lagi.

## Pemeriksaan setelah deploy

1. Buka `/admin/keuangan`.
2. Pilih rentang tanggal yang mencakup `paid_at` invoice lama.
3. Pastikan invoice lama muncul sebagai pemasukan dengan nomor `KWT-MIG`.
4. Buka `/kemitraan`.
5. Pastikan tiga paket dan nominal tampil.
6. Masuk sebagai mitra dan salin tautan referral.
7. Buka tautan referral melalui browser privat.
8. Kirim satu proposal percobaan.
9. Buka `/admin/permintaan` dan pastikan nama serta kode mitra muncul.
10. Klik **Buat invoice** dari proposal.
11. Tandai invoice sebagai terkirim.
12. Catat pembayaran.
13. Buka menu **Komisi mitra** dan pastikan komisi otomatis tersedia.
14. Koreksi nominal pembayaran dan periksa perubahan komisi.
15. Batalkan kwitansi percobaan dan pastikan transaksi keluar dari laporan.

## Catatan audit

Pembayaran hasil migrasi memakai:

- sumber `legacy_invoice_migration`;
- metode `other`;
- nomor referensi `MIGRASI-INVOICE`;
- catatan bahwa transaksi dibuat otomatis.

Periksa metode dan nomor referensi secara manual bila data bank lama tersedia.

