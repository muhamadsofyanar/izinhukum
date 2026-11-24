<?php
// Snapshot file untuk update sistem. Tidak dipakai untuk backup database.

function updateBackupPath(string $path): string
{
    $real = realpath($path);
    $normalized = str_replace('\\', '/', $real !== false ? $real : $path);
    $normalized = rtrim($normalized, '/');
    return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
}

function updateBackupWithin(string $path, string $base): bool
{
    $path = updateBackupPath($path);
    $base = updateBackupPath($base);
    return $path === $base || str_starts_with($path, $base . '/');
}

/** Pilih folder writable; jangan pernah jadikan root aplikasi atau induknya sebagai tujuan. */
function updateBackupWritableBase(string $preferred, string $fallback, string $appRoot): string
{
    foreach ([$preferred, $fallback] as $candidate) {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        if ($candidate === '') continue;
        if (updateBackupWithin($candidate, $appRoot)
            && !updateBackupWithin($candidate, $appRoot . '/storage')) continue;
        if (!is_dir($candidate) && !@mkdir($candidate, 0750, true) && !is_dir($candidate)) continue;
        $real = realpath($candidate);
        if ($real === false || updateBackupWithin($appRoot, $real)) continue;
        // Di dalam docroot hanya storage/ yang dilindungi dari akses HTTP langsung.
        if (updateBackupWithin($real, $appRoot)
            && !updateBackupWithin($real, $appRoot . '/storage')) continue;
        // is_writable() dapat memberi false-negative pada ACL Windows; coba tulis nyata.
        $probe = $real . '/.update-write-' . bin2hex(random_bytes(4));
        $handle = @fopen($probe, 'x');
        if ($handle === false) continue;
        fclose($handle);
        if (!@unlink($probe)) continue;
        return $real;
    }
    throw new RuntimeException('Folder kerja update tidak dapat dibuat atau ditulis.');
}

/** Salin kode lama, tetapi pangkas folder backup/temp SEBELUM iterator masuk ke dalamnya. */
function updateBackupCopySnapshot(string $appRoot, string $backupDir, array $excludedRoots): array
{
    $appRoot = realpath($appRoot);
    $backupDir = realpath($backupDir);
    if ($appRoot === false || $backupDir === false || !is_dir($backupDir)) {
        throw new RuntimeException('Folder aplikasi atau snapshot tidak valid.');
    }
    foreach ($excludedRoots as $excluded) {
        if (updateBackupWithin($appRoot, $excluded)) {
            throw new RuntimeException('Lokasi pengecualian snapshot tidak boleh mencakup seluruh aplikasi.');
        }
    }
    if (!in_array(true, array_map(
        static fn (string $root): bool => updateBackupWithin($backupDir, $root),
        $excludedRoots
    ), true)) {
        throw new RuntimeException('Folder snapshot wajib dikecualikan dari sumber salinan.');
    }

    $source = new RecursiveDirectoryIterator($appRoot, RecursiveDirectoryIterator::SKIP_DOTS);
    $filtered = new RecursiveCallbackFilterIterator($source, static function (SplFileInfo $item) use ($excludedRoots, $appRoot): bool {
        $name = $item->getFilename();
        if ($item->isDir() && (updateBackupIsSnapshotName($name)
            || (str_starts_with($name, '.pruning_') && updateBackupIsSnapshotName(substr($name, 9))))) return false;
        foreach ($excludedRoots as $excluded) {
            if (updateBackupWithin($item->getPathname(), $excluded)) return false;
        }
        // Symlink di area kode SENGAJA menggagalkan update (bukan dilewati diam-diam):
        // menyalin isi target berisiko (keluar app / loop), sedangkan melewatinya = symlink
        // hilang saat rollback. Admin perlu menyelesaikannya dulu (hapus/ganti jadi file/folder
        // nyata, atau pindahkan ke luar app root) sebelum update. Pesan menyebut path lengkap.
        if ($item->isLink()) {
            $rel = substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $appRoot)) + 1);
            throw new RuntimeException('Update dibatalkan: ada symlink di folder aplikasi (' . $rel
                . '). Ganti menjadi file/folder nyata atau pindahkan ke luar folder aplikasi, lalu ulangi update.');
        }
        return true;
    });
    $files = 0;
    $bytes = 0;
    foreach (new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $relative = substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $appRoot)) + 1);
        $destination = $backupDir . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($destination) && !@mkdir($destination, 0750, true) && !is_dir($destination)) {
                throw new RuntimeException('Gagal membuat folder dalam snapshot: ' . $relative);
            }
            continue;
        }
        if (!$item->isFile()) continue;
        $parent = dirname($destination);
        if (!is_dir($parent) && !@mkdir($parent, 0750, true) && !is_dir($parent)) {
            throw new RuntimeException('Gagal membuat folder dalam snapshot: ' . dirname($relative));
        }
        if (!@copy($item->getPathname(), $destination)) {
            throw new RuntimeException('Gagal menyalin file ke snapshot: ' . $relative);
        }
        clearstatcache(true, $destination);
        $size = $item->getSize();
        if (filesize($destination) !== $size) {
            throw new RuntimeException('Ukuran file snapshot tidak cocok: ' . $relative);
        }
        $files++;
        $bytes += $size;
    }
    if ($files === 0) throw new RuntimeException('Snapshot kosong; update dibatalkan.');
    return ['files' => $files, 'bytes' => $bytes];
}

