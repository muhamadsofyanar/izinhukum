<?php
require_once __DIR__ . '/../../bootstrap.php';

$admin = ($kajianAdmin ?? false) === true;
if ($admin) {
    requireAdminOrStaff();
    if (!staffCan('content')) {
        redirect('/admin/no-access');
    }
} else {
    requireLogin();
}

$path = $admin ? '/admin/kajian' : '/kajian';
$id = max(0, (int) ($_GET['id'] ?? 0));
$detailPath = $path . ($id ? '?id=' . $id : '');
$userId = (int) $_SESSION['user_id'];
$preview = !empty($_SESSION['impersonating']);
$csrf = generateCSRF();
$timezone = new DateTimeZone('Asia/Jakarta');
$now = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$event = null;
$events = $posts = $products = $participants = [];
$upcomingEvents = $pastEvents = [];
$registered = false;
$courseUrl = null;
$pageError = '';
$filters = ['kind' => 'regular', 'q' => '', 'product' => '', 'status' => '', 'when' => '', 'from' => '', 'to' => ''];
if ($admin && !$id) {
    foreach ($filters as $key => $default) {
        $value = $_GET[$key] ?? $default;
        $filters[$key] = is_string($value) ? trim($value) : $default;
    }
    if (!in_array($filters['kind'], ['regular', 'demo', 'all'], true)) $filters['kind'] = 'regular';
    if (!in_array($filters['status'], ['published', 'draft'], true)) $filters['status'] = '';
    if ($filters['when'] !== 'upcoming') $filters['when'] = '';
    if ($filters['product'] !== 'general' && (!ctype_digit($filters['product']) || (int) $filters['product'] <= 0)) {
        $filters['product'] = '';
    }
    foreach (['from', 'to'] as $key) {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $filters[$key])
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key], $timezone)
            : false;
        if (!$date || $date->format('Y-m-d') !== $filters[$key] || (int) $date->format('Y') < 1000) {
            $filters[$key] = '';
        }
    }
    $filters['q'] = mb_substr($filters['q'], 0, 180);
}

