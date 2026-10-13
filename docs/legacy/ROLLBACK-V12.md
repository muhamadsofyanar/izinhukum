# Rollback V12.0

## Rollback paling aman

Matikan seluruh feature flag V12 dari panel:

- CRM Kontak dan label
- CRM Lead dan pipeline
- Sequence follow-up
- Document Vault
- Checklist persyaratan
- FAQ otomatis
- Arsip lampiran WhatsApp
- Monitor webhook WhatsApp

Ini menghentikan proses baru tanpa menghapus data.

## Rollback source code

1. Catat commit V12.
2. Revert commit atau deploy commit V11.5 sebelumnya.
3. Redeploy.
4. Jalankan:

```bash
php artisan optimize:clear
php artisan queue:restart
```

Tabel dan kolom V12 boleh dibiarkan. Penambahan tersebut nullable atau berdiri sendiri sehingga V11.5 dapat mengabaikannya.

## Jangan langsung menjalankan migrate:rollback

Rollback migrasi V12 menghapus kontak, label, lead, sequence, checklist, dokumen, share link, dan audit V12. Gunakan hanya setelah backup dan keputusan penghapusan data yang eksplisit.

## Pemulihan penuh

Pulihkan file SQLite backup dan storage privat dari waktu sebelum deployment. Pastikan container dihentikan saat mengganti database agar file tidak sedang ditulis.
