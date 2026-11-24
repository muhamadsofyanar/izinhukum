# Kantor virtual STIFIn Mulia

Paket pembaruan untuk sumber Darmutiara Kajian. Salin file aplikasi ke folder yang sama pada repositori privat instalasi STIFIn. Buat deployment tersendiri untuk `stifinmulia.com` dengan database dan penyimpanan persisten tersendiri. Pelajari pengaturan aktual resource sebelum mengganti Compose. Jika Compose sudah disesuaikan, gabungkan baris baru `STIFIN_VOUCHER_PRODUCT_ID` saja.

## Siapkan Kajian

1. Gunakan domain kanonik `https://stifinmulia.com` dan lisensi DRM yang sah untuk domain itu. Pertahankan verifikasi lisensi Kajian. Nama aplikasi, logo, kelas, afiliasi, dan pengaturan pembayaran dikelola melalui panel Kajian.
2. Promotor memakai akun member Kajian. Berikan akses kelas berdasarkan kebijakan cabang dan aktifkan fitur afiliasi sesuai pengaturan yang ada. Pakai akun admin cabang untuk mengelola stok voucher. Fitur ini tidak memindahkan akun, progres WSL, atau kredensial dari aplikasi Branch Manager.
3. Buat satu produk khusus voucher tes. Gunakan checkout berbayar tanpa diskon 100% dan berikan harga sesuai aturan cabang. Nomor produk diperoleh dari panel Kajian. Jumlah voucher mengikuti kuantitas baris produk itu pada pesanan. Jangan memakai produk ini sebagai bonus atau bundel gratis.
4. Cadangkan database Kajian dan jalankan `migrations/stifin-vouchers.sql` pada database instalasi STIFIn sebelum mengisi `STIFIN_VOUCHER_PRODUCT_ID`. Isi variabel tersebut sebagai nilai runtime pada layanan PHP app di Coolify, lalu deploy. Saat variabel kosong, fitur dan menunya tidak aktif.
5. Masuk ke **Stok Voucher Tes**, impor kode voucher resmi, satu kode per baris. Simpan kode produksi hanya pada instalasi produksi. Jika portal pusat memberikan saldo angka tanpa kode individual, diperlukan alur alokasi saldo melalui API resmi. Angka saldo pada portal tidak dapat diimpor sebagai kode.

## Alur pembayaran dan data

- Modul memakai jalur konfirmasi pesanan lunas Kajian. Pembayaran DP tidak mengalokasikan voucher. Jangan gunakan produk voucher pada order gratis.
- Distribusi berjalan setelah konfirmasi pembayaran. Pesanan lunas tanpa stok cukup menunggu. Impor stok memicu distribusi ulang, dan admin bisa memeriksa antrean secara manual. Alokasi berjalan berurutan, satu pesanan dipenuhi seluruhnya sebelum pesanan berikutnya.
- Akun pembeli melihat kode dari pesanan lunas miliknya di **Voucher Tes**. Voucher melekat pada pembeli, bukan afiliasi perujuk. Pesanan lama sebelum modul aktif tidak dimasukkan otomatis.
- Kode disimpan dalam database Kajian. Batasi akses database dan cadangannya. Kode yang pernah diberikan tidak otomatis dikembalikan ke stok saat pesanan dibatalkan. Status alokasi di Kajian tidak menunjukkan apakah kode sudah dipakai di STIFIn pusat.

## Uji di staging

- Buat dua akun member dan satu akun admin. Pastikan hanya admin dapat mengimpor stok.
- Impor kode uji, buat pesanan berbayar, dan pastikan status pending belum membuka kode. Setelah callback gateway berhasil, buka **Voucher Tes** sebagai pembeli.
- Ulangi callback yang sama dan pastikan tidak ada voucher ekstra. Uji pesanan dua unit, akun member lain, produk reguler, dan pembayaran DP.
- Lunasi pesanan ketika stok kosong. Impor stok lagi, lalu pastikan pesanan lama yang menunggu terisi.
- Verifikasi materi belajar, transaksi biasa, dan komisi afiliasi dari akun promotor. Gunakan basis data staging terpisah.

Jika perlu menonaktifkan sementara, kosongkan variabel produk dan redeploy. Data voucher tetap tersedia di database untuk pemulihan.
