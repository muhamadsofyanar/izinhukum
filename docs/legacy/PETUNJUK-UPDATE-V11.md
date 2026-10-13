# Petunjuk Update IzinHukum V11

## Identitas rilis

Nama rilis: **IzinHukum V11 - WhatsApp CRM & Automation Infrastructure**

Basis deployment yang dilaporkan aktif:

```text
0d915eded179765e47eeb00398d2f4d55d2c0e12
```

Paket ini merupakan overlay untuk V10.1. Unggah seluruh isi hasil ekstraksi ke root repositori GitHub. Jangan mengunggah folder induk ZIP sebagai subfolder baru.

## Ruang lingkup yang dipasang

V11 memasang fondasi berikut dalam satu deployment:

- koneksi StarSender melalui service terpusat;
- queue worker dan scheduler;
- pengiriman teks, media, dan pesan grup;
- pesan manual dan pesan uji;
- template dinamis;
- notifikasi proposal, order, invoice, pembayaran, dan komisi;
- pengingat invoice terjadwal;
- inbox webhook personal;
- pencatatan webhook media dan grup bila add-on aktif;
- percakapan, penugasan admin, label, dan human handoff;
- autoreply kata kunci;
- consent promosi dan opt-out;
- campaign internal dengan batas harian;
- rotator multi-device yang harus diaktifkan secara khusus;
- sinkronisasi perangkat;
- alat provider untuk kontak, campaign provider, scan, relog, dan penghapusan perangkat;
- status pesan, retry, audit, diagnosis, healthcheck, dan retensi payload;
- feature flag untuk seluruh modul berisiko.

Semua feature flag WhatsApp dibuat **nonaktif secara default**. Deployment tidak langsung mengirim pesan pelanggan.

## Sebelum upload

1. Backup database.
2. Backup persistent volume atau folder `storage`.
3. Catat commit GitHub dan image Coolify yang sedang aktif.
4. Jangan mengganti `APP_KEY`.
5. Pastikan:

```text
RUN_MIGRATIONS=true
SEED_DATABASE=false
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=300
```

## Konfigurasi Coolify untuk satu redeploy

Agar cukup satu redeploy kode, masukkan Environment Variables StarSender **sebelum** mengunggah patch. V10.1 akan mengabaikan variabel baru tersebut sampai V11 dipasang.

```text
STARSENDER_ENABLED=true
STARSENDER_BASE_URL=https://api.starsender.online
STARSENDER_ACCOUNT_API_KEY=ISI_DI_COOLIFY
STARSENDER_DEFAULT_DEVICE_KEY=ISI_DI_COOLIFY
STARSENDER_TRANSACTION_DEVICE_KEY=ISI_DI_COOLIFY
STARSENDER_SUPPORT_DEVICE_KEY=ISI_DI_COOLIFY
STARSENDER_PARTNER_DEVICE_KEY=ISI_DI_COOLIFY
STARSENDER_CAMPAIGN_DEVICE_KEY=ISI_DI_COOLIFY
STARSENDER_WEBHOOK_SECRET=SECRET_ACAK_MINIMAL_32_KARAKTER
STARSENDER_WEBHOOK_HEADER_SECRET=
STARSENDER_TIMEOUT=20
STARSENDER_CONNECT_TIMEOUT=5
STARSENDER_MAX_ATTEMPTS=4
STARSENDER_DEFAULT_DELAY=2
STARSENDER_CAMPAIGN_DAILY_LIMIT=50
STARSENDER_ROTATOR_ENABLED=false
STARSENDER_WEBHOOK_PREMIUM_ENABLED=false
STARSENDER_WEBHOOK_MEDIA_ENABLED=false
STARSENDER_WEBHOOK_GROUP_ENABLED=false
STARSENDER_WEBHOOK_RETENTION_DAYS=90
STARSENDER_TECHNICAL_LOG_RETENTION_DAYS=180
```

Buat secret dengan salah satu perintah berikut di komputer atau server yang aman:

```bash
openssl rand -hex 32
```

API key dan secret tidak boleh dimasukkan ke GitHub, file ZIP, screenshot publik, atau percakapan.

Jika API key belum tersedia, gunakan:

```text
STARSENDER_ENABLED=false
```

V11 tetap dapat dideploy. Saat variabel diubah kemudian, container perlu restart atau redeploy agar konfigurasi runtime dibaca ulang.

## Upload ke GitHub

1. Ekstrak ZIP.
2. Buka repository `muhamadsofyanar/izinhukum` pada branch `main`.
3. Pilih **Add file**, lalu **Upload files**.
4. Upload seluruh isi hasil ekstraksi.
5. Pastikan folder `app`, `bootstrap`, `config`, `database`, `docker`, `public`, `resources`, `routes`, dan `tests` berada di root repository.
6. Commit dengan pesan:

```text
Release IzinHukum V11 WhatsApp operations
```

7. Buka Coolify.
8. Tekan **Redeploy**, bukan hanya Restart.

## Proses deployment

Startup akan:

```text
memvalidasi konfigurasi penting
php artisan optimize:clear
php artisan migrate --force
menjalankan command V10 yang sudah ada
php artisan optimize
menjalankan PHP-FPM, Nginx, queue worker, dan scheduler melalui Supervisor
```

Migrasi V11 bersifat additive. Migrasi membuat tabel WhatsApp dan tabel queue jika belum tersedia. Migrasi tidak menghapus tabel atau kolom V10.1.

## Pemeriksaan setelah deployment

### 1. Healthcheck

Buka:

```text
https://DOMAIN-ANDA/healthz
```

Hasil normal:

