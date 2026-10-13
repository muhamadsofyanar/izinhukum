# Laporan Validasi IzinHukum V11

## Validasi yang dijalankan

- PHP lint untuk seluruh file PHP dan Blade dalam paket.
- Shell syntax check untuk entrypoint.
- Pemeriksaan struktur folder dan tidak adanya symlink.
- Pemeriksaan route V10.1 agar tidak ada route lama yang dihapus.
- Pemeriksaan referensi route WhatsApp terhadap definisi route.
- Pemeriksaan referensi view WhatsApp terhadap file view.
- Perbandingan file inti dengan basis V10 untuk mencegah hilangnya fungsi order, referral, invoice, dan keuangan.
- Pemeriksaan model Invoice agar hook V10, `service_order_id`, dan relasi `serviceOrder` tetap dipertahankan.
- Pemeriksaan default feature flag seluruh fitur WhatsApp.
- Pemeriksaan webhook secret, payload size, throttling, CSRF exception terbatas, deduplikasi, dan no-access-log.
- Pemeriksaan queue claim, idempotency, stale recovery, retry, dan cancel semantics.
- Pemeriksaan campaign consent, limit, delay, rotator gate, dan preservation status cancelled.
- Pemeriksaan URL media terhadap scheme dan jaringan privat.
- Pemeriksaan file untuk secret nyata, debug statement, penanda pekerjaan tertunda, dan symlink.
- Smoke test langsung untuk normalisasi nomor dan template renderer.

Hasil lint PHP dan Blade: **lulus**.

Hasil shell syntax: **lulus**.

Hasil route dan view consistency: **lulus**.

Hasil scan secret nyata: **tidak ditemukan**.

## Test yang disertakan

```text
tests/Feature/V11WhatsAppFoundationTest.php
tests/Unit/PhoneNumberNormalizerTest.php
tests/Unit/WhatsAppTemplateRendererTest.php
```

Cakupan test:

- tabel V11 setelah migration;
- feature flag default OFF;
- penolakan webhook secret salah;
- penggunaan Device API Key pada client;
- normalisasi nomor Indonesia;
- render variabel template.

## Batas validasi

Pengujian berikut belum dapat dijalankan di lingkungan penyusunan paket:

- `php artisan test` penuh;
- migration terhadap salinan database production;
- build Docker penuh;
- build NPM dari repository lengkap;
- browser end-to-end;
- pengiriman ke API StarSender nyata;
- webhook nyata dari perangkat;
- load test campaign.

Penyebabnya: lingkungan penyusunan patch tidak memiliki dependency `vendor`, source repository lengkap dalam satu working tree, database production, dan API key StarSender.

Validasi final harus menggunakan build Coolify dan checklist pascadeploy. Seluruh fitur WhatsApp default OFF untuk membatasi dampak apabila konfigurasi provider belum siap.
