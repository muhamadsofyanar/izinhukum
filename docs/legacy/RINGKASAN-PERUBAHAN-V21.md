# Ringkasan Perubahan V21

V21 menyempurnakan V17–V20 dalam satu paket deploy dengan fokus pada hasil bisnis: lebih banyak pengunjung halaman layanan menjadi lead terukur dan lebih mudah dilanjutkan menjadi deal.

## Yang benar-benar berubah

1. **Seluruh layanan memakai standar landing konversi yang sama**: hero khusus, manfaat, paket, proses, persyaratan, FAQ, form konsultasi, assurance, related services, dan CTA mobile.
2. **Isi tetap spesifik per layanan**: headline, manfaat, tahapan, FAQ, dan SEO berasal dari data layanan, kategori, paket, dan persyaratan masing-masing—bukan satu copy generik.
3. **Editor landing di admin**: buka **Admin → Landing layanan** untuk mengubah copy setiap layanan tanpa redeploy.
4. **Lead langsung terukur**: form tertanam menyimpan paket yang dipilih, sumber `service_landing`, attribution/UTM yang tersedia, nomor referensi, dan meneruskan calon klien ke WhatsApp secara manual.
5. **SEO teknis per layanan**: canonical URL, meta title/description, Service schema, FAQ schema, Breadcrumb schema, dan AggregateOffer untuk layanan yang memiliki harga.
6. **Branding resmi diterapkan**: Deep Navy `#0D1B3D`, Refined Gold `#D4A017`, Manrope, monogram IH, dan tagline “Jalur Pasti, Usaha Aman”.
7. **Fallback aman**: fitur `service_landing_pages` dapat dimatikan melalui **Admin → Fitur aplikasi** untuk kembali ke tampilan lama tanpa menghapus konten V21.

Tidak ada automation WhatsApp baru. Sistem hanya membuka pesan yang telah diisi; pengguna atau admin tetap menekan tombol kirim sendiri.
