<?php
if (!defined('INSTALLER_ENTRY')) { http_response_code(403); exit('Akses tidak diizinkan.'); }

$currentStep = 2;
$stepTitle   = 'Koneksi Database';

$_SESSION['install_step1_done'] = true;

$error          = '';
$importDone     = false;
$importOk       = false;
$stmtTotal      = 0;
$stmtOk         = 0;
$stmtSkipped    = 0;
$stmtFailed     = 0;
$allErrors      = [];   // semua error tidak terduga: [['table'=>'...','code'=>N,'msg'=>'...']]
$criticalTables = ['users', 'products', 'orders', 'settings'];
$missingCritical = [];  // tabel kritis yang tidak ter-create
$canProceed     = false;

// ── Helper: split SQL statement-by-statement dengan aman ──────
// Menangani ; di dalam string literal, komentar --, blok /* */, escaped chars.
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current    = '';
    $inString   = false;
    $stringChar = '';
    $len        = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($inString) {
            $current .= $ch;
            if ($ch === '\\') {
                if ($i + 1 < $len) {
                    $current .= $sql[++$i];
                }
            } elseif ($ch === $stringChar) {
                if ($i + 1 < $len && $sql[$i + 1] === $stringChar) {
                    $current .= $sql[++$i];
                } else {
                    $inString = false;
                }
            }
        } elseif ($ch === "'" || $ch === '"' || $ch === '`') {
            $inString   = true;
            $stringChar = $ch;
            $current   .= $ch;
        } elseif ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
        } elseif ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $i += 2;
            while ($i < $len - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                $i++;
            }
            // Setelah while, $i menunjuk ke '*' dari '*/'. Loop for akan $i++,
            // jadi hanya perlu +1 agar berhenti tepat di '/' — jika pakai +2,
            // satu karakter setelah '*/' akan terlewat (off-by-one).
            $i += 1;
        } elseif ($ch === ';') {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
        } else {
            $current .= $ch;
        }
    }

    $stmt = trim($current);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}

// ── PRG: import sudah sukses sebelumnya ───────────────────────
// Jika step ini sudah selesai (mis. user tekan refresh / tombol back
// setelah import sukses), JANGAN jalankan ulang import — itu akan memicu
// error "database sudah berisi tabel". Teruskan langsung ke Step 3.
if (!empty($_SESSION['install_step2_done'])) {
    header('Location: ?step=3');
    exit;
}

