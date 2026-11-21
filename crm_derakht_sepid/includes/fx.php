<?php
/**
 * ══ نرخ ارز لحظه‌ای — «نرخ‌سنج» (nerkh.jahankhahan.shop) ══
 * نرخ بازار آزاد تهران، به‌روز هر دقیقه، بدون کلید، رایگان.
 * اعداد دلار/یورو/یوان/درهم به **تومان** برمی‌گردند؛ ریال ثابت ۱ ریال = ۰٫۱ تومان.
 *
 * کش در settings (fx_live_*) نگه‌داری می‌شود؛ حداکثر یک درخواست HTTP هر ۳۰ دقیقه.
 * اگر سرور نتواند به اینترنت وصل شود، آخرین کش/نرخ دستی همان قبلی می‌ماند (سایت نمی‌خوابد).
 * تعهد شرایط استفاده: هنگام نمایش عددها، «نرخ‌سنج» به‌عنوان منبع ذکر شود.
 */

function fx_setting_get(PDO $pdo, string $k, string $def = ''): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? $def : (string)$v;
    } catch (Throwable $e) { return $def; }
}

function fx_setting_set(PDO $pdo, string $k, string $v): void {
    try {
        $st = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st->execute([$k, $v]);
    } catch (Throwable $e) { /* مشکل دیتابیس — نرخ این بار اعمال نمی‌شود */ }
}

function fx_live_enabled(PDO $pdo): bool {
    $v = fx_setting_get($pdo, 'fx_live_enabled', '1');
    return $v === '1';
}

/** اطلاعات نمایشی برای تنظیمات/هدر (منبع، آخرین نرخ، آخرین خطا) */
function fx_live_status(PDO $pdo): array {
    return [
        'enabled'   => fx_live_enabled($pdo),
        'as_of'     => fx_setting_get($pdo, 'fx_live_as_of', ''),
        'cached_at' => (int)fx_setting_get($pdo, 'fx_live_cached_at', '0'),
        'error'     => fx_setting_get($pdo, 'fx_live_error', ''),
        'source'    => 'nerkh.jahankhahan.shop',
    ];
}

/** دریافت خام live.json + اعتبارسنجی؛ برمی‌گرداند ['ok'=>bool, ...] */
function fx_live_fetch(PDO $pdo): array {
    $url = 'https://nerkh.jahankhahan.shop/data/live.json';
    $raw = null;

    // تلاش ۱: cURL (معمولاً روی هاست‌های اشتراکی فعال است)
    if (function_exists('curl_init')) {
        $ch = @curl_init($url);
        if ($ch !== false) {
            @curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'DerakhtSepid/1.0 (+fx-rates)',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $body = @curl_exec($ch);
            $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body !== false && $body !== '' && ($code === 0 || ($code >= 200 && $code < 300))) $raw = (string)$body;
            @curl_close($ch);
        }
    }
    // تلاش ۲: allow_url_fopen (بدون TLS verification — اگر هاست مشکل CA داشته باشد)
    if ($raw === null) {
        $ctx = @stream_context_create([
            'http' => ['timeout' => 6, 'header' => "User-Agent: DerakhtSepid/1.0\r\nAccept: application/json\r\n"],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
        ]);
        $raw = @file_get_contents($url, false, $ctx) ?: null;
    }
    if (!is_string($raw) || $raw === '') return ['ok' => false, 'error' => 'اتصال به سرویس نرخ ارز برقرار نشد'];

    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['rates']) || !is_array($j['rates'])) {
        return ['ok' => false, 'error' => 'پاسخ سرویس نامعتبر بود (JSON)'];
    }
    $r = $j['rates'];
    $num = function (string $k) use ($r): float {
        $v = $r[$k] ?? null;
        if (is_numeric($v)) return (float)$v;
        if (is_string($v)) {
            $v = str_replace(['،', ',', '٬', ' '], '', $v);
            return is_numeric($v) ? (float)$v : 0.0;
        }
        return 0.0;
    };
    $rates = [
        'USD'  => $num('dollar'),
        'EUR'  => $num('euro'),
        'CNY'  => $num('yuan'),
        'AED'  => $num('aed'),
        'GBP'  => $num('pound'),
        'USDT' => $num('tether'),
    ];
    if ($rates['USD'] <= 0 || $rates['EUR'] <= 0 || $rates['CNY'] <= 0) {
        return ['ok' => false, 'error' => 'نرخ دلار/یورو/یوان در پاسخ نبود یا نامعتبر بود'];
    }
    $asOf = trim((string)($j['date'] ?? '') . ' ' . (string)($j['time'] ?? ''));
    return ['ok' => true, 'rates' => $rates, 'as_of' => $asOf !== '' ? $asOf : date('Y-m-d H:i')];
}

