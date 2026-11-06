<?php
/** صفحهٔ کاملاً محدود ثبت ساعت برای مجوز موقت یک شیفت. */
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/../includes/temp_grants.php';
temp_grants_ensure_table($pdo);

$__grant = null;
$gid = (int)($_GET['g'] ?? ($_POST['g'] ?? 0));
if (is_logged_in() && $gid > 0) {
    $st = $pdo->prepare("SELECT * FROM temp_entry_grants WHERE id=? AND user_id=?");
    $st->execute([$gid, (int)$_SESSION['user_id']]);
    $candidate = $st->fetch();
    if ($candidate && temp_grant_status($candidate) === 'active') $__grant = $candidate;
}

function te_time(string $value): ?string {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) return null;
    $h=(int)$m[1]; $i=(int)$m[2];
    return ($h>=0 && $h<=23 && $i>=0 && $i<=59) ? sprintf('%02d:%02d:00',$h,$i) : null;
}
function te_is_shift_row(array $r, string $shift): bool {
    $h=(int)substr((string)$r['start_time'],0,2);
    $night = !empty($r['is_night']) || $h>=18 || $h<6 || ((string)$r['end_time'] !== '00:00:00' && (string)$r['end_time'] < (string)$r['start_time']);
    return $shift === 'night' ? $night : !$night;
}
function te_flash(string $text, string $type='ok'): void { $_SESSION['temp_entry_flash']=[$text,$type]; }