/** Nama folder snapshot yang dibuat updater (versi lama dan baru). */
function updateBackupIsSnapshotName(string $name): bool
{
    return (bool) preg_match('/^backup_\d{8}_\d{6}_v\d+(?:\.\d+)*(?:_[a-f0-9]{6})?$/', $name);
}

/** Maksimal tiga terbaru, ditambah snapshot sukses/partial terbaru jika lebih tua. */
function updateBackupKeepNames(array $names, array $history, string $base): array
{
    rsort($names, SORT_STRING); // timestamp ada dalam nama folder
    $keep = array_fill_keys(array_slice($names, 0, 3), true);
    foreach ($history as $row) {
        if (!in_array($row['status'] ?? '', ['success', 'partial'], true)) continue;
        $path = (string) ($row['backup_path'] ?? '');
        if ($path === '' || updateBackupPath(dirname($path)) !== updateBackupPath($base)) continue;
        $name = basename($path);
        if (in_array($name, $names, true)) {
            $keep[$name] = true;
            break;
        }
    }
    return array_keys($keep);
}

/** Hapus sedikit demi sedikit; direktori .pruning_ tak lagi dianggap snapshot valid. */
function updateBackupDeleteBatch(string $dir, string $base, int $limit = 200, float $seconds = 2.0): array
{
    $name = basename($dir);
    if (!str_starts_with($name, '.pruning_') || !updateBackupIsSnapshotName(substr($name, 9))
        || updateBackupPath(dirname($dir)) !== updateBackupPath($base) || is_link($dir)) {
        throw new RuntimeException('Target pembersihan snapshot tidak aman.');
    }
    if (!is_dir($dir)) return ['removed' => 0, 'done' => true];
    $removed = 0;
    $deadline = microtime(true) + $seconds;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($removed >= $limit || microtime(true) >= $deadline) break;
        $ok = $item->isDir() && !$item->isLink()
            ? @rmdir($item->getPathname())
            : @unlink($item->getPathname());
        if (!$ok) throw new RuntimeException('Gagal membersihkan snapshot lama: ' . $name);
        $removed++;
    }
    @rmdir($dir);
    return ['removed' => $removed, 'done' => !is_dir($dir)];
}

