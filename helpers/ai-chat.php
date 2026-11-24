<?php
// ─── AI Sales Assistant (AI Chat Fase 1) ───────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php). Fungsi
// di sini memakai primitif core (getSetting/getDB/e) saat runtime — aman.
// Behavior-preserving: pindah utuh, tanpa ubah signature/logika.

/**
 * Konteks jualan untuk AI Chat. Dipakai actions/chat/send.php & stream.php.
 *
 * DUA TINGKAT (sejak v1.2.5) — ini soal KUOTA, bukan estetika:
 *   - SEMUA produk → baris ringkas (nama, harga, status, link beli). AI tetap
 *     tahu seluruh katalog & tak akan mengarang produk yang tak ada.
 *   - Produk RELEVAN dgn pertanyaan → + deskripsi + catatan AI Knowledge penuh.
 *
 * Kenapa: dulu SEMUA produk dikirim lengkap dgn deskripsi + ai_knowledge (bisa
 * 4.000 char/produk), DIULANG di SETIAP pesan. Groq free tier = 6.000 token/menit
 * → katalog 7 produk ber-ai_knowledge ≈ 4-5rb token/pesan = pembeli kena limit di
 * pesan KEDUA, lalu melihat "AI sedang tidak tersedia". Ironisnya, mengisi
 * ai_knowledge (obat untuk jawaban ngambang) justru MEMPERCEPAT limit itu.
 *
 * $userMessage kosong (mis. pemanggil lama) → perilaku aman: ringkas semua,
 * detail tak ada. Lebih baik hemat daripada tiba-tiba kehabisan kuota.
 */
