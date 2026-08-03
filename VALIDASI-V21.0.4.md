# Validasi IzinHukum V21.0.4

Tanggal: 3 Agustus 2026 (Asia/Jakarta)

- Error produksi kedua dikonfirmasi pada kolom `landing_headline` setelah `service_id` berhasil dilewati.
- Seluruh 20 kolom aplikasi pada tabel `marketing_campaigns` kini diperiksa sebelum data campaign ditulis.
- Relasi `inquiries.marketing_campaign_id` ikut diperiksa.
- Jalur migrasi mendukung tabel lengkap, tabel parsial, tabel tidak tersedia, serta retry setelah kegagalan parsial.
- Syntax source diperiksa sebelum paket dibuat.
- Runtime final harus diverifikasi oleh container Coolify karena environment penyusunan tidak menyediakan executable PHP atau Docker.
