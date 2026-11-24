<?php
if (!defined('INSTALLER_ENTRY')) { http_response_code(403); exit('Akses tidak diizinkan.'); }

$currentStep = 3;
$stepTitle   = 'Akun Owner';

if (empty($_SESSION['install_step2_done'])) {
    header('Location: ?step=2');
    exit;
}

// Kredensial DB harus masih ada di session. Jika hilang (sesi berakhir /
// server restart), jangan fatal — arahkan user mengulang dari Step 2.
if (empty($_SESSION['db_config']) || !is_array($_SESSION['db_config'])) {
    unset($_SESSION['install_step2_done']);
    header('Location: ?step=2');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi CSRF
    if (!hash_equals($_SESSION['installer_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $error = 'Token keamanan tidak valid. Muat ulang halaman.';
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (mb_strlen($name) < 2) {
            $error = 'Nama minimal 2 karakter.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Alamat email tidak valid.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Password minimal 8 karakter.';
        } else {
            try {
                $db  = $_SESSION['db_config'];
                $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4";
                $pdo = new PDO($dsn, $db['dbuser'], $db['dbpass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                // Cegah bentrok UNIQUE email dengan user non-admin yang mungkin
                // sudah ada (mis. dari data seed), agar tidak muncul error mentah.
                $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND role <> 'admin'");
                $dup->execute([$email]);
                if ((int) $dup->fetchColumn() > 0) {
                    throw new RuntimeException('Email ini sudah dipakai akun lain. Gunakan email yang berbeda.');
                }

                $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

                // Update admin seed yang sudah ada, atau insert baru
                $stmt = $pdo->prepare(
                    "UPDATE users SET name = ?, email = ?, password = ? WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
                );
                $stmt->execute([$name, $email, $hashed]);

                if ($stmt->rowCount() === 0) {
                    $affCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                    $stmt = $pdo->prepare(
                        "INSERT INTO users (name, email, password, role, status, affiliate_code)
                         VALUES (?, ?, ?, 'admin', 'active', ?)"
                    );
                    $stmt->execute([$name, $email, $hashed, $affCode]);
                }

                // ── Generate config.php ──────────────────────────────
                $appRoot = dirname(__DIR__, 2);

                // Deteksi APP_URL dari REQUEST_URI
                $uriPath  = strtok($_SERVER['REQUEST_URI'], '?');
                $basePath = rtrim(dirname(dirname($uriPath)), '/');

                // Deteksi scheme — harus cek X-Forwarded-Proto karena CloudPanel/cPanel
                // menggunakan Nginx/LB sebagai reverse proxy: SSL di-terminate di proxy,
                // PHP-FPM menerima request sebagai plain HTTP sehingga $_SERVER['HTTPS']
                // tidak ter-set meski user mengakses via HTTPS.
                $isHttps = (
                    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                    (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') ||
                    (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
                );
                $scheme = $isHttps ? 'https' : 'http';
                $appUrl = getenv('DARMUTIARA_APP_URL') ?: ($scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath);

                $configContent = "<?php\n";
                $configContent .= "define('DARMUTIARA_ROOT', " . var_export($appRoot, true) . ");\n";
                $configContent .= "// ─── Database ─────────────────────────────────────────────────\n";
                $configContent .= "define('DB_HOST', " . var_export($db['host'], true) . ");\n";
                $configContent .= "define('DB_NAME', " . var_export($db['dbname'], true) . ");\n";
                $configContent .= "define('DB_USER', " . var_export($db['dbuser'], true) . ");\n";
                $configContent .= "define('DB_PASS', " . var_export($db['dbpass'], true) . ");\n";
                $configContent .= "define('DB_PORT', " . var_export($db['port'], true) . ");\n";
                $configContent .= "\n";
                $configContent .= "// ─── Aplikasi: APP_URL ────────────────────────────────────────\n";
                $configContent .= "define('APP_URL', " . var_export(rtrim($appUrl, '/'), true) . ");\n";
                $configContent .= "define('APP_NAME', 'STIFIn Mulia');\n";
                $configContent .= "// APP_VERSION dibaca dari version.php (di-overwrite otomatis saat update sistem).\n";
                $configContent .= "require_once DARMUTIARA_ROOT . '/version.php';\n";
                $configContent .= "\n";
                $configContent .= "// ─── Session ──────────────────────────────────────────────────\n";
                $configContent .= "define('SESSION_NAME', 'darmutiara_sess');\n";
                $configContent .= "\n";
                $configContent .= "// ─── Upload ───────────────────────────────────────────────────\n";
                $configContent .= "define('UPLOAD_PATH', DARMUTIARA_ROOT . '/assets/uploads/');\n";
                $configContent .= "define('UPLOAD_URL', APP_URL . '/assets/uploads/');\n";
                $configContent .= "define('MAX_UPLOAD_SIZE', 2 * 1024 * 1024);\n";
                $configContent .= "\n";
                $configContent .= "// ─── Download Manager (file terproteksi di luar webroot) ──────\n";
                $configContent .= "define('DOWNLOAD_STORAGE_PATH', DARMUTIARA_ROOT . '/storage/downloads/');\n";
                $configContent .= "define('DOWNLOAD_MAX_SIZE', 100 * 1024 * 1024);\n";
                $configContent .= "\n";
                $configContent .= "// ─── Update System ────────────────────────────────────────────\n";
                $configContent .= "define('UPDATE_BACKUP_PATH', DARMUTIARA_ROOT . '/backups/');\n";
                $configContent .= "define('UPDATE_TEMP_PATH',   __DIR__ . '/storage/update-tmp/');\n";
                $configContent .= "\n";
                $configContent .= "// ─── License Server ───────────────────────────────────────────\n";
                $configContent .= "define('LICENSE_SERVER_URL', 'https://lisensi.darmutiara.com/v1');\n";
                $configContent .= "\n";
                $configContent .= "// ─── Kontak Vendor (ditampilkan di halaman license-expired) ───\n";
                $configContent .= "define('VENDOR_EMAIL', '');\n";
                $configContent .= "define('VENDOR_WHATSAPP', '');\n";

                if (file_put_contents($appRoot . '/config.php', $configContent) === false) {
                    throw new RuntimeException('Gagal menulis config.php. Pastikan folder bisa ditulis.');
                }
                @chmod($appRoot . '/config.php', 0640);

                // ── Kunci installer ──────────────────────────────────
                file_put_contents(dirname(__DIR__) . '/install.lock', date('Y-m-d H:i:s'));

                $_SESSION['install_step3_done'] = true;
                unset($_SESSION['db_config']); // hapus kredensial dari session

                header('Location: ?step=4');
                exit;

            } catch (PDOException $e) {
                $error = 'Gagal menyimpan ke database: ' . $e->getMessage();
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            } catch (Exception $e) {
                $error = 'Terjadi kesalahan: ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../partials/installer-head.php';
?>

<p class="text-sm text-gray-400 mb-5">
  Buat akun <span class="font-semibold text-gray-600">Owner</span> untuk masuk ke aplikasi setelah instalasi selesai.
</p>

<?php if ($error): ?>
  <div class="bg-red-50 border border-red-100 text-red-600 text-sm rounded-xl px-4 py-3 mb-4 leading-relaxed">
    <?= e($error) ?>
  </div>
<?php endif; ?>

<form method="POST" class="space-y-4">
  <input type="hidden" name="_csrf" value="<?= e($_SESSION['installer_csrf'] ?? '') ?>">

  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1.5">Nama Lengkap</label>
    <input type="text" name="name"
           value="<?= e($_POST['name'] ?? '') ?>"
           placeholder="Nama Anda" required
           class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl text-sm focus:ring-2 focus:ring-brand focus:bg-white outline-none transition">
  </div>

  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
    <input type="email" name="email"
           value="<?= e($_POST['email'] ?? '') ?>"
           placeholder="email@anda.com" required
           class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl text-sm focus:ring-2 focus:ring-brand focus:bg-white outline-none transition">
  </div>

  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1.5">
      Password
      <span class="text-gray-400 font-normal">(min. 8 karakter)</span>
    </label>
    <input type="password" name="password" required
           class="w-full px-4 py-3 bg-gray-50 border border-gray-100 rounded-xl text-sm focus:ring-2 focus:ring-brand focus:bg-white outline-none transition">
  </div>

  <button type="submit"
          class="w-full bg-brand text-white font-semibold py-3.5 rounded-xl hover:opacity-90 transition mt-2">
    Selesaikan Pemasangan
  </button>
</form>

<?php include __DIR__ . '/../partials/installer-foot.php'; ?>
