<?php

function stifinOfficialCsvRows($stream, string $valueColumn): array
{
    $header = fgetcsv($stream, 0, ',', '"', '');
    if ($header) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }
    if ($header !== ['referensi', 'tanggal', 'kode_promotor', $valueColumn]) {
        throw new InvalidArgumentException('Kolom CSV harus mengikuti template laporan.');
    }

    $rows = [];
    $line = 1;
    while (($raw = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $line++;
        if ($raw === [null]) {
            continue;
        }
        if (count($raw) !== 4 || count($rows) >= 5000) {
            throw new InvalidArgumentException("Baris CSV bermasalah ($line) atau melebihi 5.000 catatan.");
        }

        [$ref, $date, $promoter, $value] = array_map('trim', $raw);
        $parsed = preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/D', $date)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date)
            : false;
        $validValue = match ($valueColumn) {
            'hasil' => preg_match('/^[\p{L}\p{N} ._()+\/-]{1,120}$/uD', $value),
            'jumlah_tes' => preg_match('/^\d{1,9}$/D', $value),
            default => preg_match('/^[+-]?\d{1,9}$/D', $value),
        };
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/-]{0,99}$/D', $ref)
            || isset($rows[$ref])
            || !$parsed || $parsed->format('Y-m-d') !== $date
            || !preg_match('/^[A-Za-z0-9_-]{0,40}$/D', $promoter)
            || !$validValue) {
            throw new InvalidArgumentException("Nilai atau referensi duplikat pada catatan CSV $line.");
        }
        $rows[$ref] = [$ref, $date, strtoupper($promoter), $value];
    }

    if (!$rows) {
        throw new InvalidArgumentException('CSV belum berisi catatan.');
    }
    return $rows;
}

function stifinSyncOfficialMonth(PDO $db, string $branch, DateTimeImmutable $start): int
{
    return stifinSyncOfficialRange($db, $branch, $start, $start->modify('last day of this month'));
}

function stifinSyncOfficialRange(PDO $db, string $branch, DateTimeImmutable $start, DateTimeImmutable $end): int
{
    if (!preg_match('/\A[A-Z0-9]{2,12}-CAB-[A-Z0-9]{1,8}\z/', $branch) || $branch === 'XXX-CAB-00') {
        throw new RuntimeException('Kode cabang resmi belum dikonfigurasi.');
    }
    $path = '/print/repHasCab/1/' . $start->format('Y-m-j') . '/'
        . $end->format('Y-m-d') . '/' . rawurlencode($branch);
    $multiMonth = $start->format('Y-m') !== $end->format('Y-m');
    $rows = stifinOfficialApiResultRows(stifinPromoterApiGet($path, $multiMonth ? 60 : 15), $start, $multiMonth);
    if ($rows) {
        stifinSaveOfficialRows($db, $branch, 'hasil', $rows);
    }
    return count($rows);
}