/** Retensi otomatis di cron; baru aktif sesudah ada update baru yang snapshot-nya lengkap. */
function processUpdateBackups(PDO $pdo, string $appRoot, array $bases): array
{
    $result = ['removed' => 0, 'completed' => 0];
    try {
        $verifiedRows = $pdo->query("SELECT status, backup_path FROM update_history WHERE backup_path IS NOT NULL ORDER BY applied_at DESC, id DESC")
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('processUpdateBackups history: ' . $e->getMessage());
        return $result;
    }
    $readyAnywhere = false;
    foreach ($verifiedRows as $row) {
        if (in_array($row['status'], ['success', 'partial'], true)
            && is_file((string) $row['backup_path'] . '/.snapshot-complete')) {
            $readyAnywhere = true;
            break;
        }
    }
    $visited = [];
    foreach ($bases as $base) {
        if (!is_dir($base)) continue;
        $base = realpath($base);
        if ($base === false || updateBackupWithin($appRoot, $base)) continue;
        $baseKey = updateBackupPath($base);
        if (isset($visited[$baseKey])) continue;
        $visited[$baseKey] = true;
        $lock = @fopen($base . '/.update-retention.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); continue; }
        try {
            $names = [];
            $pruning = [];
            foreach (scandir($base) ?: [] as $name) {
                $path = $base . '/' . $name;
                if (is_link($path) || !is_dir($path)) continue;
                if (updateBackupIsSnapshotName($name)) $names[] = $name;
                if (str_starts_with($name, '.pruning_') && updateBackupIsSnapshotName(substr($name, 9))) $pruning[] = $name;
            }
            // Folder yang sudah masuk antrean pembersihan selalu dilanjutkan dahulu.
            if (!$pruning) {
                $rows = $pdo->query("SELECT id, status, backup_path FROM update_history WHERE backup_path IS NOT NULL ORDER BY applied_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
                $target = null;
                // Hanya snapshot baru dengan penanda gagal yang boleh dibuang tanpa
                // update sukses. Snapshot lama tanpa penanda mungkin masih diperlukan.
                foreach ($names as $name) {
                    $path = $base . '/' . $name;
                    if (is_file($path . '/.snapshot-incomplete') && !is_file($path . '/.snapshot-complete')
                        && filemtime($path . '/.snapshot-incomplete') < time() - 7 * 86400) {
                        $target = $name;
                        break;
                    }
                }
                if ($target === null) {
                    if (!$readyAnywhere) continue;
                    $keep = updateBackupKeepNames($names, $rows, $base);
                    rsort($names, SORT_STRING);
                    foreach (array_reverse($names) as $name) {
                        $path = $base . '/' . $name;
                        // Proses update lain mungkin masih menulis snapshot ini.
                        if (filemtime($path) >= time() - 3600
                            || (is_file($path . '/.snapshot-incomplete') && !is_file($path . '/.snapshot-complete'))) continue;
                        if (!in_array($name, $keep, true)) { $target = $name; break; }
                    }
                }
                if ($target !== null) {
                    $old = $base . '/' . $target;
                    $new = $base . '/.pruning_' . $target;
                    if (!@rename($old, $new)) {
                        throw new RuntimeException('Gagal menandai snapshot lama untuk dibersihkan: ' . (error_get_last()['message'] ?? 'rename gagal'));
                    }
                    foreach ($rows as $row) {
                        if (updateBackupPath((string) $row['backup_path']) === updateBackupPath($old)) {
                            $pdo->prepare('UPDATE update_history SET backup_path = NULL WHERE id = ?')->execute([(int) $row['id']]);
                        }
                    }
                    $pruning[] = basename($new);
                }
            }
            if ($pruning) {
                sort($pruning, SORT_STRING);
                $batch = updateBackupDeleteBatch($base . '/' . $pruning[0], $base);
                $result['removed'] += $batch['removed'];
                if ($batch['done']) $result['completed']++;
            }
        } catch (Throwable $e) {
            error_log('processUpdateBackups: ' . $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    return $result;
}
