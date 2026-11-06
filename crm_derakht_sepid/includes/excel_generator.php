<?php
/**
 * Excel/CSV Generator - ساده و مطمئن
 * تولید CSV با BOM که اکسل بدون خطا باز می‌کنه
 */
require_once __DIR__ . '/salary_calc.php';

/**
 * §v13: خروجی واقعی .xlsx
 *
 * قبلاً اینجا CSV تولید می‌شد که با جداکنندهٔ Tab نوشته شده بود ولی با پسوند
 * csv و Content-Type: text/csv فرستاده می‌شد. اکسل فایل csv را با کاما جدا
 * می‌کند، پس هیچ ستونی تشخیص داده نمی‌شد و کل ردیف در یک سلول می‌افتاد.
 * حالا از NativeExcel (تولیدکنندهٔ OOXML موجود در پروژه) استفاده می‌کنیم.
 */
function output_csv($filename, $headers, $rows, $rtl = true) {
    // پسوند را در هر حالت به xlsx تبدیل کن
    $base = preg_replace('/\.(csv|xlsx?)$/iu', '', $filename);

    // اگر افزونهٔ zip روی هاست نبود، به CSV استاندارد (کاما) برمی‌گردیم
    if (!class_exists('ZipArchive')) {
        output_csv_fallback($base . '.csv', $headers, $rows);
        return;
    }

    require_once __DIR__ . '/excel_native.php';
    $xl = new NativeExcel();
    $xl->setTitle('Sheet1');
    $xl->setRightToLeft($rtl);

    $colOf = function ($n) {
        $l = '';
        while ($n > 0) { $n--; $l = chr(65 + ($n % 26)) . $l; $n = intdiv($n, 26); }
        return $l;
    };

    // ── سطر عنوان ──
    $r = 1;
    foreach (array_values($headers) as $i => $h) {
        $ref = $colOf($i + 1) . $r;
        $xl->setCellValue($ref, $h);
        $xl->setStyle($ref, ['bold' => true, 'fill' => true]);
    }

    // ── داده‌ها ──
    $widths = [];
    foreach (array_values($headers) as $i => $h) {
        $widths[$i] = min(45, max(12, mb_strlen((string)$h, 'UTF-8') + 4));
    }
    foreach ($rows as $row) {
        $r++;
        foreach (array_values((array)$row) as $i => $cell) {
            $val = (string)$cell;
            $xl->setCellValue($colOf($i + 1) . $r, $val);
            // عرض ستون بر اساس بلندترین خطِ داخل سلول
            $longest = 0;
            foreach (explode("\n", $val) as $line) {
                $longest = max($longest, mb_strlen($line, 'UTF-8'));
            }
            if (!isset($widths[$i]) || $longest + 3 > $widths[$i]) {
                $widths[$i] = min(45, $longest + 3);
            }
        }
    }
    foreach ($widths as $i => $w) {
        $xl->setColumnWidth($i + 1, $w);
    }

    $xl->download($base . '.xlsx');
}

/** خروجی CSV استاندارد (فقط وقتی ZipArchive روی هاست نباشد) */
function output_csv_fallback($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ',', '"', '');          // کاما = جداکنندهٔ واقعی CSV
    foreach ($rows as $row) fputcsv($out, (array)$row, ',', '"', '');
    fclose($out);
    exit;
}

