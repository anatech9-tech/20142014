<?php
/**
 * پیگیری استراحت کارمندان — بازنویسی‌شده برای داخل سایت (نسخهٔ مخفی قبلی: rest_track.php?k=…)
 * ─────────────────────────────────────────────────────────────────────────────
 * • همهٔ کاربرانِ واردشده می‌توانند ببینند؛ شروع/پایان/حذف استراحت فقط برای مدیر
 *   (ادمین، مدیر کارگاه، ویراستار ساعت کاری، مدیر دفتر).
 * • هر کارمند علاوه بر ناهار (۶۰ دقیقه) روزانه ۶۰ دقیقه استراحت مجاز دارد.
 * • داده‌ها در یک‌بار خواندن برای کل بازه بارگذاری می‌شوند (بدون کوئری به‌ازای هر نفر/روز).
 */
$pageTitle = 'استراحت کارمندان';

const RT_ALLOW = 60;             // استراحت مجاز روزانه (دقیقه) — جدا از ناهار
const RT_LUNCH = 60;             // ناهار (دقیقه)
const RT_LUNCH_MIN_ONSITE = 300; // فقط اگر حضور حداقل ۵ ساعت بود، ناهار از «کار مفید» کم می‌شود

require_once __DIR__ . '/../includes/salary_calc.php'; // jalali_month_key / jalali_month_from_key

// ── جدول (اگر از نسخهٔ قدیمی هست، همان استفاده می‌شود) ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_breaks (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT(11) NOT NULL,
        workshop_id INT(11) NOT NULL,
        break_date DATE NOT NULL,
        break_no TINYINT NOT NULL DEFAULT 1,
        start_time DATETIME NULL,
        end_time DATETIME NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rt (user_id, workshop_id, break_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}
$rt_has_by = false;
try {
    $rt_has_by = function_exists('db_has_column') ? db_has_column($pdo, 'employee_breaks', 'created_by') : false;
    if (!$rt_has_by) { $pdo->exec("ALTER TABLE employee_breaks ADD COLUMN created_by INT(11) NULL"); $rt_has_by = true; }
} catch (Throwable $e) { $rt_has_by = false; }

// ── دسترسی و کارگاه ──
$rt_can_edit = is_strict_admin() || is_workshop_manager()
    || has_permission('manage_work_hours') || has_permission('office_manager')
    || access_mode('work_hours') === 'editor';
$rt_all_ws = can_see_all_workshops();
$rt_session_ws = (int)($_SESSION['workshop_id'] ?? 0);
$ws = $rt_all_ws ? (int)($_GET['ws'] ?? ($_POST['ws'] ?? $rt_session_ws)) : $rt_session_ws;
if ($ws <= 0) $ws = $rt_session_ws;

$today_g = date('Y-m-d');

