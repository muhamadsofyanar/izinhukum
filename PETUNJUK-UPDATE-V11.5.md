# IzinHukum V11.5: Kategori Grup Bernama

V11.5 mengganti konsep "pilihan grup terakhir" menjadi beberapa kategori grup yang dapat diberi nama dan dipakai ulang.

## Fitur

- Membuat kategori grup baru, misalnya `Klien`, `Tamu`, `Komunitas Bisnis`, dan `Grup Belajar`.
- Satu grup dapat masuk ke lebih dari satu kategori.
- Memuat kategori melalui dropdown atau tombol kategori.
- Memperbarui nama dan anggota kategori.
- Menghapus kategori tanpa menghapus grup WhatsApp.
- Mengirim teks, gambar, atau media ke semua grup dalam kategori.
- Tidak ada batas 50 grup di aplikasi. Semua grup yang dicentang diproses dengan jeda yang dipilih.
- Pilihan lama V11.4 otomatis dipindahkan menjadi kategori `Pilihan sebelumnya`.

## Instalasi

Salin seluruh isi patch ke root repository dan izinkan overwrite, lalu jalankan:

```bash
git add .
git commit -m "V11.5 named WhatsApp group categories"
git push origin main
```

Redeploy di Coolify. Setelah container sehat:

```bash
cd /var/www/html
php artisan optimize:clear
php artisan migrate --force
php artisan queue:restart
php artisan whatsapp:diagnose
```

Healthcheck yang diharapkan menampilkan versi `11.5.0`.

## Cara memakai

1. Buka `Admin > WhatsApp & CRM > Grup`.
2. Centang grup yang diinginkan.
3. Isi nama kategori, misalnya `Klien`.
4. Klik `Simpan sebagai kategori baru`.
5. Buat kategori lain dengan klik `Buat kategori baru`.
6. Untuk penggunaan berikutnya, klik tombol kategori atau pilih nama kategori dari dropdown.
7. Isi pesan, unggah gambar bila diperlukan, pilih jeda, lalu kirim.

Untuk mengubah isi kategori, muat kategori, ubah centang grup, lalu klik `Perbarui kategori ini`.
