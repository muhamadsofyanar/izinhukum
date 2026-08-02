# Hotfix IzinHukum V21.0.1

Hotfix ini memperbaiki error `500` pada **Admin → Campaign & ROI**.

## Penyebab

Directive Blade untuk header action ditulis berdekatan dalam satu baris. Pada runtime produksi, hasil kompilasi view meninggalkan blok kondisi yang belum tertutup dan menghasilkan `ParseError: unexpected end of file, expecting elseif or else or endif`.

## Perbaikan

- Directive kondisi dan section pada view campaign ditulis ulang secara multiline.
- Kondisi atribut `open`, status landing, dan pagination dibuat eksplisit.
- Ditambahkan feature test yang memastikan halaman admin campaign berhasil dirender.
- Versi healthcheck dinaikkan menjadi `21.0.1`.

Tidak ada migrasi database baru dan tidak ada perubahan data campaign. Setelah source diperbarui, lakukan satu kali redeploy.
