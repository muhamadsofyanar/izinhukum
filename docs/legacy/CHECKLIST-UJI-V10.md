# Checklist Uji IzinHukum V10

Gunakan checklist ini setelah Coolify menyatakan container healthy.

## A. Deployment

- [ ] Build Docker selesai.
- [ ] Migrasi `2026_07_29_000015` berhasil.
- [ ] Command rekonsiliasi selesai.
- [ ] Command backfill order selesai.
- [ ] `/healthz` mengembalikan HTTP 200.
- [ ] Database berstatus `ok`.
- [ ] Storage berstatus `ok`.

## B. Data lama

- [ ] Setiap permintaan lama memiliki maksimal satu order.
- [ ] Invoice dengan `inquiry_id` terhubung ke order yang benar.
- [ ] Invoice lunas lama memiliki pembayaran atau kwitansi rekonsiliasi.
- [ ] Menekan sinkronisasi kedua kali tidak membuat order ganda.

## C. Alur transaksi utama

- [ ] Pengunjung mengirim proposal.
- [ ] Sistem membuat referensi proposal.
- [ ] Sistem membuat nomor order.
- [ ] Admin membuka order.
- [ ] Admin menerbitkan invoice.
- [ ] Pembayaran sebagian mengubah status order menjadi Dibayar sebagian.
- [ ] Pelunasan mengubah status order menjadi Lunas.
- [ ] Setiap pembayaran aktif memiliki kwitansi.
- [ ] Laporan keuangan menampilkan pemasukan sesuai pembayaran aktif.
- [ ] Komisi mitra mengikuti pembayaran yang valid.
- [ ] Pembatalan pembayaran memperbarui laporan dan referral.

## D. Order operasional

- [ ] Pencarian order bekerja.
- [ ] Filter status, pembayaran, dan prioritas bekerja.
- [ ] Deadline lewat diberi indikator Terlambat.
- [ ] Petugas dapat ditetapkan.
- [ ] Checklist dapat ditambah, dicentang, dan dihapus.
- [ ] Progres dapat diubah dari 0 sampai 100.
- [ ] Status Selesai menetapkan progres 100 persen.
- [ ] Perubahan status order menyinkronkan status permintaan.
- [ ] Perubahan status permintaan menyinkronkan status order.

## E. Portal pelanggan

- [ ] Tautan token tidak mudah ditebak.
- [ ] Token yang salah mengembalikan 404.
- [ ] Portal menampilkan order yang benar.
- [ ] Pelanggan hanya dapat mengunduh dokumen miliknya atau dokumen berstatus approved.
- [ ] Dokumen order lain tidak dapat dibuka melalui penggantian ID.
- [ ] Upload file di atas 10 MB ditolak.
- [ ] Ekstensi yang tidak diizinkan ditolak.
- [ ] Catatan pelanggan muncul pada riwayat admin.
- [ ] Reset token membuat tautan lama tidak berlaku.

## F. Referral

- [ ] Tautan homepage mitra mencatat klik.
- [ ] Tautan katalog layanan mencatat klik.
- [ ] Tautan setiap layanan mencatat klik.
- [ ] Proposal menyimpan mitra referral.
- [ ] Order menyimpan mitra referral.
- [ ] Invoice menyimpan mitra referral.
- [ ] Pembayaran masuk ke omzet referral.
- [ ] Mitra yang sedang login tidak mencatat referral diri sendiri.
- [ ] Tautan mitra kedua tidak mengganti referral pertama selama cookie masih aktif.

## G. Feature switch

- [ ] Proposal publik dapat dimatikan dan diaktifkan tanpa redeploy.
- [ ] Portal pelanggan dapat dimatikan dan diaktifkan tanpa redeploy.
- [ ] Upload pelanggan dapat dimatikan tanpa mematikan portal.
- [ ] Referral dapat dimatikan tanpa menghapus data lama.
- [ ] Artikel, pendaftaran mitra, akademi, community, dan inbox mengikuti pengaturan.
- [ ] Menu yang dinonaktifkan tidak ditampilkan kepada mitra atau pengunjung.

## H. Tampilan

- [ ] Sidebar desktop dapat discroll sampai bawah.
- [ ] Area bawah sidebar tetap gelap.
- [ ] Lihat website dan Keluar dapat diklik.
- [ ] Sidebar ponsel tidak memotong menu.
- [ ] Tabel order tetap dapat discroll pada layar sempit.
- [ ] Portal pelanggan nyaman dibaca pada ponsel.