function stifinProcessOfficialHistory(PDO $db, string $branch): void
{
    if ($branch === '') {
        return;
    }
    $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lockName = 'stifin-report-history:' . $branch;
    $lock->execute([$lockName]);
    $locked = (int) $lock->fetchColumn() === 1;
    $lock->closeCursor();
    if (!$locked) {
        return;
    }

    try {
        $find = $db->prepare("SELECT next_month, end_month, try_range FROM stifin_official_history
            WHERE branch_code = ? AND status = 'pending' AND retry_at <= NOW()");
        $find->execute([$branch]);
        $job = $find->fetch(PDO::FETCH_ASSOC);
        if ($job === false) {
            return;
        }
        $month = $job['next_month'];
        $start = new DateTimeImmutable($month);
        if ((int) $job['try_range'] === 1) {
            $end = new DateTimeImmutable($job['end_month']);
            try {
                $count = stifinSyncOfficialRange($db, $branch, $start, $end->modify('last day of this month'));
            } catch (Throwable $error) {
                $db->prepare("UPDATE stifin_official_history SET try_range = 0
                    WHERE branch_code = ? AND next_month = ? AND status = 'pending'")->execute([$branch, $month]);
                error_log('STIFIn range sync deferred to monthly requests');
                return;
            }
            $difference = $start->diff($end);
            $months = $difference->y * 12 + $difference->m + 1;
            $next = $end->modify('+1 month')->format('Y-m-d');
            $db->prepare("UPDATE stifin_official_history
                SET status = 'complete', next_month = ?, try_range = 0, attempts = 0,
                    months_done = months_done + ?, results_seen = results_seen + ?
                WHERE branch_code = ? AND next_month = ? AND status = 'pending'")
                ->execute([$next, $months, $count, $branch, $month]);
            return;
        }
        try {
            $count = stifinSyncOfficialMonth($db, $branch, $start);
        } catch (Throwable $error) {
            $db->prepare("UPDATE stifin_official_history
                SET status = IF(attempts >= 2, 'failed', 'pending'), attempts = attempts + 1,
                    retry_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE)
                WHERE branch_code = ? AND next_month = ? AND status = 'pending'")->execute([$branch, $month]);
            error_log('STIFIn history sync failed for ' . $start->format('Y-m'));
            return;
        }
        $next = $start->modify('+1 month')->format('Y-m-d');
        $db->prepare("UPDATE stifin_official_history
            SET status = IF(? > end_month, 'complete', 'pending'), next_month = ?,
                attempts = 0, months_done = months_done + 1, results_seen = results_seen + ?
            WHERE branch_code = ? AND next_month = ? AND status = 'pending'")
            ->execute([$next, $next, $count, $branch, $month]);
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
        $release->closeCursor();
    }
}

function stifinOfficialApiDate(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/D', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

function stifinOfficialApiResultRows(string $body, DateTimeImmutable $start, bool $multiMonth = false): array
{
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $data = $payload['data'] ?? null;
    $total = filter_var($payload['total'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if (($payload['Pesan'] ?? null) !== 'Success' || !is_array($data) || !array_is_list($data)
        || $total === false || $total !== count($data)) {
        throw new RuntimeException('API belum mengembalikan laporan bulanan yang lengkap.');
    }

    $rows = [];
    foreach ($data as $item) {
        $ref = $item['KodeID'] ?? null;
        $value = $item['KetHasil'] ?? '';
        if (!is_string($ref) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D', $ref)
            || !is_string($value)
            || !preg_match('/^[\p{L}\p{N} ._()+\/-]{0,120}$/uD', trim($value))) {
            throw new RuntimeException('API mengembalikan hasil tes tanpa referensi atau kode hasil yang valid.');
        }

        $date = stifinOfficialApiDate($item['TglTes'] ?? null);
        $delivery = stifinOfficialApiDate($item['TglKirim'] ?? null);
        if ($multiMonth && $date === null && $delivery === null) {
            throw new RuntimeException('Ada hasil yang belum bisa ditempatkan pada bulan laporan.');
        }
        $period = $delivery === null ? $start->format('Y-m-d') : substr($delivery, 0, 7) . '-01';
        $identity = 'api:' . hash('sha256', implode('|', [$ref, $date ?? '', $delivery ?? '']));
        if (isset($rows[$identity])) {
            throw new RuntimeException('API mengembalikan dua hasil dengan referensi dan tanggal yang sama.');
        }
        $promoter = '';
        if (preg_match('/^([A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+)-[0-9]{4}-[0-9]{4}$/D', $ref, $parts)
            && strlen($parts[1]) <= 40) {
            $promoter = $parts[1];
        }
        $name = stifinPromoterText($item['Nama'] ?? '', 160);
        $phone = stifinPromoterText($item['Phone'] ?? '', 40);
        $email = stifinPromoterText($item['Email'] ?? '', 254);
        $rows[$identity] = [$identity, $date ?? $period, $promoter, trim($value), (int) ($date === null),
            $name, $phone, filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '', $ref, $delivery];
    }
    return $rows;
}

function stifinSaveOfficialRows(PDO $db, string $branch, string $type, array $rows): void
{
    $db->beginTransaction();
    try {
        $insert = $db->prepare(
            <<<'SQL'
            INSERT INTO stifin_official_report_rows
                (branch_code, report_type, source_ref, report_date, promoter_code, report_value, period_only, client_name, client_phone, client_email, official_ref, sent_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                report_date = CASE WHEN VALUES(period_only) = 0 THEN VALUES(report_date)
                    WHEN period_only = 0 THEN report_date ELSE VALUES(report_date) END,
                period_only = LEAST(period_only, VALUES(period_only)),
                promoter_code = IF(VALUES(promoter_code) = '', promoter_code, VALUES(promoter_code)),
                report_value = VALUES(report_value),
                client_name = COALESCE(VALUES(client_name), client_name),
                client_phone = COALESCE(VALUES(client_phone), client_phone),
                client_email = COALESCE(VALUES(client_email), client_email),
                official_ref = COALESCE(VALUES(official_ref), official_ref),
                sent_date = COALESCE(VALUES(sent_date), sent_date),
                imported_at = CURRENT_TIMESTAMP
            SQL
        );
        $legacyMatches = [];
        foreach ($rows as $row) {
            if (isset($row[8])) {
                $legacyKey = $row[8] . '|' . $row[1] . '|' . $row[3];
                $legacyMatches[$legacyKey] = ($legacyMatches[$legacyKey] ?? 0) + 1;
            }
        }
        $adopt = $db->prepare("UPDATE IGNORE stifin_official_report_rows SET source_ref = ?
            WHERE branch_code = ? AND report_type = ? AND source_ref = ? AND official_ref IS NULL
            AND report_date = ? AND report_value = ?");
        ksort($rows, SORT_STRING);
        foreach ($rows as $row) {
            if (isset($row[8]) && $legacyMatches[$row[8] . '|' . $row[1] . '|' . $row[3]] === 1) {
                $adopt->execute([$row[0], $branch, $type, $row[8], $row[1], $row[3]]);
            }
            $insert->execute([$branch, $type, ...($row + [4 => 0, 5 => null, 6 => null, 7 => null, 8 => null, 9 => null])]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