```json
{
  "status": "healthy",
  "version": "11.0.0",
  "checks": {
    "database": "ok",
    "storage": "ok",
    "queue": "ok"
  },
  "integrations": {
    "starsender": "feature_disabled"
  }
}
```

`feature_disabled` adalah kondisi normal sebelum feature flag WhatsApp diaktifkan.

### 2. Diagnosis CLI

Buka terminal container Coolify:

```bash
php artisan whatsapp:diagnose
```

Periksa database, queue, API key, webhook secret, device key, dan feature flag.

### 3. Menu admin

Buka:

```text
Admin > WhatsApp & CRM
```

Periksa menu:

```text
Ringkasan
Inbox
Riwayat pesan
Template
Otomasi
Campaign
Perangkat
Alat provider
Pengaturan
```

### 4. Aktivasi aman

Urutan aktivasi yang direkomendasikan:

1. Aktifkan hanya **Integrasi WhatsApp** pada `Admin > Pengaturan > Fitur aplikasi`.
2. Buka `WhatsApp > Pengaturan`.
3. Periksa indikator API key dan webhook secret.
4. Buka `WhatsApp > Perangkat`, lalu sinkronkan perangkat.
5. Kirim satu pesan uji ke nomor internal.
6. Pastikan status berubah dari `queued` menjadi `accepted`, `sent`, atau status provider yang sesuai.
7. Konfigurasikan webhook StarSender menggunakan URL yang ditampilkan pada halaman Pengaturan.
8. Kirim pesan dari nomor internal ke perangkat WhatsApp dan periksa Inbox.
9. Aktifkan **Notifikasi transaksi WhatsApp**.
10. Aktifkan satu otomasi saja, misalnya `Pembayaran diterima`.
11. Uji satu transaksi dummy.
12. Aktifkan otomasi lainnya secara bertahap.

Jangan langsung mengaktifkan campaign, rotator, autoreply, AI Assistant, dan alat provider pada hari pertama.

## Webhook

V11 menyediakan endpoint:

```text
POST /webhooks/starsender/{secret}
```

Proteksi yang diterapkan:

- secret acak pada URL;
- optional header `X-Webhook-Secret`;
- CSRF exception hanya untuk path webhook tersebut;
- rate limit;
- batas payload 1 MB;
- deduplikasi event;
- queue processing;
- access log Nginx dinonaktifkan khusus URL webhook agar secret tidak tercatat.

Jika StarSender tidak mendukung custom header, biarkan `STARSENDER_WEBHOOK_HEADER_SECRET` kosong. Secret URL tetap wajib.

## Consent dan campaign

Campaign hanya dapat dibuat menggunakan template yang ditandai sebagai promosi. Setiap penerima wajib memiliki consent promosi aktif. Nomor yang mengirim `STOP`, `BERHENTI`, `UNSUBSCRIBE`, atau `JANGAN KIRIM` otomatis diblokir dari pesan promosi.

Batas awal:

```text
maksimal 500 penerima per campaign
minimal delay 30 detik
batas non-rotator 50 pesan per hari secara default
```

Naikkan batas hanya setelah pengujian bertahap dan evaluasi stabilitas perangkat.

## Media dan dokumen

V11 hanya menerima URL media HTTPS publik yang tidak mengarah ke localhost, jaringan privat, atau IP reserved. Dokumen legal sensitif sebaiknya tetap dikirim melalui portal pelanggan atau signed URL terbatas, bukan URL publik permanen.

## Command operasional

```bash
php artisan whatsapp:diagnose
php artisan whatsapp:sync-devices
php artisan whatsapp:dispatch-due --limit=200
php artisan whatsapp:run-automations
php artisan whatsapp:sync-status --limit=100
php artisan whatsapp:prune
```

Scheduler menjalankan dispatch, pengingat, sinkronisasi status, sinkronisasi perangkat, dan retensi secara otomatis.

## Bila deployment gagal

### Konfigurasi ditolak entrypoint

Periksa:

```text
QUEUE_CONNECTION=database
STARSENDER_ACCOUNT_API_KEY
STARSENDER_TRANSACTION_DEVICE_KEY atau STARSENDER_DEFAULT_DEVICE_KEY
STARSENDER_WEBHOOK_SECRET minimal 32 karakter
```

Jika belum siap, set `STARSENDER_ENABLED=false`, lalu redeploy.

### Healthcheck queue error

Pastikan migrasi berhasil dan tabel `jobs` tersedia. Periksa log migration dan database.

### Pesan tetap queued

Jalankan:

```bash
php artisan whatsapp:diagnose
php artisan queue:failed
php artisan whatsapp:dispatch-due --limit=50
```

Periksa Supervisor dan log queue worker.

### Webhook tidak masuk

Periksa:

- URL webhook tepat;
- secret URL sama dengan environment;
- integrasi dan feature flag WhatsApp aktif;
- feature flag Inbox aktif;
- perangkat terhubung;
- add-on webhook yang dibutuhkan aktif pada akun StarSender.

### Tampilan lama

Lakukan `Ctrl + F5`. Bila perlu:

```bash
php artisan optimize:clear
php artisan optimize
```

## Rollback aman

1. Nonaktifkan seluruh feature flag WhatsApp.
2. Set `STARSENDER_ENABLED=false` bila diperlukan.
3. Revert commit V11 di GitHub.
4. Redeploy commit V10.1 sebelumnya.
5. Jangan menjalankan `php artisan migrate:rollback` di production tanpa backup dan pemeriksaan manual.

Tabel V11 dapat dibiarkan setelah rollback kode karena V10.1 tidak menggunakannya.
