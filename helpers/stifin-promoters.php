<?php

function stifinPromoterBranchCode(): string
{
    return strtoupper(trim((string) getenv('STIFIN_BRANCH_CODE')));
}

function stifinBranchPromoters(string $branchCode): array
{
    if (!preg_match('/\A[A-Z0-9]{2,12}-CAB-[A-Z0-9]{1,8}\z/', $branchCode) || $branchCode === 'XXX-CAB-00') {
        throw new RuntimeException('Isi STIFIN_BRANCH_CODE dengan kode cabang resmi.');
    }

    return stifinPromoterDirectory(
        stifinPromoterApiGet('/proGetCab/pro/' . rawurlencode($branchCode)), $branchCode
    );
}

function stifinPromoterApiGet(string $path, int $timeout = 15): string
{
    return stifinPromoterApiRequest($path, null, $timeout);
}

function stifinPromoterApiRequest(string $path, ?array $payload = null, int $timeout = 15): string
{
    $baseUrl = rtrim(trim((string) getenv('STIFIN_API_BASE')), '/') ?: 'https://apro.stifin.id/api';
    $urlParts = parse_url($baseUrl);
    if (($urlParts['scheme'] ?? '') !== 'https' || empty($urlParts['host'])
        || isset($urlParts['user']) || isset($urlParts['pass'])
        || isset($urlParts['query']) || isset($urlParts['fragment'])) {
        throw new RuntimeException('STIFIN_API_BASE harus berupa URL dasar HTTPS.');
    }

    $headers = ['Accept: application/json'];
    $authHeader = trim((string) getenv('STIFIN_API_AUTH_HEADER'));
    $authValue = trim((string) getenv('STIFIN_API_AUTH_VALUE'));
    if ($authHeader !== '' || $authValue !== '') {
        if (!preg_match('/\A[A-Za-z0-9-]+\z/', $authHeader)
            || $authValue === '' || preg_match('/[\x00-\x1F\x7F]/', $authValue)) {
            throw new RuntimeException('Periksa konfigurasi autentikasi API STIFIn.');
        }
        $headers[] = $authHeader . ': ' . $authValue;
    }

    $body = '';
    $options = [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 12_000_000) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if ($payload !== null) {
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json; charset=utf-8';
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    $curl = curl_init($baseUrl . $path);
    curl_setopt_array($curl, $options);
    $received = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($received === false) {
        throw new RuntimeException('API STIFIn tidak dapat dihubungi atau respons melebihi batas ukuran.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('API STIFIn mengembalikan HTTP ' . $status . '.');
    }

    return $body;
}

function stifinPromoterDirectory(string $body, string $branchCode): array
{
    $payload = json_decode($body, true);
    $rows = $payload['data'] ?? null;
    if (!is_array($rows) || !array_is_list($rows)) {
        throw new RuntimeException('Format respons daftar promotor STIFIn tidak dikenali.');
    }

    $promoters = [];
    foreach (array_slice($rows, 0, 10_000) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = strtoupper(stifinPromoterText($row['KodeID'] ?? $row['kode'] ?? $row['code'] ?? '', 80));
        $name = stifinPromoterText($row['Nama'] ?? $row['nama'] ?? $row['name'] ?? '', 160);
        $rowBranch = strtoupper(stifinPromoterText($row['Sub'] ?? $row['KodeCabang'] ?? $row['branchCode'] ?? '', 40));
        if ($code === '' || $name === '' || ($rowBranch !== '' && $rowBranch !== $branchCode) || isset($promoters[$code])) {
            continue;
        }

        $status = $row['Aktif'] ?? $row['active'] ?? null;
        $promoters[$code] = [
            'code' => $code,
            'email' => strtolower(trim(stifinPromoterText($row['Email'] ?? $row['email'] ?? '', 500))),
            'name' => $name,
            'area' => stifinPromoterText($row['Area'] ?? $row['area'] ?? '', 120),
            'province' => stifinPromoterText($row['Propinsi'] ?? $row['province'] ?? '', 120),
            'institution' => stifinPromoterText($row['Lembaga'] ?? '', 160),
            'membership' => stifinPromoterText($row['Status'] ?? '', 80),
            'validity' => stifinPromoterText($row['Masa'] ?? '', 80),
            'active' => $status === null ? null : in_array(
                strtolower(stifinPromoterText($status, 16)), ['1', 'true', 'ya', 'yes', 'aktif'], true
            ),
        ];
    }
    if ($rows !== [] && $promoters === []) {
        throw new RuntimeException('Respons API tidak memuat data yang cocok dengan kode cabang ini.');
    }

    return array_values($promoters);
}

function stifinPromoterText(mixed $value, int $maxLength): string
{
    return is_scalar($value) ? mb_substr(trim((string) $value), 0, $maxLength) : '';
}

function stifinPromoterBalance(string $code): array
{
    $data = json_decode(stifinPromoterApiGet('/voucherGet/getTotVoucher/' . rawurlencode($code)), true);
    if (!is_array($data)) {
        throw new RuntimeException('Format saldo voucher STIFIn tidak dikenali.');
    }

    $voucherKeys = ['voucher', 'totalv', 'saldoj', 'jumlah', 'jmlvoucher', 'totvoucher', 'totvou', 'totalvoucher', 'jmlvou'];
    $freeKeys = ['free', 'totalf', 'saldof', 'jmlfree', 'totfree', 'totfre', 'totalfree'];
    $balances = ['voucher' => null, 'free' => null];
    $queue = [$data];
    while ($queue) {
        foreach (array_pop($queue) as $key => $value) {
            if (is_array($value)) {
                $queue[] = $value;
                continue;
            }
            $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($number === false || is_bool($value)) {
                continue;
            }
            $key = strtolower((string) $key);
            if (in_array($key, $voucherKeys, true)) {
                $balances['voucher'] ??= $number;
            }
            if (in_array($key, $freeKeys, true)) {
                $balances['free'] ??= $number;
            }
        }
    }

    $list = $data['data'] ?? $data;
    if ($balances['voucher'] === null && is_array($list) && array_is_list($list) && in_array(count($list), [1, 2], true)) {
        $numbers = array_map(static fn ($value) => is_scalar($value) && !is_bool($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false, $list);
        if (!in_array(false, $numbers, true)) {
            $balances = count($numbers) === 2
                ? ['free' => $numbers[0], 'voucher' => $numbers[1]]
                : ['free' => null, 'voucher' => $numbers[0]];
        }
    }
    if ($balances['voucher'] === null) {
        throw new RuntimeException('Nilai saldo voucher tidak ditemukan pada respons STIFIn.');
    }
    return $balances;
}

function stifinPromoterImport(PDO $pdo, string $branch, array $promoters, array $codes): array
{
    $byCode = array_column($promoters, null, 'code');
    $emailCounts = array_count_values(array_column($promoters, 'email'));
    $result = ['created' => 0, 'skipped' => 0];
    $findCode = $pdo->prepare('SELECT 1 FROM stifin_promoter_accounts WHERE branch_code = ? AND promoter_code = ?');
    $findEmail = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $insertUser = $pdo->prepare(
        "INSERT INTO users (name, email, password, affiliate_code, role, status) VALUES (?, ?, ?, ?, 'user', 'active')"
    );
    $linkUser = $pdo->prepare('INSERT INTO stifin_promoter_accounts (branch_code, promoter_code, user_id) VALUES (?, ?, ?)');

    foreach (array_unique(array_filter($codes, 'is_string')) as $code) {
        $row = $byCode[$code] ?? null;
        $email = $row['email'] ?? '';
        if (($row['active'] ?? null) !== true || strlen($email) > 150
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || ($emailCounts[$email] ?? 0) !== 1) {
            $result['skipped']++;
            continue;
        }

        $pdo->beginTransaction();
        try {
            $findCode->execute([$branch, $code]);
            $findEmail->execute([$email]);
            if ($findCode->fetchColumn() || $findEmail->fetchColumn()) {
                $pdo->rollBack();
                $result['skipped']++;
                continue;
            }
            $insertUser->execute([
                mb_substr($row['name'], 0, 100), $email,
                password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), bin2hex(random_bytes(8)),
            ]);
            $linkUser->execute([$branch, $code, (int) $pdo->lastInsertId()]);
            $pdo->commit();
            $result['created']++;
        } catch (Throwable $error) {
            $pdo->rollBack();
            if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                $result['skipped']++;
                continue;
            }
            throw $error;
        }
    }
    return $result;
}

function stifinLinkedPromoterCode(PDO $pdo, int $userId): ?string
{
    $branch = stifinPromoterBranchCode();
    if ($userId <= 0 || $branch === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT a.promoter_code FROM stifin_promoter_accounts a
            JOIN users u ON u.id = a.user_id AND u.status = 'active' AND u.role = 'user'
            WHERE a.user_id = ? AND a.branch_code = ?");
        $stmt->execute([$userId, $branch]);
        $code = $stmt->fetchColumn();
        return $code === false ? null : (string) $code;
    } catch (PDOException $error) {
        error_log('STIFIn promoter mapping unavailable: ' . $error->getCode());
        return null;
    }
}

function stifinLinkExistingPromoter(PDO $pdo, string $branch, array $promoters, string $code): bool
{
    $row = array_column($promoters, null, 'code')[$code] ?? null;
    $email = $row['email'] ?? '';
    $emailCounts = array_count_values(array_column($promoters, 'email'));
    if (($row['active'] ?? null) !== true || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || ($emailCounts[$email] ?? 0) !== 1) {
        return false;
    }

    $stmt = $pdo->prepare("INSERT INTO stifin_promoter_accounts (branch_code, promoter_code, user_id)
        SELECT ?, ?, id FROM users WHERE email = ? AND role = 'user' AND status = 'active'");
    try {
        $stmt->execute([$branch, $code, $email]);
        return $stmt->rowCount() === 1;
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            return false;
        }
        throw $error;
    }
}
