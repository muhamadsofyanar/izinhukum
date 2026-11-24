-- Update 1.2.8 averion — tambah tipe produk
-- Melengkapi pilihan Tipe di /admin/products: Jasa, Coaching, Webinar, Template
-- (selain Class, Membership, Ebook, Software, Other). Tipe = label/kategori saja,
-- tak mengubah perilaku produk. ENUM diperluas, DEFAULT tetap 'other'.
ALTER TABLE products MODIFY COLUMN type ENUM('class','membership','ebook','software','jasa','coaching','webinar','template','other') NOT NULL DEFAULT 'other';
