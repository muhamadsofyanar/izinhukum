# Rollback V11.3

Cara paling aman:

1. Revert commit V11.3 di Git.
2. Push ke branch `main`.
3. Redeploy melalui Coolify.

Tabel `whatsapp_groups` dan kolom tambahan dapat dibiarkan karena tidak mengganggu V11.2. Jangan menjalankan `migrate:rollback` pada production tanpa backup database SQLite.
