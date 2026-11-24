<?php
/**
 * MailketingClient — pengiriman email via Mailketing API (port battle-tested).
 *
 * Adaptasi untuk Averion (main):
 *   - Setting dibaca via getSetting() (tabel settings).
 *   - Membaca kredensial Mailketing yang SUDAH ada di /admin/integrations:
 *       token  : mailketing_api_token  → fallback mailketing_api_key
 *       email  : mailketing_from_email → fallback mailketing_sender_email
 *       name   : mailketing_from_name  → fallback mailketing_sender_name → app_name
 *     Tidak ada field/konfigurasi baru — menghindari config ganda.
 *   - Logging via error_log('[Email] ...').
 *   - Token disimpan apa adanya; jika terlihat terenkripsi → error yang jelas.
 *
 * Mengembalikan array: ['ok'=>bool,'status'=>string,'message'=>string,'raw'=>string,'http_code'=>int]
 */
class MailketingClient {
    public static function sendEmail($to, $subject, $content, $userId = null, $options = []) {
        $token = getSetting('mailketing_api_token', '') ?: getSetting('mailketing_api_key', '');
        if (!$token) {
            $msg = 'Mailketing token belum diisi di Integrations.';
            error_log('[Email] ' . $msg);
            return ['ok'=>false,'status'=>'error','message'=>$msg,'raw'=>'','http_code'=>0];
        }
        $token = trim((string)$token);
        if ($token === '') {
            $msg = 'Mailketing token kosong.';
            error_log('[Email] ' . $msg);
            return ['ok'=>false,'status'=>'error','message'=>$msg,'raw'=>'','http_code'=>0];
        }

        // Token mentah diharapkan. Jika polanya "<base64>:<base64>" → kemungkinan
        // token terenkripsi dari sistem lama; di sini tidak didukung.
        $looksEncrypted = false;
        if (strpos($token, ':') !== false) {
            $parts = explode(':', $token, 2);
            if (count($parts) === 2) {
                $iv = base64_decode($parts[0], true);
                $cipher = base64_decode($parts[1], true);
                $looksEncrypted = ($iv !== false && $cipher !== false);
            }
        }
        if ($looksEncrypted) {
            $msg = 'Token Mailketing terlihat terenkripsi dan tidak didukung. Isi ulang token mentah (raw) dari dashboard Mailketing di Integrations.';
            error_log('[Email] ' . $msg);
            return ['ok'=>false,'status'=>'error','message'=>$msg,'raw'=>'','http_code'=>0];
        }

        $fromName = getSetting('mailketing_from_name', '')
            ?: getSetting('mailketing_sender_name', getSetting('app_name', 'STIFIn Mulia'));
        $fromEmail = getSetting('mailketing_from_email', '')
            ?: getSetting('mailketing_sender_email', 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromEmail = trim((string)$fromEmail);
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fallback = trim((string)getSetting('admin_email', ''));
            if ($fallback !== '' && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
                $fromEmail = $fallback;
            }
        }
        $endpoint = getSetting('mailketing_endpoint', 'https://api.mailketing.co.id/api/v1/send');
        if (!function_exists('curl_init')) {
            $msg = 'Ekstensi cURL tidak aktif. Aktifkan php_curl.dll di PHP.';
            error_log('[Email] ' . $msg);
            return ['ok'=>false,'status'=>'error','message'=>$msg,'raw'=>'','http_code'=>0];
        }
        if (!is_string($endpoint) || trim($endpoint) === '') {
            $msg = 'Mailketing endpoint kosong.';
            error_log('[Email] ' . $msg);
            return ['ok'=>false,'status'=>'error','message'=>$msg,'raw'=>'','http_code'=>0];
        }

        $params = [
            'api_token' => $token,
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'recipient' => $to,
            'subject' => $subject,
            'content' => $content
        ];

        $timeout = (int)($options['timeout'] ?? 20);
        $connectTimeout = (int)($options['connect_timeout'] ?? 10);
        if ($timeout <= 0) $timeout = 20;
        if ($connectTimeout <= 0) $connectTimeout = 10;

        // Retry error TRANSIENT (gateway/timeout). Mailketing di balik Cloudflare
        // sesekali membalas 520/522/524 atau time out saat sibuk (mis. broadcast
        // besar) — ini BUKAN penolakan permanen. Tanpa retry, tiap blip = 1
        // penerima gagal permanen → "gagal massal". cURL error (termasuk timeout)
        // & HTTP 429/5xx/52x dianggap transient dan dicoba ulang dgn backoff.
        // Idempoten secara praktis: 52x = Cloudflare tak sampai ke origin, timeout
        // = origin tak sempat balas → kemungkinan email belum terkirim; risiko
        // duplikat kecil & dapat diterima utk email marketing.
        $maxAttempts = (int) ($options['max_attempts'] ?? 3);
        if ($maxAttempts < 1) $maxAttempts = 1;
        $transientHttp = [429, 500, 502, 503, 504, 520, 521, 522, 523, 524];
        $attempt = 0;
        do {
            $attempt++;
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Averion/1.0');

            $resp = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            $isTransient = ($resp === false) || in_array($http, $transientHttp, true);
            if ($isTransient && $attempt < $maxAttempts) {
                error_log('[Email] transient (http=' . $http . ($err !== '' ? " err=$err" : '') . ') percobaan '
                    . $attempt . '/' . $maxAttempts . ' ke ' . $to . ' — coba ulang');
                usleep(700000 * $attempt); // backoff bertahap: 0,7s lalu 1,4s ...
                continue;
            }
            break;
        } while (true);

        if ($resp === false) {
            $msg = 'cURL error: ' . $err;
            error_log('[Email] ' . $msg);
            return ['ok'=>false,'status'=>'error','message'=>$msg,'raw'=>'','http_code'=>$http];
        }

        $data = json_decode($resp, true);
        $ok = false;
        $status = 'failed';
        $message = '';
        if (is_array($data)) {
            $hasExplicitError = false;
            if (array_key_exists('error', $data) && !empty($data['error'])) $hasExplicitError = true;
            if (array_key_exists('errors', $data) && !empty($data['errors'])) $hasExplicitError = true;

            if (isset($data['success'])) {
                $ok = (bool)$data['success'];
            } elseif (isset($data['status'])) {
                $statusVal = strtolower((string)$data['status']);
                $ok = in_array($statusVal, ['success','ok','200','true'], true);
            } else {
                $ok = ($http >= 200 && $http < 300);
            }
            if ($hasExplicitError) $ok = false;
            $status = $ok ? 'success' : (isset($data['status']) ? (string)$data['status'] : ($http >= 200 && $http < 300 ? 'failed' : 'error'));
            if (!empty($data['response'])) {
                $message = (string)$data['response'];
            } elseif (!empty($data['message'])) {
                $message = (string)$data['message'];
            } elseif (!empty($data['error'])) {
                $message = (string)$data['error'];
            } elseif (!empty($data['errors'])) {
                $message = is_string($data['errors']) ? (string)$data['errors'] : json_encode($data['errors'], JSON_UNESCAPED_UNICODE);
            } elseif (!empty($data['detail'])) {
                $message = (string)$data['detail'];
            } else {
                $message = $ok ? 'Mail Sent' : 'Failed';
            }
            $msgLow2 = strtolower((string)$message);
            if ($ok && ($msgLow2 !== '') && (strpos($msgLow2, 'failed') !== false || strpos($msgLow2, 'error') !== false)) {
                $ok = false;
                $status = 'failed';
            }
        } else {
            $ok = ($http >= 200 && $http < 300);
            $status = $ok ? 'success' : 'error';
            $message = $ok ? 'Sent' : (trim($resp) !== '' ? trim($resp) : 'Failed');
        }

        $finalOk = $ok;
        $finalStatus = $status;
        $finalMessage = $message;
        $finalResp = $resp;
        $finalHttp = $http;

        // Retry sekali dengan Authorization: Bearer bila tampak masalah token.
        $tokenIssue = false;
        $msgLow = strtolower($message);
        if (!$finalOk) {
            if (strpos($msgLow, 'invalid token') !== false || strpos($msgLow, 'access denied') !== false) {
                $tokenIssue = true;
            } elseif (is_array($data) && isset($data['response']) && strpos(strtolower((string)$data['response']), 'invalid token') !== false) {
                $tokenIssue = true;
            }
        }

        if (!$finalOk && $tokenIssue) {
            $params3 = $params;
            $ch3 = curl_init($endpoint);
            curl_setopt($ch3, CURLOPT_POST, true);
            curl_setopt($ch3, CURLOPT_POSTFIELDS, http_build_query($params3));
            curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch3, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch3, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch3, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
            $resp3 = curl_exec($ch3);
            $http3 = (int)curl_getinfo($ch3, CURLINFO_HTTP_CODE);
            $err3 = curl_error($ch3);
            curl_close($ch3);
            $data3 = json_decode($resp3, true);
            $ok3 = false;
            $status3 = 'failed';
            $message3 = '';
            if (is_array($data3)) {
                if (isset($data3['success'])) $ok3 = (bool)$data3['success'];
                elseif (isset($data3['status'])) $ok3 = in_array(strtolower((string)$data3['status']), ['success','ok','200','true'], true);
                else $ok3 = ($http3 >= 200 && $http3 < 300);
                $status3 = $ok3 ? 'success' : (isset($data3['status']) ? (string)$data3['status'] : ($http3 >= 200 && $http3 < 300 ? 'failed' : 'error'));
                if (!empty($data3['response'])) $message3 = (string)$data3['response'];
                elseif (!empty($data3['message'])) $message3 = (string)$data3['message'];
                elseif (!empty($data3['error'])) $message3 = (string)$data3['error'];
                else $message3 = $ok3 ? 'Mail Sent' : 'Failed';
            } else {
                $ok3 = ($http3 >= 200 && $http3 < 300);
                $status3 = $ok3 ? 'success' : 'error';
                $message3 = $ok3 ? 'Mail Sent' : (trim($resp3) !== '' ? trim($resp3) : ($err3 ? ('cURL error: ' . $err3) : 'Failed'));
            }
            if ($ok3) {
                $finalOk = $ok3;
                $finalStatus = $status3;
                $finalMessage = $message3;
                $finalResp = $resp3;
                $finalHttp = $http3;
            }
        }

        error_log('[Email] ke=' . $to . ' subjek="' . $subject . '" status=' . $finalStatus . ' pesan=' . $finalMessage);
        return ['ok'=>$finalOk,'status'=>$finalStatus,'message'=>$finalMessage,'raw'=>$finalResp,'http_code'=>$finalHttp];
    }

