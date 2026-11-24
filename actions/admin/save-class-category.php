<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdminOrStaff();
if (!staffCan('content')) { flash('error', 'Kamu tidak memiliki akses untuk aksi ini.', 'error'); redirect('/admin'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/class-categories');
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid. Silakan coba lagi.', 'error');
    redirect('/admin/class-categories');
}

$action = trim($_POST['action'] ?? '');

try {
    $pdo = getDB();

    switch ($action) {

        case 'save':
            $id          = (int) ($_POST['id']          ?? 0);
            $name        = trim($_POST['name']           ?? '');
            $description = trim($_POST['description']    ?? '');
            $sortOrder   = max(0, (int) ($_POST['sort_order'] ?? 0));

            if (strlen($name) < 2) {
                flash('error', 'Nama kategori minimal 2 karakter.', 'error');
                redirect('/admin/class-categories');
            }

            // Derive slug from name
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));

            // Ensure slug uniqueness
            $check = $pdo->prepare('SELECT id FROM class_categories WHERE slug = ? AND id != ? LIMIT 1');
            $check->execute([$slug, $id]);
            if ($check->fetch()) {
                $slug .= '-' . substr((string) time(), -5);
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE class_categories SET name = ?, slug = ?, description = ?, sort_order = ? WHERE id = ?'
                );
                $stmt->execute([$name, $slug, $description ?: null, $sortOrder, $id]);
                flash('success', 'Kategori berhasil diperbarui.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO class_categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$name, $slug, $description ?: null, $sortOrder]);
                flash('success', 'Kategori berhasil ditambahkan.');
            }
            break;

        case 'delete':
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                flash('error', 'Kategori tidak valid.', 'error');
                redirect('/admin/class-categories');
            }
            // Unset from classes (do not delete the modules themselves)
            $pdo->prepare('UPDATE classes SET category_id = NULL WHERE category_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM class_categories WHERE id = ?')->execute([$id]);
            flash('success', 'Kategori berhasil dihapus.');
            break;

        default:
            flash('error', 'Aksi tidak dikenali.', 'error');
    }

} catch (Exception $e) {
    error_log('Save class category error [' . $action . ']: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan. Silakan coba lagi.', 'error');
}

redirect('/admin/class-categories');