function generate_employee_payment_csv($pdo, $ws_id, $sel_year, $sel_month) {
    // دقیقاً همان بازه/موتور فرم واریزی کارمندان؛ قبلاً انتهای ماه میلادی و
    // فرمول قدیمی استفاده می‌شد و اکسل با صفحه حقوق هم‌خوان نبود.
    [$g_start, $g_end] = jalali_month_bounds((int)$sel_year, (int)$sel_month);
    $month_label = jalali_month_name($sel_month) . ' ' . $sel_year;
    $settings = [];
    foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $r) $settings[$r['setting_key']] = $r['setting_value'];

    $st = $pdo->prepare("SELECT u.id, u.full_name, COALESCE(MAX(wp_all.monthly_salary),0) AS monthly_salary, u.iban, u.account_name, u.account_number
        FROM users u JOIN workshop_personnel wp_current ON wp_current.user_id=u.id AND wp_current.workshop_id=? AND wp_current.status='active'
        LEFT JOIN workshop_personnel wp_all ON wp_all.user_id=u.id AND wp_all.status='active'
        WHERE u.is_karigar=0 GROUP BY u.id,u.full_name,u.iban,u.account_name,u.account_number ORDER BY u.full_name");
    $st->execute([$ws_id]);
    $headers = [t('ردیف'),t('نام و نام خانوادگی'),t('طلب ماه'),t('مانده طلب قبلی'),t('پاداش'),t('جریمه'),t('کسر مساعده/پرداخت'),t('قابل پرداخت'),t('شماره شبا'),t('نام صاحب حساب'),t('بانک مقصد')];
    $rows=[]; $total=0; $num=0;
    foreach ($st->fetchAll() as $p) {
        $base = employee_month_pay($pdo, $p['id'], 0, $g_start, $g_end, (float)$p['monthly_salary'], $settings);
        $prev = compute_prev_balance($pdo, $p['id'], 0, 0, $g_start, 0, (float)$p['monthly_salary'], $settings);
        $adv = advance_deduction_month($pdo, $p['id'], 0, $g_start, $g_end);
        $bp = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) total FROM bonuses_penalties WHERE user_id=? AND status='approved' AND date BETWEEN ? AND ? GROUP BY type");
        $bp->execute([$p['id'],$g_start,$g_end]); $bonus=0; $penalty=0;
        foreach ($bp->fetchAll() as $r) { if ($r['type']==='bonus') $bonus=(float)$r['total']; else $penalty=(float)$r['total']; }
        $payable=round($base+$prev+$bonus-$penalty-$adv);
        if ($payable<=0) continue;
        $num++; $total+=$payable;
        $rows[] = [$num,$p['full_name'],$base,$prev,$bonus,$penalty,$adv,$payable,$p['iban']?:'-',$p['account_name']?:'-',$p['account_number']?:'-'];
    }
    $rows[]=['',t('جمع کل'),'','','','','',$total,'','',''];
    output_csv(t('لیست واریزی کارمندان').'_'.$month_label.'.xlsx',$headers,$rows);
}

function excel_worker_iban_group_key($iban, $worker_id) {
    $value = strtoupper(normalize_digits(trim((string)$iban)));
    $value = preg_replace('/[\s\-_]+/u', '', $value);
    $value = preg_replace('/[^A-Z0-9]/', '', $value);
    return $value !== '' ? $value : '@worker:' . (int)$worker_id;
}

function excel_worker_iban_display($iban) {
    $value = strtoupper(normalize_digits(trim((string)$iban)));
    $value = preg_replace('/[\s\-_]+/u', '', $value);
    return $value !== '' ? $value : '-';
}

