# Changelog V11.3

## Ditambahkan

- Model dan tabel `whatsapp_groups`.
- Kolom `channel`, `device_alias`, dan `metadata` pada percakapan WhatsApp.
- Endpoint panel admin untuk sinkronisasi grup.
- Halaman Grup dengan checklist multi-select.
- Pengiriman ke maksimal 50 grup dalam satu batch.
- Command `php artisan whatsapp:sync-groups {alias}`.
- Inbox dan balasan untuk percakapan grup.
- Filter kanal personal atau grup di Inbox.
- Normalisasi beberapa kemungkinan bentuk response daftar grup StarSender.

## Diperbaiki

- Deteksi webhook grup tidak lagi hanya bergantung pada `is_group`.
- Pesan grup masuk sekarang memiliki conversation dan unread count.
- Balasan dari conversation grup menggunakan endpoint pengiriman grup.
- Halaman Pengaturan memakai fallback aman jika query feature flag atau consent bermasalah.
- Versi healthcheck menjadi `11.3.0`.

## Keamanan

- API key tetap hanya dibaca dari environment Coolify.
- Tidak ada API key, webhook secret, APP_KEY, atau password di dalam patch.
- Multi-group dibatasi maksimal 50 tujuan per request dan memakai validasi konfirmasi.
