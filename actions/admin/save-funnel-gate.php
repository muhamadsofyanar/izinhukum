<?php
// Simpan Funnel Gate: konfigurasi form + rebuild fields (kontak di awal + pertanyaan).
// Satu POST dari editor: setting form di field biasa, daftar pertanyaan di fields_json.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/funnel-gate');
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.', 'error');
    redirect('/admin/funnel-gate');
}

// ─── Slug sanitizer (huruf/angka + tanda hubung) ──────────────
function fg_sanitize_slug(string $raw): string
{
    // Slug form ternamespace di /apply/{slug} (route publik), jadi TIDAK mungkin
    // menimpa route inti sistem — tak perlu daftar reserved. Keunikan antar-form
    // dicek terpisah (query duplikat + UNIQUE uniq_fgf_slug). Di sini cukup sanitasi;
    // kosong hanya bila input kosong / seluruhnya simbol.
    $slug = strtolower(trim($raw));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

// ─── Slugify label → field_key unik dalam satu form ───────────
function fg_field_key(string $label, array &$used): string
{
    $k = strtolower(trim($label));
    $k = preg_replace('/[^a-z0-9]+/', '_', $k);
    $k = trim($k, '_');
    if ($k === '') $k = 'field';
    $k = substr($k, 0, 50);
    $base = $k; $n = 2;
    while (in_array($k, $used, true)) { $k = $base . '_' . $n; $n++; }
    $used[] = $k;
    return $k;
}

$id            = (int) ($_POST['id'] ?? 0);
$title         = trim($_POST['title'] ?? '');
$slug          = fg_sanitize_slug($_POST['slug'] ?? ($title !== '' ? $title : ''));
$intro         = trim($_POST['intro'] ?? '');
$submitText    = trim($_POST['submit_text'] ?? '');
$successMsg    = trim($_POST['success_message'] ?? '');
$status        = $_POST['status'] ?? 'draft';
if (!in_array($status, ['draft', 'published', 'paused'], true)) $status = 'draft';
$scoreEnabled  = !empty($_POST['score_enabled']) ? 1 : 0;
$notifyAdmin   = !empty($_POST['notify_admin']) ? 1 : 0;

// URL redirect boleh relatif (/checkout) atau absolut (https://…). Tolak skema aneh.
$fg_clean_url = function (string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (str_starts_with($u, '/')) return substr($u, 0, 500);
    if (preg_match('#^https?://#i', $u)) return substr($u, 0, 500);
    return '';
};
$defaultRedirect = $fg_clean_url((string) ($_POST['default_redirect_url'] ?? ''));

// Aturan redirect by-skor: [{min,max,url,label}] dievaluasi urut saat submit.
$redirectRules = [];
$rawRules = $_POST['redirect_rules_json'] ?? '';
if ($rawRules !== '') {
    $dec = json_decode($rawRules, true);
    if (is_array($dec)) {
        foreach ($dec as $r) {
            if (!is_array($r)) continue;
            $url = $fg_clean_url((string) ($r['url'] ?? ''));
            if ($url === '') continue;
            $minRaw = $r['min'] ?? '';
            $maxRaw = $r['max'] ?? '';
            $rule = ['url' => $url, 'label' => substr(trim((string) ($r['label'] ?? '')), 0, 100)];
            if ($minRaw !== '' && is_numeric($minRaw)) $rule['min'] = (float) $minRaw;
            if ($maxRaw !== '' && is_numeric($maxRaw)) $rule['max'] = (float) $maxRaw;
            $redirectRules[] = $rule;
        }
    }
}
$redirectRulesJson = !empty($redirectRules) ? json_encode($redirectRules, JSON_UNESCAPED_UNICODE) : null;

// Bonus tie-in: list Mailketing untuk submission yang di-approve.
$mailketingList = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) ($_POST['mailketing_list_id'] ?? '')));
$mailketingList = $mailketingList !== '' ? substr($mailketingList, 0, 40) : null;

// ─── Multi Step Form (wizard) ────────────────────────────────
$formMode        = (($_POST['form_mode'] ?? 'single') === 'multi') ? 'multi' : 'single';
$progressType    = in_array(($_POST['progress_type'] ?? ''), ['bar', 'number', 'circle', 'none'], true) ? $_POST['progress_type'] : 'bar';
$stepAnimation   = in_array(($_POST['step_animation'] ?? ''), ['slide', 'fade', 'none'], true) ? $_POST['step_animation'] : 'slide';
$nextText        = trim((string) ($_POST['next_text'] ?? ''));
$backText        = trim((string) ($_POST['back_text'] ?? ''));
$allowBack       = !empty($_POST['allow_back']) ? 1 : 0;
$autosaveEnabled = !empty($_POST['autosave_enabled']) ? 1 : 0;

