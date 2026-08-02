# Ringkasan Perubahan IzinHukum V17–V20

Tujuan paket ini adalah membantu tim mengubah lebih banyak lead menjadi deal. Seluruh perubahan disatukan dalam satu paket V20 agar cukup satu kali redeploy.

## V17 — Prioritas lead

- Skor lead otomatis 0–100 berdasarkan kelengkapan kontak, layanan, nilai, pesan, referral, kupon, dan campaign.
- Label prioritas panas, hangat, atau dingin pada pipeline.
- Filter lead panas dan pengurutan peluang yang perlu didahulukan.
- Pencatatan waktu kontak pertama dan rata-rata waktu respons.
- Backfill skor, kontak pertama, penawaran, dan status deal dari data lama yang tersedia.

## V18 — Playbook penjualan manual

- Enam template awal untuk respons pertama, follow-up, penawaran, pengingat penawaran, pembayaran, dan aktivasi ulang.
- Placeholder otomatis untuk nama, layanan, nomor referensi, penawaran, invoice, dan nama admin.
- Tombol pada pipeline membuka WhatsApp dengan pesan siap diperiksa.
- Sistem tidak mengirim pesan, follow-up, atau broadcast otomatis.
- Admin dapat menambah, mengubah, mengurutkan, menonaktifkan, dan menghapus template.

## V19 — Penawaran dan pemulihan lead

- Template penawaran global atau per layanan.
- Ruang lingkup, ketentuan, catatan, masa berlaku, dan jatuh tempo invoice dapat digunakan ulang.
- Jumlah penggunaan template tercatat.
- Alasan tidak lanjut terstruktur untuk membaca hambatan penjualan.
- Tanggal aktivasi ulang untuk lead yang belum siap sekarang.
- Daftar lead yang sudah waktunya dihubungi kembali.

## V20 — Funnel, tindakan harian, dan ROI

- Funnel Lead masuk → Sudah dihubungi → Menerima penawaran → Deal → Selesai.
- Panel prioritas harian untuk lead panas, respons terlambat, penawaran hampir kedaluwarsa, bukti pembayaran, dan pemulihan lead.
- Campaign tersimpan dengan sumber, media, periode, budget, biaya aktual, dan status.
- Pembuat tautan UTM per campaign dan layanan.
- Laporan lead, biaya per lead, penawaran disetujui, pembayaran teratribusi, dan ROI.
- Analisis alasan lead tidak lanjut dan nilai potensi yang hilang.

## Kontrol peluncuran

Lima sakelar baru tersedia pada **Admin → Fitur aplikasi**:

1. Prioritas dan skor lead.
2. Playbook penjualan manual.
3. Template penawaran.
4. Pemulihan lead.
5. Biaya dan ROI campaign.

Semua source dapat dideploy bersama. Sakelar dapat diubah sesudah deploy tanpa menghapus histori dan tanpa redeploy ulang.
