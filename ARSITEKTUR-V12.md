# Arsitektur V12.0

## Modul utama

### WhatsApp transport

`WhatsAppManager` tetap menjadi pintu tunggal pembuatan pesan outbound. Semua modul CRM, sequence, persyaratan, Inbox, dan Document Vault memasukkan pesan ke tabel `whatsapp_messages`. Worker memprosesnya melalui StarSender.

### Webhook ingestion

1. Endpoint publik memvalidasi secret URL dan header opsional.
2. Payload disimpan pada `whatsapp_webhook_events` menggunakan fingerprint idempotent.
3. Job webhook memisahkan personal, grup, command, opt-out, FAQ, dan media.
4. Pesan inbound disimpan pada `whatsapp_messages` dan dikaitkan ke percakapan serta kontak.
5. Payload media membuat `crm_documents` berstatus `pending`.
6. Job arsip menyalin media ke storage privat.

### CRM

- `crm_contacts`: identitas terpusat berdasarkan nomor ternormalisasi.
- `crm_labels`: segmentasi internal.
- `crm_contact_label`: relasi banyak-ke-banyak.
- `crm_leads`: pipeline calon klien.
- `crm_activities`: audit kegiatan CRM dan follow-up.

### Sequence

- `crm_sequences`: definisi rangkaian dan kebijakan stop.
- `crm_sequence_steps`: urutan, jeda, template, isi, media, atau dokumen.
- `crm_sequence_enrollments`: target personal atau kategori grup.
- `crm_sequence_dispatches`: idempotensi per enrollment dan langkah.

Scheduler menjalankan `crm:dispatch-sequences` setiap menit. Pesan grup diberi `scheduled_at` bertingkat berdasarkan `group_interval_seconds`.

### Persyaratan

- `crm_requirement_templates`: template per layanan.
- `crm_requirement_template_items`: daftar item template.
- `crm_requirements`: status aktual per lead, kontak, atau order.

### Document Vault

- `crm_documents`: metadata file dan relasi CRM.
- `crm_document_share_links`: token sementara khusus provider.
- `crm_document_access_logs`: jejak akses admin dan provider.

File fisik disimpan pada disk Laravel `local`. Share link menyimpan hash token, expiry, jumlah akses, dan waktu akses terakhir. Token asli tidak disimpan di database.

### FAQ

`crm_faq_rules` mendukung exact, contains, dan regex. Evaluasi dilakukan berdasarkan prioritas. FAQ hanya berjalan ketika feature flag aktif dan tidak menggantikan command STOP, ADMIN, STATUS, atau INVOICE.

## Integrasi sistem lama

- `InquiryObserver` membentuk kontak dan lead dari permintaan website.
- `ServiceOrderObserver` menyinkronkan kontak, lead, status order, dan penghentian sequence.
- Kolom relasi tambahan pada `whatsapp_conversations` dan `whatsapp_messages` bersifat nullable agar kompatibel dengan data lama.

## Idempotensi

- Webhook menggunakan fingerprint event.
- Pesan sequence menggunakan kombinasi enrollment, step, dan target.
- Satu enrollment aktif atau paused per sequence dan kontak/preset digunakan kembali.
- Share link dokumen dibuat per operasi pengiriman.

## Feature flags

Semua modul baru default `OFF`:

- `crm_contacts`
- `crm_leads`
- `crm_sequences`
- `crm_documents`
- `crm_requirements`
- `crm_faq`
- `crm_media_archive`
- `whatsapp_webhook_monitor`

Database dan route terpasang dalam satu redeploy, tetapi proses otomatis tidak berjalan sebelum flag terkait diaktifkan.
