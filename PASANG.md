# Voucher Tes STIFIn

Patch ini dibuat untuk commit 5ad459a. Letakkan stifin-voucher.patch di root repo Windows. Gunakan Git Bash. Pastikan tidak ada perubahan lain yang sudah di-stage.

```bash
cd /e/GITHUB/sofyan/stifincabang &&
git pull --ff-only origin main &&
git apply --check --index stifin-voucher.patch &&
git apply --index stifin-voucher.patch &&
git commit -m "Tambahkan saldo promotor dan checkout voucher STIFIn" &&
git push origin main
```

Setelah commit baru sukses di Coolify, jalankan dari Terminal container app:

```bash
php /var/www/html/scripts/setup-stifin-voucher.php
```

Perintah membuat tabel stok dan antrean yang dibutuhkan, serta satu produk Draft dengan Canvas Nonaktif. Saat dijalankan ulang, produk yang sudah dibuat oleh setup dipertahankan. Harga awal Rp165.000 per kode. Checkout wajib login. Pilih jumlah 1–1.000 dan tekan Perbarui jumlah. Total barang untuk 2 voucher adalah Rp330.000 sebelum kode unik atau biaya pembayaran yang berlaku. Pembayaran memberi kode setelah order lunas dan stok cukup. Akun bisa membeli lagi dalam transaksi baru.

## Persiapan penjualan

1. Buka Stok Voucher Tes di admin dan impor kode resmi yang dapat dijual. Pesanan lunas saat stok belum cukup akan menunggu alokasi penuh.
2. Periksa draf Voucher Tes STIFIn di Produk dan pratinjau Canvas Beli Voucher STIFIn. Saat siap menjual, aktifkan keduanya.
3. Uji /beli-voucher-stifin/ dan /order/voucher-tes-stifin sebagai anggota biasa. Uji jumlah 1 dan 2, konfirmasi pembayaran, lalu periksa jumlah kode pada menu Voucher Tes. Mode pratinjau admin bisa melihat halaman anggota, tetapi pembelian memerlukan sesi anggota biasa.
4. Untuk anggota lama, buka Promotor Cabang dan pilih Tautkan akun pada baris yang benar. Tindakan ini mencocokkan email API yang unik dengan akun anggota aktif. Akun baru yang berhasil diimpor sudah memiliki tautan.
5. Masuk sebagai promotor tertaut, lalu buka Voucher Tes dan klik Cek saldo. Halaman mengambil kode promotor dari pemetaan akun di server.

Saldo Voucher dan Free berasal dari pusat. Pembelian menggunakan stok kode lokal dan tidak memanggil API penambahan saldo pusat. Jika variabel STIFIN_VOUCHER_PRODUCT_ID sudah menunjuk produk lain, setup berhenti agar tujuan distribusi lama bisa diperiksa. Variabel kosong memakai pengaturan yang dibuat setup.
