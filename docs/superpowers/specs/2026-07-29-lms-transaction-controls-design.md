# Desain LMS Terfokus dan Kontrol Transaksi Aman

## Tujuan

Merapikan pengalaman belajar mitra dan menambahkan pengelolaan invoice serta kwitansi tanpa merusak histori laporan keuangan.

## Keputusan Desain

### LMS

Halaman kelas menampilkan satu materi aktif. Panel kiri memuat seluruh bab dan materi, status selesai, serta progres kelas. Panel utama memuat isi materi aktif, video YouTube tertanam, PDF privat, tombol selesai, dan navigasi materi sebelumnya atau berikutnya.

Materi pertama yang belum selesai menjadi materi aktif bawaan. Parameter `materi` memilih materi tertentu selama materi tersebut berada di kelas yang sama. Materi di luar kelas menghasilkan respons 404.

### Invoice

Invoice draf tanpa pembayaran dapat diedit atau dihapus permanen oleh admin atau pembuatnya. Invoice yang sudah diterbitkan tidak dapat diedit atau dihapus. Invoice terkirim hanya dapat dibatalkan dengan alasan.

Pembatalan menyimpan waktu, pengguna, alasan, alamat IP, dan catatan audit. Invoice yang memiliki pembayaran aktif tidak dapat dibatalkan.

### Kwitansi dan Pembayaran

Kwitansi tidak dihapus permanen. Admin dapat mengoreksi pembayaran aktif dengan alasan. Koreksi menyimpan nilai sebelum dan sesudah di audit log. Admin dapat membatalkan pembayaran dengan alasan. Dokumen kwitansi yang dibatalkan tetap dapat diverifikasi, tetapi menampilkan status dibatalkan.

Pembayaran yang dibatalkan tidak masuk ke pemasukan, saldo, arus kas, atau jumlah terbayar invoice. Setelah koreksi atau pembatalan, status invoice dihitung ulang menjadi terkirim, dibayar sebagian, atau lunas.

## Struktur Data

Tabel `invoices` mendapat `cancelled_at`, `cancelled_by`, dan `cancellation_reason`.

Tabel `payments` mendapat `status`, `cancelled_at`, `cancelled_by`, `cancellation_reason`, `last_edited_at`, dan `last_edited_by`.

Audit detail disimpan pada `audit_logs`. Data lama otomatis dianggap aktif.

## Hak Akses

- Admin dapat mengelola semua invoice dan pembayaran.
- Mitra hanya dapat mengedit atau menghapus invoice draf yang dibuatnya.
- Mitra dapat membatalkan invoice terkirim yang dibuatnya selama belum memiliki pembayaran.
- Hanya admin yang dapat mengoreksi atau membatalkan kwitansi.
- Peserta LMS hanya dapat membuka materi kelas tempat ia terdaftar.

## Penanganan Kesalahan

- Edit atau hapus invoice non-draf menghasilkan pesan validasi.
- Pembatalan tanpa alasan ditolak.
- Pembatalan invoice yang sudah memiliki pembayaran aktif ditolak.
- Koreksi pembayaran tidak boleh membuat total pembayaran aktif melebihi total invoice.
- Pembayaran yang sudah dibatalkan tidak dapat dikoreksi atau dibatalkan ulang.
- Materi yang tidak termasuk kelas menghasilkan 404.

## Pengujian

Pengujian fitur mencakup pemilihan satu materi aktif, navigasi LMS, edit dan hapus invoice draf, penguncian invoice terkirim, alasan pembatalan, audit log, koreksi kwitansi, pembatalan kwitansi, perhitungan ulang status invoice, dan pengecualian transaksi dibatalkan dari laporan keuangan.

## Batas Scope

Versi ini tidak menambahkan periode akuntansi terkunci, jurnal berpasangan, persetujuan berjenjang, atau penghapusan permanen pembayaran. Fitur tersebut merupakan tahap pengembangan berikutnya.