/** دریافت و اعمال نرخ (حالت اجباری برای دکمهٔ «به‌روزرسانی همین حالا») */
function fx_live_refresh(PDO $pdo): array {
    fx_setting_set($pdo, 'fx_live_last_attempt', (string)time());
    $res = fx_live_fetch($pdo);
    if (empty($res['ok'])) {
        fx_setting_set($pdo, 'fx_live_error', 'آخرین تلاش ناموفق (' . date('Y-m-d H:i') . '): ' . ($res['error'] ?? 'خطای ناشناخته') . ' — آخرین نرخ کش/دستی اعمال می‌شود.');
        return $res;
    }
    foreach (['USD', 'EUR', 'CNY', 'AED', 'GBP', 'USDT'] as $c) {
        $v = (float)($res['rates'][$c] ?? 0);
        // نرخ زنده در کلیدهای جدا (fx_live_toman_*) ذخیره می‌شود تا مقادیر دستی fx_to_toman_* دست‌نخورده بمانند
        if ($v > 0) fx_setting_set($pdo, 'fx_live_toman_' . $c, (string)round($v, 2));
    }
    fx_setting_set($pdo, 'fx_live_cached_at', (string)time());
    fx_setting_set($pdo, 'fx_live_as_of', (string)($res['as_of'] ?? ''));
    fx_setting_set($pdo, 'fx_live_error', '');
    return ['ok' => true, 'as_of' => $res['as_of'] ?? '', 'rates' => $res['rates']];
}

/** کش هوشمند: اگر تازه (<۳۰ دقیقه) رد می‌شود؛ اگر اخیراً ناموفق بوده ۵ دقیقه صبر می‌کند */
function fx_live_maybe_refresh(PDO $pdo): void {
    try {
        if (!fx_live_enabled($pdo)) return;
        $now     = time();
        $last    = (int)fx_setting_get($pdo, 'fx_live_cached_at', '0');
        $attempt = (int)fx_setting_get($pdo, 'fx_live_last_attempt', '0');
        if ($now - $last < 1800) return;  // کش تازه است
        if ($now - $attempt < 300) return; // اخیراً تلاش شد؛ ۵ دقیقه صبر
        fx_live_refresh($pdo);
    } catch (Throwable $e) { /* هرگز صفحه را خراب نکند */ }
}


/**
 * نرخ‌های مؤثر: اگر نرخ لحظه‌ای فعال باشد و کش معتبری وجود داشته باشد، نرخ زندهٔ هر ارز را برمی‌گرداند؛
 * در غیر این صورت (یا اگر آن ارز در کش نباشد) مقدار دستی fx_to_toman_* اعمال می‌شود. ریال همیشه دستی (۰٫۱).
 */
function fx_effective_rates(PDO $pdo): array {
    $manual = function (string $k, string $def) use ($pdo) {
        try {
            $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
            $st->execute(['fx_to_toman_' . $k]);
            $v = $st->fetchColumn();
            return ($v === false || $v === '') ? (float)$def : (float)$v;
        } catch (Throwable $e) { return (float)$def; }
    };
    $live = function (string $k) use ($pdo) { return (float)fx_setting_get($pdo, 'fx_live_toman_' . $k, '0'); };
    $useLive = fx_live_enabled($pdo);
    $pick = function (string $k, string $def) use ($useLive, $live, $manual) {
        if ($useLive) { $v = $live($k); if ($v > 0) return $v; }
        return $manual($k, $def);
    };
    return [
        'USD'  => $pick('USD', '0'),
        'EUR'  => $pick('EUR', '0'),
        'CNY'  => $pick('CNY', '0'),
        'AED'  => $pick('AED', '0'),
        'GBP'  => $pick('GBP', '0'),
        'USDT' => $pick('USDT', '0'),
        'TOM'  => 1.0,
        'IRR'  => $manual('IRR', '0.1'),
    ];
}

/** متن وضعیت منبع برای صفحات تنظیمات (دلار/یورو/یوان زنده یا دستی) */
function fx_live_summary(PDO $pdo): string {
    $eff = fx_effective_rates($pdo);
    $toman = fn($v) => number_format((float)$v, 0, '.', '،');
    return 'دلار ' . $toman($eff['USD']) . ' • یورو ' . $toman($eff['EUR']) . ' • یوان ' . $toman($eff['CNY']) . ' تومان';
}

/** فقط برای نمایش «منبع نرخ» روی صفحات (با قید احترام به شرایط استفادهٔ منبع) */
function fx_source_line(PDO $pdo): string {
    $asOf = fx_setting_get($pdo, 'fx_live_as_of', '');
    if ($asOf !== '' && fx_live_enabled($pdo)) {
        return 'منبع نرخ: نرخ‌سنج (nerkh.jahankhahan.shop) — نرخ ' . htmlspecialchars($asOf, ENT_QUOTES);
    }
    return 'منبع نرخ: تنظیم دستی (تنظیمات ← تارگت و کمیسیون)';
}
