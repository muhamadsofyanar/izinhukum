<?php
// ─── Auto-Backup Database ──────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Dump pure-PHP (tanpa exec/mysqldump — aman di shared hosting), gzip,
// disimpan di folder backup DI LUAR webroot (konvensi UPDATE_BACKUP_PATH),
// fallback ke dalam aplikasi dengan .htaccess deny. Jadwal harian via Auto-Cron.

function dbBackupDir(): ?string
{
    $candidates = [];
    if (defined('UPDATE_BACKUP_PATH')) {
        $candidates[] = rtrim(UPDATE_BACKUP_PATH, '/\\') . DIRECTORY_SEPARATOR . 'db';
    }
    $candidates[] = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'db';
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'db';

    foreach ($candidates as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (is_dir($dir) && is_writable($dir)) {
            // Fallback di dalam webroot → wajib tolak akses langsung via browser.
            if (strpos($dir, dirname(__DIR__) . DIRECTORY_SEPARATOR) === 0) {
                $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
                if (!is_file($ht)) {
                    @file_put_contents($ht, "Require all denied\nDeny from all\n");
                }
            }
            return $dir;
        }
    }
    return null;
}

/**
 * Buat backup DB lengkap (struktur + data) sebagai .sql.gz.
 * Pure PHP: SHOW CREATE TABLE + SELECT per-chunk → INSERT batch.
 */
function createDbBackup(): array
{
    $t0  = microtime(true);
    $dir = dbBackupDir();
    if ($dir === null) {
        return ['success' => false, 'message' => 'Folder backup tidak bisa dibuat/ditulis.'];
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'averion-db-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.sql.gz';
    $partial = tempnam($dir, '.backup-');
    if ($partial === false || dirname($partial) !== realpath($dir)) {
        if ($partial !== false) @unlink($partial);
        return ['success' => false, 'message' => 'Gagal membuat file backup di folder penyimpanan.'];
    }
    $gz = null;
    $snapshotOpen = false;

    try {
        $gz = gzopen($partial, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('File backup tidak bisa dibuka.');
        }
        $write = static function (string $data) use ($gz): void {
            if (gzwrite($gz, $data) !== strlen($data)) {
                throw new RuntimeException('Gagal menulis file backup.');
            }
        };
        $pdo = getDB();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $snapshotOpen = true;
        $write("-- Averion Database Backup\n-- Dibuat  : " . date('Y-m-d H:i:s') . "\n-- Versi   : " . (defined('APP_VERSION') ? APP_VERSION : '-') . "\n\n");
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            if (strtoupper((string) $row[1]) === 'BASE TABLE') $tables[] = (string) $row[0];
        }

        $rowTotal = 0;
        foreach ($tables as $tbl) {
            $table = '`' . str_replace('`', '``', $tbl) . '`';
            $create = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM);
            $write("DROP TABLE IF EXISTS $table;\n" . $create[1] . ";\n\n");

            $chunk = 1000; $offset = 0;
            while (true) {
                $stmt = $pdo->query("SELECT * FROM $table LIMIT $chunk OFFSET $offset");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) break;

                $values = [];
                foreach ($rows as $r) {
                    $vals = [];
                    foreach ($r as $v) {
                        $vals[] = ($v === null) ? 'NULL' : $pdo->quote((string) $v);
                    }
                    $values[] = '(' . implode(',', $vals) . ')';
                    $rowTotal++;
                }
                $write("INSERT INTO $table VALUES\n" . implode(",\n", $values) . ";\n");

                if (count($rows) < $chunk) break;
                $offset += $chunk;
            }
            $write("\n");
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        $closed = gzclose($gz);
        $gz = null;
        if (!$closed) {
            throw new RuntimeException('File backup tidak bisa ditutup dengan lengkap.');
        }
        $pdo->commit();
        $snapshotOpen = false;
        if (!rename($partial, $file)) {
            throw new RuntimeException('File backup tidak bisa disimpan.');
        }

        return [
            'success'  => true,
            'file'     => basename($file),
            'path'     => $file,
            'size'     => (int) filesize($file),
            'tables'   => count($tables),
            'rows'     => $rowTotal,
            'duration' => round(microtime(true) - $t0, 2),
        ];
    } catch (Throwable $e) {
        if ($snapshotOpen && $pdo->inTransaction()) $pdo->rollBack();
        if (is_resource($gz)) gzclose($gz);
        @unlink($partial);
        error_log('createDbBackup error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Backup gagal: ' . $e->getMessage()];
    }
}

/** Daftar file backup (terbaru dulu): [name, size, mtime]. */
function listDbBackups(): array
{
    $dir = dbBackupDir();
    if ($dir === null) return [];
    $out = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'averion-db-*.sql.gz') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'mtime' => (int) filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

/** Hapus backup lebih tua dari N hari. Return jumlah file terhapus. */
function pruneDbBackups(int $days): int
{
    $dir = dbBackupDir();
    if ($dir === null) return 0;
    $limit = time() - max(1, $days) * 86400;
    $n = 0;
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'averion-db-*.sql.gz') ?: [] as $f) {
        if (filemtime($f) < $limit && @unlink($f)) $n++;
    }
    return $n;
}

/** Backup harian otomatis melalui cron atau Auto-Cron. */
function processDbBackups(): array
{
    $result = ['created' => 0, 'pruned' => 0];
    if (getSetting('db_backup_enabled', '1') !== '1') return $result;

    $dir = dbBackupDir();
    if ($dir === null) {
        error_log('processDbBackups: folder backup tidak bisa ditulis.');
        return $result;
    }
    $lock = fopen($dir . DIRECTORY_SEPARATOR . '.auto-backup.lock', 'c');
    if ($lock === false) return $result;
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return $result;

        // Baca ulang setelah lock agar dua worker tidak memakai nilai lama dari cache.
        $lastFile = getDB()->query("SELECT setting_value FROM settings WHERE setting_key = 'db_backup_last_file'")->fetchColumn();
        $last = $lastFile && basename($lastFile) === $lastFile ? $dir . DIRECTORY_SEPARATOR . $lastFile : null;
        if ($last !== null && is_file($last) && filemtime($last) > time() - 86400) return $result;

        $backup = createDbBackup();
        if (empty($backup['success'])) {
            error_log('processDbBackups: ' . ($backup['message'] ?? 'gagal'));
            return $result;
        }
        if (!setSetting('db_backup_last_file', $backup['file'], 'backup')) {
            error_log('processDbBackups: arsip tersimpan, penanda keberhasilan gagal dicatat.');
        }
        $result['created'] = 1;
        $result['pruned'] = pruneDbBackups((int) (getSetting('db_backup_retention_days', '7') ?: 7));
    } catch (Throwable $e) {
        error_log('processDbBackups error: ' . $e->getMessage());
    } finally {
        fclose($lock);
    }
    return $result;
}
