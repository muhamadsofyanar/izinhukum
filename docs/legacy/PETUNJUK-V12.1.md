# Pembaruan V12.1 — Pusat WhatsApp Lebih Sederhana

Pembaruan ini merapikan tampilan WhatsApp agar lebih mudah dipahami dan lebih dekat dengan pola penggunaan WhatsApp Web.

## Perubahan utama

1. Menu utama dipangkas menjadi Ringkasan, Percakapan, Kontak, dan Peluang (CRM).
2. Fitur lain dikelompokkan menjadi Pemasaran, Grup & Materi, serta Pengaturan & Sistem.
3. Inbox berubah menjadi daftar chat, isi percakapan, dan detail kontak dalam satu layar.
4. Pencarian dan filter percakapan dipindahkan ke panel kiri.
5. Lampiran pesan disimpan di tombol `+` agar area balasan tetap sederhana.
6. Ringkasan menampilkan pekerjaan penting terlebih dahulu; informasi teknis berada di bagian Status sistem.
7. Nama tabel `whatsapp_messages` ditentukan secara eksplisit untuk mencegah error HTTP 500.

## Cara memasang

1. Unggah seluruh isi paket ke branch `main` repository GitHub.
2. Pastikan file baru berikut ikut terunggah:

   `resources/views/admin/whatsapp/_inbox_sidebar.blade.php`

3. Commit perubahan dengan judul:

   `Simplify WhatsApp navigation and inbox`

4. Jalankan redeploy dari commit terbaru.
5. Tunggu healthcheck berstatus `healthy`.
6. Buka `/admin/whatsapp`, kemudian tekan `Ctrl + F5`.

## Database

Pembaruan ini tidak menambah tabel atau kolom. Tidak perlu menghapus database dan tidak perlu menjalankan migration baru.

## Pemeriksaan setelah deployment

- Ringkasan menampilkan empat angka prioritas.
- Menu Pemasaran, Grup & Materi, serta Pengaturan & Sistem dapat dibuka.
- Halaman Percakapan menampilkan daftar chat di sebelah kiri.
- Setelah chat dipilih, pesan tampil di tengah dan detail percakapan tampil di kanan.
- Pesan teks dapat dimasukkan ke antrean.
- Healthcheck menampilkan versi `12.1.0`.
