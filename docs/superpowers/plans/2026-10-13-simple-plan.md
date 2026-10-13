# Penyederhanaan IzinHukum

Goal: alur katalog–checkout–WhatsApp–invoice–kas.

1. Pisahkan sumber; pertahankan schema dan data lama.
2. Buat checkout transaksi atomik inquiry+order, validasi paket aktif, cegah spam/duplikasi, batasi halaman berhasil pada session pembeli.
3. Batasi route/menu dan feature flag ke inti, lepaskan observer gateway serta worker/scheduler.
4. Pertahankan invoice/pembayaran/keuangan yang ada; tambahkan share WA manual.
5. Audit referensi route, template dan startup; kemas dengan panduan redeploy dan hasil verifikasi.

Ruling: native execution sesuai instruksi langsung user; tanpa perubahan schema destruktif. Tes runtime tertahan PHP/Composer tidak tersedia; laporkan keterbatasan tanpa klaim lulus.
