# Changelog IzinHukum V11

## WhatsApp core

- Menambahkan client StarSender terpusat dengan timeout dan validasi API key.
- Mendukung pengiriman teks, media, grup, rotator, cek nomor, detail pesan, perangkat, kontak, campaign provider, dan blacklist AI provider.
- Menambahkan device alias untuk transaksi, support, mitra, campaign, dan default.

## Queue dan reliability

- Menambahkan database queue dan failed jobs jika belum tersedia.
- Menambahkan queue worker dan scheduler pada Supervisor.
- Menambahkan idempotency key, atomic claim, stale processing recovery, retry terjadwal, dan log percobaan.
- Kegagalan WhatsApp tidak membatalkan transaksi order, invoice, pembayaran, atau komisi.

## CRM dan inbox

- Menambahkan percakapan WhatsApp, pesan masuk dan keluar, unread count, label, status, assignee, serta relasi ke lead, order, invoice, pembayaran, dan mitra.
- Menambahkan human handoff dengan kata `ADMIN`.
- Menambahkan command aman `STATUS`, `INVOICE`, `HELP`, dan opt-out.

## Template dan otomasi

- Menambahkan template dinamis dan versioning.
- Menambahkan notifikasi proposal, order, status order, invoice, pembayaran, komisi, serta pengingat invoice.
- Semua otomasi nonaktif secara default.

## Campaign dan consent

- Menambahkan campaign manual maksimal 500 penerima.
- Campaign hanya menerima nomor dengan consent promosi aktif.
- Menambahkan batas harian, delay minimal, rotator opt-in, cancel, progress, dan audit.
- Menambahkan opt-out melalui `STOP`, `BERHENTI`, `UNSUBSCRIBE`, dan `JANGAN KIRIM`.

## Perangkat dan alat provider

- Menambahkan sinkronisasi daftar perangkat dan status koneksi.
- Menambahkan create/scan QR, relog, dan delete perangkat dengan feature flag khusus dan konfirmasi.
- Menambahkan alat kontak dan campaign provider.

## Security

- Semua feature flag WhatsApp default OFF.
- API key hanya dibaca dari environment.
- Webhook memakai secret URL, optional header secret, throttle, payload limit, dan deduplikasi.
- URL webhook tidak masuk access log Nginx.
- Media URL wajib HTTPS dan ditolak jika mengarah ke jaringan privat atau lokal.
- QR scan diberi `no-store` dan `noindex`.
- Payload teknis memiliki retensi dan pruning otomatis.

## Compatibility fix

- Mempertahankan seluruh hook model Invoice dari V10 dan memastikan relasi `serviceOrder` serta `service_order_id` tetap tersedia.
- Mempertahankan status campaign `cancelled` ketika status pesan provider diperbarui setelah pembatalan.

## Batas yang sengaja tidak diaktifkan otomatis

- AI legal otomatis.
- Blast tanpa consent.
- Rotator perangkat.
- Provider write tools.
- Webhook group/media/premium yang memerlukan paket atau add-on.
- Agency resale dan white-label StarSender.