if ($__grant && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save') {
    $gws=(int)$__grant['workshop_id']; $gd=(string)$__grant['grant_date'];
    $shift=(string)($__grant['shift_type'] ?? 'day');
    $target=(int)($_POST['user_id'] ?? 0);
    $start=te_time((string)($_POST['start_time'] ?? ''));
    $endRaw=trim((string)($_POST['end_time'] ?? ''));
    $end=$endRaw==='' ? null : te_time($endRaw);

    $chk=$pdo->prepare("SELECT 1 FROM users u JOIN workshop_personnel wp ON wp.user_id=u.id WHERE u.id=? AND u.status='active' AND wp.workshop_id=? AND wp.status='active'");
    $chk->execute([$target,$gws]);
    if (!$chk->fetchColumn()) te_flash('نیروی انتخاب‌شده عضو فعال این کارگاه نیست','error');
    elseif (!$start || ($endRaw!=='' && !$end)) te_flash('ساعت ورود یا خروج نامعتبر است','error');
    else {
        $q=$pdo->prepare("SELECT id,start_time,end_time,is_night FROM work_hours WHERE user_id=? AND workshop_id=? AND work_date=? ORDER BY id");
        $q->execute([$target,$gws,$gd]);
        $matches=array_values(array_filter($q->fetchAll(),fn($r)=>te_is_shift_row($r,$shift)));
        if (count($matches)>1) {
            te_flash('برای این نفر چند رکورد در همین شیفت وجود دارد؛ مدیر اصلی باید آن را بررسی کند','error');
        } else {
            $isNight=$shift==='night'?1:0;
            $endDb=$end ?: '00:00:00';
            if ($matches) {
                $id=(int)$matches[0]['id'];
                $pdo->prepare("UPDATE work_hours SET start_time=?,end_time=?,is_night=? WHERE id=? AND user_id=? AND workshop_id=? AND work_date=?")
                    ->execute([$start,$endDb,$isNight,$id,$target,$gws,$gd]);
            } else {
                $pdo->prepare("INSERT INTO work_hours (user_id,workshop_id,work_date,start_time,end_time,is_night,note,created_by) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$target,$gws,$gd,$start,$endDb,$isNight,'ثبت با دسترسی موقت ' . temp_grant_shift_label($__grant),(int)$_SESSION['user_id']]);
                $id=(int)$pdo->lastInsertId();
            }
            try {
                $pdo->prepare("INSERT INTO audit_log (user_id,action,entity_type,entity_id,new_value) VALUES (?,'temp_shift_work_hours','work_hours',?,?)")
                    ->execute([(int)$_SESSION['user_id'],$id,json_encode(['grant_id'=>$gid,'date'=>$gd,'shift'=>$shift,'target'=>$target,'start'=>$start,'end'=>$endDb],JSON_UNESCAPED_UNICODE)]);
            } catch (Throwable $e) {}
            if (function_exists('arash_coin_sync_work_hour_rewards')) arash_coin_sync_work_hour_rewards($pdo,$id,(int)$_SESSION['user_id']);
            te_flash('ساعت همین شیفت ثبت شد ✔');
        }
    }
    header('Location: index.php?page=temp_entry&g=' . (int)$__grant['id'] . '#done'); exit;
}

$__toast=$_SESSION['temp_entry_flash'] ?? null;
unset($_SESSION['temp_entry_flash']);
$__ws_name = '';
if ($__grant) {
    $w = $pdo->prepare("SELECT name FROM workshops WHERE id = ?");
    $w->execute([$__grant['workshop_id']]);
    $__ws_name = (string)($w->fetchColumn() ?: '');
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ثبت موقت ساعت</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:Tahoma,sans-serif; background:#10151c; color:#e8eef5; min-height:100vh; padding:16px; }
.wrap { max-width:760px; margin:0 auto; }
.top { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
h1 { font-size:17px; }
.logout { background:#333c48; color:#cbd5e1; text-decoration:none; font-size:12px; padding:7px 14px; border-radius:8px; }
.box { background:#1a2230; border:1px solid #2a3547; border-radius:12px; padding:14px; margin-bottom:12px; }
.meta { font-size:13px; color:#9fb0c3; display:flex; gap:16px; flex-wrap:wrap; }
.meta b { color:#e8eef5; }
.toast { padding:9px 12px; border-radius:8px; font-size:13px; margin-bottom:10px; display:none; }
.toast.ok { background:#0f2d1c; color:#6ee7a0; border:1px solid #1d5c38; }
.toast.error { background:#33131a; color:#fca5a5; border:1px solid #7f1d1d; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { text-align:right; color:#8fa1b5; font-size:11.5px; padding:8px 6px; border-bottom:1px solid #2a3547; white-space:nowrap; }
td { padding:8px 6px; border-bottom:1px solid #232d3d; vertical-align:middle; }
input[type=time] { background:#0e141d; border:1px solid #33405a; color:#e8eef5; border-radius:7px; padding:6px 8px; font-family:inherit; font-size:13px; direction:ltr; }
.st { font-size:11px; padding:2px 8px; border-radius:9px; white-space:nowrap; }
.st-open { background:#3a2a10; color:#fbbf24; } .st-done { background:#12301f; color:#4ade80; } .st-none { background:#222c3b; color:#8fa1b5; }
.b-save { background:#2563eb; color:#fff; border:none; font-family:inherit; font-size:12px; padding:7px 14px; border-radius:8px; cursor:pointer; }
.b-save:hover { background:#1d4ed8; }
.sub { color:#8fa1b5; font-size:11.5px; }
.note { font-size:11.5px; color:#8fa1b5; margin-top:10px; }
</style>
</head>
<body>
<div class="wrap">
<div class="top">
    <h1>🕐 ثبت موقت ساعت کاری</h1>
    <?php if (is_logged_in()): ?>
    <form method="POST" action="index.php?page=logout" style="display:inline"><?= get_csrf_field() ?><button class="logout" type="submit">خروج</button></form>
    <?php endif; ?>
</div>

<?php if (!$__grant): ?>
<div class="box" style="text-align:center;padding:34px">
    <div style="font-size:40px;margin-bottom:10px">🔒</div>
    <div style="font-size:15px;font-weight:bold;margin-bottom:6px">دسترسی موقت فعالی ندارید</div>
    <div class="sub">اگر مدیر به شما دسترسی داده، مطمئن شوید در بازهٔ زمانی دقیق همان شیفت وارد شده‌اید.</div>
</div>
<?php else:
    $gws = (int)$__grant['workshop_id'];
    $gd  = (string)$__grant['grant_date'];
    [$gw_start, $gw_end] = temp_grant_window($gd, (string)($__grant['shift_type'] ?? 'day'));
    $st = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN workshop_personnel wp ON wp.user_id = u.id AND wp.workshop_id = ? AND wp.status = 'active' WHERE u.status = 'active' ORDER BY u.full_name");
    $st->execute([$gws]);
    $staff = $st->fetchAll();
    // وضعیت رکورد هر نفر برای این شیفت
    $st2 = $pdo->prepare("SELECT user_id, start_time, end_time, is_night FROM work_hours WHERE workshop_id = ? AND work_date = ?");
    $st2->execute([$gws, $gd]);
    $rec = [];
    foreach ($st2->fetchAll() as $r) {
        if (!te_is_shift_row($r, (string)($__grant['shift_type'] ?? 'day'))) continue;
        if (!isset($rec[$r['user_id']])) $rec[$r['user_id']] = $r;
        else $rec[$r['user_id']]['ambiguous'] = true;
    }
    $toast = $__toast;
?>
<div class="box">
    <div class="meta">
        <span>🏢 کارگاه: <b><?= htmlspecialchars($__ws_name) ?></b></span>
        <span>📅 تاریخ: <b><?= to_jalali($gd) ?></b></span>
        <span><?= (($__grant['shift_type'] ?? 'day') === 'night') ? '🌙' : '☀️' ?> شیفت: <b><?= temp_grant_shift_label($__grant) ?></b></span>
        <span>⏳ اعتبار: <b><?= substr($gw_start,11,5) ?> تا <?= to_jalali(substr($gw_end,0,10)) ?> ساعت <?= substr($gw_end,11,5) ?></b></span>
    </div>
    <div class="note">فقط ساعت ورود و خروجِ همین شیفت ثبت می‌شود و روی صفحهٔ ساعت کاری سایت اعمال می‌گردد.</div>
</div>

<?php if ($toast): ?><div class="toast <?= $toast[1] === 'ok' ? 'ok' : 'error' ?>" id="done"><?= $toast[0] ?></div><?php endif; ?>

<div class="box">
<table>
    <thead><tr><th>نفر</th><th>وضعیت</th><th>ورود</th><th>خروج</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($staff as $s): $r = $rec[$s['id']] ?? null; $isOpen = $r && $r['end_time'] === '00:00:00'; $ambiguous=!empty($r['ambiguous']); ?>
    <tr>
        <td><b><?= htmlspecialchars($s['full_name']) ?></b></td>
        <td>
            <?php if ($ambiguous): ?><span class="st st-open">چند رکورد؛ نیازمند مدیر</span>
            <?php elseif ($isOpen): ?><span class="st st-open">در حال کار (<?= $r['start_time'] ?>)</span>
            <?php elseif ($r): ?><span class="st st-done"><?= $r['start_time'] ?> → <?= $r['end_time'] ?></span>
            <?php else: ?><span class="st st-none">ثبت نشده</span><?php endif; ?>
        </td>
        <td><form method="POST" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="g" value="<?= (int)$__grant['id'] ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
            <?= get_csrf_field() ?>
            <input type="time" name="start_time" value="<?= $r ? substr((string)$r['start_time'], 0, 5) : '' ?>" required>
            <input type="time" name="end_time" value="<?= $r && $r['end_time'] !== '00:00:00' ? substr((string)$r['end_time'], 0, 5) : '' ?>">
            <button class="b-save" type="submit" <?= $ambiguous ? 'disabled' : '' ?>><?= $r ? 'به‌روزرسانی' : 'ثبت' ?></button>
        </form></td>
        <td colspan="2" class="sub">خروج خالی = هنوز در کارگاه</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>
<script>if (location.hash === '#done') { var t = document.getElementById('done'); if (t) { t.style.display = 'block'; t.scrollIntoView(); } }</script>
</body>
</html>
