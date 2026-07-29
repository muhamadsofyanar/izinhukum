# Integrasi Keuangan, Paket Mitra, dan Referral

## Tujuan

Menghubungkan pembayaran, laporan keuangan, paket mitra, referral, invoice, dan komisi dalam satu alur yang dapat diaudit.

## Keputusan bisnis

1. Invoice lama berstatus `paid` tanpa pembayaran akan dikonversi otomatis.
2. Tanggal transaksi memakai `paid_at`. Jika kosong, sistem memakai `updated_at`.
3. Konversi bersifat idempoten. Menjalankan perintah berulang tidak membuat pembayaran kedua.
4. Paket publik memakai nama Gratis, Berbayar, dan Prioritas. Nilai internal tetap `starter`, `professional`, dan `priority` agar data lama tetap kompatibel.
5. Harga awal per tahun:
   - Gratis: Rp0 dan komisi referral 5%.
   - Berbayar: Rp499.000 dan komisi referral 10%.
   - Prioritas: Rp1.499.000 dan komisi referral 15%.
6. Paket Berbayar menjadi rekomendasi pada landing page.
7. Atribusi referral menggunakan metode last valid click selama 30 hari.
8. Komisi dihitung dari pembayaran aktif, bukan nilai invoice.

## Alur keuangan lama

Perintah rekonsiliasi mencari invoice yang memenuhi seluruh syarat berikut:

- status `paid`;
- total lebih dari nol;
- belum memiliki baris pembayaran apa pun.

Sistem membuat pembayaran dengan sumber `legacy_invoice_migration`, nomor kwitansi khusus migrasi, metode `other`, dan catatan audit. `source_key` unik mencegah duplikasi. Pembayaran hasil konversi langsung dibaca laporan keuangan.

## Alur referral

1. Mitra membagikan tautan `/proposal?ref=KODE-MITRA`.
2. Middleware memvalidasi kode mitra aktif.
3. Sistem menyimpan kunjungan, kode, dan token pengunjung selama 30 hari.
4. Proposal menyimpan mitra asal dan snapshot kode referral.
5. Admin dapat membuat invoice dari proposal.
6. Invoice membawa mitra asal tanpa memakai kolom penerima mitra.
7. Pembayaran aktif membuat satu komisi per pembayaran.
8. Koreksi pembayaran menghitung ulang komisi.
9. Pembatalan pembayaran membatalkan komisi yang belum dibayar. Komisi yang sudah dibayar berubah menjadi `adjustment_required`.

## Batas data

Kolom `partner_id` pada invoice tetap berarti mitra penerima invoice. Atribusi pemasaran memakai `referred_by_partner_id`. Pemisahan ini mencegah satu kolom memiliki dua arti.

## Antarmuka

- Landing kemitraan menampilkan tiga kartu paket, harga, komisi, manfaat, dan pilihan paket pada formulir.
- Panel admin menampilkan nama paket dalam bahasa Indonesia, harga, paket yang dipilih pendaftar, dan sumber mitra pada permintaan.
- Dashboard mitra menampilkan tautan referral, jumlah klik, prospek, invoice, omzet terbayar, dan komisi.
- Halaman invoice admin menampilkan mitra referral.
- Daftar komisi menampilkan invoice, kwitansi, tarif, nilai, sumber, dan status.

## Penanganan kesalahan

- Kode referral tidak valid atau mitra tidak aktif diabaikan.
- Referral tidak dapat dipalsukan melalui input formulir karena server mengambil atribusi dari cookie dan data mitra aktif.
- Pembayaran migrasi tidak dibuat bila invoice sudah memiliki kwitansi aktif maupun batal.
- Komisi memakai `payment_id` unik agar satu pembayaran tidak menghasilkan dua komisi.

## Pengujian

- Konversi invoice lama memakai `paid_at`.
- Fallback tanggal memakai `updated_at`.
- Rekonsiliasi kedua tidak membuat duplikasi.
- Pembayaran migrasi muncul pada laporan.
- Tautan referral valid tersimpan pada proposal.
- Kode tidak valid tidak menghasilkan atribusi.
- Invoice dari proposal membawa mitra referral.
- Pembayaran, koreksi, dan pembatalan menyinkronkan komisi.
- Landing page menampilkan tepat tiga paket dan nominal yang benar.