// Daftar step dari builder: [{cid, title, description}] urut tampil.
$steps = [];
$rawSteps = $_POST['steps_json'] ?? '';
if ($rawSteps !== '') {
    $dec = json_decode($rawSteps, true);
    if (is_array($dec)) $steps = $dec;
}

// Konfigurasi kontak (kunci desain: kontak di AWAL).
$nameLabel     = trim($_POST['name_label'] ?? '') ?: 'Nama Lengkap';
$waLabel       = trim($_POST['whatsapp_label'] ?? '') ?: 'Nomor WhatsApp';
$emailEnabled  = !empty($_POST['email_enabled']);
$emailRequired = !empty($_POST['email_required']) ? 1 : 0;
$emailLabel    = trim($_POST['email_label'] ?? '') ?: 'Email';

// ─── Validasi ─────────────────────────────────────────────────
if ($title === '') {
    flash('error', 'Judul form wajib diisi.', 'error');
    redirect('/admin/funnel-gate/edit' . ($id ? '?id=' . $id : ''));
}
if ($slug === '') {
    flash('error', 'Slug tidak valid (kosong). Isi slug memakai huruf/angka.', 'error');
    redirect('/admin/funnel-gate/edit' . ($id ? '?id=' . $id : ''));
}

// ─── Parse pertanyaan (fields_json dari builder) ──────────────
$questions = [];
$rawFields = $_POST['fields_json'] ?? '';
if ($rawFields !== '') {
    $decoded = json_decode($rawFields, true);
    if (is_array($decoded)) $questions = $decoded;
}

$allowedTypes = ['text', 'textarea', 'select', 'radio', 'checkbox', 'number', 'rating'];
$choiceTypes  = ['select', 'radio', 'checkbox'];

$pdo = getDB();