function darmutiaraField(string $name): string
{
    $value = $_POST[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

function darmutiaraHttpsLink(string $name): ?string
{
    $link = darmutiaraField($name);
    if ($link === '') {
        return null;
    }
    if (strlen($link) > 500 || !filter_var($link, FILTER_VALIDATE_URL)
        || strtolower((string) parse_url($link, PHP_URL_SCHEME)) !== 'https'
        || parse_url($link, PHP_URL_USER) !== null || parse_url($link, PHP_URL_PASS) !== null) {
        throw new DomainException('Gunakan alamat HTTPS yang valid.');
    }
    return $link;
}

function darmutiaraKajianAccess(array $event, int $userId): bool
{
    return !str_starts_with(strtolower($event['title']), '[demo]')
        && (bool) $event['published']
        && ($event['product_id'] === null
            || (!empty($event['product_exists']) && hasAccess($userId, (int) $event['product_id'])));
}

function darmutiaraKajianCalendar(array $event, DateTimeZone $timezone): void
{
    $start = new DateTimeImmutable($event['starts_at'], $timezone);
    $utc = $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    $title = strtr($event['title'], ["\\" => "\\\\", "\r" => '', "\n" => '\\n', ',' => '\\,', ';' => '\\;']);
    $uid = hash('sha256', APP_URL . '/kajian?id=' . $event['id']) . '@darmutiara';
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Darmutiara//Kajian//ID',
        'BEGIN:VEVENT', 'UID:' . $uid,
        'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART:' . $utc,
        'SUMMARY:' . $title,
        'BEGIN:VALARM', 'TRIGGER:-PT30M', 'ACTION:DISPLAY', 'DESCRIPTION:Pengingat kajian',
        'END:VALARM', 'END:VEVENT', 'END:VCALENDAR',
    ];
    header('Content-Type: text/calendar; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kajian-' . (int) $event['id'] . '.ics"');
    header('Cache-Control: private, no-store');
    echo implode("\r\n", $lines) . "\r\n";
    exit;
}

try {
    $pdo = getDB();
    if ($isPost) {
        if (!validateCSRF(darmutiaraField('csrf_token')) || $preview) {
            throw new DomainException('Aksi tidak tersedia. Muat ulang halaman atau keluar dari mode pratinjau.');
        }
        $pdo->beginTransaction();
    }

    if ($id) {
        $stmt = $pdo->prepare('SELECT k.*, EXISTS(SELECT 1 FROM products WHERE id = k.product_id) AS product_exists FROM darmutiara_kajian k WHERE k.id = ?' . ($isPost ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $event = $stmt->fetch();
        if (!$event || (!$admin && !darmutiaraKajianAccess($event, $userId))) {
            throw new DomainException('Kajian tidak tersedia untuk akun Anda.');
        }
    }

    if (!$admin && $event) {
        $stmt = $pdo->prepare('SELECT 1 FROM darmutiara_kajian_participants WHERE kajian_id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $registered = (bool) $stmt->fetchColumn();
        if (!$isPost && $registered && ($_GET['calendar'] ?? '') === '1') {
            darmutiaraKajianCalendar($event, $timezone);
        }
        if (!$isPost && $event['product_id'] !== null) {
            $stmt = $pdo->prepare("SELECT 1 FROM products p
                WHERE p.id = ? AND p.access_type = 'course'
                AND EXISTS (
                    SELECT 1 FROM classes c
                    JOIN materials m ON m.class_id = c.id
                    WHERE c.status = 'active' AND (c.product_id = p.id OR EXISTS (
                        SELECT 1 FROM class_products cp WHERE cp.class_id = c.id AND cp.product_id = p.id
                    ))
                )");
            $stmt->execute([$event['product_id']]);
            if ($stmt->fetchColumn()) {
                $courseUrl = rtrim(APP_URL, '/') . '/member/' . (int) $event['product_id'];
            }
        }
    }

    if ($isPost) {
        $intent = darmutiaraField('intent');
        if ($admin && $intent === 'save') {
            $title = darmutiaraField('title');
            $speaker = darmutiaraField('speaker');
            $location = darmutiaraField('location');
            $dateText = darmutiaraField('starts_at');
            $start = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $dateText, $timezone);
            if ($title === '' || mb_strlen($title) > 180 || $speaker === '' || mb_strlen($speaker) > 120
                || mb_strlen($location) > 240 || !$start || $start->format('Y-m-d\TH:i') !== $dateText
                || (int) $start->format('Y') < 1000) {
                throw new DomainException('Periksa judul, pemateri, lokasi, dan jadwal kajian.');
            }
            $product = darmutiaraField('product_id');
            if ($product !== '' && (!ctype_digit($product) || (int) $product <= 0)) {
                throw new DomainException('Pilih program Averion yang valid.');
            }
            $productId = $product === '' ? null : (int) $product;
            if ($productId !== null) {
                $stmt = $pdo->prepare('SELECT 1 FROM products WHERE id = ?');
                $stmt->execute([$productId]);
                if (!$stmt->fetchColumn()) {
                    throw new DomainException('Program Averion yang dipilih tidak tersedia.');
                }
            }
            $values = [$title, $speaker, $start->format('Y-m-d H:i:s'), $location,
                darmutiaraHttpsLink('meeting_url'), darmutiaraHttpsLink('recording_url'),
                $productId, darmutiaraField('published') === '1' ? 1 : 0];
            if ($id) {
                $stmt = $pdo->prepare('UPDATE darmutiara_kajian SET title=?, speaker=?, starts_at=?,
                    location=?, meeting_url=?, recording_url=?, product_id=?, published=? WHERE id=?');
                $stmt->execute([...$values, $id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO darmutiara_kajian
                    (title, speaker, starts_at, location, meeting_url, recording_url, product_id, published)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute($values);
                $id = (int) $pdo->lastInsertId();
            }
            $message = 'Kajian disimpan.';
        } elseif ($admin && $event && in_array($intent, ['hide', 'show'], true)) {
            $stmt = $pdo->prepare('UPDATE darmutiara_kajian_posts SET hidden = ? WHERE id = ? AND kajian_id = ?');
            $stmt->execute([$intent === 'hide' ? 1 : 0, (int) darmutiaraField('post_id'), $id]);
            $message = 'Status komentar diperbarui.';
        } elseif (!$admin && $event && $intent === 'register') {
            $stmt = $pdo->prepare('INSERT INTO darmutiara_kajian_participants (kajian_id, user_id)
                VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)');
            $stmt->execute([$id, $userId]);
            $message = 'Pendaftaran dicatat.';
        } elseif (!$admin && $event && $registered && $intent === 'comment') {
            $body = darmutiaraField('body');
            if ($body === '' || mb_strlen($body) > 2000) {
                throw new DomainException('Isi komentar antara 1 dan 2.000 karakter.');
            }
            $stmt = $pdo->prepare('INSERT INTO darmutiara_kajian_posts (kajian_id, user_id, body) VALUES (?, ?, ?)');
            $stmt->execute([$id, $userId, $body]);
            $message = 'Komentar dikirim.';
        } else {
            throw new DomainException('Aksi kajian tidak tersedia.');
        }
        $pdo->commit();
        flash('kajian', $message, 'success');
        redirect($path . '?id=' . $id);
    }

    if ($admin) {
        $products = $pdo->query('SELECT id, name FROM products ORDER BY name, id')->fetchAll();
    }
    if (!$event) {
        $query = 'SELECT k.id, k.title, k.speaker, k.starts_at, k.published, k.product_id,
            EXISTS(SELECT 1 FROM products WHERE id = k.product_id) AS product_exists FROM darmutiara_kajian k';
        if ($admin) {
            $where = [];
            $params = [];
            if ($filters['kind'] === 'demo') $where[] = "LOWER(k.title) LIKE '[demo]%'";
            if ($filters['kind'] === 'regular') $where[] = "LOWER(k.title) NOT LIKE '[demo]%'";
            if ($filters['q'] !== '') {
                $where[] = '(LOCATE(?, k.title) > 0 OR LOCATE(?, k.speaker) > 0)';
                $params[] = $filters['q'];
                $params[] = $filters['q'];
            }
            if ($filters['product'] === 'general') {
                $where[] = 'k.product_id IS NULL';
            } elseif ($filters['product'] !== '') {
                $where[] = 'k.product_id = ?';
                $params[] = (int) $filters['product'];
            }
            if ($filters['status'] !== '') {
                $where[] = 'k.published = ?';
                $params[] = (int) ($filters['status'] === 'published');
            }
            if ($filters['when'] === 'upcoming') {
                $where[] = 'k.starts_at >= ?';
                $params[] = $now;
            }
            if ($filters['from'] !== '') {
                $where[] = 'k.starts_at >= ?';
                $params[] = $filters['from'] . ' 00:00:00';
            }
            if ($filters['to'] !== '') {
                $where[] = 'k.starts_at <= ?';
                $params[] = $filters['to'] . ' 23:59:59';
            }
            $query .= $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare($query . ' ORDER BY starts_at DESC, id DESC');
            $stmt->execute($params);
            $events = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare('SELECT k.id, k.title, k.speaker, k.starts_at, k.published, k.product_id,
                p.id IS NOT NULL AS product_exists, r.user_id IS NOT NULL AS registered
                FROM darmutiara_kajian k
                LEFT JOIN products p ON p.id = k.product_id
                LEFT JOIN darmutiara_kajian_participants r ON r.kajian_id = k.id AND r.user_id = ?
                WHERE k.published = 1 ORDER BY k.starts_at DESC, k.id DESC');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll() as $row) {
                if (!darmutiaraKajianAccess($row, $userId)) {
                    continue;
                }
                if ($row['starts_at'] >= $now) {
                    $upcomingEvents[] = $row;
                } else {
                    $pastEvents[] = $row;
                }
            }
            $upcomingEvents = array_reverse($upcomingEvents);
        }
    }
    if ($event && ($admin || $registered)) {
        if ($admin) {
            $stmt = $pdo->prepare('SELECT u.name FROM darmutiara_kajian_participants p
                JOIN users u ON u.id = p.user_id WHERE p.kajian_id = ? ORDER BY p.registered_at, u.id');
            $stmt->execute([$id]);
            $participants = $stmt->fetchAll();
        }
        $query = 'SELECT p.id, p.body, p.hidden, u.name FROM darmutiara_kajian_posts p
            JOIN users u ON u.id = p.user_id WHERE p.kajian_id = ?';
        $stmt = $pdo->prepare($query . ($admin ? '' : ' AND p.hidden = 0') . ' ORDER BY p.id DESC LIMIT 100');
        $stmt->execute([$id]);
        $posts = $stmt->fetchAll();
    }
} catch (DomainException $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($isPost) {
        flash('kajian', $error->getMessage(), 'error');
        redirect($detailPath);
    }
    http_response_code(404);
    $pageError = $error->getMessage();
} catch (PDOException $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Darmutiara Kajian: ' . $error->getMessage());
    http_response_code(503);
    $pageError = 'Modul Kajian belum dapat dimuat. Hubungi pengurus.';
}

$pageTitle = $topbarTitle = $admin ? 'Kelola Kajian' : 'Jadwal Kajian';
$root = __DIR__ . '/../../';
require __DIR__ . '/kajian-view.php';
