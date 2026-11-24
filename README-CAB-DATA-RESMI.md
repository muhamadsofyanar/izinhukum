# Data Resmi App Cabang di STIFIn Mulia

Pembaruan ini menambahkan menu admin **Data Resmi App Cabang** untuk hasil tes, rekap tes, serta transaksi voucher Pusat dan Cabang. Data disalin ke Mulia melalui impor admin. Setiap laporan juga menyediakan tautan langsung ke menu sumbernya di App Cabang.

## Upload ke GitHub dan redeploy di Coolify

1. Buat cadangan database produksi dan catat commit yang sedang aktif.
2. Ekstrak ZIP. Unggah isi folder hasil ekstrak ke root branch yang dipakai Coolify. Sertakan file tersembunyi bawaan seperti `.htaccess`, `.dockerignore`, dan `.env.example`. Jaga file konfigurasi khusus repo Anda. Simpan kredensial, token, salinan database, dan file konfigurasi produksi di luar GitHub.
3. Pertahankan konfigurasi Coolify yang sekarang, termasuk Dockerfile, port HTTP internal 80, domain, database, dan volume persisten `/data`. Pastikan `STIFIN_BRANCH_CODE` diisi kode cabang resmi.
4. Redeploy. Entrypoint menjalankan migrasi idempoten untuk instalasi yang sudah aktif. Periksa log startup bila menu menampilkan bahwa laporan belum tersedia. Untuk pemasangan baru, jalankan kembali deployment setelah konfigurasi dan instalasi database selesai.
5. Masuk sebagai admin dan buka **Data Resmi App Cabang**. Coba satu CSV dengan data uji di staging. Verifikasi impor ulang referensi yang sama, filter bulan, penolakan tanggal tidak valid, dan pembatasan akses pengguna biasa. Verifikasi juga menu promotor dan saldo lama sebelum memakai build di produksi.

## Format dan cakupan CSV

Unduh template masing-masing dari Mulia. Salin laporan App Cabang yang diizinkan untuk cabang Anda ke template tersebut. Format template Mulia memakai UTF-8, pemisah koma, dan satu baris header. Gunakan tanggal `YYYY-MM-DD` dan referensi stabil per catatan. Untuk hasil tes, gunakan ID laporan yang diizinkan dan kode hasil tanpa nama peserta, data kontak, atau data biometrik. Kode promotor boleh kosong untuk catatan tingkat cabang. Untuk rekap gunakan jumlah tes. Untuk voucher gunakan kuantitas bertanda sesuai sumber.

Setiap impor menampung maksimal 5.000 catatan dan 2 MB. Catatan dengan referensi yang sama pada cabang dan jenis laporan yang sama diperbarui. Catatan yang tidak tercantum dalam impor berikutnya tetap ada. Satu impor dibatalkan seluruhnya apabila salah satu catatan gagal. Riwayat voucher Mulia tetap tersedia di **Laporan Cabang**.

## Akun resmi

Menu akun menyediakan tautan perubahan password dan pengelolaan password promotor di App Cabang. Perubahan User ID Pusat, perubahan password melalui Mulia, pengiriman voucher ke Pusat, serta sinkronisasi otomatis memerlukan kontrak API dan izin dari pengelola STIFIn. Rute yang terlihat di navigasi App Cabang adalah rute antarmuka pengguna. Template impor ini mengikuti kebutuhan Mulia dan belum dicocokkan dengan berkas ekspor aktual dari Pusat.

Jika bulan yang dipilih belum memiliki rekap impor, tab rekap menampilkan jumlah dari hasil tes yang sudah diimpor, dikelompokkan per promotor. Rekap yang diimpor untuk bulan tersebut diutamakan. Impor yang berisi satu bulan langsung membuka bulan tersebut.

## Direktori promotor

Halaman **Promotor Cabang** menyediakan pencarian nama, kode, area dan lembaga, serta filter promotor aktif dan promotor aktif tanpa akses Mulia. Status akses memakai penautan yang sudah ada dan status akun anggota di Mulia. Ekspor direktori tetap mencakup seluruh daftar cabang. Cek saldo tetap dilakukan untuk promotor yang dipilih.

