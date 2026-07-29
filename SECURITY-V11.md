# Security Notes IzinHukum V11

## Default deny

Seluruh feature flag WhatsApp default OFF. Kode dan tabel dapat dipasang tanpa mengirim pesan. Fitur berisiko tinggi memiliki lapisan tambahan:

- `whatsapp_campaigns` untuk campaign;
- `whatsapp_rotator` ditambah `STARSENDER_ROTATOR_ENABLED=true` untuk rotator;
- `whatsapp_provider_tools` untuk operasi tulis provider;
- `whatsapp_ai_assistant` untuk blacklist AI provider;
- environment add-on untuk webhook premium, media, dan group.

## Secret management

API key dan webhook secret hanya dibaca dari Environment Variables. Tidak ada key nyata dalam patch. Startup menolak secret contoh atau kurang dari 32 karakter ketika integrasi aktif.

## Webhook

Endpoint webhook:

```text
POST /webhooks/starsender/{secret}
```

Kontrol:

- exact secret comparison dengan `hash_equals`;
- optional `X-Webhook-Secret`;
- CSRF bypass terbatas pada satu path;
- rate limit;
- payload maksimum 1 MB;
- deduplikasi fingerprint;
- proses asynchronous;
- URI webhook dikecualikan dari access log Nginx.

## Data minimization

- Audit provider menyimpan hash nomor, bukan nomor mentah, ketika nomor tidak diperlukan.
- API key tidak pernah dirender ke view.
- Payload webhook dan respons teknis dipangkas sesuai retensi.
- Dokumen sensitif tidak otomatis dipublikasikan.

## Media URL

Validasi menolak:

- non-HTTPS;
- localhost;
- domain `.local`;
- IP privat dan reserved;
- hostname yang terdeteksi memetakan ke IP privat.

Tetap gunakan portal pelanggan untuk berkas legal sensitif.

## Idempotency dan race control

- Pesan bisnis memakai idempotency key.
- Job mengambil pesan dengan atomic update.
- Job unik per message ID.
- Pesan `processing` yang stale dapat dipulihkan.
- Webhook menggunakan fingerprint unik.
- Retry hanya dikelola scheduler internal agar tidak ada dua mekanisme pengiriman ulang.

## Consent

Campaign membutuhkan consent promosi eksplisit. Opt-out melalui keyword tetap diproses ketika Inbox dimatikan. Notifikasi transaksi dipisahkan dari promosi.

## Operational warning

Integrasi berbasis perangkat WhatsApp perlu diuji dengan nomor bisnis khusus dan volume rendah. Jangan mengaktifkan blast massal, rotator, atau AI otomatis sebelum pengiriman transaksi dan webhook stabil.
