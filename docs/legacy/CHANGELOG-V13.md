# IzinHukum V13 — Mode Fokus

- Menyederhanakan navigasi admin menjadi Pesanan, Keuangan, Mitra, LMS, Bank Konten, dan Pengaturan.
- Menyederhanakan navigasi mitra menjadi Harga, Invoice, Komisi, LMS, Bank Konten, dan Profil.
- Menonaktifkan akses WhatsApp CRM, inbox, campaign, sequence, autoresponder, provider tools, webhook masuk, community, dan inbox internal.
- Mempertahankan tabel serta data lama tanpa penghapusan destruktif.
- Menambahkan notifikasi pesanan baru ke email admin dan WhatsApp admin melalui queue terpisah.
- Menambahkan idempotency key untuk notifikasi WhatsApp agar pesanan yang sama tidak dikirim ganda.
- Mencegah pesan campaign, grup, dan pesan nontransaksi tertunda terkirim setelah mode fokus aktif.
- Menyederhanakan persyaratan StarSender menjadi device API key transaksi saja.
- Menambahkan migrasi otomatis dan panduan satu kali redeploy.
