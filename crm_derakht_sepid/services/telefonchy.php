<?php
/**
 * ══ کلاینت وب‌سرویسِ تلفنچی — فاز AI‑1 («مکش») ══
 *
 * چرا: در ۲۰۲۶-۱۱-۱۱ تست واقعی نشان داد هاست پشت «چالش جاوااسکریپتی» آنتی‌بات
 * (/aes.js) قرار دارد و هیچ ترافیک سرور-به-سرور به PHP نمی‌رسد؛ پس وب‌هوک حذف شد
 * و این سرویس از سمت سرور ما به پنل تلفنچی درخواست می‌فرستد (خروجی؛ بدون وابستگی
 * به پذیرش اتصال / باز شدن فایروال).
 *
 * احراز هویت: هدرهای webservice-token (اصلی) و webservice_token (سازگاری با
 * مستندات قدیمی). توکن فقط در config.local.php هاست با نام
 * TELEFONCHY_WEBSERVICE_TOKEN تعریف می‌شود؛ هرگز در گیت یا چت.
 *
 * اندپوینت‌ها (مستندات رسمی telefonchy.com/help، بازیابی‌شده در ۲۰۲۶-۱۱-۱۱):
 *   لیست تماس‌ها:  GET  https://panel.telefonchy.com/webservice/v1/calls?service_id=SID&page=N&sort=created_at DESC&date_from=<شمسی Y/m/d H:i>&date_to=…
 *   صوت:          GET  https://panel.telefonchy.com/webservice/v1/calls/record?file_id=FID&cuid=CUID
 *   درخواست تحلیل: POST https://panel.telefonchy.com/webservice/v1/calls/analysis/request   {"service_id":SID,"cuid":CUID} → 200 کامل‌شده | 202 در صف
 *   نتیجهٔ تحلیل:  GET  https://panel.telefonchy.com/webservice/v1/calls/analysis/show?service_id=SID&cuid=CUID → analysis_status∈{requesting,queued,transcribing,analyzing,reconciling,completed,failed}؛ GET سهمیه نمی‌سوزاند
 *
 * بخش‌های اصلی:
 *   ensure_telefonchy_schema()  — ساخت/ارتقای جداول (phone_calls, call_analysis, ai_usage_log) + پوشهٔ uploads/calls (self-heal، idempotent)
 *   tel_sync_calls()            — مکش صفحه‌به‌صفحه (جدیدترین اول) تا برخورد با رکورد موجود یا پایان صفحات؛ تحلیل خودکار در صورت مجاز بودن
 *   tel_request_analysis()      — ثبت درخواست تحلیل (هر فراخوانی ۱ واحد از سهمیهٔ وب‌سرویس؛ تکراری شارژ نمی‌کند)
 *   tel_poll_analysis()         — پیگیری وضعیت و اعمال نتیجه در call_analysis (فقط ۲۰۰؛ سقف ~۶۰ req/min)
 *   tel_download_audio/tel_stream_headers — صوت از طریق پراکسی سمت سرور (یا آرشیو محلی در uploads/calls)
 */

// ── شناسهٔ سرویس تلفنچی (عمومی؛ قابل تغییر از تنظیمات داخلی) ─────────────
function tel_service_id(PDO $pdo): string {
    static $sid = null;
    if ($sid !== null) return $sid;
    $sid = '02191098069';
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'telefonchy_service_id' LIMIT 1");
        $st->execute();
        $v = trim((string)($st->fetchColumn() ?: ''));
        if ($v !== '') $sid = $v;
    } catch (Throwable $e) { /* settings در دسترس نیست — مقدار پیش‌فرض */ }
    return $sid;
}

/** آیا توکن وب‌سرویس روی هاست تنظیم شده؟ */
function tel_configured(): bool {
    return defined('TELEFONCHY_WEBSERVICE_TOKEN') && trim((string)TELEFONCHY_WEBSERVICE_TOKEN) !== '';
}

function tel_setting(PDO $pdo, string $key, string $default): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}

function tel_set_setting(PDO $pdo, string $key, string $value): void {
    try {
        $st = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st->execute([$key, $value]);
    } catch (Throwable $e) { /* نادیده */ }
}

