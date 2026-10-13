# Changelog IzinHukum V16 — One-Deploy Growth Suite

## 1. Campaign dan halaman layanan

- Menangkap `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, dan landing page dari kunjungan publik.
- Menyimpan snapshot atribusi pada proposal, terpisah dari referral mitra dan kupon.
- Menyediakan pembuat tautan campaign siap salin untuk halaman layanan/form proposal.

## 2. Pipeline penjualan ringan

- Proposal website masuk ke pipeline secara otomatis.
- Admin dapat menambahkan lead WhatsApp/manual, mengubah tahap, PIC, nilai, probabilitas, catatan, dan jadwal follow-up.
- Perubahan tahap menyelaraskan status inquiry dan order.
- Migrasi memasukkan inquiry lama ke pipeline tanpa mengaktifkan inbox, campaign, sequence, atau CRM WhatsApp lama.

## 3. Penawaran digital

- Admin membuat draf penawaran berisi penerima, item, potongan, ruang lingkup, ketentuan, dan masa berlaku.
- Penawaran diterbitkan melalui tautan publik yang dapat dikirim lewat WhatsApp.
- Klien dapat menyetujui atau mengirim alasan revisi.
- Persetujuan terkunci dan idempoten: hanya satu invoice yang dibuat, lalu pipeline/order diperbarui.

## 4. Bukti pembayaran

- Pelanggan mengunggah JPG, PNG, atau PDF maksimal 5 MB dari halaman invoice.
- File disimpan privat dan hanya admin dapat mengunduhnya.
- Checksum mencegah pengiriman bukti identik untuk invoice yang sama.
- Persetujuan admin membuat pembayaran dan kwitansi melalui service transaksi lama sehingga status invoice, order, referral, komisi, dan laporan keuangan tetap konsisten.

## 5. Analitik pertumbuhan dan feature flags

- Ringkasan 7/30/90 hari untuk lead, penawaran, rasio persetujuan, nilai invoice, dan pembayaran masuk.
- Tabel sumber, campaign, dan layanan diminati.
- Lima feature flag baru: campaign tracking, pipeline penjualan, penawaran digital, bukti pembayaran, dan analitik pertumbuhan.
- Healthcheck dinaikkan menjadi `16.0.0`.
