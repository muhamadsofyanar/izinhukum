# Rollback IzinHukum V11

## Rollback cepat tanpa perubahan kode

1. Buka `Admin > Pengaturan > Fitur aplikasi`.
2. Nonaktifkan seluruh fitur WhatsApp.
3. Bila panel tidak dapat diakses, set `STARSENDER_ENABLED=false` di Coolify lalu restart container.

Queue yang sudah accepted provider tidak dapat ditarik dari IzinHukum. Pesan yang masih queued dapat dibatalkan dari Riwayat pesan atau database setelah pemeriksaan.

## Rollback kode

1. Backup database dan storage.
2. Revert commit V11.
3. Redeploy commit V10.1:

```text
0d915eded179765e47eeb00398d2f4d55d2c0e12
```

4. Jangan menjalankan migration rollback secara otomatis.
5. Tabel `whatsapp_*`, `jobs`, dan `failed_jobs` dapat dibiarkan. V10.1 tidak menggunakannya.

## Pemulihan bila queue bermasalah

```bash
php artisan queue:restart
php artisan queue:failed
php artisan whatsapp:dispatch-due --limit=25
```

Jangan menjalankan `queue:retry all` tanpa memeriksa idempotency dan status pesan provider.
