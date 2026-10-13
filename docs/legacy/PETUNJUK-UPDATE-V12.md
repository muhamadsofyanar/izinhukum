# Petunjuk Update IzinHukum V12.0

## Tujuan rilis

V12.0 menyatukan operasi WhatsApp, CRM, follow-up, order, persyaratan, dan arsip dokumen ke dalam satu paket. Kode dan migrasi dipasang melalui satu redeploy. Modul kemudian dapat diaktifkan bertahap melalui Feature Flag tanpa redeploy ulang.

## Ruang lingkup

1. Inbox pesan personal dan grup dari webhook StarSender.
2. Monitor webhook, error, dan retry.
3. Kontak CRM, label, filter, impor CSV, dan ekspor CSV.
4. Lead pipeline dari WhatsApp, website, order, atau input manual.
5. Konversi lead menjadi Service Order IzinHukum.
6. Checklist persyaratan dan pengiriman daftar persyaratan melalui WhatsApp.
7. Sequence pesan bertahap untuk kontak, label, dan kategori grup.
8. Sequence mendukung teks, template, URL media, atau dokumen privat dari Document Vault.
9. Jeda antargrup pada sequence agar kategori grup tidak dikirim serentak.
10. Document Vault privat, verifikasi, audit unduh, pengarsipan lampiran masuk, dan pengiriman dokumen final.
11. Pengiriman beberapa dokumen final dalam satu tindakan.
12. FAQ otomatis berbasis exact, contains, atau regex dengan handoff ke admin.
13. Sinkronisasi otomatis kontak dan lead dari Inquiry serta Service Order.

## Prasyarat

- V11.5 sudah terpasang.
- `QUEUE_CONNECTION=database`.
- Tabel `jobs` dan worker queue aktif.
- Scheduler Laravel aktif melalui Supervisor.
- Database SQLite tetap berada pada volume persisten.
- StarSender Account API Key dan Device API Key sudah valid.

## Sebelum memasang

1. Jangan mengubah `APP_KEY`.
2. Backup database SQLite:

```bash
cp /var/www/html/database/database.sqlite /var/www/html/database/database-before-v12-$(date +%Y%m%d-%H%M%S).sqlite
```

3. Pastikan container V11.5 sehat.
4. Pastikan API key dan password tidak masuk ke GitHub.

## Pemasangan source code

Ekstrak isi ZIP ke root repository IzinHukum dan izinkan overwrite. Setelah itu:

```bash
git add .
git commit -m "V12 CRM WhatsApp operations and document vault"
git push origin main
```

## Environment tambahan

Tambahkan sebelum redeploy:

```env
STARSENDER_MEDIA_ALLOWED_HOSTS=starsender.online
STARSENDER_MEDIA_ARCHIVE_MAX_BYTES=20971520
```

`STARSENDER_MEDIA_ALLOWED_HOSTS` adalah daftar domain yang diizinkan sebagai sumber lampiran inbound. Pisahkan beberapa domain dengan koma. Jangan memasukkan wildcard umum, alamat IP, atau domain yang tidak dikendalikan provider.

Konfigurasi add-on tetap mengikuti akun StarSender:

```env
STARSENDER_WEBHOOK_PREMIUM_ENABLED=false
STARSENDER_WEBHOOK_MEDIA_ENABLED=false
STARSENDER_WEBHOOK_GROUP_ENABLED=false
```

Ubah menjadi `true` hanya untuk add-on yang benar-benar aktif pada akun.

## Redeploy

Lakukan satu kali redeploy di Coolify. Migrasi V12 bersifat additive dan tidak menghapus tabel V11. Healthcheck yang diharapkan:

```json
{
  "status": "healthy",
  "version": "12.0.0",
  "checks": {
    "database": "ok",
    "storage": "ok",
    "queue": "ok",
    "crm": "ok"
  },
  "integrations": {
    "starsender": "configured"
  }
}
```

## Perintah setelah deployment

Jalankan di Terminal aplikasi Coolify:

```bash
cd /var/www/html
php artisan optimize:clear
php artisan migrate --force
php artisan queue:restart
php artisan crm:backfill-contacts --limit=10000
php artisan whatsapp:diagnose
```

Pemeriksaan tambahan:

```bash
php artisan crm:dispatch-sequences --limit=10
php artisan crm:archive-whatsapp-media --limit=10
```

Gunakan opsi berikut hanya untuk mencoba ulang arsip yang sebelumnya gagal:

```bash
php artisan crm:archive-whatsapp-media --limit=10 --retry-failed
```

