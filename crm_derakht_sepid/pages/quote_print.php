<?php
/**
 * پیش‌فاکتور چاپی استعلام قیمت — صفحه‌ای مستقل (A4) با سربرگ شرکت
 */
// display_errors عمداً روشن نیست — خطاها نباید روی اسناد چاپی دیده شوند

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/quotes.php';
require_once __DIR__ . '/../includes/letterhead.php';
require_login();
ensure_quote_schema($pdo);

$id = (int)($_GET['id'] ?? 0);
$me = (int)($_SESSION['user_id'] ?? 0);
$canAll = is_strict_admin() || is_finance_officer();
$managed = managed_team_ids($pdo);

$st = $pdo->prepare("SELECT q.*, c.name AS customer_name, c.address AS customer_address, c.phone AS customer_phone, c.mobile AS customer_mobile, c.national_id AS customer_national_id, c.economic_code AS customer_economic_code, d.title AS deal_title, d.team_id AS deal_team, d.owner_user_id AS deal_owner
    FROM crm_quotes q
    LEFT JOIN crm_customers c ON c.id = q.customer_id
    LEFT JOIN crm_deals d ON d.id = q.deal_id
    WHERE q.id = ? LIMIT 1");
try { $st->execute([$id]); } catch (Throwable $e) {
    $st = $pdo->prepare("SELECT q.*, c.name AS customer_name, c.phone AS customer_phone, c.mobile AS customer_mobile, NULL AS customer_national_id, NULL AS customer_economic_code, d.title AS deal_title, d.team_id AS deal_team, d.owner_user_id AS deal_owner
        FROM crm_quotes q LEFT JOIN crm_customers c ON c.id = q.customer_id LEFT JOIN crm_deals d ON d.id = q.deal_id WHERE q.id = ? LIMIT 1");
    $st->execute([$id]);
}
$q = $st->fetch(PDO::FETCH_ASSOC);
if (!$q) { http_response_code(404); echo 'استعلام پیدا نشد'; exit; }

// کنترل دسترسی: مثل صفحهٔ استعلام
$curCanAct = quote_user_can_act($pdo, $q, $me);
$canSee = $canAll || (int)$q['created_by'] === $me || (int)($q['deal_owner'] ?? 0) === $me || $curCanAct || (!empty($managed) && in_array((int)($q['deal_team'] ?? 0), $managed, true));
if (!$canSee) { http_response_code(403); echo 'دسترسی ندارید'; exit; }

$rates   = quote_rates_for($pdo, $q);
$isFixed = ($q['status'] === 'fixed');
$total   = (float)($q['total_cost_toman'] ?? 0);
$margin  = $q['margin_pct'] !== null ? (float)$q['margin_pct'] : quote_default_margin($pdo);
$sell    = $isFixed ? (float)($q['fixed_sell_toman'] ?? 0) : (($total > 0) ? quote_sell_toman($total, $margin) : 0.0);
$qty     = (float)($q['quantity'] ?? 1);
if ($qty <= 0) $qty = 1;
$unitPriceToman = $sell / $qty;
$jt = function_exists('to_jalali') ? to_jalali(substr(date('Y-m-d'), 0, 10)) : date('Y-m-d');
$jtQuote = function_exists('to_jalali') ? to_jalali(substr((string)$q['created_at'], 0, 10)) : substr((string)$q['created_at'], 0, 10);
$jtFix   = (!empty($q['fixed_at']) && function_exists('to_jalali')) ? to_jalali(substr((string)$q['fixed_at'], 0, 10)) : substr((string)($q['fixed_at'] ?? ''), 0, 10);
$fmt = fn($v) => $v === null ? '—' : number_format($v, 0, '.', '،');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پیش‌فاکتور استعلام #<?= (int)$id ?> — <?= htmlspecialchars((string)$q['title']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Vazirmatn',Tahoma,sans-serif; direction:rtl; background:#f0f2f5; color:#1a1a2e; min-height:100vh; }
        .page { max-width:900px; margin:20px auto; background:#fff; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,.08); overflow:hidden; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:linear-gradient(135deg,#308e89,#26756f); color:#fff; }
        .top-bar h1 { font-size:16px; font-weight:700; }
        .top-bar a { color:#fff; text-decoration:none; background:rgba(255,255,255,.2); padding:5px 12px; border-radius:8px; font-size:12px; }
        .content { padding:22px; }
        .quote-meta { display:flex; justify-content:space-between; gap:12px; font-size:12px; color:#475569; border-bottom:1px dashed #cbd5e1; padding-bottom:10px; margin-bottom:12px; flex-wrap:wrap; }
        .quote-title { font-size:19px; font-weight:800; color:#134e4a; margin:8px 0 2px; }
        .quote-sub { font-size:12px; color:#64748b; margin-bottom:14px; }
        .info-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; font-size:12px; margin-bottom:14px; line-height:2; }
        .info-box b { color:#0f172a; }
        table.pi { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:14px; }
        table.pi th { background:#f1f5f9; border:1px solid #cbd5e1; padding:9px 8px; font-size:12px; }
        table.pi td { border:1px solid #e2e8f0; padding:10px 8px; }
        .totals { margin-right:auto; width:320px; font-size:12.5px; }
        .totals tr td { border:none; border-bottom:1px dotted #e2e8f0; padding:6px 4px; }
        .grand { font-size:17px !important; font-weight:800 !important; color:#134e4a !important; border-top:2px solid #308e89 !important; border-bottom:none !important; }
        .print-header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #333; padding-bottom:12px; margin-bottom:14px; }
        .print-header img { height:60px; }
        .print-title { font-size:18px; font-weight:700; text-align:center; }
        .print-meta { font-size:12px; color:#475569; text-align:right; }
        .fx-strip { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
        .fx-chip { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; border-radius:12px; padding:8px 14px; font-size:13px; font-weight:800; }
        .fx-chip small { display:block; font-size:9.5px; font-weight:600; color:#047857; }
        .note-block { font-size:10.5px; color:#64748b; margin-top:16px; line-height:1.9; border-top:1px solid #e2e8f0; padding-top:10px; }
        @media print {
            * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
            body { background:#fff; }
            .page { box-shadow:none; margin:0; max-width:100%; border-radius:0; }
            .top-bar, .action-bar { display:none !important; }
            .print-header img { height:42px !important; }
            .print-title { font-size:13px !important; }
            @page { size:A4; margin:10mm; }
        }
        .action-bar { display:flex; justify-content:center; gap:8px; padding:0 22px 18px; }
        .btn { padding:9px 18px; border:none; border-radius:10px; cursor:pointer; font-family:inherit; font-size:13px; font-weight:700; background:#308e89; color:#fff; }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar">
        <h1>🧾 پیش‌فاکتور اعلام قیمت — استعلام #<?= (int)$id ?></h1>
        <a href="javascript:window.close()">بستن</a>
    </div>
    <div class="content">
        <?php letterhead_header('پیش‌فاکتور اعلام قیمت', '<div style="font-size:13px;font-weight:700">شماره: استعلام #' . (int)$id . '</div><div style="font-size:11px;margin-top:4px">تاریخ صدور: ' . $jt . '</div>' . ($isFixed ? '<div style="font-size:11px;margin-top:4px;color:#047857;font-weight:700">تاریخ فیکس: ' . $jtFix . '</div>' : '')) ?>

        <div class="quote-meta">
            <span>مشتری: <b><?= htmlspecialchars((string)($q['customer_name'] ?: '—')) ?></b></span>
            <?php if (!empty($q['customer_phone']) || !empty($q['customer_mobile'])): ?><span dir="ltr">☎ <?= htmlspecialchars((string)($q['customer_mobile'] ?: $q['customer_phone'])) ?></span><?php endif; ?>
            <span>تاریخ ثبت استعلام: <b><?= $jtQuote ?></b></span>
        </div>

        <h2 class="quote-title"><?= htmlspecialchars((string)$q['title']) ?></h2>
        <div class="quote-sub"><?= $q['deal_title'] ? 'مرتبط با معامله: ' . htmlspecialchars((string)$q['deal_title']) : '' ?></div>

        <div class="info-box">
            <?php if (!empty($q['product_name'])): ?><div>کالا / شرح: <b><?= htmlspecialchars((string)$q['product_name']) ?></b></div><?php endif; ?>
            <?php if (!empty($q['specs'])): ?><div>مشخصات فنی: <b><?= nl2br(htmlspecialchars((string)$q['specs'])) ?></b></div><?php endif; ?>
            <div style="display:flex;gap:18px;flex-wrap:wrap">
                <span>مقدار: <b><?= quote_trim_num($q['quantity'] ?? '1') ?> <?= htmlspecialchars((string)$q['unit']) ?></b></span>
                <?php if (!empty($q['hs_code'])): ?><span>HS Code: <b dir="ltr"><?= htmlspecialchars((string)$q['hs_code']) ?></b></span><?php endif; ?>
                <?php if (!empty($q['incoterm'])): ?><span>اینکوترم: <b dir="ltr"><?= htmlspecialchars((string)$q['incoterm']) ?></b></span><?php endif; ?>
            </div>
        </div>

        <table class="pi">
            <thead><tr><th style="width:44px">ردیف</th><th>شرح</th><th style="width:70px">مقدار</th><th style="width:60px">واحد</th><th style="width:150px">مبلغ واحد (تومان)</th><th style="width:170px">مبلغ کل (تومان)</th></tr></thead>
            <tbody>
                <tr>
                    <td style="text-align:center">۱</td>
                    <td style="font-weight:700"><?= htmlspecialchars((string)($q['product_name'] ?: $q['title'])) ?></td>
                    <td style="text-align:center" dir="ltr"><?= quote_trim_num($qty) ?></td>
                    <td style="text-align:center"><?= htmlspecialchars((string)$q['unit']) ?></td>
                    <td style="text-align:left" dir="ltr"><?= $fmt($unitPriceToman) ?></td>
                    <td style="text-align:left;font-weight:800" dir="ltr"><?= $fmt($sell) ?></td>
                </tr>
            </tbody>
        </table>

        <table class="totals">
            <tr><td>جمع کل (تومان)</td><td style="text-align:left" dir="ltr"><?= $fmt($sell) ?></td></tr>
            <tr><td>جمع کل (دلار آمریکا)</td><td style="text-align:left" dir="ltr"><?= $fmt(quote_from_toman($sell, 'USD', $rates)) ?></td></tr>
            <tr><td>جمع کل (یوان چین)</td><td style="text-align:left" dir="ltr"><?= $fmt(quote_from_toman($sell, 'CNY', $rates)) ?></td></tr>
            <tr><td class="grand">مبلغ نهایی قابل پرداخت</td><td class="grand" style="text-align:left" dir="ltr"><?= $fmt($sell) ?> تومان</td></tr>
        </table>

        <div class="fx-strip">
            <div class="fx-chip"><small>معادل دلاری</small><?= $fmt(quote_from_toman($sell, 'USD', $rates)) ?> USD</div>
            <div class="fx-chip"><small>معادل یوان</small><?= $fmt(quote_from_toman($sell, 'CNY', $rates)) ?> CNY</div>
            <div class="fx-chip"><small>معادل ریالی</small><?= $fmt(quote_from_toman($sell, 'IRR', $rates)) ?> IRR</div>
        </div>

        <p class="note-block">
            <?= htmlspecialchars(function_exists('fx_source_line') ? fx_source_line($pdo) : 'منبع نرخ ارز: نرخ‌سنج') ?>. این سند صرفاً جهت «اعلام قیمت» صادر شده و به‌منزلهٔ قرارداد نهایی یا بیع‌نامه نمی‌باشد. قیمت‌های فوق بر اساس نرخ‌های ارز ثبت‌شده در تاریخ <?= $jtQuote ?> محاسبه شده‌اند و در صورت تغییر نرخ ارز یا مشخصات کالا، امکان بازنگری پیش از فیکس نهایی وجود دارد. جزئیات هزینه‌ها (قیمت درب کارخانه، حمل، بیمه، ترخیص، حواله و …) در پروندهٔ داخلی استعلام #<?= (int)$id ?> قابل مشاهده است.
        </p>

        <?php letterhead_signature_block('مهر و امضاء شرکت', 'امضاء مدیر فروش') ?>
        <?php letterhead_footer() ?>
    </div>
    <div class="action-bar">
        <button class="btn" onclick="window.print()">🖨 چاپ A4</button>
    </div>
</div>
</body>
</html>
