# Changelog IzinHukum V12.0

## Ringkasan

V12.0 menggabungkan operasi WhatsApp dan CRM dalam satu rilis additive di atas V11.5. Semua modul baru dipasang melalui satu redeploy, sedangkan aktivasi operasional dilakukan bertahap melalui Feature Flag.

## Modul baru

### 1. Inbox WhatsApp

- Penyimpanan pesan masuk personal dan grup dari webhook.
- Keterkaitan percakapan dengan kontak dan lead CRM.
- Balasan langsung dari panel admin.
- Pengiriman dokumen privat dari percakapan.
- Status belum dibaca dan riwayat pesan.
- Penghentian sequence otomatis ketika kontak membalas.

### 2. Monitor webhook

- Daftar event webhook masuk.
- Status pemrosesan, error, dan payload teknis.
- Retry event gagal dari panel admin.
- Pemeriksaan integrasi tanpa membuka log server secara manual.

### 3. Kontak dan label CRM

- Daftar kontak terpusat dengan normalisasi nomor Indonesia.
- Label multi-kategori: sumber, status, layanan, dokumen, prioritas, dan kustom.
- Pencarian, filter, detail kontak, dan tindakan cepat.
- Impor CSV maksimal 5.000 baris per proses.
- Ekspor CSV.
- Pengiriman pesan dan enrollment sequence dari detail kontak.
- Backfill kontak dari data WhatsApp yang sudah ada.

### 4. Pipeline lead

- Lead dari WhatsApp, Inquiry website, Service Order, dan input admin.
- Tahapan lead: baru, bertanya, terkualifikasi, penawaran, deal, menunggu persyaratan, proses, selesai, atau gagal.
- Penanggung jawab, nilai estimasi, probabilitas, jadwal follow-up, dan catatan.
- Konversi lead menjadi Service Order memakai ServiceOrderService yang sudah ada.
- Sinkronisasi status Service Order kembali ke lead.

### 5. Persyaratan layanan

- Template awal untuk Pendirian PT, Pendirian CV, dan NIB.
- Checklist per lead atau order.
- Status: belum diminta, sudah diminta, sudah diterima, perlu revisi, dan sudah valid.
- Pengiriman daftar persyaratan melalui WhatsApp dalam satu tindakan.

### 6. Sequence follow-up

- Sequence bernama dengan langkah berurutan.
- Jeda menit, jam, atau hari dan jam kirim opsional.
- Target kontak tunggal, label kontak, atau kategori grup WhatsApp.
- Teks, template WhatsApp, URL media, atau dokumen privat.
- Pause, lanjutkan, hentikan, dan monitoring dispatch.
- Berhenti otomatis ketika penerima membalas atau lead sudah deal.
- Jeda antargrup agar pengiriman kategori grup tidak dilakukan serentak.

### 7. Document Vault

- Upload dokumen ke storage privat Laravel.
- Keterkaitan dokumen dengan kontak, lead, order, percakapan, dan persyaratan.
- Kategori persyaratan, revisi, pembayaran, proses, final, atau lainnya.
- Verifikasi, checksum, metadata, dan audit akses.
- Pengiriman satu atau beberapa dokumen melalui WhatsApp.
- Share link provider terpisah, acak, ter-hash, dan memiliki masa berlaku.

### 8. Arsip lampiran WhatsApp

- Pembuatan placeholder dokumen ketika webhook membawa media.
- Download asynchronous melalui queue.
- HTTPS wajib.
- Allowlist host provider.
- Batas ukuran dan MIME type.
- Validasi ulang redirect untuk mengurangi risiko SSRF.
- Retry eksplisit untuk arsip yang gagal.

### 9. FAQ otomatis

- Pencocokan exact, contains, atau regex.
- Prioritas aturan.
- Template atau jawaban teks.
- Handoff ke admin setelah jawaban.
- Regex tidak valid ditolak saat penyimpanan.

## Integrasi otomatis

- Inquiry website dapat membentuk kontak dan lead saat flag aktif.
- Service Order dapat membentuk atau memperbarui lead CRM.
- Pesan masuk dapat membentuk kontak baru, menghubungkan percakapan, menghentikan sequence, dan memicu FAQ.
- Scheduler menjalankan sequence setiap menit dan arsip media setiap lima menit.

## Database

Migrasi additive membuat tabel:

- `crm_labels`
- `crm_contacts`
- `crm_contact_label`
- `crm_leads`
- `crm_activities`
- `crm_sequences`
- `crm_sequence_steps`
- `crm_sequence_enrollments`
- `crm_sequence_dispatches`
- `crm_requirement_templates`
- `crm_requirement_template_items`
- `crm_requirements`
- `crm_documents`
- `crm_document_share_links`
- `crm_document_access_logs`
- `crm_faq_rules`

Migrasi menambahkan kolom nullable pada tabel WhatsApp untuk menghubungkan kontak, lead, dan dokumen. Tidak ada tabel V11 yang dihapus.

## Feature Flag baru

Semua flag berikut default `false`:

- `crm_contacts`
- `crm_leads`
- `crm_sequences`
- `crm_documents`
- `crm_requirements`
- `crm_faq`
- `crm_media_archive`
- `whatsapp_webhook_monitor`

## Kompatibilitas

- Basis: IzinHukum V11.5.
- Laravel 12.
- SQLite pada volume persisten.
- Queue database.
- Coolify dan Dockerfile yang sudah digunakan V11.
- StarSender tetap dikendalikan melalui konfigurasi dan add-on akun.

## Validasi yang dilakukan

- PHP lint seluruh file PHP dan Blade PHP pada work tree.
- Pemeriksaan route yang dibutuhkan dan route yang dipanggil Blade.
- Pemeriksaan deklarasi tabel migrasi.
- Pemeriksaan feature flag default nonaktif.
- Pemeriksaan versi healthcheck V12.0.0.
- Pemeriksaan pola keamanan downloader media dan share link dokumen.
- Pemindaian credential yang pernah terekspos agar tidak masuk paket.

Validasi live provider, browser production, dan migrasi pada database production tetap harus dilakukan setelah deployment menggunakan checklist yang disertakan.