try {
    // Cek duplikat slug (kecuali milik sendiri)
    $dup = $pdo->prepare('SELECT id FROM funnel_gate_forms WHERE slug = ? AND id != ? LIMIT 1');
    $dup->execute([$slug, $id]);
    if ($dup->fetch()) {
        flash('error', 'Slug "' . $slug . '" sudah digunakan. Pakai slug lain.', 'error');
        redirect('/admin/funnel-gate/edit' . ($id ? '?id=' . $id : ''));
    }

    $pdo->beginTransaction();

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE funnel_gate_forms
             SET title = ?, slug = ?, intro = ?, submit_text = ?, success_message = ?,
                 status = ?, score_enabled = ?, notify_admin = ?,
                 default_redirect_url = ?, redirect_rules = ?, mailketing_list_id = ?,
                 form_mode = ?, progress_type = ?, step_animation = ?,
                 next_text = ?, back_text = ?, allow_back = ?, autosave_enabled = ?
             WHERE id = ?'
        )->execute([
            $title, $slug, ($intro ?: null), ($submitText ?: null), ($successMsg ?: null),
            $status, $scoreEnabled, $notifyAdmin,
            ($defaultRedirect ?: null), $redirectRulesJson, $mailketingList,
            $formMode, $progressType, $stepAnimation,
            ($nextText ?: null), ($backText ?: null), $allowBack, $autosaveEnabled, $id,
        ]);
        $formId = $id;
    } else {
        $pdo->prepare(
            'INSERT INTO funnel_gate_forms
               (title, slug, intro, submit_text, success_message, status, score_enabled, notify_admin,
                default_redirect_url, redirect_rules, mailketing_list_id,
                form_mode, progress_type, step_animation, next_text, back_text, allow_back, autosave_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $title, $slug, ($intro ?: null), ($submitText ?: null), ($successMsg ?: null),
            $status, $scoreEnabled, $notifyAdmin,
            ($defaultRedirect ?: null), $redirectRulesJson, $mailketingList,
            $formMode, $progressType, $stepAnimation,
            ($nextText ?: null), ($backText ?: null), $allowBack, $autosaveEnabled,
        ]);
        $formId = (int) $pdo->lastInsertId();
    }

    // ─── Rebuild steps + fields: hapus semua lalu insert ulang urut ───
    // Urutan penting: hapus fields dulu (FK step_id), lalu steps.
    $pdo->prepare('DELETE FROM funnel_gate_fields WHERE form_id = ?')->execute([$formId]);
    $pdo->prepare('DELETE FROM funnel_gate_steps WHERE form_id = ?')->execute([$formId]);

    // Bangun steps (hanya mode multi). Map cid builder → step_id DB.
    $stepMap     = [];   // cid => step_id
    $firstStepId = null;
    if ($formMode === 'multi') {
        if (empty($steps)) {
            // Jaga-jaga: mode multi tanpa step → buat satu step default.
            $steps = [['cid' => 's1', 'title' => 'Langkah 1', 'description' => '']];
        }
        $insStep = $pdo->prepare(
            'INSERT INTO funnel_gate_steps (form_id, title, description, sort_order) VALUES (?, ?, ?, ?)'
        );
        $so = 0;
        foreach ($steps as $stp) {
            if (!is_array($stp)) continue;
            $cid   = (string) ($stp['cid'] ?? ('s' . $so));
            $stitle = trim((string) ($stp['title'] ?? '')) ?: ('Langkah ' . ($so + 1));
            $sdesc  = trim((string) ($stp['description'] ?? ''));
            $insStep->execute([$formId, substr($stitle, 0, 200), ($sdesc !== '' ? substr($sdesc, 0, 500) : null), $so++]);
            $sid = (int) $pdo->lastInsertId();
            $stepMap[$cid] = $sid;
            if ($firstStepId === null) $firstStepId = $sid;
        }
    }

    $ins = $pdo->prepare(
        'INSERT INTO funnel_gate_fields
           (form_id, step_id, field_role, field_key, field_type, label, placeholder, help_text,
            is_required, options, weight, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    // Resolusi step_id sebuah field pertanyaan dari cid builder.
    $resolveStep = function ($cid) use ($formMode, $stepMap, $firstStepId) {
        if ($formMode !== 'multi') return null;
        $cid = (string) $cid;
        return $stepMap[$cid] ?? $firstStepId;
    };

    $used = [];
    $sort = 0;

    // Kontak dulu (selalu di awal). Di mode multi → masuk Step 1 (kunci desain).
    $contactStep = ($formMode === 'multi') ? $firstStepId : null;
    $ins->execute([$formId, $contactStep, 'name', 'name', 'text', $nameLabel, null, null, 1, null, 0, $sort++]);
    $ins->execute([$formId, $contactStep, 'whatsapp', 'whatsapp', 'phone', $waLabel, '08xxxxxxxxxx', null, 1, null, 0, $sort++]);
    if ($emailEnabled) {
        $ins->execute([$formId, $contactStep, 'email', 'email', 'email', $emailLabel, null, null, $emailRequired, null, 0, $sort++]);
    }
    // Kunci terpakai oleh kontak — jangan bentrok.
    $used = ['name', 'whatsapp', 'email'];

    // Pertanyaan.
    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $type  = in_array(($q['type'] ?? ''), $allowedTypes, true) ? $q['type'] : 'text';
        $label = trim((string) ($q['label'] ?? ''));
        if ($label === '') $label = 'Pertanyaan';
        $ph    = trim((string) ($q['placeholder'] ?? ''));
        $help  = trim((string) ($q['help'] ?? ''));
        $req   = !empty($q['required']) ? 1 : 0;
        $weight = 0;
        $optionsJson = null;

        if (in_array($type, $choiceTypes, true)) {
            $opts = [];
            $rawOpts = is_array($q['options'] ?? null) ? $q['options'] : [];
            foreach ($rawOpts as $oi => $o) {
                if (!is_array($o)) continue;
                $ol = trim((string) ($o['label'] ?? ''));
                if ($ol === '') continue;
                $os = (float) ($o['score'] ?? 0);
                $opts[] = ['label' => $ol, 'value' => (string) $oi, 'score' => $os];
            }
            if (empty($opts)) $opts[] = ['label' => 'Opsi 1', 'value' => '0', 'score' => 0];
            $optionsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
        } elseif ($type === 'number') {
            $weight = (float) ($q['weight'] ?? 0);
        } elseif ($type === 'rating') {
            $weight = (float) ($q['weight'] ?? 0);
            $max = (int) ($q['ratingMax'] ?? 5);
            if ($max < 2)  $max = 2;
            if ($max > 10) $max = 10;
            $optionsJson = json_encode(['max' => $max], JSON_UNESCAPED_UNICODE);
        }

        $key = fg_field_key($label, $used);
        $stepId = $resolveStep($q['stepCid'] ?? '');
        $ins->execute([
            $formId, $stepId, 'none', $key, $type, $label, ($ph ?: null), ($help ?: null),
            $req, $optionsJson, $weight, $sort++,
        ]);
    }

    $pdo->commit();

    if (function_exists('logActivity')) {
        logActivity('admin', $id ? 'funnel_gate.updated' : 'funnel_gate.created',
            'funnel_gate_forms', $formId, 'Funnel Gate "' . $title . '" disimpan.');
    }
    flash('success', 'Funnel Gate berhasil disimpan. URL publik: /apply/' . $slug);
    redirect('/admin/funnel-gate/edit?id=' . $formId);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Save funnel gate error: ' . $e->getMessage());
    flash('error', 'Terjadi kesalahan saat menyimpan. Pastikan migrasi sudah dijalankan.', 'error');
    redirect('/admin/funnel-gate/edit' . ($id ? '?id=' . $id : ''));
}
