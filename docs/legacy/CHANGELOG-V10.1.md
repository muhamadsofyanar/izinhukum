# Changelog V10.1

## Fixed

- Tanggal akhir laporan sebelumnya dapat berhenti pada pukul 00.00 bila kolom database berupa `DATETIME`.
- Invoice, pembayaran, dan pengeluaran pada tanggal akhir sekarang selalu ikut terbaca.
- Ringkasan invoice tidak lagi membingungkan antara dokumen aktif dan dibatalkan.
- Pesan pemeriksaan data tidak lagi menyatakan semua data benar tanpa menampilkan hasil konsistensi periode.
- Status invoice dan tipe penerima memakai bahasa Indonesia.
- Panel kategori tidak lagi menyisakan ruang kosong besar.

## Changed

- Sinkronisasi invoice hanya berjalan setelah tombol `Periksa data lama` ditekan.
- Form input transaksi dan kategori menggunakan panel lipat agar halaman laporan lebih pendek.
- Ditambahkan filter cepat pada tabel invoice periode.