## Aktivasi modul tanpa redeploy

Buka `Admin → Pengaturan Fitur`. Aktifkan bertahap dalam urutan berikut.

### Tahap 1: Inbox dan observabilitas

- Integrasi WhatsApp
- Inbox WhatsApp
- Monitor webhook WhatsApp

Pasang URL webhook dari `WhatsApp & CRM → Pengaturan` pada StarSender. Lakukan uji satu pesan personal masuk. Jika memakai pesan grup, aktifkan add-on Webhook Group dan environment terkait terlebih dahulu.

### Tahap 2: Kontak dan lead

- CRM Kontak dan label
- CRM Lead dan pipeline

Jalankan backfill satu kali. Uji kontak baru dari pesan masuk dan Inquiry website.

### Tahap 3: Dokumen dan persyaratan

- Document Vault
- Arsip lampiran WhatsApp
- Checklist persyaratan

Uji menggunakan satu gambar atau PDF kecil. Pastikan file berubah dari `pending` menjadi `stored` dan dapat diunduh hanya dari panel admin.

### Tahap 4: Sequence

- Sequence follow-up

Buat sequence uji dengan dua langkah dan satu kontak internal. Untuk kategori grup, gunakan jeda minimal 10 detik.

### Tahap 5: FAQ

- FAQ otomatis terkontrol

Aktifkan terakhir. Mulai dari satu aturan pertanyaan umum, bukan jawaban hukum kompleks. Uji handoff ke admin.

## Alur operasional akhir

```text
Website / Iklan WhatsApp / Chat pribadi
→ Inbox
→ Kontak dan label
→ Lead
→ Penawaran atau follow-up
→ Deal
→ Service Order
→ Checklist persyaratan
→ Lampiran diterima dan diarsipkan
→ Proses layanan
→ Dokumen final
→ Pengiriman WhatsApp
→ Riwayat dan audit tersimpan
```

## Kontak dan label

- Nomor dinormalisasi ke format internasional tanpa `+`.
- Nomor yang sama dalam format `08`, `628`, atau `+62` digabung menjadi satu kontak.
- Satu kontak dapat memiliki banyak label.
- CSV mendukung header `phone` serta kolom opsional `name`, `email`, `company`, `source`, `service_interest`, dan `labels`.
- Beberapa label pada CSV dipisahkan dengan `|`.
- Impor dibatasi 5.000 baris per proses agar aman untuk SQLite.

## Sequence follow-up

- Setiap sequence dapat memiliki langkah tak terbatas secara aplikasi.
- Setiap langkah memiliki jeda menit, jam, atau hari serta jam kirim opsional.
- Target dapat berupa satu kontak, semua kontak berlabel tertentu, atau kategori grup tersimpan.
- Sequence personal dapat berhenti otomatis saat kontak membalas atau lead deal.
- Dokumen vault menghasilkan tautan provider sementara saat langkah jatuh tempo, bukan saat sequence dibuat.
- Untuk kategori grup, `Jeda antargrup` mengatur `scheduled_at` per grup agar pengiriman tidak serentak.

## Document Vault

- File disimpan pada disk Laravel `local`, bukan URL publik `/storage`.
- Pengunduhan admin melalui controller berizin dan dicatat pada audit log.
- Pengiriman WhatsApp memakai share link acak dengan token ter-hash dan masa berlaku 3 jam.
- Setiap pengiriman membuat share link terpisah sehingga beberapa dokumen atau penerima tidak saling membatalkan token.
- Lampiran inbound hanya diunduh dari host HTTPS dalam allowlist.
- Redirect media divalidasi ulang maksimal tiga kali untuk mencegah akses ke host yang tidak diizinkan.
- Ukuran default maksimal 20 MB.

## Batasan provider

Kode V12 dapat menerima dan mengolah payload yang dikirim StarSender. Pesan masuk tidak akan tersedia apabila URL webhook belum dipasang, add-on yang dibutuhkan belum aktif, perangkat terputus, atau provider tidak mengirim field media/pengirim grup. V12 tidak dapat mengambil riwayat pesan lama yang tidak pernah dikirim ke webhook.

## Keamanan

- Jangan mengirim API key, APP_KEY, password, atau URL webhook berisi secret ke chat atau GitHub.
- Reset kredensial yang pernah terlihat pada screenshot atau percakapan.
- Jangan memasukkan domain umum seperti `google.com` ke media allowlist.
- Backup database dan directory storage secara berkala.
- Dokumen legal harus tetap pada volume persisten dan tidak boleh hanya bergantung pada URL provider.