function generate_worker_payment_csv($pdo, $ws_id, $sel_year, $sel_month) {
    // همان منطق فرم چاپی کارگران: ماه جلالی، حقوق تاریخ‌محور و حذف مبلغ صفر.
    [$g_start, $g_end] = jalali_month_bounds((int)$sel_year, (int)$sel_month);
    $month_label = jalali_month_name($sel_month) . ' ' . $sel_year;
    $settings = [];
    foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $r) $settings[$r['setting_key']] = $r['setting_value'];

    $st = $pdo->prepare("SELECT u.id, u.full_name, COALESCE(MAX(wp_all.daily_salary),0) AS daily_salary, u.iban, u.account_name, u.account_number
        FROM users u JOIN workshop_personnel wp_current ON wp_current.user_id=u.id AND wp_current.workshop_id=? AND wp_current.status='active'
        LEFT JOIN workshop_personnel wp_all ON wp_all.user_id=u.id AND wp_all.status='active'
        WHERE u.is_karigar=1 GROUP BY u.id,u.full_name,u.iban,u.account_name,u.account_number ORDER BY u.full_name");
    $st->execute([$ws_id]);
    $headers = [t('ردیف'),t('نام و نام خانوادگی'),t('طلب ماه'),t('مانده طلب قبلی'),t('پاداش'),t('جریمه'),t('کسر مساعده/پرداخت'),t('قابل پرداخت'),t('شماره شبا'),t('نام صاحب حساب'),t('بانک مقصد')];
    $payment_rows=[]; $total=0;
    foreach ($st->fetchAll() as $p) {
        $base = worker_month_pay($pdo, $p['id'], 0, $g_start, $g_end, (float)$p['daily_salary']);
        $prev = compute_prev_balance($pdo, $p['id'], 0, 1, $g_start, (float)$p['daily_salary'], 0, $settings);
        $adv = advance_deduction_month($pdo, $p['id'], 0, $g_start, $g_end);
        $bp = $pdo->prepare("SELECT type, COALESCE(SUM(amount),0) total FROM bonuses_penalties WHERE user_id=? AND status='approved' AND date BETWEEN ? AND ? GROUP BY type");
        $bp->execute([$p['id'],$g_start,$g_end]); $bonus=0; $penalty=0;
        foreach ($bp->fetchAll() as $r) { if ($r['type']==='bonus') $bonus=(float)$r['total']; else $penalty=(float)$r['total']; }
        $payable=round($base+$prev+$bonus-$penalty-$adv);
        if ($payable<=0) continue;
        $total += $payable;
        $payment_rows[] = [
            'id'=>(int)$p['id'], 'name'=>$p['full_name'], 'monthly'=>$base, 'prev'=>$prev,
            'bonus'=>$bonus, 'penalty'=>$penalty, 'advance'=>$adv, 'payable'=>$payable,
            'iban'=>excel_worker_iban_display($p['iban'] ?? ''),
            'iban_key'=>excel_worker_iban_group_key($p['iban'] ?? '', $p['id']),
            'account_name'=>$p['account_name']?:'-', 'bank'=>$p['account_number']?:'-'
        ];
    }

    // CSV نیز باید مانند فرم چاپی یک ردیف برای هر شبا داشته باشد:
    // نام افراد داخل همان حساب زیر هم، شبا یک‌بار، و مبلغ‌ها کاملاً تجمیع‌شده.
    $iban_groups=[];
    foreach ($payment_rows as $payment) $iban_groups[$payment['iban_key']][] = $payment;
    $rows=[]; $num=0;
    foreach ($iban_groups as $members) {
        $num++;
        $names = implode("\n", array_map(function ($member) { return $member['name']; }, $members));
        $rows[] = [
            $num,
            $names,
            array_sum(array_column($members, 'monthly')),
            array_sum(array_column($members, 'prev')),
            array_sum(array_column($members, 'bonus')),
            array_sum(array_column($members, 'penalty')),
            array_sum(array_column($members, 'advance')),
            array_sum(array_column($members, 'payable')),
            $members[0]['iban'],
            implode(' / ', array_unique(array_filter(array_column($members, 'account_name')))),
            implode(' / ', array_unique(array_filter(array_column($members, 'bank')))),
        ];
    }
    $rows[]=['',t('جمع کل'),'','','','','',$total,'','',''];
    output_csv(t('لیست واریزی کارگران').'_'.$month_label.'.xlsx',$headers,$rows);
}

// نکته: تابع قدیمی generate_project_report_csv حذف شد — هیچ صفحه یا اسکریپتی آن را صدا نمی‌زد
// (خروجی «project_report» در api/download_excel.php هم به همین دلیل برداشته شد).
