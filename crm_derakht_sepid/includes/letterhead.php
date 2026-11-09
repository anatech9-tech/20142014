<?php
/**
 * سربرگ چاپی شرکت — تنظیمات سراسری (جدول settings) + توابع کمکی چاپ.
 * لوگو/مهر/امضا از «تنظیمات ← پروفایل شرکت» توسط ادمین تنظیم می‌شود.
 */
function letterhead_company(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $defaults = [
        'app_name'                 => 'درخت سپید تجارت پارس',
        'letterhead_company_name'  => '',
        'letterhead_economic_code' => '',
        'letterhead_national_id'   => '',
        'letterhead_reg_no'        => '',
        'letterhead_phone'         => '',
        'letterhead_address'       => '',
        'letterhead_website'       => '',
        'letterhead_logo'          => '',
        'letterhead_stamp'         => '',
        'letterhead_signature'     => '',
    ];
    try {
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo) {
            foreach ($pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll() as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Throwable $e) {}
    return $cache = $defaults;
}

function letterhead_image(string $key): string {
    $lh = letterhead_company();
    $path = (string)($lh[$key] ?? '');
    return ($path !== '' && is_file(__DIR__ . '/../' . $path)) ? $path : '';
}

function letterhead_logo(): string {
    $lh = letterhead_company();
    $logo = letterhead_image('letterhead_logo');
    if ($logo !== '') return $logo;
    $ws = (string)($_SESSION['workshop_logo'] ?? '');
    if ($ws !== '' && is_file(__DIR__ . '/../' . $ws)) return $ws;
    return is_file(__DIR__ . '/../uploads/logos/logo.png') ? 'uploads/logos/logo.png' : '';
}

function letterhead_company_name(): string {
    $lh = letterhead_company();
    return trim((string)$lh['letterhead_company_name']) !== '' ? (string)$lh['letterhead_company_name'] : (trim((string)$lh['app_name']) !== '' ? (string)$lh['app_name'] : 'درخت سپید تجارت پارس');
}

/** ردیف کدهای شرکت (کد اقتصادی/شناسه ملی/شماره ثبت/وب‌سایت) — چیزی نباشد، چیزی چاپ نمی‌شود. */
function letterhead_company_bar(): void {
    $lh = letterhead_company();
    $bits = [];
    if (trim((string)$lh['letterhead_economic_code']) !== '') $bits[] = 'کد اقتصادی: ' . htmlspecialchars($lh['letterhead_economic_code'], ENT_QUOTES, 'UTF-8');
    if (trim((string)$lh['letterhead_national_id'])   !== '') $bits[] = 'شناسهٔ ملی: ' . htmlspecialchars($lh['letterhead_national_id'], ENT_QUOTES, 'UTF-8');
    if (trim((string)$lh['letterhead_reg_no'])        !== '') $bits[] = 'شمارهٔ ثبت: ' . htmlspecialchars($lh['letterhead_reg_no'], ENT_QUOTES, 'UTF-8');
    if (trim((string)$lh['letterhead_website'])       !== '') $bits[] = htmlspecialchars($lh['letterhead_website'], ENT_QUOTES, 'UTF-8');
    $addr = trim((string)$lh['letterhead_address']);
    $phone = trim((string)$lh['letterhead_phone']);
    $line2 = $addr . ($addr !== '' && $phone !== '' ? '  |  ' : '') . ($phone !== '' ? 'تلفن: ' . $phone : '');
    if (!$bits && $line2 === '') return;
    echo '<div style="text-align:center;font-size:10px;color:#555;margin:4px 0 12px;line-height:2;border-bottom:1px dashed #e2e8f0;padding-bottom:8px">';
    if ($bits) echo '<div>' . implode(' &nbsp;·&nbsp; ', $bits) . '</div>';
    if ($line2 !== '') echo '<div>' . htmlspecialchars($line2, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '</div>';
}

/** سربرگ استاندارد چاپ: تاریخ/متای سفارشی + نام شرکت و عنوان فرم + لوگو (+ ردیف کدها). */
function letterhead_header(string $title_html, string $meta_html = ''): void {
    $logo = letterhead_logo();
    $meta = $meta_html !== '' ? $meta_html : '<div style="font-size:14px;font-weight:700">' . htmlspecialchars(to_jalali(date('Y-m-d')), ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="print-header">';
    echo '<div class="print-meta">' . $meta . '</div>';
    echo '<div class="print-title"><div style="font-size:12.5px;font-weight:600;color:#333;margin-bottom:2px">' . htmlspecialchars(letterhead_company_name(), ENT_QUOTES, 'UTF-8') . '</div><div>' . $title_html . '</div></div>';
    echo '<div>' . ($logo !== '' ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="logo">' : '') . '</div>';
    echo '</div>';
    letterhead_company_bar();
}

/** پاورقی سربرگ: نام شرکت + آدرس/تلفن (اگر تنظیم شده باشد). */
function letterhead_footer(): void {
    $lh = letterhead_company();
    $addr = trim((string)$lh['letterhead_address']);
    $phone = trim((string)$lh['letterhead_phone']);
    if ($addr === '' && $phone === '') return;
    echo '<div style="border-top:1px solid #999;margin-top:16px;padding-top:6px;font-size:9.5px;color:#666;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;line-height:1.8">'
        . '<strong>' . htmlspecialchars(letterhead_company_name(), ENT_QUOTES, 'UTF-8') . '</strong>'
        . '<span>' . htmlspecialchars($addr . ($addr !== '' && $phone !== '' ? '  |  ' : '') . ($phone !== '' ? 'تلفن: ' . $phone : ''), ENT_QUOTES, 'UTF-8') . '</span>'
        . '</div>';
}

/** بلوک مهر و امضاء برای اسناد پرداختی/رسمی. */
function letterhead_signature_block(string $label_right = 'مهر و امضاء شرکت', string $label_left = 'امضاء حسابدار / مسئول'): void {
    $stamp = letterhead_image('letterhead_stamp');
    $sig = letterhead_image('letterhead_signature');
    $cell = function (string $label, string $img): void {
        echo '<div style="width:230px;max-width:45%;text-align:center">'
            . '<div style="min-height:70px;display:flex;align-items:flex-end;justify-content:center;padding-bottom:4px">'
            . ($img !== '' ? '<img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" alt="" style="max-height:64px;max-width:100%">' : '')
            . '</div>'
            . '<div style="border-top:1.5px solid #333;padding-top:4px;font-size:10.5px;color:#333">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
            . '</div>';
    };
    echo '<div style="display:flex;justify-content:space-between;gap:24px;margin-top:20px">';
    $cell($label_left, $sig);
    $cell($label_right, $stamp);
    echo '</div>';
}
