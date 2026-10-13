# Validasi IzinHukum V20

Paket V20 direvisi sebelum redeploy untuk memasukkan landing page campaign V17 dan seluruh fitur konversi V17–V20.

## Pemeriksaan yang berhasil

- Parser PHP statis: 283 file pada `app`, `config`, `database`, `routes`, dan `tests` valid.
- Struktur directive Blade untuk landing page, layout publik, campaign admin, pipeline, penawaran, dan analitik valid.
- JavaScript inline landing page, campaign admin, proposal, penawaran, dan analitik valid.
- Empat nomor migrasi V17–V20 unik dan berurutan.
- Build produksi Vite berhasil untuk 113 modul.
- Manifest dan aset CSS/JavaScript produksi tersedia di `public/build`.
- Route landing campaign, atribusi session, form proposal, redirect WhatsApp, dan pencatatan kunjungan dicakup oleh source serta test V20.

## Batas validasi lingkungan kerja

`php artisan test` tidak dapat dijalankan pada lingkungan penyusunan paket karena executable PHP/Composer tidak tersedia. Test fitur V20 tetap disertakan pada `tests/Feature/V20ConversionSuiteTest.php` dan akan dijalankan oleh workflow CI atau lingkungan Laravel yang memiliki PHP 8.4.

## Pemeriksaan setelah deploy

Ikuti `PETUNJUK-REDEPLOY-V20.md`, terutama pemeriksaan `/healthz`, pratinjau landing page, pengiriman form uji, pembukaan WhatsApp, serta atribusi campaign pada funnel.