function buildAiSalesContext(string $userMessage = '', int $focusProductId = 0): string
{
    $out = '';
    try {
        $pdo = getDB();
        $products = $pdo->query(
            "SELECT id, name, slug, type, status, price, original_price,
                    COALESCE(short_description, '') AS sdesc,
                    COALESCE(SUBSTR(description, 1, 300), '') AS descr,
                    COALESCE(ai_knowledge, '') AS knowledge
             FROM products WHERE status IN ('active', 'coming_soon')
             ORDER BY sort_order ASC, created_at DESC LIMIT 30"
        )->fetchAll();
        if (!$products) return '';

        // Produk fokus = produk yang tertaut ke halaman (salespage canvas). Harus
        // ada di katalog publik ('active'/'coming_soon') agar tak membocorkan draft.
        $focusName = '';
        if ($focusProductId > 0) {
            foreach ($products as $p) {
                if ((int) $p['id'] === $focusProductId) { $focusName = (string) $p['name']; break; }
            }
            if ($focusName === '') $focusProductId = 0; // bukan produk publik → abaikan fokus
        }

        // Upsell terpasang per produk pemicu (untuk tawaran lanjutan).
        $upsells = [];
        try {
            foreach ($pdo->query(
                "SELECT pu.main_product_id, p.name, pu.upsell_price
                 FROM product_upsells pu JOIN products p ON p.id = pu.upsell_product_id
                 WHERE pu.status = 'active'"
            )->fetchAll() as $u) {
                $upsells[(int) $u['main_product_id']] = $u;
            }
        } catch (Exception $e) { /* tabel belum ada di instalasi lama — abaikan */ }

        $relevan = aiRelevantProductIds($userMessage, $products);
        // Produk halaman SELALU dapat detail+knowledge penuh (fokus jualan), apa pun
        // isi pertanyaan — pengunjung sedang melihat halaman produk ini.
        if ($focusProductId > 0 && !in_array($focusProductId, $relevan, true)) {
            $relevan[] = $focusProductId;
        }

        $base = baseUrl();
        if ($focusProductId > 0) {
            $out .= "\n\n=== KONTEKS HALAMAN ===\nPengunjung sedang membuka halaman penjualan produk: \"{$focusName}\". "
                 . "Utamakan menjawab dan menawarkan produk ini. Boleh menyinggung produk lain hanya bila relevan dengan pertanyaan.\n";
        }
        $out .= "\n=== KATALOG PRODUK (data resmi — HANYA jawab berdasarkan ini) ===\n";
        foreach ($products as $p) {
            $pid   = (int) $p['id'];
            $line  = '• ' . $p['name'] . ' [' . $p['type'] . ']';
            $line .= ' — Harga: ' . formatRupiah((float) $p['price']);
            if ((float) $p['original_price'] > (float) $p['price']) {
                $line .= ' (diskon dari ' . formatRupiah((float) $p['original_price']) . ')';
            }
            if ($p['status'] === 'coming_soon') {
                $line .= ' — STATUS: COMING SOON (belum bisa dibeli, tawarkan join waitlist di halaman produk)';
            } else {
                $line .= ' — Link beli: ' . $base . '/checkout?product_id=' . $pid;
            }
            $out .= $line . "\n";

            // Detail panjang HANYA untuk produk yang relevan dgn pertanyaan.
            if (!in_array($pid, $relevan, true)) continue;

            $sdesc = trim(preg_replace('/\s+/', ' ', strip_tags($p['sdesc'] !== '' ? $p['sdesc'] : $p['descr'])));
            if ($sdesc !== '') { $out .= '  Deskripsi: ' . $sdesc . "\n"; }
            $kn = trim((string) $p['knowledge']);
            if ($kn !== '') { $out .= "  Catatan penjual:\n" . preg_replace('/^/m', '  ', mb_substr($kn, 0, 1500)) . "\n"; }
            if (isset($upsells[$pid])) {
                $u = $upsells[$pid];
                $out .= '  Setelah beli produk ini, tersedia penawaran lanjutan: ' . $u['name']
                    . ' seharga ' . formatRupiah((float) $u['upsell_price']) . "\n";
            }
        }

        $faq = trim((string) getSetting('ai_chat_faq', ''));
        if ($faq !== '') {
            $out .= "\n=== INFO & KEBIJAKAN TOKO (FAQ resmi) ===\n" . mb_substr($faq, 0, 4000) . "\n";
        }
    } catch (Exception $e) {
        error_log('buildAiSalesContext: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Model Groq yang dipakai AI Chat. Satu sumber kebenaran — dulu di-hardcode di 4
 * file (send.php, stream.php, helpers aiCompleteSimple, test-ai-chat) sehingga
 * menggantinya = mengedit 4 tempat, dan kalau Groq memensiunkan sebuah model,
 * chat mati tanpa admin bisa berbuat apa pun sendiri.
 *
 * Default gpt-oss-120b: diuji berdampingan pada pertanyaan & katalog nyata —
 * jawabannya paling terstruktur (sebut produk + harga + link + arahan), masih
 * ~1,3 detik. llama-3.1-8b (default lama) bahkan tak mengenali produknya.
 */
function aiChatModel(): string
{
    $m = trim((string) getSetting('ai_chat_model', ''));
    return $m !== '' ? $m : 'openai/gpt-oss-120b';
}

/** Pilihan model yang ditawarkan di Admin → AI Chat (label = penjelasan utk user). */
function aiChatModelOptions(): array
{
    return [
        'openai/gpt-oss-120b'    => 'gpt-oss-120b — paling pintar & terstruktur (disarankan)',
        'llama-3.3-70b-versatile' => 'llama-3.3-70b — seimbang',
        'llama-3.1-8b-instant'   => 'llama-3.1-8b — paling cepat & irit, jawaban paling dangkal',
    ];
}

/**
 * Pesan error AI Chat yang JUJUR & bisa ditindaklanjuti pengunjung.
 *
 * Semua sebab dulu dibalas "AI sedang tidak tersedia" — menyesatkan: 429 (kuota
 * per menit habis) itu keadaan SEMENTARA yang cuma butuh tunggu ~1 menit, bukan
 * layanan rusak. Detail teknis tetap tidak dibocorkan ke pengunjung; yang
 * berbeda hanya arahannya. Sebab teknis dicatat di error_log utk admin.
 */
function aiChatErrorMessage(int $httpCode, string $curlErr = ''): string
{
    if ($httpCode === 429) {
        return 'Lagi banyak yang bertanya bersamaan. Tunggu sekitar 1 menit lalu kirim ulang pesanmu ya.';
    }
    if ($httpCode === 401 || $httpCode === 403) {
        // Salah konfigurasi di sisi admin — pengunjung tak bisa berbuat apa-apa
        // selain menghubungi manusia; jangan suruh dia "coba lagi" sia-sia.
        return 'Asisten sedang bermasalah dan tim kami sudah diberi tahu. Silakan hubungi admin untuk bantuan langsung.';
    }
    if ($httpCode >= 500 || $curlErr !== '' || $httpCode === 0) {
        return 'Koneksi ke asisten sedang terganggu. Coba kirim ulang sebentar lagi.';
    }
    return 'Asisten sedang tidak bisa menjawab. Coba lagi atau hubungi admin.';
}

/**
 * Produk mana yang detail panjangnya layak dikirim ke AI untuk pertanyaan ini?
 *
 * Sengaja SEDERHANA (cocok-kata), bukan embedding/AI: harus murah & dapat ditebak.
 * Aturan main — lebih baik MENGIRIM detail yang tak terpakai daripada AI buta
 * terhadap produk yang justru ditanyakan:
 *   - katalog kecil (<= 3 produk) → kirim semua detail, tak ada gunanya menyaring
 *   - nama/slug produk disebut → produk itu relevan
 *   - tak ada yang cocok → kirim detail 2 produk teratas (biar tetap bisa
 *     merekomendasikan sesuatu utk pertanyaan umum spt "ada produk apa aja?")
 * Maksimal 3 produk berdetail supaya token tetap terkendali.
 *
 * @param array $products baris produk (butuh id, name, slug)
 * @return int[] id produk yang detailnya disertakan
 */
function aiRelevantProductIds(string $userMessage, array $products): array
{
    $ids = array_map(fn($p) => (int) $p['id'], $products);
    if (count($products) <= 3) return $ids;   // katalog kecil: kirim semua

    $msg = mb_strtolower(trim($userMessage));
    if ($msg === '') return array_slice($ids, 0, 2);

    // Kata umum yang bikin hampir semua produk "cocok" → jangan dihitung.
    $stop = ['dan','atau','yang','untuk','bisa','apa','apakah','ini','itu','ada','saya','kamu','harga',
             'produk','beli','gimana','bagaimana','berapa','di','ke','dari','dengan','buat','pakai','the','a'];

    $skor = [];
    foreach ($products as $p) {
        $pid   = (int) $p['id'];
        $poin  = 0;
        $nama  = mb_strtolower((string) $p['name']);
        $slug  = mb_strtolower((string) ($p['slug'] ?? ''));

        // Nama produk utuh disebut = sinyal terkuat.
        if ($nama !== '' && mb_strpos($msg, $nama) !== false) $poin += 10;
        if ($slug !== '' && mb_strpos($msg, $slug) !== false) $poin += 8;

        // Per kata bermakna dari nama produk (mis. "averion" di "Averion - 30 Hari").
        foreach (preg_split('/[^a-z0-9]+/u', $nama) as $kata) {
            if (mb_strlen($kata) < 4 || in_array($kata, $stop, true)) continue;
            if (mb_strpos($msg, $kata) !== false) $poin += 3;
        }
        if ($poin > 0) $skor[$pid] = $poin;
    }

    if (!$skor) return array_slice($ids, 0, 2);   // pertanyaan umum
    arsort($skor);
    return array_slice(array_keys($skor), 0, 3);
}

/**
 * Template system prompt "Sales Assistant" siap pakai (tombol preset di
 * Admin → AI Chat). Mengandalkan konteks katalog dari buildAiSalesContext.
 */
function aiSalesPersonaTemplate(): string
{
    return 'Kamu adalah asisten penjualan toko ini: ramah, ringkas, dan membantu — bukan robot penjawab kaku.'
        . "\n\nCARA KERJA:"
        . "\n1. Jawab pertanyaan pengunjung dengan jelas memakai data katalog & FAQ resmi di bawah."
        . "\n2. Gali kebutuhan mereka dengan 1 pertanyaan singkat bila belum jelas produk mana yang cocok."
        . "\n3. Rekomendasikan SATU produk yang paling cocok (jangan menyebut semua produk sekaligus), sebutkan manfaat utamanya untuk kebutuhan mereka, lalu sertakan link belinya."
        . "\n4. Bila mereka siap membeli, tutup dengan sopan: sertakan link beli + satu alasan konkret untuk mengambil keputusan sekarang (mis. harga diskon yang tercantum). Jangan mengarang urgensi palsu."
        . "\n5. Bila ada penawaran lanjutan (upsell) untuk produk yang diminati, sebutkan singkat setelah mereka menunjukkan minat beli."
        . "\n\nATURAN:"
        . "\n- HANYA gunakan informasi dari katalog & FAQ di bawah. Jangan mengarang harga, fitur, kebijakan, atau promo."
        . "\n- Kalau jawabannya tidak ada di data, katakan jujur tidak tahu dan sarankan menghubungi admin."
        . "\n- Bahasa Indonesia santai-sopan, jawaban maksimal 4-5 kalimat, tanpa format berlebihan."
        . "\n- Tulis link beli apa adanya (URL lengkap) di baris sendiri.";
}

/**
 * Panggil AI provider yang dikonfigurasi AI Chat untuk satu completion
 * sederhana (tanpa riwayat). Dipakai tombol "Generate draft dengan AI" di
 * form produk. Return teks atau null bila gagal.
 */
/**
 * Provider AI Chat yang didukung — SATU sumber kebenaran.
 *
 * `shape` menentukan bentuk request/response, bukan nama providernya:
 *   - 'gemini' : format khas Google (system_instruction/contents/candidates).
 *   - 'openai' : format OpenAI-compatible (messages/choices) — dipakai Groq,
 *                dan siap dipakai gateway lain yang bicara protokol sama cukup
 *                dengan menambah satu entri di sini.
 *
 * Dulu percabangan provider disalin di EMPAT file (aiCompleteSimple, chat/stream,
 * chat/send, admin/test-ai-chat) — masing-masing dgn URL & parser sendiri, plus
 * whitelist provider terpisah di test-ai-chat. Menambah/mengubah provider berarti
 * 4 suntingan yang wajib sinkron; kalau meleset, gejalanya persis bug lama di repo
 * ini (UI menawarkan pilihan yang backend tolak). Sekarang cukup di sini.
 */
function aiChatProviderDefs(): array
{
    return [
        'gemini' => ['label' => 'Google Gemini', 'shape' => 'gemini'],
        'groq'   => ['label' => 'Groq',          'shape' => 'openai', 'base' => 'https://api.groq.com/openai/v1/chat/completions'],
    ];
}

/** Definisi satu provider. Nilai tak dikenal → 'groq', PERSIS perilaku lama (`else { // groq }`). */
function aiChatProviderDef(string $provider): array
{
    $defs = aiChatProviderDefs();
    return $defs[$provider] ?? $defs['groq'];
}

/** Slug provider yang valid — dipakai whitelist & radio di halaman Admin. */
function aiChatProviderKeys(): array
{
    return array_keys(aiChatProviderDefs());
}

/**
 * Rakit permintaan ke provider: ['url','headers','body'].
 * $opts: system, history[[role,content]], user, max_tokens, temperature (null=tak
 * dikirim), stream (bool). Urutan kunci body sengaja dipertahankan sama dgn kode
 * lama supaya payload ke provider tak berubah.
 */
function aiChatBuildRequest(string $provider, string $apiKey, array $opts = []): array
{
    $def     = aiChatProviderDef($provider);
    $system  = (string) ($opts['system'] ?? '');
    $history = (array)  ($opts['history'] ?? []);
    $user    = (string) ($opts['user'] ?? '');
    $maxTok  = (int)    ($opts['max_tokens'] ?? 500);
    $temp    = $opts['temperature'] ?? null;
    $stream  = !empty($opts['stream']);

    if ($def['shape'] === 'gemini') {
        // Role Gemini valid: 'user' | 'model' (assistant → model).
        $contents = [];
        foreach ($history as $h) {
            $role = (($h['role'] ?? '') === 'assistant') ? 'model' : (string) ($h['role'] ?? 'user');
            $contents[] = ['role' => $role, 'parts' => [['text' => (string) ($h['content'] ?? '')]]];
        }
        if ($user !== '') $contents[] = ['role' => 'user', 'parts' => [['text' => $user]]];

        $gen = ['maxOutputTokens' => $maxTok];
        if ($temp !== null) $gen['temperature'] = (float) $temp;

        $body = ['contents' => $contents, 'generationConfig' => $gen];
        if ($system !== '') {
            $body = ['system_instruction' => ['parts' => [['text' => $system]]]] + $body;
        }

        $endpoint = $stream ? 'streamGenerateContent?alt=sse&key=' : 'generateContent?key=';
        return [
            'url'     => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:' . $endpoint . rawurlencode($apiKey),
            'headers' => ['Content-Type: application/json'],
            'body'    => $body,
        ];
    }

    // OpenAI-compatible. Role valid: 'system' | 'user' | 'assistant' (model → assistant).
    $messages = [];
    if ($system !== '') $messages[] = ['role' => 'system', 'content' => $system];
    foreach ($history as $h) {
        $role = (($h['role'] ?? '') === 'model') ? 'assistant' : (string) ($h['role'] ?? '');
        if (!in_array($role, ['user', 'assistant'], true)) continue;
        $messages[] = ['role' => $role, 'content' => (string) ($h['content'] ?? '')];
    }
    if ($user !== '') $messages[] = ['role' => 'user', 'content' => $user];

    $body = ['model' => aiChatModel(), 'messages' => $messages, 'max_tokens' => $maxTok];
    if ($temp !== null) $body['temperature'] = (float) $temp;
    if ($stream)        $body['stream']      = true;

    return [
        'url'     => (string) $def['base'],
        'headers' => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        'body'    => $body,
    ];
}

/** Ambil teks dari respons UTUH (non-streaming). */
function aiChatExtractText(string $provider, array $data): string
{
    return aiChatProviderDef($provider)['shape'] === 'gemini'
        ? (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '')
        : (string) ($data['choices'][0]['message']['content'] ?? '');
}

/** Ambil potongan teks dari satu chunk SSE (streaming). */
function aiChatExtractDelta(string $provider, array $chunk): string
{
    return aiChatProviderDef($provider)['shape'] === 'gemini'
        ? (string) ($chunk['candidates'][0]['content']['parts'][0]['text'] ?? '')
        : (string) ($chunk['choices'][0]['delta']['content'] ?? '');
}

/** Respons berbentuk wajar? Dipakai tes koneksi (cek struktur, bukan isi). */
function aiChatResponseHasPayload(string $provider, array $data): bool
{
    return aiChatProviderDef($provider)['shape'] === 'gemini'
        ? isset($data['candidates'])
        : isset($data['choices']);
}

function aiCompleteSimple(string $system, string $user, int $maxTokens = 700): ?string
{
    $provider = getSetting('ai_chat_provider', 'gemini');
    $apiKey   = (string) getSetting('ai_chat_api_key', '');
    if ($apiKey === '') return null;

    $post = function (string $url, array $payload, array $headers): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $resp === false ? null : $resp];
    };

    try {
        $req = aiChatBuildRequest($provider, $apiKey, [
            'system'      => $system,
            'user'        => $user,
            'max_tokens'  => $maxTokens,
            'temperature' => 0.6,
        ]);
        [$code, $body] = $post($req['url'], $req['body'], $req['headers']);
        if ($code < 200 || $code >= 300) {
            error_log('aiCompleteSimple ' . $provider . ' http ' . $code);
            return null;
        }
        $data = json_decode((string) $body, true);
        $text = is_array($data) ? aiChatExtractText($provider, $data) : '';
        return trim($text) !== '' ? trim($text) : null;
    } catch (Throwable $e) {
        error_log('aiCompleteSimple: ' . $e->getMessage());
        return null;
    }
}

/**
 * Append sepasang pesan (pengunjung + balasan AI) ke transcript chat_leads
 * milik sesi ber-token (lead yang sudah tertangkap). Diam-diam bila token
 * tak dikenal / tabel belum ada. Transcript dibatasi ±100KB (append berhenti).
 */
function appendChatTranscript(string $token, string $userMsg, string $botReply): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return;
    try {
        $chunk = "\nPengunjung: " . mb_substr(trim($userMsg), 0, 2000)
               . "\nAI: " . mb_substr(trim($botReply), 0, 2000);
        getDB()->prepare(
            "UPDATE chat_leads
             SET transcript = CONCAT(COALESCE(transcript, ''), ?), updated_at = NOW()
             WHERE token = ? AND COALESCE(LENGTH(transcript), 0) < 100000"
        )->execute([$chunk, $token]);
    } catch (Exception $e) {
        error_log('appendChatTranscript: ' . $e->getMessage());
    }
}