Promotor yang tertaut ke akun Mulia dan berstatus aktif di direktori resmi dapat membuka **STIFIn Saya → Hasil & rekap tes saya**. Bagian ini menampilkan hasil yang sudah masuk ke Mulia dan terikat pada kode cabang serta kode promotor miliknya. Uji dengan dua akun promotor di cabang yang sama sebelum mengandalkan fitur di produksi.

## Operasional dan voucher

Kantor Virtual Cabang menampilkan pendaftaran menunggu jadwal, tes terjadwal, voucher yang perlu diperiksa, dan akses langsung ke modul kerja. Antrean voucher tetap mengikuti pengaturan Coolify yang ada. Ketika transfer aktif, worker memverifikasi keanggotaan promotor di direktori resmi sebelum pengiriman, memeriksa penolakan API, dan hanya mencatat terkirim setelah saldo cocok. Periksa status **Perlu dicek** berdasarkan nomor invoice di App Cabang. Uji dengan lingkungan dan akun uji yang sesuai sebelum mengaktifkan transfer nyata.

## Sinkronisasi hasil tes lewat API

Buka **Data Resmi App Cabang → Hasil tes resmi**, pilih bulan, klik **Tampilkan** lalu **Sinkronkan hasil tes**. Mulia meminta laporan bulanan untuk cabang dari `STIFIN_BRANCH_CODE`. Mulia menyimpan referensi, kode hasil, kode promotor jika format referensinya dikenali, dan tanggal tes yang valid atau periode laporan. Respons yang jumlahnya berbeda dari total API tidak disimpan. Sinkronisasi ulang memperbarui referensi yang sama. Hasil yang tidak tercantum pada respons berikutnya tetap tersimpan. Rekap yang sudah diimpor diutamakan dibanding rekap berdasarkan hasil yang tersimpan.

## Voucher mandiri dan riwayat klien untuk satu cabang

- Di **Voucher Tes** promotor tertaut memilih jumlah dan lanjut ke checkout. Pembayaran manual dikonfirmasi admin melalui Transaksi. Gateway yang didukung menggunakan callback pembayaran terverifikasi ke jalur invoice lunas yang sama. Pembayaran manual tetap menunggu verifikasi manusia. Setelah lunas, promotor tidak membutuhkan tindakan kedua dari admin untuk pengisian saldo.
- Pertahankan kode cabang, ID produk voucher, User ID petugas API, dan autentikasi yang sudah berlaku di Coolify. Transfer saldo resmi memerlukan `STIFIN_VOUCHER_TRANSFER=1` dan migrasi voucher yang sudah terpasang. Docker memeriksa antrean voucher setiap 30 detik saat worker senggang. Cocokkan satu invoice uji dengan saldo sesudahnya sebelum memakai transfer di produksi. Status **Perlu dicek** harus dicocokkan dengan App Cabang sebelum melakukan transfer manual.
- Di **Data Resmi App Cabang → Hasil tes resmi**, pilih awal dan akhir periode di **Tarik riwayat hasil tes**. Docker memproses periode per bulan, menyimpan progres di database, dan bisa melanjutkan bulan yang gagal. Cakupan mengikuti riwayat yang dikembalikan API. Gunakan **Semua periode** lalu cari nama, kontak, kode promotor, atau referensi untuk tindak lanjut. Kontak dari API tampil di area admin cabang. Paket tidak memuat data klien maupun kredensial.

Tarik riwayat mencoba satu permintaan untuk periode yang dipilih, lalu beralih ke penarikan per bulan apabila perlu. Untuk mencocokkan laporan 308 baris dari App Cabang, pilih Oktober 2020 sampai Oktober 2026. Gunakan **Semua periode** untuk melihat semua catatan. API dapat menyertakan hasil dengan tanggal tes di luar rentang apabila tanggal kirimnya masih masuk rentang. Referensi asli tetap terlihat walaupun digunakan oleh lebih dari satu hasil.