function tel_usage_log(PDO $pdo, string $action, ?string $cuid, int $ok, int $http, string $note = ''): void {
    try {
        $st = $pdo->prepare("INSERT INTO ai_usage_log (action, cuid, ok, http_code, note, created_at) VALUES (?,?,?,?,?,NOW())");
        $st->execute([substr($action, 0, 48), $cuid !== null ? substr($cuid, 0, 96) : null, $ok ? 1 : 0, $http, mb_substr($note, 0, 255)]);
    } catch (Throwable $e) { /* لاگ اختیاری است */ }
}

/** تعداد درخواست‌های تحلیل امروز (برای سقف روزانه) */
function tel_analysis_used_today(PDO $pdo): int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM ai_usage_log WHERE action = 'analysis_request' AND ok = 1 AND DATE(created_at) = CURDATE()");
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

// ══ Schema (self-heal — idempotent) ═════════════════════════════════════
function ensure_telefonchy_schema(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS phone_calls (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cuid VARCHAR(96) NOT NULL,
            type VARCHAR(16) NULL,
            exten VARCHAR(32) NULL,
            trunk VARCHAR(64) NULL,
            status VARCHAR(24) NULL,
            call_source VARCHAR(48) NULL,
            call_source_name VARCHAR(255) NULL,
            call_dest VARCHAR(48) NULL,
            call_dest_name VARCHAR(255) NULL,
            time_wait INT NULL,
            time_talk INT NULL,
            file_id VARCHAR(128) NULL,
            price_wait INT NULL,
            price_talk INT NULL,
            price_record INT NULL,
            price_saved INT NULL,
            price INT NULL,
            note TEXT NULL,
            created_at_tel DATETIME NULL,
            ended_at_tel DATETIME NULL,
            customer_id INT NULL,
            lead_id INT NULL,
            analyzed TINYINT(1) NOT NULL DEFAULT 0,
            analysis_status VARCHAR(24) NULL,
            analysis_requested_at DATETIME NULL,
            analysis_completed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cuid (cuid),
            KEY idx_tel_time (created_at_tel),
            KEY idx_tel_file (file_id),
            KEY idx_tel_customer (customer_id),
            KEY idx_tel_lead (lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS call_analysis (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cuid VARCHAR(96) NOT NULL,
            analysis_status VARCHAR(24) NULL,
            progress TINYINT NULL,
            summary TEXT NULL,
            outcome TEXT NULL,
            report_text MEDIUMTEXT NULL,
            coaching_tip TEXT NULL,
            coaching_reason TEXT NULL,
            customer_sentiment VARCHAR(64) NULL,
            sentiment_reason TEXT NULL,
            active_keywords MEDIUMTEXT NULL,
            matched_keywords MEDIUMTEXT NULL,
            transcript MEDIUMTEXT NULL,
            transcript_by_role MEDIUMTEXT NULL,
            mapping VARCHAR(16) NULL,
            payload MEDIUMTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_analysis_cuid (cuid),
            KEY idx_analysis_status (analysis_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_usage_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(48) NOT NULL,
            cuid VARCHAR(96) NULL,
            ok TINYINT(1) NOT NULL DEFAULT 0,
            http_code INT NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_usage_day (created_at),
            KEY idx_usage_action (action, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // پوشهٔ آرشیو صوت (دسترسی مستقیم عمومی بسته؛ پخش فقط از طریق api/telefonchy_audio.php)
        $dir = rtrim(UPLOAD_DIR, '/') . '/calls';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\nDeny from all\n"); }

        // ستون پیوند به فعالیت CRM (خودترمیم)
        try {
            $col = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'phone_calls' AND COLUMN_NAME = 'crm_activity_id'");
            $col->execute();
            if ((int)$col->fetchColumn() === 0) { $pdo->exec("ALTER TABLE phone_calls ADD COLUMN crm_activity_id INT NULL AFTER lead_id"); }
        } catch (Throwable $e) { /* نادیده */ }

        // تنظیمات پیش‌فرض (فقط در صورت نبود)
        $ins = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $ins->execute(['ai_analysis_auto', '1']);
        $ins->execute(['ai_analysis_daily_cap', '30']);
        $ins->execute(['telefonchy_service_id', '02191098069']);

        $done = true;
    } catch (Throwable $e) {
        $done = false;
    }
    return $done;
}

// ══ گزینه‌هأ HTTP (curl) ═════════════════════════════════════════════════
function tel_http(string $method, string $url, ?array $jsonBody, int $timeout = 25, ?string $destFile = null): array {
    $out = ['ok' => false, 'http' => 0, 'body' => '', 'json' => null, 'error' => ''];
    if (!function_exists('curl_init')) { $out['error'] = 'افزونهٔ curl در PHP فعال نیست'; return $out; }
    $token = trim((string)(defined('TELEFONCHY_WEBSERVICE_TOKEN') ? TELEFONCHY_WEBSERVICE_TOKEN : ''));
    if ($token === '') { $out['error'] = 'TELEFONCHY_WEBSERVICE_TOKEN تعریف نشده'; return $out; }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    $headers = [
        'Accept: application/json',
        'webservice-token: ' . $token,
        'webservice_token: ' . $token,
    ];
    $m = strtoupper($method);
    if ($m === 'POST') {
        $payload = $jsonBody !== null ? json_encode($jsonBody, JSON_UNESCAPED_UNICODE) : '';
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($payload);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($destFile !== null) {
        $fp = fopen($destFile, 'wb');
        if ($fp) {
            curl_setopt($ch, CURLOPT_FILE, $fp);
        } else {
            curl_close($ch);
            $out['http'] = 0;
            $out['error'] = 'خطا در باز کردن فایل آرشیو برای نوشتن';
            return $out;
        }
    }

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errc = curl_error($ch);
    curl_close($ch);
    if (isset($fp) && is_resource($fp)) fclose($fp);

    $out['http'] = $http;
    if ($destFile !== null) {
        $out['ok'] = ($http === 200 && is_file($destFile) && filesize($destFile) > 0);
        if (!$out['ok'] && is_file($destFile)) { @unlink($destFile); }
        if ($errc !== '') $out['error'] = $errc;
        return $out;
    }
    $out['body'] = (string)$resp;
    $json = json_decode($out['body'], true);
    $out['json'] = is_array($json) ? $json : null;
    $out['ok'] = ($http >= 200 && $http < 300);
    if ($errc !== '') { $out['ok'] = false; $out['error'] = $errc; }
    return $out;
}

// ══ تبدیل تاریخ شمسی تلفنچی → DATETIME میلادی ═══════════════════════════
function tel_jalali_to_mysql(?string $j): ?string {
    $j = trim(normalize_digits((string)$j));
    if ($j === '') return null;
    if (!preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/', $j, $m)) return null;
    $g = jalali_to_gregorian((int)$m[1], (int)$m[2], (int)$m[3]);
    if (empty($g[0])) return null;
    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int)$g[0], (int)$g[1], (int)$g[2], (int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0));
}

function tel_to_int($v): ?int {
    if ($v === null || $v === false || $v === true) return null;
    $v = normalize_digits((string)$v);
    return is_numeric($v) ? (int)$v : null;
}

/** نرمال‌سازی شماره برای تطبیق (۱۰ رقم آخر) */
function tel_phone_tail(?string $number): ?string {
    $d = preg_replace('/\D+/', '', normalize_digits((string)$number));
    if ($d === '') return null;
    if (substr($d, 0, 2) === '00') $d = substr($d, 2);
    if (strlen($d) > 10) $d = substr($d, -10);
    return strlen($d) >= 8 ? $d : null;
}

/** تطبیق شماره با مشتری/سرنخ CRM (۱۰ رقم آخر) — ترتیب: مشتریان موبایل، مشتریان تلفن، سرنخ‌ها */
function tel_match_customer(PDO $pdo, ?string $number): array {
    $tail = tel_phone_tail($number);
    if ($tail === null) return ['customer_id' => null, 'lead_id' => null];
    try {
        $norm = "RIGHT(REPLACE(REPLACE(REPLACE(%s, '-', ''), ' ', ''), '+', ''), 10)";
        $st = $pdo->prepare("SELECT id FROM crm_customers WHERE " . sprintf($norm, 'mobile') . " = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$tail]);
        $id = $st->fetchColumn();
        if (!$id) {
            $st2 = $pdo->prepare("SELECT id FROM crm_customers WHERE " . sprintf($norm, 'phone') . " = ? AND deleted_at IS NULL LIMIT 1");
            $st2->execute([$tail]);
            $id = $st2->fetchColumn();
        }
        if ($id) return ['customer_id' => (int)$id, 'lead_id' => null];
        $st3 = $pdo->prepare("SELECT id FROM crm_leads WHERE " . sprintf($norm, 'mobile') . " = ? LIMIT 1");
        $st3->execute([$tail]);
        $id = $st3->fetchColumn();
        if ($id) return ['customer_id' => null, 'lead_id' => (int)$id];
    } catch (Throwable $e) { /* جدول‌ها موجود نیستند */ }
    return ['customer_id' => null, 'lead_id' => null];
}

// ══ لیست تماس‌ها (یک صفحه؛ جدولی DESC) ════════════════════════════════════
function tel_fetch_calls_page(PDO $pdo, int $page, ?string $dateFromJalali = null, ?string $dateToJalali = null): array {
    $sid = tel_service_id($pdo);
    $url = 'https://panel.telefonchy.com/webservice/v1/calls?service_id=' . rawurlencode($sid) . '&page=' . max(1, $page) . '&sort=' . rawurlencode('created_at DESC');
    if ($dateFromJalali) $url .= '&date_from=' . rawurlencode($dateFromJalali);
    if ($dateToJalali)   $url .= '&date_to='   . rawurlencode($dateToJalali);
    $r = tel_http('GET', $url, null, 30);
    $calls = [];
    $totalPages = 1;
    if (is_array($r['json'])) {
        $d = $r['json'];
        $envelope = is_array($d['data'] ?? null) ? $d['data'] : [];
        // فرمت واقعی تأییدشده از پاسخ زندهٔ API: data.data = آرایهٔ تماس‌ها، data.pagination = صفحه‌بندی
        if (isset($envelope['data']) && is_array($envelope['data'])) {
            $calls = $envelope['data'];
        } elseif (isset($envelope['calls']) && is_array($envelope['calls'])) {
            $calls = $envelope['calls'];
        } elseif (isset($d['calls']) && is_array($d['calls'])) {
            $calls = $d['calls'];
        } elseif (is_array($envelope) && array_keys($envelope) === range(0, count($envelope) - 1)) {
            $calls = $envelope; // data خودش آرایهٔ عددی باشد
        }
        $pg = $envelope['pagination'] ?? $d['paginator'] ?? ($envelope['paginator'] ?? null);
        if (is_array($pg) && isset($pg['total_pages'])) $totalPages = max(1, (int)$pg['total_pages']);
    }
    return ['ok' => $r['ok'], 'http' => $r['http'], 'error' => $r['error'] ?? '', 'calls' => $calls, 'total_pages' => $totalPages, 'body' => $r['body'] ?? ''];
}

/** درج/به‌روز‌رسانی یک تماس؛ true اگر ردیف تازه بود */
function tel_upsert_call(PDO $pdo, array $call): bool {
    $cuid = trim((string)($call['call_id'] ?? $call['cuid'] ?? ''));
    if ($cuid === '') return false;

    $stmt = $pdo->prepare("SELECT id FROM phone_calls WHERE cuid = ? LIMIT 1");
    $stmt->execute([$cuid]);
    if ($stmt->fetchColumn()) {
        return false; // از قبل داریم — نگهداری نسخهٔ قبلی
    }

    $cs = is_array($call['call_source'] ?? null) ? $call['call_source'] : [];
    $cd = is_array($call['call_dest'] ?? null) ? $call['call_dest'] : [];
    $csContact = is_array($cs['contact'] ?? null) ? $cs['contact'] : [];
    $cdContact = is_array($cd['contact'] ?? null) ? $cd['contact'] : [];

    $created = tel_jalali_to_mysql($call['created_at'] ?? null);
    $ended   = tel_jalali_to_mysql($call['ended_at'] ?? null);

    $fileId = (isset($call['file_id']) && $call['file_id'] !== false && $call['file_id'] !== null && (string)$call['file_id'] !== '')
        ? substr((string)$call['file_id'], 0, 128) : null;
    $recordId = (isset($call['record_id']) && $call['record_id'] !== false && $call['record_id'] !== null && (string)$call['record_id'] !== '')
        ? substr((string)$call['record_id'], 0, 64) : $fileId;

    $exten = null;
    if (isset($call['exten']) && is_array($call['exten'])) {
        $exten = (string)($call['exten']['caller_id'] ?? $call['exten']['id'] ?? '');
    } else {
        $exten = (string)($call['exten'] ?? '');
    }

    $srcNum = (string)($cs['number'] ?? (is_string($call['call_source'] ?? null) ? $call['call_source'] : ''));
    $dstNum = (string)($cd['number'] ?? (is_string($call['call_dest'] ?? null) ? $call['call_dest'] : ''));

    $type = strtolower((string)($call['type'] ?? ''));
    $match = tel_match_customer($pdo, $type === 'outgoing' ? $dstNum : $srcNum);

    $st = $pdo->prepare("INSERT INTO phone_calls
        (cuid, type, exten, trunk, status, call_source, call_source_name, call_dest, call_dest_name,
         time_wait, time_talk, file_id, price_wait, price_talk, price_record, price_saved, price,
         note, created_at_tel, ended_at_tel, customer_id, lead_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $cuid,
        $type !== '' ? substr($type, 0, 14) : null,
        $exten !== '' ? substr($exten, 0, 30) : null,
        isset($call['trunk']) ? substr((string)$call['trunk'], 0, 62) : null,
        isset($call['status']) ? substr(strtoupper((string)$call['status']), 0, 22) : null,
        $srcNum !== '' ? substr($srcNum, 0, 46) : null,
        !empty($csContact['name']) ? substr((string)$csContact['name'], 0, 250) : null,
        $dstNum !== '' ? substr($dstNum, 0, 46) : null,
        !empty($cdContact['name']) ? substr((string)$cdContact['name'], 0, 250) : null,
        tel_to_int($call['time_wait'] ?? null),
        tel_to_int($call['time_talk'] ?? null),
        $fileId ?? $recordId,
        tel_to_int($call['price_wait'] ?? null),
        tel_to_int($call['price_talk'] ?? null),
        tel_to_int($call['price_record'] ?? null),
        tel_to_int($call['price_saved'] ?? null),
        tel_to_int($call['price'] ?? null),
        isset($call['note']) && $call['note'] !== '' ? mb_substr((string)$call['note'], 0, 2000) : null,
        $created,
        $ended,
        $match['customer_id'],
        $match['lead_id'],
    ]);

    tel_link_crm_activity($pdo, $cuid, $type, $match, $created, tel_to_int($call['time_talk'] ?? null), isset($call['status']) ? (string)$call['status'] : '', $srcNum, $dstNum);
    return true;
}

/** ثبت فعالیت CRM (type=call) برای تماس تازه‌ای که به مشتری/سرنخ چسبیده است */
function tel_link_crm_activity(PDO $pdo, string $cuid, string $type, array $match, ?string $createdAt, ?int $talkSec, string $status, string $srcNum, string $dstNum): void {
    if (!$match['customer_id'] && !$match['lead_id']) return; // بدون مخاطب متصل را وارد تایم‌لاین CRM نمی‌کنیم
    try {
        $tp = strtolower($type) === 'outgoing' ? 'خروجی' : (strtolower($type) === 'incoming' ? 'ورودی' : 'داخلی');
        $stMap = ['ANSWERED' => 'پاسخ‌داده‌شده', 'BUSY' => 'مشغول', 'NO ANSWER' => 'بی‌پاسخ', 'CONGESTION' => 'شلوغی'];
        $stLabel = $stMap[strtoupper($status)] ?? ($status !== '' ? $status : '');
        $directionNumber = strtolower($type) === 'outgoing' ? $dstNum : $srcNum;
        $subject = 'تماس تلفنی (' . $tp . ') — ' . ($stLabel !== '' ? $stLabel : 'تلفنچی');
        $body = 'شمارهٔ تماس: ' . $directionNumber . "\nشناسهٔ مکالمه: " . $cuid;
        $actDate = $createdAt ?: date('Y-m-d H:i:s');
        $dur = $talkSec !== null ? (int)ceil($talkSec / 60) : null;
        $ins = $pdo->prepare("INSERT INTO crm_activities (customer_id, lead_id, user_id, type, subject, body, activity_date, duration_min, outcome)
            VALUES (?,?,NULL,'call',?,?,?,?,?)");
        $ins->execute([$match['customer_id'], $match['lead_id'], $subject, $body, $actDate, $dur, $stLabel !== '' ? $stLabel : null]);
        $aid = (int)$pdo->lastInsertId();
        if ($aid > 0) {
            $up = $pdo->prepare("UPDATE phone_calls SET crm_activity_id = ? WHERE cuid = ?");
            $up->execute([$aid, $cuid]);
        }
    } catch (Throwable $e) { /* عدم ثبت فعالیت نباید مکش را متوقف کند */ }
}

// ══ تحلیل هوشمند تماس ═════════════════════════════════════════════════════
function tel_request_analysis(PDO $pdo, string $cuid): array {
    $rest = tel_http('POST', 'https://panel.telefonchy.com/webservice/v1/calls/analysis/request',
        ['service_id' => tel_service_id($pdo), 'cuid' => $cuid], 30);
    $ok = ($rest['http'] === 200 || $rest['http'] === 202);
    tel_usage_log($pdo, 'analysis_request', $cuid, $ok ? 1 : 0, (int)$rest['http'], (string)($rest['error'] ?? ''));
    if ($ok) {
        try {
            $st = $pdo->prepare("UPDATE phone_calls SET analysis_requested_at = NOW(), analysis_status = COALESCE(analysis_status, 'requesting') WHERE cuid = ?");
            $st->execute([$cuid]);
        } catch (Throwable $e) { /* نادیده */ }
    }
    return $rest;
}

function tel_poll_analysis(PDO $pdo, string $cuid): array {
    $url = 'https://panel.telefonchy.com/webservice/v1/calls/analysis/show?service_id=' . rawurlencode(tel_service_id($pdo)) . '&cuid=' . rawurlencode($cuid);
    $r = tel_http('GET', $url, null, 30);
    if ($r['http'] !== 200 || !is_array($r['json'])) {
        tel_usage_log($pdo, 'analysis_poll', $cuid, 0, (int)$r['http'], (string)($r['error'] ?? ''));
        return ['status' => null, 'applied' => false, 'http' => $r['http'], 'error' => $r['error'] ?? ''];
    }
    $d = $r['json'];
    if (isset($d['data']) && is_array($d['data']) && (isset($d['data']['analysis_status']) || isset($d['data']['summary']) || isset($d['data']['progress']))) {
        $d = $d['data']; // unwrap envelope
    }
    $status = isset($d['analysis_status']) ? (string)$d['analysis_status'] : null;
    $progress = isset($d['progress']) ? (int)$d['progress'] : null;

    // به‌روزرسانی شماره‌های پیش‌رونده (در حال پردازش)
    try {
        if ($status && !in_array($status, ['completed', 'failed'], true)) {
            $st = $pdo->prepare("UPDATE phone_calls SET analysis_status = ? WHERE cuid = ?");
            $st->execute([$status, $cuid]);
            $st2 = $pdo->prepare("INSERT INTO call_analysis (cuid, analysis_status, progress, payload) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE analysis_status = VALUES(analysis_status), progress = VALUES(progress), payload = VALUES(payload)");
            $st2->execute([$cuid, $status, $progress, json_encode($d, JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) { /* نادیده */ }

    $applied = false;
    if ($status === 'completed' || $status === 'failed') {
        $applied = tel_apply_analysis($pdo, $cuid, $d);
    }
    tel_usage_log($pdo, 'analysis_poll', $cuid, 1, 200, (string)$status);
    return ['status' => $status, 'applied' => $applied, 'http' => 200, 'data' => $d];
}

function tel_apply_analysis(PDO $pdo, string $cuid, array $d): bool {
    $status = isset($d['analysis_status']) ? (string)$d['analysis_status'] : 'unknown';
    $jenc = function ($v): ?string {
        if ($v === null) return null;
        return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v;
    };
    try {
        $st = $pdo->prepare("INSERT INTO call_analysis
            (cuid, analysis_status, progress, summary, outcome, report_text, coaching_tip, coaching_reason,
             customer_sentiment, sentiment_reason, active_keywords, matched_keywords, transcript, transcript_by_role, mapping, payload)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              analysis_status = VALUES(analysis_status), progress = VALUES(progress),
              summary = VALUES(summary), outcome = VALUES(outcome), report_text = VALUES(report_text),
              coaching_tip = VALUES(coaching_tip), coaching_reason = VALUES(coaching_reason),
              customer_sentiment = VALUES(customer_sentiment), sentiment_reason = VALUES(sentiment_reason),
              active_keywords = VALUES(active_keywords), matched_keywords = VALUES(matched_keywords),
              transcript = VALUES(transcript), transcript_by_role = VALUES(transcript_by_role),
              mapping = VALUES(mapping), payload = VALUES(payload)");
        $st->execute([
            $cuid,
            substr($status, 0, 22),
            isset($d['progress']) ? (int)$d['progress'] : ($status === 'completed' ? 100 : null),
            isset($d['summary']) ? mb_substr((string)$d['summary'], 0, 6000) : null,
            isset($d['outcome']) ? mb_substr((string)$d['outcome'], 0, 2000) : null,
            isset($d['report_text']) ? (string)$d['report_text'] : null,
            isset($d['coaching_tip']) ? mb_substr((string)$d['coaching_tip'], 0, 4000) : null,
            isset($d['coaching_reason']) ? mb_substr((string)$d['coaching_reason'], 0, 4000) : null,
            isset($d['customer_sentiment']) ? mb_substr((string)$d['customer_sentiment'], 0, 62) : null,
            isset($d['sentiment_reason']) ? mb_substr((string)$d['sentiment_reason'], 0, 4000) : null,
            $jenc($d['active_keywords'] ?? null),
            $jenc($d['matched_keywords'] ?? null),
            $jenc($d['transcript'] ?? null),
            $jenc($d['transcript_by_role'] ?? null),
            isset($d['transcript_by_role']['mapping']) ? substr((string)$d['transcript_by_role']['mapping'], 0, 14)
                : (isset($d['mapping']) ? substr((string)$d['mapping'], 0, 14) : null),
            json_encode($d, JSON_UNESCAPED_UNICODE),
        ]);
        $st2 = $pdo->prepare("UPDATE phone_calls SET analyzed = ?, analysis_status = ?,
            analysis_completed_at = IF(? = 'completed', NOW(), analysis_completed_at) WHERE cuid = ?");
        $st2->execute([$status === 'completed' ? 1 : 0, $status, $status, $cuid]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ══ صوت: دانلود/آرشیو ════════════════════════════════════════════════════
function tel_download_audio(string $fileId, string $cuid, string $destPath): array {
    if (!preg_match('/^[A-Za-z0-9_\-\.]{1,128}$/', $fileId) || !preg_match('/^[A-Za-z0-9._\-]{1,96}$/', $cuid)) {
        return ['ok' => false, 'error' => 'شناسهٔ نامعتبر', 'http' => 0];
    }
    $url = 'https://panel.telefonchy.com/webservice/v1/calls/record?file_id=' . rawurlencode($fileId) . '&cuid=' . rawurlencode($cuid);
    return tel_http('GET', $url, null, 60, $destPath);
}

// ══ تحلیل دسته‌جمعی + همگام‌سازی کامل (مصرف سهمیه با سقف روزانه) ══════════
function tel_archive_path(string $cuid): string {
    return rtrim(UPLOAD_DIR, '/') . '/calls/' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $cuid) . '.mp3';
}

/** تعداد تماس‌های تحلیل‌پذیرِ هنوز درخواست‌نشده (برای نمایش دکمهٔ تحلیل دسته‌جمعی) */
function tel_analysis_pending_count(PDO $pdo): int {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM phone_calls WHERE UPPER(COALESCE(status,'')) = 'ANSWERED' AND file_id IS NOT NULL AND analysis_requested_at IS NULL")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** شمارش آمار سریع برای کارت‌های صفحه */
function tel_calls_stats(PDO $pdo): array {
    $s = ['total' => 0, 'with_audio' => 0, 'analyzed' => 0, 'today' => 0];
    try {
        $s['total'] = (int)$pdo->query("SELECT COUNT(*) FROM phone_calls")->fetchColumn();
        $s['with_audio'] = (int)$pdo->query("SELECT COUNT(*) FROM phone_calls WHERE file_id IS NOT NULL")->fetchColumn();
        $s['analyzed'] = (int)$pdo->query("SELECT COUNT(*) FROM phone_calls WHERE analyzed = 1")->fetchColumn();
        $s['today'] = (int)$pdo->query("SELECT COUNT(*) FROM phone_calls WHERE DATE(created_at_tel) = CURDATE()")->fetchColumn();
    } catch (Throwable $e) { /* نادیده */ }
    return $s;
}

/** مکش تماس‌ها + تحلیل خودکار در صورت مجاز بودن (هر درخواست تحلیل ۱ واحد سهمیه) */
function tel_sync_calls(PDO $pdo, int $maxPages = 3, int $autoMax = 25): array {
    $report = ['configured' => tel_configured(), 'inserted' => 0, 'skipped_existing' => 0, 'pages_tried' => 0, 'pages_done' => 0, 'errors' => [], 'analysis_requested' => 0, 'analysis_capped' => false];
    if (!tel_configured()) { $report['errors'][] = 'توکن وب‌سرویس تلفنچی تنظیم نشده است — TELEFONCHY_WEBSERVICE_TOKEN را در config.local.php هاست تعریف کنید.'; return $report; }
    if (!ensure_telefonchy_schema($pdo)) { $report['errors'][] = 'ساخت جدول‌های تلفنچی ناموفق بود.'; return $report; }

    $auto = tel_setting($pdo, 'ai_analysis_auto', '1') === '1';
    $cap  = (int)tel_setting($pdo, 'ai_analysis_daily_cap', '30');
    $used = tel_analysis_used_today($pdo);
    $candidates = [];

    for ($page = 1; $page <= $maxPages; $page++) {
        $report['pages_tried'] = $page;
        $r = tel_fetch_calls_page($pdo, $page);
        if (!$r['ok']) {
            $report['errors'][] = 'صفحهٔ ' . $page . ': HTTP ' . $r['http'] . ($r['error'] ? ' — ' . $r['error'] : '');
            break;
        }
        if (!$r['calls']) break;
        $freshThisPage = 0;
        foreach ($r['calls'] as $call) {
            $cuid = trim((string)($call['call_id'] ?? $call['cuid'] ?? ''));
            $isNew = tel_upsert_call($pdo, $call);
            if ($isNew) {
                $report['inserted']++;
                $freshThisPage++;
                $status = strtoupper((string)($call['status'] ?? ''));
                $fid = $call['file_id'] ?? $call['record_id'] ?? null;
                if ($status === 'ANSWERED' && !empty($fid) && $fid !== false && $cuid !== '') $candidates[] = $cuid;
            } else {
                $report['skipped_existing']++;
            }
        }
        $report['pages_done'] = $page;
        if ($freshThisPage === 0) break; // همهٔ صفحه از قبل موجود بود — تاریخچه کامل شد
        if ($page >= (int)$r['total_pages']) break;
    }

    if ($auto && $candidates) {
        $requested = 0;
        foreach ($candidates as $cuid) {
            if ($used + $requested >= $cap) { $report['analysis_capped'] = true; break; }
            if ($requested >= $autoMax) break;
            $ar = tel_request_analysis($pdo, $cuid);
            if ($ar['http'] === 200 || $ar['http'] === 202) $requested++;
            usleep(300000); // 0.3 ثانیه — آرام روی Rate Limit
        }
        $report['analysis_requested'] = $requested;
    }
    return $report;
}

/** تحلیل دسته‌جمعیِ تماس‌های صوت‌دار درخواست‌نشده (تا سقف روزانه) */
function tel_bulk_request_analysis(PDO $pdo, int $limit = 20): array {
    $report = ['ok' => true, 'requested' => 0, 'failed' => 0, 'capped' => false];
    if (!tel_configured()) { $report['ok'] = false; $report['error'] = 'توکن تنظیم نشده'; return $report; }
    if (!ensure_telefonchy_schema($pdo)) { $report['ok'] = false; $report['error'] = 'ساخت جداول ناموفق'; return $report; }
    $cap  = (int)tel_setting($pdo, 'ai_analysis_daily_cap', '30');
    $used = tel_analysis_used_today($pdo);
    if ($used >= $cap) { $report['capped'] = true; return $report; }
    try {
        $st = $pdo->prepare("SELECT cuid FROM phone_calls
            WHERE UPPER(COALESCE(status,'')) = 'ANSWERED' AND file_id IS NOT NULL AND analysis_requested_at IS NULL
            ORDER BY created_at_tel DESC LIMIT " . max(1, min(50, $limit)));
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $report['ok'] = false; $report['error'] = $e->getMessage(); return $report; }
    foreach ($rows as $cuid) {
        if ($used + $report['requested'] >= $cap) { $report['capped'] = true; break; }
        $ar = tel_request_analysis($pdo, (string)$cuid);
        if ($ar['http'] === 200 || $ar['http'] === 202) $report['requested']++; else $report['failed']++;
        usleep(300000);
    }
    return $report;
}
