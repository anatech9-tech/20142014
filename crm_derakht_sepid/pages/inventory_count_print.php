<?php
/**
 * §11.3: فرم موجودی انبار / انبارگردانی — موجودی سیستم + ستون شمارش واقعی و اختلاف — A4
 * ?blank=1 → چاپ فرم خالی (بدون موجودی سیستم)
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/salary_calc.php';
require_login();

$ws_id = $_SESSION['workshop_id'] ?? 0;

$sel_date = trim((string)($_GET['date'] ?? ''));
if ($sel_date === '') $sel_date = to_jalali(date('Y-m-d'));
$sel_cat = trim((string)($_GET['cat'] ?? ''));
$blank = !empty($_GET['blank']);

// اقلام فعال انبار
$st = $pdo->prepare("SELECT * FROM inventory_items WHERE workshop_id = ? AND status = 'active' ORDER BY category, name");
$st->execute([$ws_id]);
$items = $st->fetchAll();

// تحویل‌شده و برنگشته به تفکیک کالا (موجودی کلی = آماده + نیازمند تعمیر + برنگشته)
$out_map = [];
try {
    $st = $pdo->prepare("SELECT item_id, COALESCE(SUM(quantity),0) q FROM inventory_transactions WHERE workshop_id = ? AND type IN ('checkout','consumable') AND (return_date IS NULL OR return_approved = 0) AND is_consumable = 0 AND deleted_at IS NULL GROUP BY item_id");
    $st->execute([$ws_id]);
    foreach ($st->fetchAll() as $o) { $out_map[$o['item_id']] = (float)$o['q']; }
} catch (Throwable $e) {}

$categories = [];
foreach ($items as $i) { if (trim((string)$i['category']) !== '') $categories[$i['category']] = true; }
$categories = array_keys($categories);
sort($categories);

if ($sel_cat !== '') $items = array_values(array_filter($items, fn($i) => (string)$i['category'] === $sel_cat));

$rows = [];
foreach ($items as $i) {
    $ready = (float)$i['quantity'];
    $repair = (float)($i['repair_quantity'] ?? 0);
    $out = (float)($out_map[$i['id']] ?? 0);
    $rows[] = [
        'sku' => $i['sku'] ?: '—', 'name' => $i['name'], 'cat' => $i['category'] ?: '—',
        'unit' => $i['unit'] ?: t('عدد'), 'sys' => $ready + $repair + $out,
    ];
}

$logo_path = $_SESSION['workshop_logo'] ?? '';
if (!$logo_path || !file_exists(__DIR__ . '/../' . $logo_path)) $logo_path = 'uploads/logos/logo.png';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>" dir="<?= htmlspecialchars($lang_dir, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('فرم') ?> <?= t('inv_stock') ?> <?= t('nav_inventory') ?> / <?= t('انبارگردانی') ?><?= $blank ? ' (فرم خالی)' : '' ?> - <?= $sel_date ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Vazirmatn',Tahoma,sans-serif; direction:<?= $lang_dir ?>; background:#f0f2f5; color:#1a1a2e; min-height:100vh; }
        .page { max-width:1000px; margin:20px auto; background:white; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,0.08); overflow:hidden; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:linear-gradient(135deg,#0891b2,#164e63); color:white; flex-wrap:wrap; gap:8px; }
        .top-bar h1 { font-size:16px; font-weight:700; }
        .top-bar a { color:white; text-decoration:none; background:rgba(255,255,255,0.2); padding:5px 12px; border-radius:8px; font-size:12px; }
        .content { padding:20px; }
        .print-header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #333; padding-bottom:12px; margin-bottom:16px; }
        .print-header img { height:60px; }
        .print-title { font-size:18px; font-weight:700; text-align:center; }
        .print-meta { font-size:12px; color:#666; text-align:right; }
        .filter-bar { padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .filter-bar select, .filter-bar input { padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:inherit; font-size:13px; }
        .print-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:12px; }
        .print-table th { background:#f1f5f9; padding:8px 4px; border:1px solid #ccc; text-align:center; font-weight:600; }
        .print-table td { padding:9px 4px; border:1px solid #ddd; text-align:center; }
        .btn { padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-family:inherit; font-size:12px; font-weight:600; }
        .btn-print { background:linear-gradient(135deg,#0891b2,#164e63); color:white; }
        .blank-check { display:flex; align-items:center; gap:6px; font-size:12px; cursor:pointer; }
        @media print {
            * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
            html, body { margin:0; padding:0; background:white; width:100%; height:100%; }
            .page { box-shadow:none; border-radius:0; margin:0; max-width:100%; width:100%; }
            .top-bar, .filter-bar, .action-bar { display:none !important; }
            .print-header { border-bottom:1px solid #000; padding:4px 6px !important; }
            .print-header img { height:40px !important; }
            .print-title { font-size:13px !important; }
            .print-table { font-size:9px !important; }
            .print-table th { padding:3px 2px !important; font-size:9px !important; }
            .print-table td { padding:8px 2px !important; font-size:9px !important; }
            @page { size:A4; margin:8mm; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="top-bar">
        <h1>📦 <?= t('فرم') ?> <?= t('inv_stock') ?> <?= t('nav_inventory') ?> / <?= t('انبارگردانی') ?></h1>
        <a href="javascript:window.close()"><?= t('jdp_close') ?></a>
    </div>
    <div class="content">
        <form method="GET" class="filter-bar">
            <input type="hidden" name="page" value="inventory_count_print">
            <label style="font-size:13px;font-weight:600"><?= t('wh_date') ?>:</label>
            <input type="text" name="date" value="<?= htmlspecialchars($sel_date, ENT_QUOTES) ?>" style="width:110px" placeholder="1405/05/18">
            <label style="font-size:13px;font-weight:600"><?= t('دسته') ?>:</label>
            <select name="cat" onchange="this.form.submit()">
                <option value=""><?= t('همه دسته‌ها') ?></option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>" <?= $sel_cat === $c ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>
            <label class="blank-check"><input type="checkbox" name="blank" value="1" <?= $blank ? 'checked' : '' ?> onchange="this.form.submit()"> <?= t('btn_print') ?> <?= t('فرم') ?> <?= t('خالی') ?> (<?= t('بدون') ?> <?= t('inv_stock') ?> <?= t('سیستم') ?>)</label>
            <button type="submit" class="btn btn-print" style="padding:6px 14px"><?= t('اعمال') ?></button>
        </form>

        <div class="print-header">
            <div class="print-meta">
                <div style="font-size:14px;font-weight:700"><?= $sel_date ?></div>
                <div style="font-size:11px;margin-top:4px"><?= date('H:i') ?></div>
                <div style="font-size:12px;margin-top:4px"><?= $sel_cat !== '' ? (t('دسته:') . ' ' . htmlspecialchars($sel_cat)) : t('همه دسته‌ها') ?></div>
            </div>
            <div class="print-title"><?= t('فرم') ?> <?= t('inv_stock') ?> <?= t('nav_inventory') ?> / <?= t('انبارگردانی') ?><?= $blank ? ' (فرم خالی)' : '' ?></div>
            <div>
                <?php if (file_exists(__DIR__ . '/../' . $logo_path)): ?><img src="<?= $logo_path ?>" alt="logo"><?php endif; ?>
            </div>
        </div>

        <?php if (empty($rows)): ?>
        <p style="text-align:center;color:#999;padding:30px"><?= t('کالایی') ?> <?= t('یافت نشد') ?></p>
        <?php else: ?>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:35px"><?= t('ردیف') ?></th>
                    <th><?= t('inv_sku') ?></th>
                    <th><?= t('wh_name') ?> <?= t('کالا') ?></th>
                    <th><?= t('دسته') ?></th>
                    <th><?= t('واحد') ?></th>
                    <?php if (!$blank): ?><th><?= t('inv_stock') ?> <?= t('سیستم') ?></th><?php endif; ?>
                    <th style="width:90px"><?= t('inv_stock') ?> <?= t('واقعی') ?></th>
                    <th style="width:80px"><?= t('اختلاف') ?></th>
                    <th style="width:130px"><?= t('wor_description') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $rn = 0; foreach ($rows as $r): $rn++; ?>
                <tr>
                    <td><?= $rn ?></td>
                    <td style="font-size:10px"><?= $r['sku'] ?></td>
                    <td style="text-align:right;font-weight:600"><?= $r['name'] ?></td>
                    <td><?= $r['cat'] ?></td>
                    <td><?= $r['unit'] ?></td>
                    <?php if (!$blank): ?><td style="font-weight:700"><?= rtrim(rtrim(number_format($r['sys'], 2, '.', ''), '0'), '.') ?></td><?php endif; ?>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size:11px;color:#64748b;margin-top:10px"><?= t('مأمور') ?> <?= t('انبارگردانی') ?>: .............................. <?= t('امضا') ?>: .............................. <?= t('anbar_keeper') ?>: .............................. <?= t('امضا') ?>: ..............................</p>
        <?php endif; ?>

        <div class="action-bar" style="margin-top:16px;display:flex;gap:8px;justify-content:center">
            <button onclick="window.print()" class="btn btn-print">🖨 <?= t('btn_print') ?> A4</button>
        </div>
    </div>
</div>
<script id="guideTourScript" src="assets/js/guide_tour.js"></script>
</body>
</html>
