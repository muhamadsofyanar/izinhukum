# Patch IzinHukum V9

Patch ini dibuat untuk struktur repositori `muhamadsofyanar/izinhukum` branch `main` yang diperiksa pada 29 Juli 2026.

## Perubahan

### 1. Invoice dan kwitansi masuk serta terlihat di laporan keuangan

- Laporan sekarang memiliki daftar **invoice pada periode**.
- Pemasukan aktual tetap memakai **basis kas**, yaitu dihitung dari kwitansi/pembayaran aktif.
- Invoice lama yang sudah berstatus `paid` tetapi:
  - belum mempunyai kwitansi aktif; atau
  - jumlah kwitansi aktifnya masih kurang dari total invoice

  akan dilengkapi otomatis dengan kwitansi rekonsiliasi sebesar selisihnya.
- Komisi referral ikut disinkronkan ketika kwitansi rekonsiliasi dibuat.
- Ekspor CSV dan tampilan cetak sekarang menyertakan daftar invoice serta transaksi kwitansi.
- Tombol **Sinkronkan invoice** tersedia pada halaman Laporan Keuangan.

> Jangan menghapus kwitansi rekonsiliasi otomatis sebelum memeriksa invoice terkait. Koreksi data melalui menu pembayaran/kwitansi bila metode atau referensi pembayaran perlu diperbarui.

### 2. Referral untuk seluruh layanan

Dashboard mitra sekarang menyediakan:

- tautan website utama;
- tautan katalog semua layanan;
- tautan proposal langsung; dan
- satu tautan khusus untuk setiap layanan aktif.

Kode `ref` juga dipertahankan saat calon pelanggan menekan tombol konsultasi atau memilih paket dari halaman layanan.

### 3. Sidebar admin/mitra

- Navigasi sidebar mempunyai area gulir sendiri.
- Bagian bawah sidebar tidak lagi keluar dari latar biru.
- Menu dikelompokkan menjadi Utama, Operasional, dan Pengaturan.
- Tautan website dan tombol keluar selalu berada di footer sidebar.
- Status menu aktif diperjelas untuk menu operasional.

## Cara memasang melalui GitHub web

1. Cadangkan database dan unduh salinan repositori yang sedang aktif.
2. Ekstrak `izinhukum-v9-patch.zip`.
3. Buka repositori GitHub Anda.
4. Pilih **Add file → Upload files**.
5. Seret seluruh isi folder hasil ekstraksi ke halaman upload. Pastikan struktur folder tetap sama.
6. GitHub harus menampilkan file-file pada daftar `UPDATE-V9-FILE-LIST.txt`.
7. Isi pesan commit, misalnya `Apply IzinHukum V9 finance referral sidebar fixes`.
8. Commit langsung ke branch yang dipakai Coolify, biasanya `main`.
9. Di Coolify, pilih **Redeploy**. Jangan hanya menekan Restart karena source code baru perlu dibangun ulang.

Patch ini tidak menambahkan migrasi database.

## Setelah redeploy

1. Masuk ke admin.
2. Buka **Laporan keuangan**.
3. Atur periode cukup lebar agar mencakup invoice lama.
4. Tekan **Sinkronkan invoice** satu kali.
5. Pastikan:
   - daftar invoice tampil;
   - invoice lunas mempunyai nilai terbayar yang sama dengan total;
   - nomor kwitansi dapat dibuka;
   - total pemasukan sesuai kwitansi aktif.
6. Masuk sebagai mitra dan buka Ringkasan Mitra.
7. Salin satu tautan layanan, buka pada jendela privat, kemudian kirim proposal percobaan.
8. Pastikan proposal tersebut tercatat sebagai referral mitra.
9. Periksa sidebar pada layar desktop dan ponsel.

## Pengaturan Coolify

- Lakukan **Redeploy** setelah commit.
- Pertahankan persistent storage yang sudah dipakai aplikasi.
- Jangan menghapus volume database atau storage.
- Setelah deployment sehat, lakukan hard refresh browser (`Ctrl+F5`) agar CSS sidebar baru langsung termuat.
- URL publik normalnya memakai HTTPS tanpa menampilkan port container. Port `8080` adalah port internal aplikasi/Docker; konfigurasi domain Coolify harus mengikuti pola routing yang sudah berhasil pada server Anda.

## Pemulihan

Apabila deployment bermasalah:

1. Gunakan fitur rollback deployment Coolify, atau revert commit patch di GitHub.
2. Redeploy commit sebelumnya.
3. Data invoice/kwitansi yang sudah direkonsiliasi tidak dihapus otomatis oleh rollback kode. Periksa audit log sebelum melakukan koreksi manual.