// ── Ubah pesan error PDO mentah jadi ramah pengguna ───────────
function friendlyDbError(PDOException $e): string
{
    $code = (int) ($e->errorInfo[1] ?? $e->getCode());
    $raw  = $e->getMessage();

    switch ($code) {
        case 1045: // ER_ACCESS_DENIED_ERROR
            return 'Username atau password database salah. Periksa kembali kredensial dari cPanel &rarr; MySQL Databases.';
        case 1044: // ER_DBACCESS_DENIED_ERROR
            return 'User database tidak punya akses ke database ini. Pastikan user sudah ditambahkan ke database dengan hak akses penuh (ALL PRIVILEGES).';
        case 1049: // ER_BAD_DB_ERROR
            return 'Database tidak ditemukan. Buat database-nya dulu di cPanel, atau periksa penulisan nama database.';
        case 2002: // CR_CONNECTION_ERROR (host/socket)
            return 'Tidak bisa menghubungi server database. Periksa Host dan Port — untuk cPanel biasanya "localhost" port 3306.';
        default:
            // host tak dikenal / timeout / lainnya
            if (stripos($raw, 'getaddrinfo') !== false || stripos($raw, 'Unknown MySQL server host') !== false) {
                return 'Host database tidak dikenal. Periksa kembali kolom Host (biasanya "localhost").';
            }
            if (stripos($raw, 'timed out') !== false || stripos($raw, 'timeout') !== false) {
                return 'Koneksi ke database timeout. Server database mungkin sibuk atau Host/Port salah. Coba lagi.';
            }
            return 'Gagal terhubung ke database. Periksa Host, Port, nama database, username, dan password. '
                 . '(Detail teknis: ' . htmlspecialchars($raw, ENT_QUOTES) . ')';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['installer_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $error = 'Token keamanan tidak valid. Muat ulang halaman.';
    } else {
        $host   = trim($_POST['db_host'] ?? 'localhost');
        $port   = trim($_POST['db_port'] ?? '3306');
        $dbname = trim($_POST['db_name'] ?? '');
        $dbuser = trim($_POST['db_user'] ?? '');
        $dbpass = $_POST['db_pass'] ?? '';

        if (!$dbname || !$dbuser) {
            $error = 'Nama database dan username wajib diisi.';
        } else {
            @ini_set('memory_limit', '256M');

            $sqlFile = dirname(__DIR__, 2) . '/stifin-install.sql';

            if (!file_exists($sqlFile)) {
                $error = 'File stifin-install.sql tidak ditemukan di folder utama aplikasi. '
                       . 'Pastikan file tersebut sudah di-upload ke server.';
            } else {
                try {
                    $baseDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                    $pdoOpts = [
                        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT                  => 5,
                        // PHP 8.4+ pakai Pdo\Mysql::ATTR_USE_BUFFERED_QUERY; nama lama
                        // deprecated sejak 8.5. Fallback ke lama untuk PHP < 8.4 (nilai identik 1000).
                        (defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) => true,
                        PDO::ATTR_EMULATE_PREPARES         => true,
                    ];

                    // Konek LANGSUNG ke database yang dituju. Di shared hosting
                    // (cPanel/StackCP/Plesk) database dibuat lewat panel & user DB
                    // TIDAK boleh menjalankan CREATE DATABASE (butuh privilege global
                    // yang tak bisa diberikan) → dulu gagal "Access denied / 1044"
                    // padahal user sudah ALL PRIVILEGES pada database-nya. Jadi jangan
                    // paksa CREATE dulu; baru fallback CREATE bila database BELUM ADA
                    // (1049) — untuk VPS/hosting yang mengizinkan atau dev lokal.
                    try {
                        $pdo = new PDO($baseDsn . ";dbname={$dbname}", $dbuser, $dbpass, $pdoOpts);
                    } catch (PDOException $connEx) {
                        if ((int) ($connEx->errorInfo[1] ?? $connEx->getCode()) !== 1049) {
                            throw $connEx; // bukan "database belum ada" → teruskan ke penanganan error
                        }
                        // Database belum ada → coba buat (hanya jalan di host yang mengizinkan).
                        $pdo = new PDO($baseDsn, $dbuser, $dbpass, $pdoOpts);
                        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        $pdo->exec("USE `{$dbname}`");
                    }

                    // ── Cegah overwrite database yang sudah berisi data ──
                    $tableCount = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
                    )->fetchColumn();
                    if ($tableCount > 0) {
                        throw new RuntimeException(
                            "Database \"{$dbname}\" sudah berisi {$tableCount} tabel. " .
                            "Gunakan database yang benar-benar kosong, atau buat database baru, " .
                            "untuk menghindari penimpaan data yang sudah ada."
                        );
                    }

                    // MySQL error codes yang aman di-skip (objek sudah ada)
                    $skipCodes = [
                        1050,  // ER_TABLE_EXISTS_ERROR
                        1060,  // ER_DUP_FIELDNAME
                        1061,  // ER_DUP_KEY
                        1062,  // ER_DUP_ENTRY
                        1091,  // ER_CANT_DROP_FIELD_OR_KEY
                    ];

                    $sql        = file_get_contents($sqlFile);
                    $statements = splitSqlStatements($sql);
                    $stmtTotal  = count($statements);

                    // Nonaktifkan FK check dari PHP — phpMyAdmin pakai conditional comment
                    // /*!40014 ...*/ yang di-strip oleh parser kita.
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                    try {
                        foreach ($statements as $stmt) {
                            try {
                                $pdo->exec($stmt);
                                $stmtOk++;
                            } catch (PDOException $stmtEx) {
                                $mysqlCode = (int) ($stmtEx->errorInfo[1] ?? 0);

                                if (in_array($mysqlCode, $skipCodes, true)) {
                                    $stmtSkipped++;
                                } else {
                                    $stmtFailed++;
                                    $sqlstate = $stmtEx->errorInfo[0] ?? '?';
                                    $preview  = mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 150);
                                    // Coba ekstrak nama tabel dari statement (CREATE/ALTER TABLE)
                                    $tableHint = '';
                                    if (preg_match('/(?:CREATE|ALTER)\s+TABLE\s+[`"]?(\w+)[`"]?/i', $stmt, $m)) {
                                        $tableHint = $m[1];
                                    }
                                    $allErrors[] = [
                                        'table' => $tableHint,
                                        'code'  => $mysqlCode,
                                        'state' => $sqlstate,
                                        'msg'   => $stmtEx->getMessage(),
                                        'stmt'  => $preview,
                                    ];
                                }
                            }
                        }
                    } finally {
                        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                    }

                    // ── Validasi tabel kritis ────────────────────────────
                    // Cek keberadaan 4 tabel inti secara EKSPLISIT setelah import.
                    // Jika salah satu tidak ada, instalasi harus diblokir.
                    foreach ($criticalTables as $tbl) {
                        $chk = $pdo->query("SHOW TABLES LIKE '{$tbl}'");
                        if (!$chk || $chk->rowCount() === 0) {
                            // Cari error spesifik untuk tabel ini di $allErrors
                            $specificErr = '';
                            foreach ($allErrors as $e) {
                                if ($e['table'] === $tbl) {
                                    $specificErr = "[MySQL {$e['code']}] {$e['msg']}";
                                    break;
                                }
                            }
                            $missingCritical[$tbl] = $specificErr ?: 'Tidak ditemukan error spesifik — kemungkinan statement CREATE TABLE tidak tereksekusi.';
                        }
                    }

                    $_SESSION['db_config'] = [
                        'host'   => $host,
                        'port'   => $port,
                        'dbname' => $dbname,
                        'dbuser' => $dbuser,
                        'dbpass' => $dbpass,
                    ];

                    if (empty($missingCritical)) {
                        $_SESSION['install_step2_done'] = true;
                        $canProceed = true;
                    }

                    $importOk   = true;
                    $importDone = true;

                } catch (PDOException $e) {
                    $error = friendlyDbError($e);
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }
}

include __DIR__ . '/../partials/installer-head.php';
?>

<?php if ($importDone): ?>
<!-- ═══════════════════════════════════════════════════════════
     HASIL IMPORT
═══════════════════════════════════════════════════════════ -->

<?php if (!empty($missingCritical)): ?>
<!-- ── FATAL: tabel kritis tidak ter-create ── -->
<div class="bg-red-50 border-2 border-red-300 rounded-xl px-4 py-4 mb-5">
  <div class="flex items-start gap-3 mb-3">
    <svg class="w-5 h-5 text-red-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <div>
      <p class="text-sm font-bold text-red-700">INSTALASI GAGAL — Tabel inti tidak berhasil dibuat</p>
      <p class="text-xs text-red-600 mt-1">
        Aplikasi <strong>tidak bisa dijalankan</strong> tanpa tabel-tabel berikut ini.
        Instalasi tidak dapat dilanjutkan ke Step 3.
      </p>
    </div>
  </div>
  <div class="space-y-2 mt-3">
    <?php foreach ($missingCritical as $tbl => $reason): ?>
    <div class="bg-red-100 border border-red-200 rounded-lg px-3 py-2">
      <p class="text-xs font-bold text-red-800 font-mono"><?= e($tbl) ?> — TIDAK ADA</p>
      <p class="text-xs text-red-700 mt-0.5 break-words leading-relaxed"><?= e($reason) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="text-xs text-red-600 mt-3 leading-relaxed">
    <strong>Solusi:</strong> DROP database, buat ulang database yang benar-benar kosong,
    hapus <code class="font-mono">installer/install.lock</code> jika ada,
    lalu ulangi instalasi dari Step 1. Jika error terus muncul, kirimkan log lengkap di bawah ke support.
  </p>
</div>

<?php elseif ($stmtFailed > 0): ?>
<!-- ── WARNING: ada error tapi tabel kritis OK ── -->
<div class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-4 mb-5">
  <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
  </svg>
  <div class="flex-1 min-w-0">
    <p class="text-sm font-semibold text-amber-800">Import selesai dengan peringatan — tabel inti OK</p>
    <p class="text-xs text-amber-700 mt-1">
      Tabel <code class="font-mono">users</code>, <code class="font-mono">products</code>,
      <code class="font-mono">orders</code>, <code class="font-mono">settings</code> berhasil ter-create.
      Ada <?= $stmtFailed ?> statement lain yang gagal — kemungkinan fitur tambahan tertentu tidak berfungsi.
    </p>
    <p class="text-xs text-amber-600 mt-1">
      Instalasi bisa dilanjutkan. Lihat log lengkap di bawah untuk detail.
    </p>
  </div>
</div>

<?php else: ?>
<!-- ── Sukses bersih ── -->
<div class="flex items-start gap-3 bg-green-50 border border-green-100 rounded-xl px-4 py-4 mb-5">
  <svg class="w-5 h-5 text-green-500 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
  </svg>
  <div>
    <p class="text-sm font-semibold text-green-800">Database berhasil diimport</p>
    <p class="text-xs text-green-700 mt-1">
      <?= $stmtOk ?> statement dieksekusi
      <?= $stmtSkipped > 0 ? ", {$stmtSkipped} dilewati (objek sudah ada, aman)" : '' ?>
      dari <?= $stmtTotal ?> statement dalam <code class="font-mono">stifin-install.sql</code>.
    </p>
  </div>
</div>
<?php endif; ?>

<!-- Ringkasan angka -->
<div class="grid grid-cols-3 gap-2 mb-5 text-center">
  <div class="bg-green-50 border border-green-100 rounded-xl py-3">
    <p class="text-lg font-bold text-green-700"><?= $stmtOk ?></p>
    <p class="text-xs text-green-600">Sukses</p>
  </div>
  <div class="bg-gray-50 border border-gray-100 rounded-xl py-3">
    <p class="text-lg font-bold text-gray-500"><?= $stmtSkipped ?></p>
    <p class="text-xs text-gray-400">Dilewati (aman)</p>
  </div>
  <div class="<?= $stmtFailed > 0 ? 'bg-red-50 border-red-100' : 'bg-gray-50 border-gray-100' ?> border rounded-xl py-3">
    <p class="text-lg font-bold <?= $stmtFailed > 0 ? 'text-red-600' : 'text-gray-400' ?>"><?= $stmtFailed ?></p>
    <p class="text-xs <?= $stmtFailed > 0 ? 'text-red-500' : 'text-gray-400' ?>">Error</p>
  </div>
</div>

<?php if (!empty($allErrors)): ?>
<!-- Log semua error -->
<div class="mb-5">
  <p class="text-xs font-semibold text-gray-600 mb-2">Log error lengkap (<?= count($allErrors) ?> error):</p>
  <div class="bg-gray-900 rounded-xl p-3 max-h-64 overflow-y-auto space-y-2">
    <?php foreach ($allErrors as $i => $err): ?>
    <div class="text-xs font-mono">
      <span class="text-red-400">[<?= $i + 1 ?>]</span>
      <?php if ($err['table']): ?>
        <span class="text-yellow-400">tabel:<?= e($err['table']) ?></span>
      <?php endif; ?>
      <span class="text-gray-400">MySQL <?= $err['code'] ?></span>
      <span class="text-white"><?= e($err['msg']) ?></span>
      <div class="text-gray-500 mt-0.5 pl-4 break-all"><?= e($err['stmt']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Tombol aksi -->
<?php if ($canProceed): ?>
<a href="?step=3"
   class="block w-full text-center bg-brand text-white font-semibold py-3.5 rounded-xl hover:opacity-90 transition">
  Lanjut ke Step 3 →
</a>
<?php else: ?>
<a href="?step=2"
   class="block w-full text-center bg-red-600 text-white font-semibold py-3.5 rounded-xl hover:opacity-90 transition">
  ← Ulangi dari Awal (Step 2)
</a>
<?php endif; ?>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════
     FORM — kondisi awal / gagal koneksi
═══════════════════════════════════════════════════════════ -->

<p class="text-sm text-gray-400 mb-5">
  Buat database &amp; user di menu <span class="font-semibold text-gray-600">MySQL Databases</span> cPanel,
  lalu isi informasi di bawah ini.
</p>

<?php if ($error): ?>
  <div class="bg-red-50 border border-red-100 text-red-600 text-sm rounded-xl px-4 py-3 mb-4 leading-relaxed">
    <?= e($error) ?>
  </div>
<?php endif; ?>

<form method="POST" class="space-y-4">
  <input type="hidden" name="_csrf" value="<?= e($_SESSION['installer_csrf'] ?? '') ?>">

  <div class="grid grid-cols-3 gap-3">
    <div class="col-span-2">
      <label class="block text-sm font-medium text-gray-700 mb-1.5">Host Database</label>
      <input type="text" name="db_host"
             value="<?= e($_POST['db_host'] ?? 'localhost') ?>"
             required
             class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl text-sm focus:ring-2 focus:ring-brand focus:bg-white outline-none transition">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1.5">Port</label>
      <input type="text" name="db_port"
             value="<?= e($_POST['db_port'] ?? '3306') ?>"
             required
             class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl text-sm focus:ring-2 focus:ring-brand focus:bg-white outline-none transition">
    </div>
  </div>

  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1.5">Nama Database</label>
    <input type="text" name="db_name"
           value="<?= e($_POST['db_name'] ?? '') ?>"
           placeholder="namaakun_stifin" required
           class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl text-sm focus:ring-2 focus:ring-brand focus:bg-white outline-none transition">
  </div>

  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1.5">Username Database</label>
    <input type="text" name="db_user"
           value="<?= e($_POST['db_user'] ?? '') ?>"
           placeholder="namaakun_user" required
           class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl text-sm focus:ring-2 focus:ring-brand focus:bg-white outline-none transition">
  </div>

  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1.5">Password Database</label>
    <input type="password" name="db_pass"
           class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl text-sm focus:ring-2 focus:ring-brand focus:bg-white outline-none transition">
    <p class="text-xs text-gray-400 mt-1">Kosongkan jika tidak ada password.</p>
  </div>

  <div class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-3 text-xs text-blue-700">
    <p class="font-semibold mb-0.5">Yang akan diimport:</p>
    <p class="text-blue-600 font-mono">stifin-install.sql</p>
    <p class="text-blue-500 mt-1">File SQL lengkap yang berisi seluruh skema dan data aplikasi.</p>
  </div>

  <button type="submit"
          class="w-full bg-brand text-white font-semibold py-3.5 rounded-xl hover:opacity-90 transition mt-2">
    Hubungkan &amp; Import Database
  </button>
</form>

<?php endif; ?>

<?php include __DIR__ . '/../partials/installer-foot.php'; ?>