// ═══ ابزارها ═══
function rt_hm($min) {
    $min = max(0, (int)round((float)$min));
    return intdiv($min, 60) . ':' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT);
}
function rt_url(array $over = []): string {
    $q = array_merge(['page' => 'rest_track'], $over);
    foreach ($q as $k => $v) if ($v === null || $v === '') unset($q[$k]);
    return 'index.php?' . http_build_query($q);
}
/** ورود/خروج/حضور یک روز از رکوردهای work_hours */
function rt_presence(array $rows, string $d, string $today): array {
    if (!$rows) return ['entry' => null, 'exit' => null, 'onsite' => 0, 'open' => false, 'records' => 0];
    $entry = null; $entryTs = null; $exitTs = null; $exitStr = null; $onsite = 0; $open = false;
    foreach ($rows as $r) {
        $sTs = strtotime($d . ' ' . $r['start_time']);
        if ($entry === null || $sTs < $entryTs) { $entry = $r['start_time']; $entryTs = $sTs; }
        if ($r['end_time'] === '00:00:00') {
            $open = true;
            if ($d === $today) $onsite += max(0, (time() - $sTs) / 60);
            continue;
        }
        $eTs = strtotime($d . ' ' . $r['end_time']);
        if ($eTs < $sTs) $eTs += 86400;
        $onsite += ($eTs - $sTs) / 60;
        if ($exitTs === null || $eTs > $exitTs) { $exitTs = $eTs; $exitStr = $r['end_time']; }
    }
    return ['entry' => $entry, 'exit' => $exitStr, 'onsite' => $onsite, 'open' => $open, 'records' => count($rows)];
}
/** جلسه‌های استراحت یک روز (لیست آمادهٔ نمایش) */
function rt_breaks(array $rows, string $d, string $today): array {
    $list = []; $total = 0; $open = 0;
    foreach ($rows as $b) {
        $running = ($b['end_time'] === null);
        // جلسهٔ ناتمامِ قدیمی (بیش از ۶ ساعت از شروعش گذشته) شمرده نمی‌شود؛ مدیر باید ویرایش/حذفش کند
        $stale = $running && (time() - strtotime((string)$b['start_time'])) > 6 * 3600;
        if ($stale) $dur = 0;
        else {
            $e = $b['end_time'] ?: date('Y-m-d H:i:s');
            $dur = max(0, (int)round((strtotime($e) - strtotime($b['start_time'])) / 60));
        }
        $total += $dur;
        if ($running && !$stale) $open++;
        $list[] = [
            'id' => (int)$b['id'], 'no' => (int)$b['break_no'],
            'start' => substr((string)$b['start_time'], 11, 5),
            'end' => $b['end_time'] ? substr((string)$b['end_time'], 11, 5) : ($stale ? 'ناتمام' : '…'),
            'start_ts' => strtotime((string)$b['start_time']),
            'end_raw' => $b['end_time'] ? substr((string)$b['end_time'], 11, 5) : '',
            'dur' => $dur, 'running' => $running && !$stale, 'stale' => $stale,
        ];
    }
    return ['list' => $list, 'total' => $total, 'open' => $open];
}
function rt_metrics(array $whRows, array $brRows, string $d, string $today): array {
    $p = rt_presence($whRows, $d, $today);
    $b = rt_breaks($brRows, $d, $today);
    $has = ($p['entry'] !== null);
    $onsite = $has ? $p['onsite'] : 0;
    // اگر کارمند برای ناهار خروج/ورود جدا زده، ناهار از قبل کسر شده است.
    $lunch = ($has && $p['records'] === 1 && $onsite >= RT_LUNCH_MIN_ONSITE) ? RT_LUNCH : 0;
    return [
        'entry' => $p['entry'], 'exit' => $p['exit'], 'open' => $p['open'],
        'onsite' => $onsite, 'lunch' => $lunch, 'breaks' => $b,
        'minus_allow' => $b['total'] - RT_ALLOW,
        'excess' => max(0, $b['total'] - RT_ALLOW),
        'left' => max(0, RT_ALLOW - $b['total']),
        'eff' => $has ? max(0, $onsite - $lunch - $b['total']) : null,
    ];
}
function rt_pick(array $m, string $metric): float {
    if ($metric === 'break') return (float)$m['breaks']['total'];
    if ($metric === 'onsite') return (float)$m['onsite'];
    return $m['eff'] === null ? 0.0 : (float)$m['eff'];
}
function rt_jday(string $d): string {
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    $j = gregorian_to_jalali($y, $m, $dd);
    return (string)$j[2];
}
function rt_prev_key(string $key): string {
    [$y, $m] = array_map('intval', explode('-', $key));
    if ($m === 1) { $m = 12; $y--; } else { $m--; }
    return $y . '-' . $m;
}
/** ساعت تایپ‌شده: 09:12 یا 0912 یا 912 (و ارقام فارسی) → 'HH:MM:00' یا null */
function rt_parse_time($v): ?string {
    $v = trim(strtr((string)$v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
    if ($v === '') return null;
    if (preg_match('/^(\d{1,2}):(\d{2})/', $v, $m)) { $h = (int)$m[1]; $i = (int)$m[2]; }
    elseif (preg_match('/^\d{3,4}$/', $v)) { $v = str_pad($v, 4, '0', STR_PAD_LEFT); $h = (int)substr($v, 0, 2); $i = (int)substr($v, 2, 2); }
    else return null;
    return ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) ? sprintf('%02d:%02d:00', $h, $i) : null;
}
/**
 * اعتبارسنجی یک جلسهٔ استراحت (ثبت دستی/ویرایش).
 * خروجی: [پیام خطا|null, شروع DATETIME, پایان DATETIME|null]
 * اگر پایان ≤ شروع باشد، پایان به روز بعد می‌رود (شیفت شب). سقف یک جلسه ۶ ساعت است تا اشتباه تایپی رد شود.
 */
function rt_check_session(PDO $pdo, int $ws, int $uid, string $d, ?string $s, ?string $e, int $excl, string $today, bool $allowOpen): array {
    if ($s === null) return ['ساعت شروع نامعتبر است', null, null];
    $startDT = $d . ' ' . $s; $startTs = strtotime($startDT);
    $endDT = null; $endTs = null;
    if ($e === null) {
        if (!$allowOpen) return ['ساعت پایان را وارد کنید', null, null];
    } else {
        $endTs = strtotime($d . ' ' . $e);
        if ($endTs <= $startTs) $endTs += 86400;
        if (($endTs - $startTs) > 6 * 3600) return ['مدت استراحت بیشتر از ۶ ساعت است؛ ساعت‌ها را بررسی کنید', null, null];
        $endDT = date('Y-m-d H:i:s', $endTs);
    }
    if ($startTs > time() + 60) return ['زمان شروع در آینده است', null, null];
    if ($endTs !== null && $endTs > time() + 60) return ['زمان پایان در آینده است', null, null];
    $newEnd = $endTs ?? time();
    $st = $pdo->prepare("SELECT break_date, start_time, end_time FROM employee_breaks WHERE user_id = ? AND workshop_id = ? AND id != ? AND break_date BETWEEN ? AND ?");
    $st->execute([$uid, $ws, $excl, date('Y-m-d', strtotime($d . ' -1 day')), date('Y-m-d', strtotime($d . ' +1 day'))]);
    foreach ($st->fetchAll() as $o) {
        $os = strtotime((string)$o['start_time']);
        if ($o['end_time'] !== null) $oe = strtotime((string)$o['end_time']);
        elseif ($o['break_date'] === $today) $oe = time();
        else continue;
        if ($startTs < $oe && $os < $newEnd) return ['با استراحت دیگری از همین نفر هم‌پوشانی دارد (' . substr((string)$o['start_time'], 11, 5) . ' تا ' . ($o['end_time'] ? substr((string)$o['end_time'], 11, 5) : 'اکنون') . ')', null, null];
    }
    return [null, date('Y-m-d H:i:s', $startTs), $endDT];
}
/** همهٔ کارمندان (غیرکارگر) فعال کارگاه */
function rt_all_employees(PDO $pdo, int $ws): array {
    $st = $pdo->prepare("SELECT u.id, u.full_name FROM users u
        JOIN workshop_personnel wp ON wp.user_id = u.id AND wp.workshop_id = ? AND wp.status = 'active'
        WHERE u.status = 'active' AND u.is_karigar = 0 AND u.username != 'yaser' ORDER BY u.full_name");
    $st->execute([$ws]);
    return $st->fetchAll();
}
/** شناسهٔ نیروهایی که مدیر از لیست پنهان کرده (به‌ازای هر کارگاه) */
function rt_hidden_ids(PDO $pdo, int $ws): array {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $st->execute(['rest_hidden_ws' . $ws]);
        return array_values(array_filter(array_map('intval', explode(',', (string)$st->fetchColumn()))));
    } catch (Throwable $e) { return []; }
}
/** بارگذاری یک‌جای دادهٔ بازه: [uid][date] => rows */
function rt_load(PDO $pdo, int $ws, string $from, string $to): array {
    $wh = []; $br = [];
    $st = $pdo->prepare("SELECT user_id, work_date, start_time, end_time FROM work_hours WHERE workshop_id = ? AND work_date BETWEEN ? AND ?");
    $st->execute([$ws, $from, $to]);
    foreach ($st->fetchAll() as $r) $wh[(int)$r['user_id']][$r['work_date']][] = $r;
    $st = $pdo->prepare("SELECT id, user_id, break_date, break_no, start_time, end_time FROM employee_breaks WHERE workshop_id = ? AND break_date BETWEEN ? AND ? ORDER BY id");
    $st->execute([$ws, $from, $to]);
    foreach ($st->fetchAll() as $r) $br[(int)$r['user_id']][$r['break_date']][] = $r;
    return [$wh, $br];
}
function rt_bars(array $labels, array $vals, string $color): string {
    $n = count($vals);
    if ($n === 0) return '<div class="text-muted" style="padding:16px;text-align:center">داده‌ای نیست</div>';
    $W = 780; $H = 210; $padL = 6; $padB = 30; $padT = 22;
    $max = max(array_merge($vals, [1]));
    $bw = ($W - 2 * $padL) / $n;
    $fs = $n > 18 ? 7.5 : 9.5;
    $skip = $n > 18 ? 2 : 1;
    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto">';
    $svg .= '<line x1="' . $padL . '" y1="' . ($H - $padB) . '" x2="' . ($W - $padL) . '" y2="' . ($H - $padB) . '" style="stroke:var(--border)" stroke-width="1"/>';
    for ($i = 0; $i < $n; $i++) {
        $v = (float)$vals[$i];
        $h = ($v / $max) * ($H - $padB - $padT);
        $x = $padL + $i * $bw + $bw * 0.14; $w = $bw * 0.72; $y = $H - $padB - $h;
        if ($v > 0) {
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($w, 1) . '" height="' . round($h, 1) . '" rx="3" fill="' . $color . '"/>';
            $svg .= '<text x="' . round($x + $w / 2, 1) . '" y="' . round($y - 4, 1) . '" font-size="' . $fs . '" text-anchor="middle" style="fill:var(--text)">' . (int)round($v) . '</text>';
        }
        if ($i % $skip === 0) $svg .= '<text x="' . round($x + $w / 2, 1) . '" y="' . ($H - $padB + 14) . '" font-size="' . $fs . '" text-anchor="middle" style="fill:var(--text-secondary)">' . htmlspecialchars((string)$labels[$i]) . '</text>';
    }
    return $svg . '</svg>';
}

// ═══ اکشن‌های POST (فقط مدیر) ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['rt_action'] ?? '';
    $d = to_gregorian($_POST['date'] ?? '') ?: $today_g;
    $back = ['view' => 'control', 'ws' => $rt_all_ws ? $ws : null, 'date' => (string)($_POST['date_jalali'] ?? $_POST['date'] ?? '')];
    if (!$rt_can_edit) { showToast('فقط مدیر می‌تواند استراحت ثبت کند', 'error'); redirect(rt_url($back)); }

    if ($act === 'save_list') {
        $shown = array_map('intval', (array)($_POST['shown'] ?? []));
        $hide = [];
        foreach (rt_all_employees($pdo, $ws) as $e) if (!in_array((int)$e['id'], $shown, true)) $hide[] = (int)$e['id'];
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute(['rest_hidden_ws' . $ws, implode(',', $hide)]);
        showToast('لیست نیروها ذخیره شد');
    } elseif ($act === 'break_add') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $dg = to_gregorian($_POST['entry_date'] ?? '');
        $ok = $pdo->prepare("SELECT 1 FROM users u JOIN workshop_personnel wp ON wp.user_id = u.id AND wp.workshop_id = ? AND wp.status = 'active' WHERE u.id = ? AND u.status = 'active'");
        $ok->execute([$ws, $uid]);
        if (!$dg || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dg)) showToast('تاریخ نامعتبر است', 'error');
        elseif ($dg > $today_g) showToast('ثبت برای روز آینده ممکن نیست', 'error');
        elseif (!$ok->fetchColumn()) showToast('این نفر عضو فعال این کارگاه نیست', 'error');
        else {
            [$err, $sdt, $edt] = rt_check_session($pdo, $ws, $uid, $dg, rt_parse_time($_POST['start_time'] ?? ''), rt_parse_time($_POST['end_time'] ?? ''), 0, $today_g, false);
            if ($err) showToast($err, 'error');
            else {
                $st = $pdo->prepare("SELECT COALESCE(MAX(break_no),0) + 1 FROM employee_breaks WHERE user_id = ? AND workshop_id = ? AND break_date = ?");
                $st->execute([$uid, $ws, $dg]);
                $no = (int)$st->fetchColumn();
                if ($rt_has_by) $pdo->prepare("INSERT INTO employee_breaks (user_id, workshop_id, break_date, break_no, start_time, end_time, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$uid, $ws, $dg, $no, $sdt, $edt, (int)$_SESSION['user_id']]);
                else $pdo->prepare("INSERT INTO employee_breaks (user_id, workshop_id, break_date, break_no, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$uid, $ws, $dg, $no, $sdt, $edt]);
                showToast('استراحت دستی ثبت شد');
                $back['date'] = to_jalali($dg);
            }
        }
    } elseif ($act === 'break_edit') {
        $st = $pdo->prepare("SELECT * FROM employee_breaks WHERE id = ? AND workshop_id = ?");
        $st->execute([(int)($_POST['session_id'] ?? 0), $ws]);
        $row = $st->fetch();
        if (!$row) showToast('جلسه پیدا نشد', 'error');
        else {
            $bd = (string)$row['break_date'];
            $allowOpen = ($row['end_time'] === null && (time() - strtotime((string)$row['start_time'])) <= 6 * 3600);
            [$err, $sdt, $edt] = rt_check_session($pdo, $ws, (int)$row['user_id'], $bd, rt_parse_time($_POST['start_time'] ?? ''), rt_parse_time($_POST['end_time'] ?? ''), (int)$row['id'], $today_g, $allowOpen);
            if ($err) showToast($err, 'error');
            else {
                $pdo->prepare("UPDATE employee_breaks SET start_time = ?, end_time = ? WHERE id = ? AND workshop_id = ?")->execute([$sdt, $edt, (int)$row['id'], $ws]);
                showToast('ساعت استراحت ویرایش شد');
                $back['date'] = to_jalali($bd);
            }
        }
    } elseif ($act === 'break_start') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $ok = $pdo->prepare("SELECT 1 FROM users u JOIN workshop_personnel wp ON wp.user_id = u.id AND wp.workshop_id = ? AND wp.status = 'active' WHERE u.id = ? AND u.status = 'active'");
        $ok->execute([$ws, $uid]);
        if ($d !== $today_g) {
            showToast('شروع استراحت فقط برای امروز ممکن است', 'error');
        } elseif (!$ok->fetchColumn()) {
            showToast('این نفر عضو فعال این کارگاه نیست', 'error');
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM employee_breaks WHERE user_id = ? AND workshop_id = ? AND end_time IS NULL AND start_time >= ?");
            $st->execute([$uid, $ws, date('Y-m-d H:i:s', time() - 6 * 3600)]);
            if ($st->fetchColumn()) {
                showToast('این نفر یک استراحت باز دارد؛ اول پایان بزنید', 'error');
            } else {
                $st = $pdo->prepare("SELECT COALESCE(MAX(break_no),0) + 1 FROM employee_breaks WHERE user_id = ? AND workshop_id = ? AND break_date = ?");
                $st->execute([$uid, $ws, $d]);
                $no = (int)$st->fetchColumn();
                if ($rt_has_by) $pdo->prepare("INSERT INTO employee_breaks (user_id, workshop_id, break_date, break_no, start_time, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$uid, $ws, $d, $no, date('Y-m-d H:i:s'), (int)$_SESSION['user_id']]);
                else $pdo->prepare("INSERT INTO employee_breaks (user_id, workshop_id, break_date, break_no, start_time) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$uid, $ws, $d, $no, date('Y-m-d H:i:s')]);
                showToast('شروع استراحت ثبت شد');
            }
        }
    } elseif ($act === 'break_stop') {
        $pdo->prepare("UPDATE employee_breaks SET end_time = ? WHERE id = ? AND workshop_id = ? AND end_time IS NULL AND start_time >= ?")
            ->execute([date('Y-m-d H:i:s'), (int)($_POST['session_id'] ?? 0), $ws, date('Y-m-d H:i:s', time() - 6 * 3600)]);
        showToast('پایان استراحت ثبت شد');
    } elseif ($act === 'break_del') {
        $pdo->prepare("DELETE FROM employee_breaks WHERE id = ? AND workshop_id = ?")->execute([(int)($_POST['session_id'] ?? 0), $ws]);
        showToast('جلسهٔ استراحت حذف شد');
    }
    redirect(rt_url($back));
}

// ═══ پارامترهای نمایش ═══
$view = in_array($_GET['view'] ?? '', ['control', 'day', 'month', 'charts', 'compare'], true) ? $_GET['view'] : 'control';
$d_greg = to_gregorian($_GET['date'] ?? '') ?: $today_g;
$d_jal = to_jalali($d_greg);
$cur_mkey = jalali_month_key($today_g);
$mkey = preg_match('/^(\d{4})-(\d{1,2})$/', (string)($_GET['month'] ?? ''), $mm) ? ((int)$mm[1] . '-' . (int)$mm[2]) : $cur_mkey;
$mi = jalali_month_from_key($mkey);
$m_start = $mi['start']; $m_end = $mi['end']; $m_label = $mi['label'];
[$sel_jy, $sel_jm] = [(int)$mi['jy'], (int)$mi['jm']];
$jy_now = (int)explode('-', $cur_mkey)[0];
$person = (int)($_GET['person'] ?? 0);
$metric = in_array($_GET['metric'] ?? '', ['eff', 'onsite'], true) ? $_GET['metric'] : 'break';
$metric_label = ['break' => 'استراحت (دقیقه)', 'eff' => 'کار مفید (دقیقه)', 'onsite' => 'حضور در کارگاه (دقیقه)'][$metric];

$workshops = [];
if ($rt_all_ws) { try { $workshops = $pdo->query("SELECT id, name FROM workshops WHERE status = 'active' ORDER BY id")->fetchAll(); } catch (Throwable $e) {} }

$all_employees = rt_all_employees($pdo, $ws);
$hidden_ids = rt_hidden_ids($pdo, $ws);
$employees = array_values(array_filter($all_employees, fn($e) => !in_array((int)$e['id'], $hidden_ids, true)));

// ═══ محاسبه ═══
$day_rows = [];
if (in_array($view, ['control', 'day'], true)) {
    [$WH, $BR] = rt_load($pdo, $ws, $d_greg, $d_greg);
    foreach ($employees as $e) {
        $uid = (int)$e['id'];
        $day_rows[$uid] = ['name' => $e['full_name'], 'm' => rt_metrics($WH[$uid][$d_greg] ?? [], $BR[$uid][$d_greg] ?? [], $d_greg, $today_g)];
    }
}

$month_rows = []; $chart_days = []; $chart_months = [];
if (in_array($view, ['month', 'charts', 'compare'], true)) {
    $range_from = $m_start;
    if ($view === 'charts') { // ۶ ماه اخیر هم در همین یک بارگذاری
        $keys = []; $kk = $cur_mkey;
        for ($i = 0; $i < 6; $i++) { $keys[] = $kk; $kk = rt_prev_key($kk); }
        $keys = array_reverse($keys);
        $range_from = min($m_start, jalali_month_from_key($keys[0])['start']);
        $range_to = max($m_end, jalali_month_from_key($keys[5])['end']);
    } else $range_to = $m_end;
    [$WH, $BR] = rt_load($pdo, $ws, $range_from, $range_to);

    $emps = array_values(array_filter($employees, fn($e) => !$person || (int)$e['id'] === $person));

    // ماه انتخابی
    $dates = [];
    foreach ($emps as $e) {
        $uid = (int)$e['id'];
        foreach (array_keys($WH[$uid] ?? []) as $dd) if ($dd >= $m_start && $dd <= $m_end) $dates[$dd] = true;
        foreach (array_keys($BR[$uid] ?? []) as $dd) if ($dd >= $m_start && $dd <= $m_end) $dates[$dd] = true;
    }
    $dates = array_keys($dates); sort($dates);
    $per_day = [];
    foreach ($emps as $e) {
        $uid = (int)$e['id'];
        $acc = ['days' => 0, 'onsite' => 0, 'brk' => 0, 'excess' => 0, 'eff' => 0, 'over_days' => 0];
        foreach ($dates as $dd) {
            $m = rt_metrics($WH[$uid][$dd] ?? [], $BR[$uid][$dd] ?? [], $dd, $today_g);
            if ($m['entry'] !== null) {
                $acc['days']++; $acc['onsite'] += $m['onsite']; $acc['brk'] += $m['breaks']['total'];
                $acc['excess'] += $m['excess']; $acc['eff'] += $m['eff'];
                if ($m['breaks']['total'] > RT_ALLOW) $acc['over_days']++;
            }
            if ($m['entry'] !== null || $m['breaks']['total'] > 0) $per_day[$dd] = ($per_day[$dd] ?? 0) + rt_pick($m, $metric);
        }
        $month_rows[$uid] = ['name' => $e['full_name'], 'a' => $acc];
    }
    ksort($per_day);
    foreach ($per_day as $dd => $v) $chart_days[] = ['label' => rt_jday($dd), 'val' => (int)round($v)];

    if ($view === 'charts') {
        foreach ($keys as $mk) {
            $x = jalali_month_from_key($mk); $tot = 0;
            foreach ($emps as $e) {
                $uid = (int)$e['id'];
                $ds = array_unique(array_merge(array_keys($WH[$uid] ?? []), array_keys($BR[$uid] ?? [])));
                foreach ($ds as $dd) {
                    if ($dd < $x['start'] || $dd > $x['end']) continue;
                    $m = rt_metrics($WH[$uid][$dd] ?? [], $BR[$uid][$dd] ?? [], $dd, $today_g);
                    if ($m['entry'] === null && $m['breaks']['total'] <= 0) continue;
                    $tot += rt_pick($m, $metric);
                }
            }
            $chart_months[] = ['label' => jalali_month_name((int)$x['jm']), 'val' => (int)round($tot)];
        }
    }
}

include 'includes/header.php';

$tabs = ['control' => '🎛 امروز / استارت و استپ', 'day' => '📅 گزارش روزانه', 'month' => '🗓 گزارش ماهانه', 'charts' => '📊 نمودارها', 'compare' => '⚖ مقایسه'];
$keep = ['ws' => $rt_all_ws ? $ws : null];
?>
<style>
.rt-num { direction:ltr; text-align:center; white-space:nowrap; font-variant-numeric:tabular-nums; }
.rt-sess { display:inline-flex; align-items:center; gap:6px; background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:4px 8px; margin:2px 0; font-size:11.5px; }
.rt-sess.run { background:rgba(239,68,68,.10); border-color:rgba(239,68,68,.35); }
.rt-sess.stale { background:rgba(245,158,11,.12); border-color:rgba(245,158,11,.4); }
.rt-bar { height:8px; background:var(--border); border-radius:5px; overflow:hidden; min-width:90px; }
.rt-bar > div { height:100%; border-radius:5px; }
.rt-filter { display:flex; gap:8px; flex-wrap:wrap; align-items:center; font-size:13px; }
.rt-filter label { color:var(--text-secondary); }
.rt-tag { display:inline-block; padding:2px 9px; border-radius:10px; font-size:11px; white-space:nowrap; }
.rt-ok { background:rgba(34,197,94,.14); color:#15803d; } .rt-over { background:rgba(245,158,11,.18); color:#b45309; } .rt-run { background:rgba(239,68,68,.14); color:#dc2626; }
[data-theme="dark"] .rt-ok { color:#4ade80; } [data-theme="dark"] .rt-over { color:#fbbf24; } [data-theme="dark"] .rt-run { color:#f87171; }
.rt-me td { background:rgba(99,102,241,.07); }
.rt-mini { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
.rt-mini .stat { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); padding:10px 16px; min-width:130px; }
.rt-mini .stat b { display:block; font-size:18px; margin-top:2px; }
</style>

<div class="tabs mb-4 animate-in">
    <?php foreach ($tabs as $v => $label): ?>
    <a class="tab <?= $view === $v ? 'active' : '' ?>" href="<?= rt_url(array_merge($keep, ['view' => $v, 'date' => in_array($v, ['control', 'day'], true) ? $d_jal : null, 'month' => in_array($v, ['month', 'charts', 'compare'], true) ? $mkey : null])) ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<div class="card mb-4 animate-in">
    <form method="GET" class="rt-filter">
        <input type="hidden" name="page" value="rest_track">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
        <?php if ($rt_all_ws && count($workshops) > 1): ?>
        <label>کارگاه:</label>
        <select name="ws" class="form-control" style="width:170px" onchange="this.form.submit()">
            <?php foreach ($workshops as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $ws === (int)$w['id'] ? 'selected' : '' ?>><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if (in_array($view, ['control', 'day'], true)): ?>
        <label>روز:</label>
        <input type="text" name="date" class="form-control jalali-date" value="<?= $d_jal ?>" style="width:125px" placeholder="1405/07/07">
        <a class="btn btn-ghost btn-sm" href="<?= rt_url(array_merge($keep, ['view' => $view, 'date' => to_jalali(date('Y-m-d', strtotime($d_greg . ' -1 day')))])) ?>">◀ روز قبل</a>
        <a class="btn btn-ghost btn-sm" href="<?= rt_url(array_merge($keep, ['view' => $view, 'date' => to_jalali(date('Y-m-d', strtotime($d_greg . ' +1 day')))])) ?>">روز بعد ▶</a>
        <a class="btn btn-ghost btn-sm" href="<?= rt_url(array_merge($keep, ['view' => $view])) ?>">امروز</a>
        <?php else: ?>
        <label>ماه:</label>
        <select name="month" class="form-control" style="width:200px" onchange="this.form.submit()">
            <?php for ($yy = $jy_now; $yy >= $jy_now - 2; $yy--): for ($mo = 12; $mo >= 1; $mo--):
                if ($yy === $jy_now && $mo > (int)explode('-', $cur_mkey)[1]) continue; ?>
            <option value="<?= $yy . '-' . $mo ?>" <?= ($yy === $sel_jy && $mo === $sel_jm) ? 'selected' : '' ?>><?= jalali_month_name($mo) . ' ' . $yy ?></option>
            <?php endfor; endfor; ?>
        </select>
        <?php endif; ?>
        <?php if (in_array($view, ['month', 'charts', 'compare'], true)): ?>
        <label>نفر:</label>
        <select name="person" class="form-control" style="width:170px" onchange="this.form.submit()">
            <option value="0">همهٔ کارمندان</option>
            <?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>" <?= $person === (int)$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['full_name']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($view === 'charts'): ?>
        <label>شاخص:</label>
        <select name="metric" class="form-control" style="width:130px" onchange="this.form.submit()">
            <option value="break" <?= $metric === 'break' ? 'selected' : '' ?>>استراحت</option>
            <option value="eff" <?= $metric === 'eff' ? 'selected' : '' ?>>کار مفید</option>
            <option value="onsite" <?= $metric === 'onsite' ? 'selected' : '' ?>>حضور</option>
        </select>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm" type="submit">نمایش</button>
    </form>
    <div class="text-muted" style="font-size:11.5px;margin-top:8px">
        هر کارمند علاوه بر ناهار (۱ ساعت)، روزانه <b><?= RT_ALLOW ?> دقیقه</b> استراحت مجاز دارد. «کار مفید» = ساعات حضور − ناهار − استراحت‌ها.
        <?php if (!$rt_can_edit): ?> — ثبت شروع/پایان استراحت فقط با مدیر است.<?php endif; ?>
    </div>
</div>

<?php
// فرم کوچک اکشن (POST)
$rt_form = function (string $action, array $fields, string $btn, string $cls, string $confirm = '') use ($ws, $d_jal): string {
    $h = '<form method="POST" style="display:inline"' . ($confirm ? ' onsubmit="return confirm(\'' . $confirm . '\')"' : '') . '>' . get_csrf_field()
       . '<input type="hidden" name="rt_action" value="' . $action . '">'
       . '<input type="hidden" name="ws" value="' . $ws . '"><input type="hidden" name="date" value="' . $d_jal . '"><input type="hidden" name="date_jalali" value="' . $d_jal . '">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . $k . '" value="' . htmlspecialchars((string)$v) . '">';
    return $h . '<button type="submit" class="btn ' . $cls . '">' . $btn . '</button></form>';
};
?>

<?php if ($rt_can_edit && $view === 'control'): ?>
<details class="card mb-4 animate-in">
    <summary style="cursor:pointer;font-weight:700;font-size:13px">👥 انتخاب نیروهای نمایش‌داده‌شده در لیست <span class="text-muted" style="font-weight:400;font-size:11.5px">(<?= count($employees) ?> از <?= count($all_employees) ?> نفر نمایش داده می‌شود)</span></summary>
    <form method="POST" style="margin-top:10px">
        <?= get_csrf_field() ?>
        <input type="hidden" name="rt_action" value="save_list">
        <input type="hidden" name="ws" value="<?= $ws ?>">
        <input type="hidden" name="date_jalali" value="<?= $d_jal ?>">
        <div style="display:flex;gap:8px;margin-bottom:8px">
            <button type="button" class="btn btn-ghost btn-sm" onclick="document.querySelectorAll('.rt-pick').forEach(c=>c.checked=true)">همه</button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="document.querySelectorAll('.rt-pick').forEach(c=>c.checked=false)">هیچ‌کدام</button>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:6px">
            <?php foreach ($all_employees as $e): ?>
            <label style="display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px">
                <input type="checkbox" class="rt-pick" name="shown[]" value="<?= (int)$e['id'] ?>" <?= in_array((int)$e['id'], $hidden_ids, true) ? '' : 'checked' ?>>
                <span><?= htmlspecialchars($e['full_name']) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="text-muted" style="font-size:11.5px;margin:8px 0">نفرات بدون تیک در لیست و گزارش‌ها و نمودارهای این صفحه نمایش داده نمی‌شوند (اطلاعاتشان پاک نمی‌شود). نفر جدید به‌صورت خودکار نمایش داده می‌شود.</div>
        <button type="submit" class="btn btn-primary btn-sm">ذخیرهٔ لیست</button>
    </form>
</details>
<?php endif; ?>

<?php if ($view === 'control'):
    $sum_brk = 0; $sum_eff = 0; $on_break = 0;
    foreach ($day_rows as $r) { $sum_brk += $r['m']['breaks']['total']; if ($r['m']['eff'] !== null) $sum_eff += $r['m']['eff']; if ($r['m']['breaks']['open']) $on_break++; }
    $is_today = ($d_greg === $today_g);
?>
<div class="rt-mini animate-in">
    <div class="stat"><span class="text-muted" style="font-size:11px">روز</span><b><?= $d_jal ?></b></div>
    <div class="stat"><span class="text-muted" style="font-size:11px">اکنون در استراحت</span><b><?= $on_break ?> نفر</b></div>
    <div class="stat"><span class="text-muted" style="font-size:11px">مجموع استراحت</span><b><?= rt_hm($sum_brk) ?></b></div>
    <div class="stat"><span class="text-muted" style="font-size:11px">کار مفید کل</span><b><?= rt_hm($sum_eff) ?></b></div>
</div>
<?php if ($rt_can_edit): ?>
<div class="mb-3 animate-in"><button type="button" class="btn btn-primary btn-sm" onclick="openBreakAdd(0)">✍ ثبت دستی ساعت استراحت</button>
    <span class="text-muted" style="font-size:11.5px">— اگر شروع را نزده‌اید، بعداً ساعت‌ها را (مثلاً ۰۹۱۲ تا ۱۲۵۴) دستی وارد کنید؛ برای روزهای قبل هم ممکن است.</span></div>

<!-- ثبت دستی -->
<div id="breakAddModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:420px">
        <div class="modal-header"><h3 class="modal-title">✍ ثبت دستی استراحت</h3><button type="button" onclick="closeModal('breakAddModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="rt_action" value="break_add">
            <input type="hidden" name="ws" value="<?= $ws ?>">
            <input type="hidden" name="date" value="<?= $d_jal ?>">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">کارمند *</label>
                    <select name="user_id" id="ba_user" class="form-control" required>
                        <option value="">— انتخاب —</option>
                        <?php foreach ($employees as $e): ?><option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['full_name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label class="form-label">تاریخ *</label><input type="text" name="entry_date" id="ba_date" class="form-control jalali-date" value="<?= $d_jal ?>" required></div>
                <div class="form-group"><label class="form-label">ساعت شروع *</label><input type="time" name="start_time" class="form-control" required></div>
                <div class="form-group"><label class="form-label">ساعت پایان *</label><input type="time" name="end_time" class="form-control" required></div>
                <div class="text-muted" style="font-size:11px">ساعت را می‌توانید تایپ کنید: ۰۹۱۲ = ۰۹:۱۲. اگر پایان از شروع کوچک‌تر باشد، روز بعد حساب می‌شود.</div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">ثبت</button></div>
        </form>
    </div>
</div>

<!-- ویرایش -->
<div id="breakEditModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:400px">
        <div class="modal-header"><h3 class="modal-title">✎ ویرایش ساعت استراحت</h3><button type="button" onclick="closeModal('breakEditModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?>
            <input type="hidden" name="rt_action" value="break_edit">
            <input type="hidden" name="ws" value="<?= $ws ?>">
            <input type="hidden" name="date" value="<?= $d_jal ?>">
            <input type="hidden" name="session_id" id="be_id">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">ساعت شروع *</label><input type="time" name="start_time" id="be_start" class="form-control" required></div>
                <div class="form-group"><label class="form-label">ساعت پایان <span id="be_opt" class="text-muted" style="font-size:11px"></span></label><input type="time" name="end_time" id="be_end" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary">ذخیره</button></div>
        </form>
    </div>
</div>
<script>
function openBreakAdd(uid){
    document.getElementById('ba_user').value = uid ? String(uid) : '';
    openModal('breakAddModal');
}
function openBreakEdit(id, start, end, running){
    document.getElementById('be_id').value = id;
    document.getElementById('be_start').value = start;
    document.getElementById('be_end').value = end;
    document.getElementById('be_opt').textContent = running ? '(خالی = هنوز در استراحت)' : '*';
    document.getElementById('be_end').required = !running;
    openModal('breakEditModal');
}
</script>
<?php endif; ?>
<div class="card animate-in">
    <div class="table-container"><table>
        <thead><tr><th>کارمند</th><th>جلسات استراحت</th><th class="rt-num">مجموع</th><th style="min-width:120px">از <?= RT_ALLOW ?> دقیقه</th><th class="rt-num">وضعیت</th><?php if ($rt_can_edit): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$day_rows): ?><tr><td colspan="6" class="text-muted" style="text-align:center;padding:26px">کارمندی در این کارگاه نیست</td></tr><?php endif; ?>
        <?php foreach ($day_rows as $uid => $row): $m = $row['m']; $tot = $m['breaks']['total']; $pct = min(100, round($tot / RT_ALLOW * 100)); ?>
        <tr class="<?= $uid === (int)$_SESSION['user_id'] ? 'rt-me' : '' ?>">
            <td><b><?= htmlspecialchars($row['name']) ?></b><?php if ($m['open']): ?> <span class="text-muted" style="font-size:10px">در کارگاه</span><?php endif; ?></td>
            <td>
                <?php if (!$m['breaks']['list']): ?><span class="text-muted">—</span>
                <?php else: foreach ($m['breaks']['list'] as $s): ?>
                    <span class="rt-sess <?= $s['running'] ? 'run' : ($s['stale'] ? 'stale' : '') ?>">
                        #<?= $s['no'] ?> <?= $s['start'] ?> → <?= $s['end'] ?>
                        <b><?php if ($s['running']): ?><span class="rt-live" data-start="<?= (int)$s['start_ts'] ?>"><?= $s['dur'] ?></span><?php else: ?><?= $s['dur'] ?><?php endif; ?> دقیقه</b>
                        <?php if ($rt_can_edit): ?><button type="button" class="btn btn-ghost btn-sm" title="ویرایش ساعت" onclick="openBreakEdit(<?= $s['id'] ?>, '<?= $s['start'] ?>', '<?= $s['end_raw'] ?>', <?= $s['running'] ? 1 : 0 ?>)">✎</button><?php endif; ?>
                        <?php if ($rt_can_edit && $s['running']): ?>
                            <?= $rt_form('break_stop', ['session_id' => $s['id']], 'پایان', 'btn-danger btn-sm') ?>
                        <?php elseif ($rt_can_edit && !$s['running']): ?>
                            <?= $rt_form('break_del', ['session_id' => $s['id']], '✕', 'btn-ghost btn-sm', 'این جلسه حذف شود؟') ?>
                        <?php endif; ?>
                    </span>
                <?php endforeach; endif; ?>
            </td>
            <td class="rt-num"><b><?= rt_hm($tot) ?></b></td>
            <td><div class="rt-bar"><div style="width:<?= $pct ?>%;background:<?= $tot > RT_ALLOW ? 'var(--danger)' : ($pct >= 80 ? 'var(--warning)' : 'var(--success)') ?>"></div></div>
                <span class="text-muted" style="font-size:10.5px"><?= $tot > RT_ALLOW ? 'مازاد ' . $m['excess'] . ' دقیقه' : $m['left'] . ' دقیقه مانده' ?></span></td>
            <td class="rt-num">
                <?php if ($m['breaks']['open']): ?><span class="rt-tag rt-run">در استراحت…</span>
                <?php elseif ($tot == 0): ?><span class="rt-tag rt-ok">بدون استراحت</span>
                <?php elseif ($tot > RT_ALLOW): ?><span class="rt-tag rt-over">مازاد +<?= $m['excess'] ?></span>
                <?php else: ?><span class="rt-tag rt-ok">در حد مجاز</span><?php endif; ?>
            </td>
            <?php if ($rt_can_edit): ?>
            <td style="white-space:nowrap"><?php if (!$m['breaks']['open'] && $is_today): ?>
                <?= $rt_form('break_start', ['user_id' => $uid], '▶ شروع استراحت', 'btn-success btn-sm') ?>
            <?php endif; ?>
                <button type="button" class="btn btn-ghost btn-sm" onclick="openBreakAdd(<?= $uid ?>)" title="ثبت دستی ساعت استراحت">✍ دستی</button></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<script>
(function(){
    function tick(){document.querySelectorAll('.rt-live').forEach(function(el){
        var s=parseInt(el.getAttribute('data-start'),10); if(!s)return;
        el.textContent=Math.max(0,Math.round((Date.now()/1000-s)/60));
    });}
    setInterval(tick,20000); tick();
    <?php if (!$rt_can_edit && $is_today): ?>setTimeout(function(){ if(!document.querySelector('input:focus,select:focus')) location.reload(); },60000);<?php endif; ?>
})();
</script>

<?php elseif ($view === 'day'): $T = ['onsite' => 0, 'brk' => 0, 'eff' => 0]; ?>
<div class="card animate-in">
    <div class="text-muted" style="font-size:12px;margin-bottom:8px">روز <?= $d_jal ?> — ورود و خروج از صفحهٔ «ساعت کاری» خوانده می‌شود.</div>
    <div class="table-container"><table>
        <thead><tr><th>کارمند</th><th class="rt-num">ورود</th><th class="rt-num">خروج</th><th class="rt-num">حضور</th><th>ساعات استراحت</th><th class="rt-num">مجموع استراحت</th><th class="rt-num">نسبت به <?= RT_ALLOW ?></th><th class="rt-num">مازاد</th><th class="rt-num">کار مفید</th></tr></thead>
        <tbody>
        <?php foreach ($day_rows as $uid => $row): $m = $row['m'];
            $T['onsite'] += $m['onsite']; $T['brk'] += $m['breaks']['total']; if ($m['eff'] !== null) $T['eff'] += $m['eff']; ?>
        <tr class="<?= $uid === (int)$_SESSION['user_id'] ? 'rt-me' : '' ?>">
            <td><b><?= htmlspecialchars($row['name']) ?></b></td>
            <td class="rt-num"><?= $m['entry'] ? substr($m['entry'], 0, 5) : '—' ?></td>
            <td class="rt-num"><?= $m['exit'] ? substr($m['exit'], 0, 5) : ($m['open'] ? 'در کارگاه' : '—') ?></td>
            <td class="rt-num"><?= $m['entry'] ? rt_hm($m['onsite']) : '—' ?></td>
            <td style="font-size:12px"><?= $m['breaks']['list'] ? htmlspecialchars(implode(' ، ', array_map(fn($s) => $s['start'] . '→' . $s['end'], $m['breaks']['list']))) : '<span class="text-muted">—</span>' ?></td>
            <td class="rt-num"><b><?= rt_hm($m['breaks']['total']) ?></b></td>
            <td class="rt-num" style="color:<?= $m['minus_allow'] > 0 ? 'var(--warning)' : 'var(--success)' ?>"><?= $m['minus_allow'] > 0 ? '+' : '' ?><?= $m['minus_allow'] ?> دقیقه</td>
            <td class="rt-num"><?= $m['excess'] > 0 ? '<b style="color:var(--danger)">' . $m['excess'] . ' دقیقه</b>' : '<span class="text-muted">—</span>' ?></td>
            <td class="rt-num"><b><?= $m['eff'] === null ? '—' : rt_hm($m['eff']) ?></b></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="font-weight:700"><td>مجموع</td><td></td><td></td><td class="rt-num"><?= rt_hm($T['onsite']) ?></td><td></td><td class="rt-num"><?= rt_hm($T['brk']) ?></td><td></td><td></td><td class="rt-num"><?= rt_hm($T['eff']) ?></td></tr></tfoot>
    </table></div>
</div>

<?php elseif ($view === 'month'): ?>
<div class="card animate-in">
    <div class="text-muted" style="font-size:12px;margin-bottom:8px">ماه <?= $m_label ?> — جمع روزهای کاری هر کارمند</div>
    <div class="table-container"><table>
        <thead><tr><th>کارمند</th><th class="rt-num">روز کاری</th><th class="rt-num">حضور کل</th><th class="rt-num">استراحت کل</th><th class="rt-num">استراحت − (<?= RT_ALLOW ?>×روز)</th><th class="rt-num">روزهای مازاد</th><th class="rt-num">مازاد کل</th><th class="rt-num">کار مفید کل</th></tr></thead>
        <tbody>
        <?php if (!$month_rows): ?><tr><td colspan="8" class="text-muted" style="text-align:center;padding:26px">داده‌ای برای این ماه نیست</td></tr>
        <?php else: foreach ($month_rows as $uid => $row): $a = $row['a']; $diff = $a['brk'] - RT_ALLOW * $a['days']; ?>
        <tr class="<?= $uid === (int)$_SESSION['user_id'] ? 'rt-me' : '' ?>">
            <td><b><?= htmlspecialchars($row['name']) ?></b></td>
            <td class="rt-num"><?= $a['days'] ?></td>
            <td class="rt-num"><?= rt_hm($a['onsite']) ?></td>
            <td class="rt-num"><b><?= rt_hm($a['brk']) ?></b></td>
            <td class="rt-num" style="color:<?= $diff > 0 ? 'var(--warning)' : 'var(--success)' ?>"><?= $diff > 0 ? '+' : '' ?><?= $diff ?> دقیقه</td>
            <td class="rt-num"><?= $a['over_days'] ?: '—' ?></td>
            <td class="rt-num"><?= $a['excess'] > 0 ? '<b style="color:var(--danger)">' . $a['excess'] . ' دقیقه</b>' : '—' ?></td>
            <td class="rt-num"><b><?= rt_hm($a['eff']) ?></b></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>

<?php elseif ($view === 'charts'): ?>
<div class="card mb-4 animate-in">
    <b>نمودار روزانه — <?= $m_label ?></b> <span class="text-muted" style="font-size:11px">(شاخص: <?= $metric_label ?>)</span>
    <?= rt_bars(array_column($chart_days, 'label'), array_column($chart_days, 'val'), '#f59e0b') ?>
</div>
<div class="card animate-in">
    <b>نمودار ماهانه — ۶ ماه اخیر</b> <span class="text-muted" style="font-size:11px">(شاخص: <?= $metric_label ?>)</span>
    <?= rt_bars(array_column($chart_months, 'label'), array_column($chart_months, 'val'), '#6366f1') ?>
</div>

<?php elseif ($view === 'compare'):
    $ranked = $month_rows;
    uasort($ranked, fn($a, $b) => $b['a']['eff'] <=> $a['a']['eff']);
    $maxOn = max(array_merge(array_map(fn($r) => $r['a']['onsite'], $month_rows), [1]));
    $rank = 0; ?>
<div class="card mb-4 animate-in">
    <b>مقایسهٔ کارمندان — <?= $m_label ?></b> <span class="text-muted" style="font-size:11px">(میله‌ها نسبت به بیشترین حضور)</span>
    <div class="table-container" style="margin-top:8px"><table>
        <thead><tr><th>کارمند</th><th style="width:26%">حضور کل</th><th style="width:20%">استراحت کل</th><th class="rt-num">کار مفید</th><th class="rt-num">مازاد</th><th class="rt-num">رتبه</th></tr></thead>
        <tbody>
        <?php if (!$month_rows): ?><tr><td colspan="6" class="text-muted" style="text-align:center;padding:26px">داده‌ای برای این ماه نیست</td></tr>
        <?php else: foreach ($ranked as $uid => $row): $a = $row['a']; $rank++; ?>
        <tr class="<?= $uid === (int)$_SESSION['user_id'] ? 'rt-me' : '' ?>">
            <td><b><?= htmlspecialchars($row['name']) ?></b></td>
            <td><div class="rt-bar"><div style="width:<?= round($a['onsite'] / $maxOn * 100) ?>%;background:var(--primary)"></div></div><span class="text-muted" style="font-size:11px"><?= rt_hm($a['onsite']) ?></span></td>
            <td><div class="rt-bar"><div style="width:<?= round($a['brk'] / $maxOn * 100) ?>%;background:var(--warning)"></div></div><span class="text-muted" style="font-size:11px"><?= rt_hm($a['brk']) ?></span></td>
            <td class="rt-num"><b><?= rt_hm($a['eff']) ?></b></td>
            <td class="rt-num"><?= $a['excess'] > 0 ? '<b style="color:var(--danger)">' . $a['excess'] . ' دقیقه</b>' : '—' ?></td>
            <td class="rt-num"><?= $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : '#' . $rank)) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>
<div class="card animate-in">
    <b>روز به روز (<?= $m_label ?>)</b> <span class="text-muted" style="font-size:11px">(شاخص: <?= $metric_label ?>)</span>
    <?= rt_bars(array_column($chart_days, 'label'), array_column($chart_days, 'val'), '#22c55e') ?>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
