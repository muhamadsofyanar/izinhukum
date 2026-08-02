# IzinHukum V21.0.2 · Fokus Closing Yayasan

V21.0.2 membuat strategi closing Yayasan benar-benar tampil dan bekerja di website. Pembaruan ini bukan sekadar naskah campaign.

## Perubahan yang langsung terlihat

### `/layanan/pendirian-yayasan`

- Hero, manfaat, tahapan, persyaratan, 10 FAQ, SEO title, dan SEO description khusus Yayasan.
- Banner promo yang menampilkan kode, potongan, harga setelah promo, tanggal berakhir, dan sisa kuota.
- Kode `YAYASAN300` otomatis terisi pada formulir.
- Tiga pertanyaan kualifikasi opsional—fokus kegiatan, kesiapan struktur, dan target mulai—ikut tersimpan pada catatan lead.
- Paket dasar menjadi rekomendasi pertama agar hambatan harga awal lebih rendah.
- Ruang lingkup paket tidak lagi memakai manfaat generik badan usaha seperti kartu nama direktur, K3L, SPPL, atau Sertifikat Standar secara menyeluruh.

### `/promo/yayasan-agustus-2026`

- Headline campaign Yayasan dan CTA `Ambil Promo Yayasan`.
- Harga normal dan harga setelah diskon terlihat pada kartu paket.
- Manfaat utama setiap paket tampil sebelum calon klien memilih.
- Kode promo otomatis dibawa ke form dan dicatat pada inquiry.
- Admin menerima konteks kesiapan Yayasan sebelum percakapan WhatsApp dimulai.
- Kunjungan, lead, penawaran disetujui, pembayaran, biaya per lead, dan ROI tetap dapat diukur dari modul V20.

## Promo bawaan

| Pengaturan | Nilai |
| --- | --- |
| Kode | `YAYASAN300` |
| Potongan | Rp300.000 |
| Minimum paket | Rp4.000.000 |
| Kuota | 20 penggunaan |
| Periode | 3–17 Agustus 2026 |
| Layanan | Pendirian Yayasan |
| Harga dasar bawaan | Rp4.000.000 |
| Harga setelah promo | Rp3.700.000 |

Harga paket yang sebelumnya diubah admin tidak ditimpa. Karena kupon memerlukan subtotal minimal Rp4.000.000, pastikan harga produksi tidak berada di bawah nilai tersebut bila promo ingin digunakan.

## Ruang lingkup paket

`Pendirian Yayasan` mencakup konsultasi tujuan dan struktur, pemeriksaan/pemesanan nama, pemeriksaan organ Yayasan, minuta, akta, pengajuan pengesahan melalui AHU, serta arahan kewajiban berikutnya.

`Pendirian Yayasan + Izin` menambahkan pendampingan NPWP, pemetaan kegiatan dan KBLI, NIB bila diperlukan dan dapat diterapkan, serta identifikasi izin sektoral. Izin tambahan di luar paket harus dikonfirmasi sebelum dikerjakan.

## Pengelolaan admin

- **Admin → Campaign pemasaran:** campaign sekarang dapat dihubungkan dengan satu kupon otomatis.
- **Admin → Kupon:** periode, nilai, kuota, status, dan cakupan layanan dapat diubah.
- **Admin → Landing layanan:** headline, manfaat, proses, FAQ, dan SEO Yayasan tetap dapat disunting tanpa deploy.
- **Admin → Paket:** harga dan ruang lingkup paket tetap dapat disesuaikan.

## Perlindungan data produksi

Migration membuat atau memperbarui hanya record dengan slug `pendirian-yayasan`, slug campaign `yayasan-agustus-2026`, dan kode kupon `YAYASAN300`. Harga paket yang sudah ada tidak ditimpa. Campaign, kupon, dan konten tidak dihapus otomatis pada rollback agar histori lead dan penggunaan promo tetap dapat diaudit.
