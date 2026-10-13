# Petunjuk Update V11.3: Inbox dan Multi-Group WhatsApp

## Tujuan

Patch ini menambahkan:

1. Sinkronisasi daftar grup WhatsApp dari device StarSender.
2. Pengiriman ke maksimal 50 grup dalam satu klik.
3. Penyimpanan pesan grup masuk ke Inbox.
4. Balas pesan personal dan grup langsung dari Inbox.
5. Filter Inbox personal atau grup.
6. Penanganan aman pada halaman Pengaturan WhatsApp agar error pembacaan feature flag atau consent tidak menjatuhkan seluruh halaman.
7. Deteksi webhook grup berdasarkan `group_name`, JID `@g.us`, atau flag grup.

## Cara memasang

1. Ekstrak ZIP ke komputer.
2. Salin seluruh isi folder patch ke root repository `izinhukum`.
3. Izinkan overwrite file dengan nama yang sama.
4. Commit dan push ke branch `main`.
5. Redeploy melalui Coolify.

Contoh Git:

```bash
git add .
git commit -m "V11.3 inbox and multi-group WhatsApp"
git push origin main
```

## Setelah deployment sehat

Jalankan melalui Terminal aplikasi Coolify:

```bash
cd /var/www/html
php artisan optimize:clear
php artisan migrate --force
php artisan queue:restart
php artisan whatsapp:diagnose
php artisan whatsapp:sync-groups support
```

Hasil `whatsapp:diagnose` harus menunjukkan tabel grup tersedia.

## Pengaturan fitur

Buka:

```text
Admin → Pengaturan Fitur
```

Aktifkan:

```text
Integrasi WhatsApp
Inbox WhatsApp
```

Notifikasi transaksi, autoreply, campaign, AI, dan rotator tetap boleh dinonaktifkan selama pengujian.

## Webhook pesan personal

Buka:

```text
Admin → WhatsApp & CRM → Pengaturan
```

Salin URL webhook, lalu pasang pada pengaturan webhook device `izin` di StarSender.

Setelah itu, kirim pesan baru ke nomor WhatsApp device. Pesan lama sebelum webhook dipasang tidak dapat diambil otomatis.

## Webhook pesan grup

Untuk menerima pesan masuk dari grup, akun StarSender harus memiliki Add-On Webhook Group.

Setelah add-on aktif, ubah environment Coolify:

```env
STARSENDER_WEBHOOK_GROUP_ENABLED=true
```

Simpan, lalu redeploy. Gunakan URL webhook yang sama pada kolom Webhook Group di StarSender.

Jika add-on belum aktif, pertahankan:

```env
STARSENDER_WEBHOOK_GROUP_ENABLED=false
```

Daftar grup dan pengiriman ke grup tetap dapat diuji tanpa mengaktifkan penyimpanan pesan grup masuk.

## Sinkronisasi dan pengiriman grup

Buka:

```text
Admin → WhatsApp & CRM → Grup
```

1. Pilih device `Support`.
2. Klik `Sinkronkan daftar grup`.
3. Pilih grup dengan checklist.
4. Isi pesan.
5. Pilih jeda antargrup.
6. Centang konfirmasi anti-spam.
7. Klik `Kirim ke grup terpilih`.

Sistem membuat satu record dan satu job untuk setiap grup. Status tiap grup dapat diperiksa pada riwayat pesan grup.

## Batasan penting

- Maksimal 50 grup per satu batch panel.
- Pesan masuk hanya direkam setelah webhook dipasang.
- Pesan grup masuk memerlukan Add-On Webhook Group.
- Nama atau nomor anggota yang mengirim di grup hanya tampil bila field tersebut dikirim oleh payload StarSender.
- Hindari pesan promosi berulang dan pengiriman cepat. Gunakan jeda yang wajar.

## Bila halaman Pengaturan masih 500

Buka halaman Pengaturan sekali, lalu jalankan:

```bash
tail -n 100 storage/logs/laravel.log
```

Gunakan error paling akhir. Pengujian controller melalui Tinker yang tidak melewati middleware dapat menampilkan error `$currentUser` dan bukan bukti bahwa route browser gagal karena hal yang sama.
