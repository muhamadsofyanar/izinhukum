# IzinHukum V11.2 - Perbaikan Nama Tabel WhatsApp

Patch ini memperbaiki error 500 pada `/admin/whatsapp` yang disebabkan oleh konvensi nama tabel Eloquent. Model bernama `WhatsAppMessage` secara otomatis diarahkan Laravel ke `whats_app_messages`, sedangkan migration membuat tabel `whatsapp_messages`.

## Pemasangan permanen

1. Ekstrak ZIP.
2. Upload isi folder ke root repository GitHub.
3. Pastikan 11 file di `app/Models` menimpa file lama.
4. Commit ke branch `main`.
5. Redeploy melalui Coolify.
6. Setelah sehat, jalankan `php artisan optimize:clear`.
7. Buka `/admin/whatsapp`.

Patch tidak memiliki migration dan tidak mengubah database.
