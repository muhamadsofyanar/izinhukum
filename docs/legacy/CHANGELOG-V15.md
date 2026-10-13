# Changelog IzinHukum V15

## Katalog layanan

- Menambahkan Pengukuhan PKP, Layanan RUPS, Penyusunan Laporan Keuangan, Brand Identity, dan Perizinan Lainnya.
- Kelima layanan baru diterbitkan melalui migrasi agar tetap masuk pada produksi dengan `SEED_DATABASE=false`.
- Harga awal menggunakan status penawaran (`0` + estimasi) dan ditampilkan sebagai **Hubungi kami/Harga berdasarkan penawaran** sampai admin menetapkan harga.

## Kupon dan referral

- Menambahkan panel **Kupon & promo** untuk admin.
- Kupon dapat dibatasi per layanan atau seluruh layanan, berupa persentase atau nominal, dan memiliki minimum transaksi, batas potongan, masa berlaku, kuota, serta status aktif.
- Validasi dan perhitungan kupon dilakukan ulang di server ketika proposal disimpan.
- Snapshot kupon dicatat pada inquiry, redemption, order, dan invoice agar histori tidak berubah saat aturan promo diperbarui.
- Referral mitra tetap memakai kolom dan alur atribusi sendiri; kupon tidak menggantikan referral maupun komisi.

## Generator legal

- Menambahkan PT PMA, Perkumpulan Berbadan Hukum, dan Koperasi.
- Memperbarui rujukan PT ke Permenkum 49 Tahun 2025.
- Memperbarui rujukan CV/Firma/Persekutuan Perdata ke Permenkum 25 Tahun 2025.
- Menambahkan Permenkum 18 Tahun 2025 untuk Perkumpulan dan Permenkum 13 Tahun 2025 untuk Koperasi.
- Generator tetap bersifat penyaringan awal dan tidak mengklaim ketersediaan atau persetujuan nama AHU.

## Operasional

- Healthcheck menjadi `15.0.0`.
- Notifikasi email dan WhatsApp admin menampilkan kode serta nominal promo bila digunakan.
- Invoice end user dari inquiry menyalin potongan yang telah disahkan, selama paket asal tetap terdapat pada item invoice.
