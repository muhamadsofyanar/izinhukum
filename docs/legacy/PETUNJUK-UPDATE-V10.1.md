# IzinHukum V10.1 - Perbaikan Laporan Keuangan

Patch ini dipasang di atas IzinHukum V10 yang sudah aktif.

## Perbaikan utama

1. Filter tanggal memakai rentang setengah terbuka. Seluruh invoice, pembayaran, dan pengeluaran pada tanggal akhir ikut terbaca, termasuk bila kolom tersimpan sebagai `DATETIME`.
2. Ringkasan membedakan invoice aktif, invoice dibatalkan, dan seluruh dokumen.
3. Laporan menampilkan peringatan jika status invoice tidak konsisten dengan pembayaran aktif.
4. Tombol sinkronisasi diubah menjadi `Periksa data lama` dan tidak lagi menjalankan perubahan data setiap kali halaman laporan dibuka.
5. Invoice dibatalkan tidak masuk nilai invoice aktif, piutang, atau pemasukan.
6. Halaman laporan diringkas. Form input transaksi dan kategori dipindahkan ke panel yang dapat dibuka saat diperlukan.
7. Panel kategori menggunakan lebar penuh dan menampilkan daftar kategori secara jelas.
8. Status invoice pada daftar invoice menggunakan bahasa Indonesia. `End user` diubah menjadi `Pelanggan langsung`.
9. Ekspor CSV dan laporan cetak memakai data periode yang sama dengan tampilan layar.

## Cara memasang

1. Backup database dan persistent storage.
2. Ekstrak ZIP.
3. Upload seluruh isi hasil ekstraksi ke root repositori GitHub. Pertahankan struktur folder.
4. Commit ke branch `main`.
5. Tekan `Redeploy` di Coolify.
6. Setelah healthcheck sehat, lakukan hard refresh dengan `Ctrl+F5`.
7. Buka Laporan Keuangan dan pilih periode 01/07/2026 sampai 29/07/2026.
8. Pastikan invoice tanggal 29/07/2026 muncul.
9. Tekan `Periksa data lama` satu kali jika ada invoice lunas lama yang belum memiliki pembayaran aktif.

Patch tidak menambah migration dan tidak mengubah data secara otomatis saat halaman laporan dibuka.
