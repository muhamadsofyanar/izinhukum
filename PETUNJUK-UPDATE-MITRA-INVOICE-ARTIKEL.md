# Pembaruan Sistem Mitra, Invoice, Artikel, dan Email IzinHukum

## Yang ditambahkan

- Harga website, harga terendah end user, dan harga Mitra LegaOne.
- Tujuh harga utama sesuai daftar IzinHukum: PT, CV, Yayasan, Koperasi, Perkumpulan, Firma, dan PT PMA.
- Persyaratan awal setiap layanan pendirian.
- Akun admin berbasis database dan portal Mitra LegaOne.
- Pendaftaran, persetujuan, aktivasi, dan penonaktifan mitra.
- Invoice untuk mitra atau end user.
- Validasi server agar harga end user tidak dapat berada di bawah batas minimum.
- Tampilan invoice publik yang dapat dicetak atau disimpan sebagai PDF melalui browser.
- Pengiriman invoice melalui SMTP Mailketing.
- Pengelolaan sender email.
- Halaman artikel publik dan pengelolaan artikel dari admin.
- Edit profil admin/mitra.
- Pelacakan permintaan, Kebijakan Privasi, dan Syarat & Ketentuan.

## Pemasangan pada Coolify

1. Salin seluruh file pembaruan ke root repository IzinHukum.
2. Commit dan push ke branch `main`.
3. Pertahankan environment berikut:

   ```dotenv
   SEED_DATABASE=false
   RUN_MIGRATIONS=true
   ```

4. Klik **Redeploy** dan tunggu hingga container `healthy`.
5. Masuk kembali melalui `/admin/masuk`. Sesi admin lama akan diminta login ulang satu kali karena autentikasi telah dipindahkan ke akun database.

Migrasi otomatis mengambil `ADMIN_EMAIL` dan `ADMIN_PASSWORD` saat membuat akun admin pertama. Jangan mengaktifkan kembali `SEED_DATABASE`, karena migrasi sudah mengisi struktur dan harga baru tanpa menimpa perubahan lain.

## Konfigurasi Mailketing

Masuk ke **Admin → Email & SMTP**:

- Host: `smtp.mailketing.id`
- Port: `587`
- Enkripsi: `TLS`
- Username: gunakan username SMTP milik akun Mailketing
- Password: gunakan password SMTP, bukan password login jika Mailketing membedakannya
- Sender: masukkan nama dan email yang sudah berstatus approved

Simpan, lalu gunakan **Kirim email tes**. Status DKIM/SPF/MX tetap dikelola dan diverifikasi dari dashboard Mailketing; IzinHukum tidak mengubah DNS secara otomatis.

## Aturan harga

- Pengunjung hanya melihat harga website.
- Mitra melihat harga website, harga minimum end user, dan harga mitra.
- Invoice kepada mitra otomatis menggunakan harga mitra.
- Invoice kepada end user harus sama dengan atau lebih tinggi dari harga minimum end user.
- Admin dapat mengubah semua harga dari menu **Harga & Paket**.

## Catatan invoice

Tombol **Cetak / Simpan PDF** membuka dokumen cetak A4. Pilih **Save as PDF** pada dialog cetak browser untuk menghasilkan file PDF.
