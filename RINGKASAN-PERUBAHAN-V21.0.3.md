# IzinHukum V21.0.3 · Hotfix Migrasi Campaign Yayasan

V21.0.3 memperbaiki kegagalan deploy pada database produksi yang tabel `marketing_campaigns`-nya belum memiliki kolom `service_id`, walaupun migrasi V20 sudah tercatat berjalan.

Perubahan inti:

- migrasi `000029` sekarang memeriksa dan menambahkan `service_id` sebelum menambahkan atau memakai `coupon_id`;
- migrasi aman dijalankan kembali setelah percobaan V21.0.2 gagal sebagian;
- data layanan, paket, kupon, dan pivot yang sempat tersimpan akan diperbarui secara idempoten, bukan diduplikasi;
- tidak diperlukan rollback manual, seeder, atau perubahan environment variable;
- versi healthcheck dinaikkan menjadi `21.0.3` agar hasil deploy mudah dipastikan.

Seluruh fitur closing Yayasan V21.0.2 tetap dipertahankan: landing khusus, promo Rp300.000, harga promo Rp3.700.000, kuota 20, landing broadcast, atribusi campaign, dan integrasi ke alur lead.
