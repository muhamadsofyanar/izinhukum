# Checklist Uji V12.0

## A. Deployment

- [ ] Commit V12 terbaca oleh Coolify.
- [ ] Build selesai.
- [ ] Migrasi V12 berhasil.
- [ ] Healthcheck `version=12.0.0`.
- [ ] Database, storage, queue, dan CRM berstatus `ok`.
- [ ] Container lama diganti setelah container baru sehat.

## B. Inbox dan webhook

- [ ] Feature `whatsapp`, `whatsapp_inbox`, dan `whatsapp_webhook_monitor` aktif.
- [ ] URL webhook terpasang di device StarSender.
- [ ] Pesan personal dari nomor lain membuat webhook event.
- [ ] Pesan muncul sebagai `inbound` pada Inbox.
- [ ] Nama dan nomor pengirim benar.
- [ ] Balasan dari Inbox diterima tujuan.
- [ ] Status unread kembali nol setelah percakapan dibuka.
- [ ] Payload gagal dapat dilihat dan di-retry dari Monitor Webhook.
- [ ] Pesan grup diuji hanya setelah add-on Webhook Group aktif.

Periksa data:

```bash
php artisan tinker --execute='echo "events=".\App\Models\WhatsAppWebhookEvent::count().PHP_EOL; echo "inbound=".\App\Models\WhatsAppMessage::where("direction","inbound")->count().PHP_EOL;'
```

## C. Kontak dan label

- [ ] Pesan personal masuk membuat kontak CRM baru.
- [ ] Format `0812`, `62812`, dan `+62812` tidak membuat duplikat.
- [ ] Label dapat dibuat, diedit, dinonaktifkan, dan dihapus jika tidak dipakai.
- [ ] Label tampil pada daftar kontak dan detail kontak.
- [ ] Filter nama, label, tahap, dan sumber bekerja.
- [ ] Ekspor CSV dapat dibuka.
- [ ] Impor CSV uji membuat atau memperbarui kontak.
- [ ] Label dari kolom CSV terpasang.

## D. Lead dan order

- [ ] Kontak dapat dijadikan lead dari halaman detail.
- [ ] Inquiry website membuat kontak dan lead ketika feature aktif.
- [ ] Tahap lead dapat diubah dari baru sampai selesai.
- [ ] Penanggung jawab dan jadwal follow-up tersimpan.
- [ ] Lead dapat dikonversi menjadi Service Order.
- [ ] Order terhubung kembali ke lead, persyaratan, dan dokumen.
- [ ] Status order `document_collection` memetakan lead ke `waiting_requirements`.
- [ ] Order selesai memetakan lead ke `completed`.

## E. Persyaratan

- [ ] Template Pendirian PT, CV, dan NIB tersedia.
- [ ] Checklist dapat diterapkan ke lead.
- [ ] Daftar persyaratan dapat dikirim via WhatsApp.
- [ ] Status berubah menjadi `requested` setelah pesan masuk antrean.
- [ ] Dokumen diterima dapat dihubungkan dan diverifikasi.
- [ ] Status `valid`, `needs_revision`, dan `rejected` tersimpan.

## F. Sequence

- [ ] Sequence dapat dibuat sebagai draft.
- [ ] Langkah 1 sampai 5 dapat ditambahkan.
- [ ] Jeda menit, jam, dan hari bekerja.
- [ ] Jam kirim opsional bekerja.
- [ ] Variabel `nama`, `nomor_whatsapp`, `layanan`, dan `perusahaan` dirender.
- [ ] Target satu kontak bekerja.
- [ ] Target label bekerja tanpa membuat enrollment aktif ganda.
- [ ] Target kategori grup bekerja.
- [ ] Jeda antargrup menghasilkan jadwal berbeda per grup.
- [ ] Dokumen vault dapat dipilih sebagai langkah.
- [ ] Pause, resume, dan stop bekerja.
- [ ] Balasan inbound menghentikan sequence yang memakai `stop_on_reply`.
- [ ] Tahap deal menghentikan sequence yang memakai `stop_on_deal`.

## G. Document Vault

- [ ] Upload PDF atau gambar tersimpan pada disk privat.
- [ ] URL publik langsung tidak membuka file.
- [ ] Tombol unduh admin bekerja.
- [ ] Audit unduh tercatat.
- [ ] Lampiran inbound membuat placeholder `pending`.
- [ ] Arsip otomatis mengubah status menjadi `stored`.
- [ ] Host di luar allowlist ditolak.
- [ ] File melebihi batas ditolak.
- [ ] Dokumen tunggal dapat dikirim via WhatsApp.
- [ ] Beberapa dokumen dapat dicentang dan dikirim ke satu kontak.
- [ ] Share link kadaluarsa ditolak.

## H. FAQ

- [ ] Aturan exact bekerja.
- [ ] Aturan contains bekerja.
- [ ] Regex valid diterima.
- [ ] Regex tidak valid ditolak saat disimpan.
- [ ] FAQ nonaktif tidak membalas.
- [ ] Handoff menandai percakapan untuk admin.
- [ ] Perintah STOP tetap diprioritaskan di atas FAQ.

## I. Keamanan dan regresi

- [ ] Tidak ada secret di commit.
- [ ] APP_KEY lama dipertahankan.
- [ ] Login admin tetap bekerja.
- [ ] Order, invoice, pembayaran, referral, dan grup V11.5 tetap bekerja.
- [ ] Queue worker dan scheduler tetap aktif setelah redeploy.
- [ ] Backup SQLite dapat dipulihkan.
