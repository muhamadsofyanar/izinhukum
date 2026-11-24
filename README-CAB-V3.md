# CAB V3 — pembaruan dari V2

Ekstrak arsip dan unggah isi ke root branch main, lalu redeploy. Tidak ada migrasi database tambahan di V3; tabel peserta V2 digunakan kembali.

## Perubahan
- Admin: direktori promotor dengan lembaga, masa aktif, ekspor CSV tanpa kredensial, cetak PDF melalui browser.
- Admin: laporan tanggal/bulan, rekap pendaftaran per promotor, riwayat transfer invoice Mulia, tautan laporan pusat.
- Promotor tertaut: profil resmi sendiri, status/masa aktif, saldo atas permintaan, pendaftaran peserta lokal dengan jadwal, akses voucher.
- Password/PassID tidak ditampilkan atau dipindahkan. Perubahan akun pusat tetap melalui App Cabang yang resmi.

## Uji setelah deploy
1. Buka dashboard admin, direktori promotor dan ekspor. Pastikan hanya admin dapat mengakses direktori.
2. Buka Laporan Cabang; pilih tanggal dan bulan, cocokkan jumlah dengan data lokal.
3. Login sebagai promotor tertaut; cek profil dan saldo. Tambahkan peserta uji yang disetujui, pastikan terlihat pada admin dan hanya promotor terkait.
4. Pastikan promotor lain tidak dapat melihat peserta tersebut. Cek voucher dan checkout lama tanpa melakukan transfer nyata pada pengujian.
5. Laporan resmi pusat tetap dibuka melalui tautan App Cabang. Integrasi otomatis baru memerlukan dokumentasi API resmi.