    // ════════════════ List / Subscriber API ════════════════
    // Token dibaca sama seperti sendEmail() — tanpa kredensial baru.

    public static function apiToken(): string {
        $t = getSetting('mailketing_api_token', '') ?: getSetting('mailketing_api_key', '');
        return trim((string) $t);
    }

    /** POST form sederhana ke API Mailketing. Return ['ok','http','body','data']. */
    private static function postForm(string $url, array $params, int $timeout = 15): array {
        $out = ['ok' => false, 'http' => 0, 'body' => '', 'data' => null];
        if (!function_exists('curl_init')) { $out['body'] = 'cURL tidak aktif'; return $out; }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout > 0 ? $timeout : 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'Averion/1.0',
        ]);
        $body = curl_exec($ch);
        $out['http'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $out['body'] = (string) $body;
        $out['ok']   = ($body !== false && $out['http'] >= 200 && $out['http'] < 300);
        $out['data'] = json_decode((string) $body, true);
        return $out;
    }

    /** Ambil daftar list (list_id + list_name) dari akun. Untuk dropdown pengaturan. */
    public static function viewList(): array {
        $token = self::apiToken();
        if ($token === '') return ['ok' => false, 'message' => 'Token Mailketing belum diisi di Integrations.', 'lists' => []];
        $r = self::postForm('https://api.mailketing.co.id/api/v1/viewlist', ['api_token' => $token]);
        $data = $r['data'];
        if (is_array($data) && strtolower((string) ($data['status'] ?? '')) === 'success' && !empty($data['lists']) && is_array($data['lists'])) {
            $lists = [];
            foreach ($data['lists'] as $l) {
                if (is_array($l) && isset($l['list_id'])) {
                    $lists[] = ['list_id' => (string) $l['list_id'], 'list_name' => (string) ($l['list_name'] ?? $l['list_id'])];
                }
            }
            return ['ok' => true, 'message' => '', 'lists' => $lists];
        }
        $msg = is_array($data) && !empty($data['response']) ? (string) $data['response']
             : ($r['http'] ? ('Gagal (HTTP ' . $r['http'] . ')') : 'Tidak ada respons dari Mailketing.');
        return ['ok' => false, 'message' => $msg, 'lists' => []];
    }

    /** Tambah 1 subscriber ke sebuah list. $fields: first_name,last_name,mobile,phone. */
    public static function addSubscriberToList(string $listId, string $email, array $fields = []): array {
        $token = self::apiToken();
        $email = trim($email);
        $listId = trim($listId);
        if ($token === '' || $listId === '' || $email === '') {
            return ['ok' => false, 'message' => 'Token / list_id / email kosong.', 'http' => 0, 'raw' => ''];
        }
        $params = array_merge(
            ['api_token' => $token, 'list_id' => $listId, 'email' => $email],
            array_filter([
                'first_name' => trim((string) ($fields['first_name'] ?? '')),
                'last_name'  => trim((string) ($fields['last_name'] ?? '')),
                'mobile'     => trim((string) ($fields['mobile'] ?? '')),
                'phone'      => trim((string) ($fields['phone'] ?? '')),
            ], fn($v) => $v !== '')
        );
        $r = self::postForm('https://api.mailketing.co.id/api/v1/addsubtolist', $params);
        $data = $r['data'];
        $ok = is_array($data) ? (strtolower((string) ($data['status'] ?? '')) === 'success') : $r['ok'];
        $msg = is_array($data) ? (string) ($data['response'] ?? $data['message'] ?? ($ok ? 'Added to List' : 'Failed'))
             : ('HTTP ' . $r['http']);
        return ['ok' => $ok, 'message' => $msg, 'http' => $r['http'], 'raw' => $r['body']];
    }
}
