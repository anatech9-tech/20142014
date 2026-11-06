<?php
/** WTTP COIN — کیف پول، فروشگاه، پاداش‌های خودکار و پنل مدیریت */
$pageTitle = t('nav_arash_coins');
$ws_id = (int)($_SESSION['workshop_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$can_manage = is_strict_admin() || has_permission('manage_arash_coins');
$coin_ready = arash_coin_tables_ready($pdo);
$coin_v16_ready = $coin_ready && arash_coin_v16_ready($pdo) && db_has_column($pdo, 'arash_coin_rewards', 'reward_type');
$coin_v18_ready = $coin_v16_ready && db_has_column($pdo, 'arash_coin_redemptions', 'delivery_attachment');

$gdate_or_null = function ($input) {
    $input = trim((string)$input);
    if ($input === '') return null;
    $g = to_gregorian($input);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$g) ? $g : false;
};

if (!function_exists('arash_reward_icon_value')) {
    function arash_reward_icon_value($icon): string {
        $icon = trim((string)$icon);
        if ($icon === '') return '🎁';
        if (str_starts_with($icon, 'uploads/')) return '<img src="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" alt="" style="width:48px;height:48px;object-fit:contain">';
        return '<span style="font-size:38px;line-height:1">' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
if (!function_exists('arash_save_reward_icon')) {
    function arash_save_reward_icon(array $file, string $old_icon = ''): string {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $old_icon;
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException(t('آپلود آیکون جایزه ناموفق بود.'));
        $mime = @mime_content_type($file['tmp_name']) ?: '';
        $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];
        if (!isset($allowed[$mime])) throw new RuntimeException(t('آیکون باید تصویر PNG، JPG، WEBP یا GIF باشد.'));
        $dir = __DIR__ . '/../uploads/coin_rewards/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = 'reward_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        $path = $dir . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) throw new RuntimeException(t('ذخیرهٔ آیکون جایزه ناموفق بود.'));
        compress_image($path, 500, 85);
        return 'uploads/coin_rewards/' . $name;
    }
}

$event_labels = [
    'all' => t('همهٔ عملیات امروز'),
    'early_checkin' => t('ورود قبل از ساعت ۸'),
    'overtime' => t('rep_overtime_hours'),
    'night_work' => t('rep_night_hours'),
    'leave_unused' => t('مرخصی استفاده‌نشده'),
    'task_approved' => t('دستورکار تأییدشده'),
    'report_approved' => t('گزارش کار تأییدشده'),
    'inventory_activity' => t('فعالیت انبار'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $coin_ready && $coin_v18_ready) {
    $action = $_POST['action'] ?? '';
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        showToast(t('درخواست نامعتبر است؛ صفحه را تازه‌سازی و دوباره تلاش کنید.'), 'error');
        redirect('index.php?page=arash_coins');
    }

    if ($action === 'redeem_reward') {
        $reward_id = (int)($_POST['reward_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM arash_coin_rewards WHERE id=? AND status='active' AND (workshop_id=? OR workshop_id=0) FOR UPDATE");
            $st->execute([$reward_id, $ws_id]); $reward = $st->fetch();
            if (!$reward) throw new RuntimeException(t('این جایزه دیگر در دسترس نیست.'));
            if ($reward['stock'] !== null && (int)$reward['stock'] <= 0) throw new RuntimeException(t('موجودی این جایزه تمام شده است.'));
            $cost = max(1, (int)$reward['cost']);
            if (arash_coin_balance($pdo, $user_id) < $cost) throw new RuntimeException(t('موجودی WTTP COIN شما برای این جایزه کافی نیست.'));
            $leave_date = null;
            if (($reward['reward_type'] ?? 'generic') === 'paid_leave') {
                $leave_date = $gdate_or_null($_POST['leave_date'] ?? '');
                if (!$leave_date || $leave_date < date('Y-m-d')) throw new RuntimeException(t('برای جایزهٔ مرخصی، یک تاریخ معتبر از امروز به بعد انتخاب کنید.'));
            }
            $st = $pdo->prepare("INSERT INTO arash_coin_redemptions (reward_id,user_id,workshop_id,cost,leave_date,status) VALUES (?,?,?,?,?,'requested')");
            $st->execute([$reward_id,$user_id,$ws_id?:null,$cost,$leave_date]);
            $redemption_id = (int)$pdo->lastInsertId();
            $tx = arash_coin_add_transaction($pdo, $user_id, -$cost, 'reward_redemption', strtr(t('درخواست جایزه: {title}'), ['{title}' => $reward['title']]), $ws_id, $user_id, 'redemption:' . $redemption_id . ':spend');
            if (!$tx) throw new RuntimeException(t('ثبت خرج سکه ناموفق بود.'));
            if ($reward['stock'] !== null) $pdo->prepare("UPDATE arash_coin_rewards SET stock=stock-1 WHERE id=? AND stock>0")->execute([$reward_id]);
            $pdo->prepare("INSERT INTO audit_log (user_id,action,entity_type,entity_id,new_value) VALUES (?, 'arash_coin_redeem','arash_coin_redemption',?,?)")
                ->execute([$user_id,$redemption_id,'خرج '.$cost.' WTTP COIN برای «'.$reward['title'].'»']);
            $pdo->commit();
            showToast(t('درخواست جایزه ثبت شد 🎁 — ') . number_format($cost) . ' ' . t('WTTP COIN از کیف شما کم شد.'));
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            showToast($e->getMessage(), 'error');
        }
        redirect('index.php?page=arash_coins');
    }

    if ($can_manage && $action === 'save_rule') {
        $title = trim((string)($_POST['title'] ?? ''));
        $start = $gdate_or_null($_POST['start_date'] ?? '');
        $end = $gdate_or_null($_POST['end_date'] ?? '');
        $event_type = $_POST['event_type'] ?? 'all';
        if (!isset($event_labels[$event_type])) $event_type = 'all';
        $team = $_POST['target_team'] ?? ''; if (!in_array($team,['','ejra','hse','anbar'],true)) $team='';
        if ($title === '' || $start === false || $end === false || ($start && $end && $end < $start)) {
            showToast(t('عنوان یا بازهٔ تاریخ قانون نامعتبر است.'), 'error');
        } else {
            // صفر یعنی نرخ ثابت رویداد دست‌نخورده بماند؛ فقط ضریب اعمال شود.
            $base = max(0, (int)normalize_digits($_POST['base_coins'] ?? 0));
            $multiplier = max(0.1, (float)normalize_digits($_POST['multiplier'] ?? 1));
            $priority = max(0, (int)normalize_digits($_POST['priority'] ?? 0));
            $st=$pdo->prepare("INSERT INTO arash_coin_rules (workshop_id,title,task_keyword,event_type,target_team,start_date,end_date,base_coins,multiplier,priority,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$ws_id,sanitize($title),sanitize($_POST['task_keyword']??''),$event_type,$team?:null,$start,$end,$base,$multiplier,$priority,$user_id]);
            showToast(t('قانون پاداش/ضریب WTTP COIN ذخیره شد.'));
        }
        redirect('index.php?page=arash_coins#coin-admin');
    }
    if ($can_manage && $action === 'toggle_rule') {
        $pdo->prepare("UPDATE arash_coin_rules SET status=IF(status='active','inactive','active') WHERE id=? AND workshop_id=?")->execute([(int)$_POST['rule_id'],$ws_id]);
        showToast(t('وضعیت قانون تغییر کرد.')); redirect('index.php?page=arash_coins#coin-admin');
    }
    if ($can_manage && $action === 'delete_rule') {
        $pdo->prepare("DELETE FROM arash_coin_rules WHERE id=? AND workshop_id=?")->execute([(int)$_POST['rule_id'],$ws_id]);
        showToast(t('قانون حذف شد؛ سوابق پاداش‌های قبلی حفظ می‌شوند.')); redirect('index.php?page=arash_coins#coin-admin');
    }

    if ($can_manage && in_array($action, ['save_reward','update_reward'], true)) {
        $reward_id = (int)($_POST['reward_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $cost = max(1,(int)normalize_digits($_POST['cost']??0));
        $stock_raw=trim(normalize_digits($_POST['stock']??'')); $stock=$stock_raw===''?null:max(0,(int)$stock_raw);
        $reward_type = ($_POST['reward_type'] ?? 'generic') === 'paid_leave' ? 'paid_leave' : 'generic';
        $leave_days = $reward_type === 'paid_leave' ? max(1,(int)normalize_digits($_POST['leave_days']??1)) : 0;
        if ($title==='') { showToast(t('عنوان جایزه را وارد کنید.'), 'error'); redirect('index.php?page=arash_coins#coin-admin'); }
        try {
            $old_icon = '';
            if ($reward_id) { $q=$pdo->prepare("SELECT icon FROM arash_coin_rewards WHERE id=? AND workshop_id=?"); $q->execute([$reward_id,$ws_id]); $old_icon=(string)$q->fetchColumn(); }
            $icon_text=trim((string)($_POST['icon_text']??''));
            $icon = !empty($_POST['remove_icon']) ? '' : ($icon_text !== '' ? mb_substr($icon_text,0,40) : $old_icon);
            if (!empty($_FILES['icon_file']['name'])) $icon=arash_save_reward_icon($_FILES['icon_file'],$old_icon);
            if ($reward_id) {
                $st=$pdo->prepare("UPDATE arash_coin_rewards SET title=?,description=?,icon=?,reward_type=?,leave_days=?,cost=?,stock=? WHERE id=? AND workshop_id=?");
                $st->execute([sanitize($title),sanitize($_POST['description']??''),$icon?:null,$reward_type,$leave_days,$cost,$stock,$reward_id,$ws_id]);
                showToast(t('جایزه و آیکون آن ویرایش شد.'));
            } else {
                $st=$pdo->prepare("INSERT INTO arash_coin_rewards (workshop_id,title,description,icon,reward_type,leave_days,cost,stock,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
                $st->execute([$ws_id,sanitize($title),sanitize($_POST['description']??''),$icon?:null,$reward_type,$leave_days,$cost,$stock,$user_id]);
                showToast(t('جایزه به فروشگاه WTTP COIN اضافه شد.'));
            }
        } catch(Throwable $e) { showToast($e->getMessage(), 'error'); }
        redirect('index.php?page=arash_coins#coin-admin');
    }
    if ($can_manage && $action === 'toggle_reward') {
        $pdo->prepare("UPDATE arash_coin_rewards SET status=IF(status='active','inactive','active') WHERE id=? AND workshop_id=?")->execute([(int)$_POST['reward_id'],$ws_id]);
        showToast(t('وضعیت جایزه تغییر کرد.')); redirect('index.php?page=arash_coins#coin-admin');
    }
    if ($can_manage && $action === 'delete_reward') {
        $rid=(int)$_POST['reward_id']; $q=$pdo->prepare("SELECT COUNT(*) FROM arash_coin_redemptions WHERE reward_id=?"); $q->execute([$rid]);
        if ((int)$q->fetchColumn()>0) { $pdo->prepare("UPDATE arash_coin_rewards SET status='inactive' WHERE id=? AND workshop_id=?")->execute([$rid,$ws_id]); showToast(t('این جایزه سابقه دارد؛ غیرفعال شد.')); }
        else { $pdo->prepare("DELETE FROM arash_coin_rewards WHERE id=? AND workshop_id=?")->execute([$rid,$ws_id]); showToast(t('جایزه حذف شد.')); }
        redirect('index.php?page=arash_coins#coin-admin');
    }

    if ($can_manage && $action === 'manual_adjustment') {
        $target=(int)($_POST['target_user_id']??0); $value=abs((int)normalize_digits($_POST['amount']??0));
        $amount=(($_POST['direction']??'add')==='subtract'?-1:1)*$value; $reason=trim((string)($_POST['reason']??''));
        if($target<=0||$value<=0||$reason==='') showToast(t('کاربر، مقدار و دلیل تغییر دستی را کامل کنید.'),'error');
        elseif($amount<0&&arash_coin_balance($pdo,$target)<abs($amount)) showToast(t('موجودی کاربر برای این کسر کافی نیست.'),'error');
        else {
            $tx = arash_coin_add_transaction($pdo, $target, $amount, 'manual_adjustment', t('مدیریت') . ': ' . sanitize($reason), $ws_id, $user_id);
            if($tx) { $pdo->prepare("INSERT INTO audit_log (user_id,action,entity_type,entity_id,new_value) VALUES (?, 'arash_coin_adjust','arash_coin_transaction',?,?)")->execute([$user_id,$tx,($amount>0?'افزایش ':'کسر ').abs($amount).' WTTP COIN: '.$reason]); showToast(t('موجودی WTTP COIN کاربر اصلاح شد.')); }
        }
        redirect('index.php?page=arash_coins#coin-admin');
    }

    if ($can_manage && $action === 'save_coin_settings') {
        $limit = max(0, (int)normalize_digits($_POST['daily_limit'] ?? 50));
        $st = $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('arash_coin_daily_limit',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $st->execute([(string)$limit]);
        showToast($limit === 0 ? t('سقف روزانه غیرفعال شد.') : strtr(t('سقف دریافت روزانه روی {limit} WTTP COIN تنظیم شد.'), ['{limit}' => number_format($limit)]));
        redirect('index.php?page=arash_coins#coin-admin');
    }

    if ($can_manage && $action === 'save_challenge') {
        $title = trim((string)($_POST['title'] ?? ''));
        $start = $gdate_or_null($_POST['start_date'] ?? '');
        $end = $gdate_or_null($_POST['end_date'] ?? '');
        $event_type = $_POST['event_type'] ?? 'all';
        if (!isset($event_labels[$event_type])) $event_type = 'all';
        $team = $_POST['target_team'] ?? ''; if (!in_array($team, ['','ejra','hse','anbar'], true)) $team = '';
        $multiplier = max(0.1, (float)normalize_digits($_POST['multiplier'] ?? 1));
        if ($title === '' || !$start || !$end || $end < $start) {
            showToast(t('عنوان یا تاریخ چالش نامعتبر است.'), 'error');
        } else {
            $st = $pdo->prepare("INSERT INTO arash_coin_challenges (workshop_id,title,description,event_type,target_team,task_keyword,multiplier,start_date,end_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$ws_id, sanitize($title), sanitize($_POST['description'] ?? ''), $event_type, $team ?: null, sanitize($_POST['task_keyword'] ?? ''), $multiplier, $start, $end, $user_id]);
            operations_system_chat_message($pdo, $ws_id, $user_id, strtr(t('🚀 چالش جدید WTTP COIN: «{title}» با ضریب ×{multiplier} از {start} تا {end}'), ['{title}' => $title, '{multiplier}' => $multiplier, '{start}' => to_jalali($start), '{end}' => to_jalali($end)]));
            showToast(t('چالش کوتاه‌مدت ثبت شد و در بازهٔ انتخابی خودکار اعمال می‌شود.'));
        }
        redirect('index.php?page=arash_coins#coin-admin');
    }
    if ($can_manage && $action === 'toggle_challenge') {
        $pdo->prepare("UPDATE arash_coin_challenges SET status=IF(status='active','inactive','active') WHERE id=? AND workshop_id=?")->execute([(int)$_POST['challenge_id'],$ws_id]);
        showToast(t('وضعیت چالش تغییر کرد.')); redirect('index.php?page=arash_coins#coin-admin');
    }
    if ($can_manage && $action === 'delete_challenge') {
        $pdo->prepare("DELETE FROM arash_coin_challenges WHERE id=? AND workshop_id=?")->execute([(int)$_POST['challenge_id'],$ws_id]);
        showToast(t('چالش حذف شد.')); redirect('index.php?page=arash_coins#coin-admin');
    }

    if ($can_manage && $action === 'save_season') {
        $title=trim((string)($_POST['title']??''));$start=$gdate_or_null($_POST['start_date']??'');$end=$gdate_or_null($_POST['end_date']??'');
        if($title===''||!$start||!$end||$end<$start)showToast(t('اطلاعات فصل نامعتبر است.'),'error');
        else{$st=$pdo->prepare("INSERT INTO arash_coin_seasons (workshop_id,title,description,start_date,end_date,created_by) VALUES (?,?,?,?,?,?)");$st->execute([$ws_id,sanitize($title),sanitize($_POST['description']??''),$start,$end,$user_id]);operations_system_chat_message($pdo,$ws_id,$user_id,strtr(t('🏆 فصل جدید WTTP COIN «{title}» از {start} تا {end} آغاز شد.'), ['{title}' => $title, '{start}' => to_jalali($start), '{end}' => to_jalali($end)]));showToast(t('فصل مسابقه ثبت شد.'));}
        redirect('index.php?page=arash_coins#coin-admin');
    }
    if ($can_manage && $action === 'toggle_season') {$pdo->prepare("UPDATE arash_coin_seasons SET status=IF(status='active','inactive','active') WHERE id=? AND workshop_id=?")->execute([(int)$_POST['season_id'],$ws_id]);showToast(t('وضعیت فصل تغییر کرد.'));redirect('index.php?page=arash_coins#coin-admin');}
    if ($can_manage && $action === 'wallet_control') {
        $target=(int)$_POST['user_id'];$lock=!empty($_POST['is_locked'])?1:0;$reason=sanitize($_POST['reason']??'');
        $st=$pdo->prepare("INSERT INTO arash_coin_wallet_controls (user_id,workshop_id,is_locked,reason,locked_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE is_locked=VALUES(is_locked),reason=VALUES(reason),locked_by=VALUES(locked_by)");$st->execute([$target,$ws_id,$lock,$reason,$user_id]);operations_audit($pdo,$user_id,'coin_wallet_control','arash_coin_wallet',$target,($lock?t('قفل'):t('wh_open')).': '.$reason);showToast($lock?t('کیف پول کاربر موقتاً قفل شد.'):t('قفل کیف پول برداشته شد.'));redirect('index.php?page=arash_coins#coin-admin');
    }
    if ($can_manage && $action === 'review_coin_flag') {$status=in_array($_POST['status']??'',['reviewed','dismissed'],true)?$_POST['status']:'reviewed';$pdo->prepare("UPDATE arash_coin_flags SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND workshop_id=?")->execute([$status,$user_id,(int)$_POST['flag_id'],$ws_id]);showToast(t('هشدار بررسی شد.'));redirect('index.php?page=arash_coins#coin-admin');}

    if ($can_manage && $action === 'set_redemption_status') {
        $redemption_id=(int)($_POST['redemption_id']??0); $status=$_POST['status']??'requested';
        $allowed=['requested','approved','delivered','rejected','cancelled']; if(!in_array($status,$allowed,true))$status='requested';
        try {
            $pdo->beginTransaction();
            $st=$pdo->prepare("SELECT r.*,rw.stock,rw.title,rw.reward_type,rw.leave_days FROM arash_coin_redemptions r JOIN arash_coin_rewards rw ON rw.id=r.reward_id WHERE r.id=? AND (r.workshop_id=? OR r.workshop_id IS NULL) FOR UPDATE");
            $st->execute([$redemption_id,$ws_id]); $redemption=$st->fetch();
            if(!$redemption) throw new RuntimeException(t('درخواست جایزه پیدا نشد.'));
            if(in_array($redemption['status'],['rejected','cancelled'],true) && !in_array($status,['rejected','cancelled'],true)) throw new RuntimeException(t('درخواست رد/لغوشده را دوباره فعال نکنید؛ درخواست تازه ثبت شود.'));
            $is_refund=in_array($status,['rejected','cancelled'],true)&&!in_array($redemption['status'],['rejected','cancelled'],true);
            // تأیید جایزهٔ مرخصی، یک مرخصی روزانهٔ تأییدشده می‌سازد تا در مخرج ساعات الزامی لحاظ شود.
            if(in_array($status,['approved','delivered'],true) && $redemption['reward_type']==='paid_leave' && empty($redemption['leave_request_id'])) {
                if(empty($redemption['leave_date'])) throw new RuntimeException(t('تاریخ مرخصی در درخواست ثبت نشده است.'));
                $days=max(1,(int)$redemption['leave_days']);
                $leave_end=date('Y-m-d',strtotime($redemption['leave_date'].' +'.($days-1).' day'));
                $stLeave=$pdo->prepare("INSERT INTO leave_requests (user_id,workshop_id,start_date,end_date,reason,status,approved_by,approved_at,coin_redemption_id) VALUES (?,?,?,?,?,'approved',?,NOW(),?)");
                $stLeave->execute([(int)$redemption['user_id'],$ws_id,$redemption['leave_date'],$leave_end,'مرخصی جایزهٔ WTTP COIN: '.$redemption['title'],$user_id,$redemption_id]);
                $leave_id=(int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE arash_coin_redemptions SET leave_request_id=? WHERE id=?")->execute([$leave_id,$redemption_id]);
                $redemption['leave_request_id']=$leave_id;
            }
            $delivery_attachment = $redemption['delivery_attachment'] ?? null;
            if (!empty($_FILES['delivery_attachment']['name'])) $delivery_attachment = operations_upload_file($_FILES['delivery_attachment'], 'uploads/reward_deliveries', ['image/png'=>'png','image/jpeg'=>'jpg','application/pdf'=>'pdf'], 12_000_000);
            $pdo->prepare("UPDATE arash_coin_redemptions SET status=?,note=?,handled_by=?,handled_at=NOW(),delivery_attachment=? WHERE id=?")->execute([$status,sanitize($_POST['note']??''),$user_id,$delivery_attachment,$redemption_id]);
            if($is_refund) {
                arash_coin_add_transaction($pdo, (int)$redemption['user_id'], (int)$redemption['cost'], 'reward_refund', strtr(t('بازگشت WTTP COINِ درخواست «{title}»'), ['{title}' => $redemption['title']]), $ws_id, $user_id, 'redemption:' . $redemption_id . ':refund');
                if($redemption['stock']!==null)$pdo->prepare("UPDATE arash_coin_rewards SET stock=stock+1 WHERE id=?")->execute([(int)$redemption['reward_id']]);
                if(!empty($redemption['leave_request_id'])) $pdo->prepare("DELETE FROM leave_requests WHERE id=? AND coin_redemption_id=?")->execute([(int)$redemption['leave_request_id'],$redemption_id]);
            }
            $pdo->commit(); showToast($is_refund?t('درخواست رد/لغو شد و سکه برگشت.'):t('وضعیت درخواست به‌روز شد.'));
        } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); showToast($e->getMessage(),'error'); }
        redirect('index.php?page=arash_coins#coin-admin');
    }
}

include 'includes/header.php';
?>
<style>
.coin-page-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(290px,.75fr);gap:18px;align-items:start}.coin-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start}.coin-form-card{padding:18px;border:1px solid var(--border);border-radius:14px;background:var(--surface)}.coin-form-card h3{margin:0 0 12px;font-size:15px}.coin-section-title{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:24px 0 12px;flex-wrap:wrap}.reward-icon{width:54px;height:54px;border-radius:14px;background:#fff7d6;border:1px solid rgba(217,145,0,.25);display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 54px}@media(max-width:850px){.coin-page-grid,.coin-admin-grid{grid-template-columns:1fr}}
</style>
<?php if (!$coin_ready || !$coin_v18_ready): ?>
<div class="alert alert-warning animate-in"><i data-lucide="database"></i><span><?= t('بخش') ?> <?= t('nav_arash_coins') ?> <?= t('نسخهٔ') ?> <?= t('جدید') ?> <?= t('آماده') ?> <?= t('است') ?>، <?= t('اما') ?> <?= t('ستون') ?>‌<?= t('های') ?> <?= t('دیتابیس') ?> <?= t('آن') ?> <?= t('هنوز') ?> <?= t('ساخته') ?> <?= t('نشده‌اند') ?>. <?= t('پس') ?> <?= t('lea_from') ?> <?= t('آپلود') ?>، <?= t('یک‌بار') ?> <code>migrate.php</code> <?= t('را') ?> <?= t('اجرا') ?> <?= t('کنید') ?> <?= t('و') ?> <?= t('سپس') ?> <?= t('آن') ?> <?= t('فایل') ?> <?= t('را') ?> <?= t('btn_delete') ?> <?= t('کنید') ?>.</span></div>
<?php include 'includes/footer.php'; exit; endif; ?>

<?php
$balance=arash_coin_balance($pdo,$user_id); $ledger=[];$rewards=[];$my_redemptions=[];
try {
    $st=$pdo->prepare("SELECT t.* FROM arash_coin_transactions t WHERE t.user_id=? ORDER BY t.created_at DESC,t.id DESC LIMIT 100");$st->execute([$user_id]);$ledger=$st->fetchAll();
    $st=$pdo->prepare("SELECT * FROM arash_coin_rewards WHERE status='active' AND (workshop_id=? OR workshop_id=0) ORDER BY created_at DESC,id DESC");$st->execute([$ws_id]);$rewards=$st->fetchAll();
    $st=$pdo->prepare("SELECT r.*,rw.title,rw.icon,rw.reward_type FROM arash_coin_redemptions r JOIN arash_coin_rewards rw ON rw.id=r.reward_id WHERE r.user_id=? ORDER BY r.created_at DESC LIMIT 30");$st->execute([$user_id]);$my_redemptions=$st->fetchAll();
} catch(Throwable $e) { showToast(t('خواندن اطلاعات WTTP COIN با خطا روبه‌رو شد.'),'error'); }
?>
<?php
[$coin_week_start, $coin_week_end] = arash_coin_week_bounds();
[$coin_month_start, $coin_month_end] = arash_coin_month_bounds();
$weekly_leaderboard = arash_coin_period_leaderboard($pdo, $ws_id, $coin_week_start, $coin_week_end, 10);
$monthly_leaderboard = arash_coin_period_leaderboard($pdo, $ws_id, $coin_month_start, $coin_month_end, 10);
$weekly_badges = arash_coin_period_badges($pdo, $ws_id, $coin_week_start, $coin_week_end);
$team_leaderboard = arash_coin_team_leaderboard($pdo, $ws_id, $coin_month_start, $coin_month_end);
$current_season = null; $season_leaderboard = [];
try { $st=$pdo->prepare("SELECT * FROM arash_coin_seasons WHERE workshop_id=? AND status='active' AND start_date<=? AND end_date>=? ORDER BY id DESC LIMIT 1");$st->execute([$ws_id,date('Y-m-d'),date('Y-m-d')]);$current_season=$st->fetch(); if($current_season)$season_leaderboard=arash_coin_period_leaderboard($pdo,$ws_id,$current_season['start_date'],$current_season['end_date'],5); }catch(Throwable $e){}
$daily_cap = arash_coin_daily_limit_status($pdo, $user_id, date('Y-m-d'));
?>
<div class="arash-wallet-hero animate-in"><div class="arash-wallet-hero-inner"><img src="assets/icons/wttp-coin.png" class="arash-coin-hero-img" alt="WTTP COIN"><div><div style="font-weight:800;font-size:15px"><?= t('کیف پول') ?> <?= t('nav_arash_coins') ?></div><div class="arash-wallet-count" data-arash-coin-balance><?= number_format($balance) ?></div><div class="arash-wallet-caption">WTTP COIN · <?= t('پاداش تلاش، دقت و انجام کار') ?></div><div class="arash-wallet-note"><?= t('ورود زود، اضافه‌کاری، شب‌کاری، گزارش تأییدشده، دستورکار و فعالیت انبار همگی امتیاز دارند.') ?></div></div></div></div>

<div class="stats-grid mt-4 animate-in">
    <div class="stat-card"><div class="stat-icon primary"><i data-lucide="gauge"></i></div><div class="stat-info"><h3><?= $daily_cap['limit'] <= 0 ? '∞' : number_format($daily_cap['remaining']) ?></h3><p><?= $daily_cap['limit'] <= 0 ? t('دریافت روزانه نامحدود') : strtr(t('باقی‌مانده از سقف روزانه {limit}'), ['{limit}' => number_format($daily_cap['limit'])]) ?></p></div></div>
    <div class="stat-card"><div class="stat-icon warning"><i data-lucide="trophy"></i></div><div class="stat-info"><h3><?= count($weekly_leaderboard) ?></h3><p><?= t('per_count') ?> <?= t('در') ?> <?= t('رتبه‌بندی') ?> <?= t('هفتگی') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon success"><i data-lucide="medal"></i></div><div class="stat-info"><h3><?= count($weekly_badges) ?></h3><p><?= t('نشان افتخار این هفته') ?></p></div></div>
</div>

<div class="coin-page-grid mt-4"><section class="card animate-in"><div class="card-header"><h3 class="card-title">🎁 <?= t('فروشگاه') ?> <?= t('nav_arash_coins') ?></h3><span class="text-muted" style="font-size:11px"><?= t('برای جایزه مرخصی، تاریخ موردنظر را هم انتخاب کنید.') ?></span></div>
<?php if(empty($rewards)): ?><div class="arash-shop-empty"><?= t('هنوز') ?> <?= t('جایزه') ?><?= t('‌ای') ?> <?= t('در') ?> <?= t('فروشگاه') ?> <?= t('این') ?> <?= t('کارگاه') ?> <?= t('تعریف نشده') ?> <?= t('است') ?>.</div><?php else: ?><div class="arash-reward-grid">
<?php foreach($rewards as $reward): $out=$reward['stock']!==null&&(int)$reward['stock']<=0; $is_leave=($reward['reward_type']??'generic')==='paid_leave'; ?>
<article class="arash-reward-card"><div style="display:flex;gap:10px;align-items:flex-start"><div class="reward-icon"><?= arash_reward_icon_value($reward['icon']??'') ?></div><div><h4><?= htmlspecialchars($reward['title']) ?></h4><p><?= nl2br(htmlspecialchars($reward['description'] ?: t('جایزهٔ ویژهٔ WTTP COIN'), ENT_QUOTES, 'UTF-8')) ?></p></div></div><div class="arash-reward-cost"><img src="assets/icons/wttp-coin.png" alt=""> <?= number_format((int)$reward['cost']) ?> <?= t('WTTP COIN') ?><?= $is_leave ? ' · ' . (int)$reward['leave_days'] . ' ' . t('روز مرخصی') : '' ?></div><form method="post" class="mt-2" onsubmit="return confirm('<?= t('این') ?> <?= t('جایزه') ?> <?= t('درخواست') ?> <?= t('شود') ?>؟')"><?= get_csrf_field() ?><input type="hidden" name="action" value="redeem_reward"><input type="hidden" name="reward_id" value="<?= (int)$reward['id'] ?>"><?php if($is_leave): ?><input type="text" name="leave_date" class="form-control jalali-date" required placeholder="<?= t('wh_date') ?> <?= t('nav_leaves') ?>" style="margin-bottom:7px;font-size:11px"><?php endif; ?><div class="d-flex justify-between align-center" style="gap:8px"><span class="text-muted" style="font-size:10px"><?= $reward['stock']===null ? t('موجودی نامحدود') : strtr(t('{count} عدد باقی‌مانده'), ['{count}' => (int)$reward['stock']]) ?></span><button class="btn btn-sm <?=($out||$balance<(int)$reward['cost'])?'btn-ghost':'btn-warning'?>" <?=($out||$balance<(int)$reward['cost'])?'disabled':''?>><?= $out ? t('اتمام موجودی') : ($balance < (int)$reward['cost'] ? t('سکه کافی نیست') : t('درخواست جایزه')) ?></button></div></form></article>
<?php endforeach; ?></div><?php endif; ?></section>
<aside class="card animate-in"><div class="card-header"><h3 class="card-title">📦 <?= t('درخواست') ?>‌<?= t('های') ?> <?= t('من') ?></h3></div><?php $status_labels=['requested'=>t('در انتظار بررسی'),'approved'=>t('تأیید شده'),'delivered'=>t('تحویل شد'),'rejected'=>t('رد شد'),'cancelled'=>t('لغو شد')]; foreach($my_redemptions as $request): ?><div style="padding:10px 0;border-bottom:1px solid var(--border);display:flex;gap:8px"><span style="font-size:21px"><?= arash_reward_icon_value($request['icon']??'🎁') ?></span><div style="flex:1"><strong style="font-size:13px"><?= htmlspecialchars($request['title']) ?></strong><?php if(($request['reward_type']??'')==='paid_leave'&&$request['leave_date']): ?><div class="text-muted" style="font-size:10px">📅 <?= to_jalali($request['leave_date']) ?></div><?php endif; ?><div class="d-flex justify-between mt-1" style="font-size:11px;color:var(--text-secondary)"><span><?= to_jalali(substr($request['created_at'],0,10)) ?></span><span class="arash-rule-badge"><?= $status_labels[$request['status']]??$request['status'] ?></span></div></div></div><?php endforeach; ?><?php if(empty($my_redemptions)): ?><p class="text-muted text-center" style="font-size:12px;padding:14px"><?= t('هنوز') ?> <?= t('جایزه') ?><?= t('‌ای') ?> <?= t('درخواست') ?> <?= t('نکرده‌اید.') ?></p><?php endif; ?></aside></div>

<div class="coin-section-title"><h3 style="margin:0">🏆 <?= t('رتبه‌بندی') ?> <?= t('nav_arash_coins') ?></h3><span class="text-muted" style="font-size:11px"><?= t('رتبه') ?> <?= t('براساس') ?> <?= t('کوین') ?> <?= t('دریافتیِ') ?> <?= t('کارگاه') ?> <?= t('فعلی') ?> <?= t('است') ?>.</span></div>
<div class="coin-page-grid">
    <section class="card animate-in"><div class="card-header"><h3 class="card-title">📅 <?= t('برترین‌های هفته') ?></h3><span class="text-muted" style="font-size:11px"><?= to_jalali($coin_week_start) ?> <?= t('تا') ?> <?= to_jalali($coin_week_end) ?></span></div>
        <div class="table-container"><table><thead><tr><th>#</th><th><?= t('نیرو') ?></th><th><?= t('pet_income') ?></th><th><?= t('خرج‌شده') ?></th></tr></thead><tbody><?php foreach($weekly_leaderboard as $i=>$row): ?><tr><td><?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':$i+1)) ?></td><td><strong><?= htmlspecialchars($row['full_name']) ?></strong></td><td class="arash-ledger-positive">+<?= number_format((int)$row['earned']) ?></td><td class="arash-ledger-negative">−<?= number_format((int)$row['spent']) ?></td></tr><?php endforeach; ?><?php if(empty($weekly_leaderboard)): ?><tr><td colspan="4" class="text-center text-muted"><?= t('هنوز') ?> <?= t('امتیازی') ?> <?= t('در') ?> <?= t('این') ?> <?= t('هفته') ?> <?= t('ثبت نشده') ?> <?= t('است') ?>.</td></tr><?php endif; ?></tbody></table></div>
    </section>
    <section class="card animate-in"><div class="card-header"><h3 class="card-title">🗓️ <?= t('برترین') ?>‌<?= t('های') ?> <?= t('rep_month') ?></h3><span class="text-muted" style="font-size:11px"><?= to_jalali($coin_month_start) ?> <?= t('تا') ?> <?= to_jalali($coin_month_end) ?></span></div>
        <div class="table-container"><table><thead><tr><th>#</th><th><?= t('نیرو') ?></th><th><?= t('pet_income') ?></th><th><?= t('pet_balance') ?> <?= t('دوره') ?></th></tr></thead><tbody><?php foreach($monthly_leaderboard as $i=>$row): ?><tr><td><?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':$i+1)) ?></td><td><strong><?= htmlspecialchars($row['full_name']) ?></strong></td><td class="arash-ledger-positive">+<?= number_format((int)$row['earned']) ?></td><td><?= number_format((int)$row['net']) ?></td></tr><?php endforeach; ?><?php if(empty($monthly_leaderboard)): ?><tr><td colspan="4" class="text-center text-muted"><?= t('هنوز') ?> <?= t('امتیازی') ?> <?= t('در') ?> <?= t('این') ?> <?= t('rep_month') ?> <?= t('ثبت نشده') ?> <?= t('است') ?>.</td></tr><?php endif; ?></tbody></table></div>
    </section>
</div>
<?php if(!empty($weekly_badges)): ?><div class="card mt-4 animate-in"><div class="card-header"><h3 class="card-title">🎖️ <?= t('نشان‌های افتخار هفته') ?></h3><span class="text-muted" style="font-size:11px"><?= t('بر مبنای') ?> <?= t('رویدادهای') ?> <?= t('ثبت') ?><?= t('‌شده') ?> <?= t('در') ?> <?= t('همین') ?> <?= t('کارگاه') ?></span></div><div class="stats-grid" style="margin:0"><?php foreach($weekly_badges as $badge): ?><div class="stat-card" style="background:linear-gradient(135deg,#fff7d6,#fff)"><div class="stat-icon warning" style="font-size:24px"><?= $badge['icon'] ?></div><div class="stat-info"><h3 style="font-size:16px"><?= htmlspecialchars($badge['user_name']) ?></h3><p><strong><?= $badge['title'] ?></strong> · <?= number_format($badge['score']) ?> <?= $badge['metric'] ?></p></div></div><?php endforeach; ?></div></div><?php endif; ?>

<?php if($current_season): ?><div class="card mt-4 animate-in" style="background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff"><div class="card-header" style="border-color:rgba(255,255,255,.2)"><h3 class="card-title">🏆 <?= t('فصل فعال') ?>: <?= htmlspecialchars($current_season['title']) ?></h3><span style="font-size:11px;color:#c7d2fe"><?= to_jalali($current_season['start_date']) ?> <?= t('تا') ?> <?= to_jalali($current_season['end_date']) ?></span></div><p style="font-size:12px;color:#e0e7ff"><?= nl2br(htmlspecialchars($current_season['description'] ?: t('رقابت فصل جاری WTTP COIN'))) ?></p><div class="d-flex gap-3" style="flex-wrap:wrap;margin-top:10px"><?php foreach($season_leaderboard as $i=>$row): ?><div style="padding:8px 12px;background:rgba(255,255,255,.12);border-radius:10px"><strong><?= $i===0?'🥇':($i+1) ?> <?= htmlspecialchars($row['full_name']) ?></strong> · <?= number_format((int)$row['earned']) ?> <?= arash_coin_icon_markup('arash-coin-tiny') ?></div><?php endforeach; ?><?php if(empty($season_leaderboard)): ?><span style="font-size:12px"><?= t('هنوز امتیازی در این فصل ثبت نشده است.') ?></span><?php endif; ?></div></div><?php endif; ?>
<?php if($team_leaderboard): ?><div class="card mt-4 animate-in"><div class="card-header"><h3 class="card-title">👥 <?= t('رتبه‌بندی') ?> <?= t('واحدها') ?> — <?= t('rep_month') ?> <?= t('جاری') ?></h3></div><div class="stats-grid" style="margin:0"><?php foreach($team_leaderboard as $i=>$team): ?><div class="stat-card"><div class="stat-icon <?= $i===0?'warning':'primary' ?>"><i data-lucide="users"></i></div><div class="stat-info"><h3><?= htmlspecialchars(t($team['team'])) ?></h3><p><?= number_format((int)$team['earned']) ?> <?= t('کوین') ?> <?= t('pet_income') ?></p></div></div><?php endforeach; ?></div></div><?php endif; ?>

<div class="coin-section-title"><h3 style="margin:0">📜 <?= t('گردش کیف پول') ?></h3><span class="text-muted" style="font-size:11px"><?= t('inv_stock') ?> <?= t('lea_from') ?> <?= t('rep_total') ?> <?= t('دفترکل') ?> <?= t('btn_calculate') ?> <?= t('می‌شود') ?>.</span></div><div class="card animate-in"><div class="table-container"><table><thead><tr><th><?= t('wh_date') ?></th><th><?= t('شرح') ?></th><th><?= t('sal_type') ?></th><th><?= t('تغییر') ?></th></tr></thead><tbody><?php foreach($ledger as $row): ?><tr><td style="white-space:nowrap"><?= to_jalali(substr($row['created_at'],0,10)) ?> <?= substr($row['created_at'],11,5) ?></td><td><?= htmlspecialchars($row['description']?:'—') ?></td><td><span class="tag" style="font-size:10px"><?= htmlspecialchars(arash_coin_event_type_label($row['transaction_type'])) ?></span></td><td class="<?= (int)$row['amount']>=0?'arash-ledger-positive':'arash-ledger-negative' ?>"><?= (int)$row['amount']>=0?'+':'' ?><?= number_format((int)$row['amount']) ?> <?= arash_coin_icon_markup('arash-coin-tiny') ?></td></tr><?php endforeach; ?><?php if(empty($ledger)): ?><tr><td colspan="4" class="text-center text-muted" style="padding:28px"><?= t('هنوز') ?> <?= t('تراکنشی') ?> <?= t('ثبت نشده') ?> <?= t('است') ?>.</td></tr><?php endif; ?></tbody></table></div></div>

<?php if($can_manage): ?>
<?php
$rules=$all_rewards=$redemptions=$people=$challenges=$coin_admin_report=$seasons=$coin_flags=$wallet_controls=[];
$coin_admin_period=$_GET['coin_report']??'month';
if(!in_array($coin_admin_period,['week','month','all'],true))$coin_admin_period='month';
$coin_report_start=$coin_report_end=null;
if($coin_admin_period==='week')[$coin_report_start,$coin_report_end]=arash_coin_week_bounds();
elseif($coin_admin_period==='month')[$coin_report_start,$coin_report_end]=arash_coin_month_bounds();
$daily_limit_setting=max(0,(int)arash_coin_setting($pdo,'arash_coin_daily_limit',50));
try {
    $st=$pdo->prepare("SELECT * FROM arash_coin_rules WHERE workshop_id=? ORDER BY status='active' DESC,priority DESC,id DESC");$st->execute([$ws_id]);$rules=$st->fetchAll();
    $st=$pdo->prepare("SELECT * FROM arash_coin_rewards WHERE workshop_id=? ORDER BY status='active' DESC,id DESC");$st->execute([$ws_id]);$all_rewards=$st->fetchAll();
    $st=$pdo->prepare("SELECT r.*,rw.title AS reward_title,rw.reward_type,rw.leave_days,u.full_name FROM arash_coin_redemptions r JOIN arash_coin_rewards rw ON rw.id=r.reward_id JOIN users u ON u.id=r.user_id WHERE r.workshop_id=? ORDER BY FIELD(r.status,'requested','approved','delivered','rejected','cancelled'),r.created_at DESC LIMIT 100");$st->execute([$ws_id]);$redemptions=$st->fetchAll();
    $st=$pdo->prepare("SELECT DISTINCT u.id,u.full_name FROM users u JOIN workshop_personnel wp ON wp.user_id=u.id WHERE wp.workshop_id=? AND u.status='active' ORDER BY u.full_name");$st->execute([$ws_id]);$people=$st->fetchAll();
    $st=$pdo->prepare("SELECT * FROM arash_coin_challenges WHERE workshop_id=? ORDER BY status='active' DESC,start_date DESC,id DESC");$st->execute([$ws_id]);$challenges=$st->fetchAll();
    $st=$pdo->prepare("SELECT * FROM arash_coin_seasons WHERE workshop_id=? ORDER BY status='active' DESC,start_date DESC,id DESC");$st->execute([$ws_id]);$seasons=$st->fetchAll();
    $st=$pdo->prepare("SELECT f.*,u.full_name FROM arash_coin_flags f JOIN users u ON u.id=f.user_id WHERE f.workshop_id=? AND f.status='open' ORDER BY f.created_at DESC LIMIT 50");$st->execute([$ws_id]);$coin_flags=$st->fetchAll();
    $st=$pdo->prepare("SELECT c.*,u.full_name FROM arash_coin_wallet_controls c JOIN users u ON u.id=c.user_id WHERE c.workshop_id=? ORDER BY c.is_locked DESC,u.full_name");$st->execute([$ws_id]);$wallet_controls=$st->fetchAll();
    $dateExpr=arash_coin_tx_date_expression($pdo,'t');
    $sql="SELECT u.id,u.full_name,COALESCE(SUM(CASE WHEN t.amount>0 THEN t.amount ELSE 0 END),0) received,COALESCE(SUM(CASE WHEN t.amount<0 THEN -t.amount ELSE 0 END),0) spent,COALESCE(SUM(t.amount),0) net,COUNT(t.id) tx_count FROM arash_coin_transactions t JOIN users u ON u.id=t.user_id WHERE t.workshop_id=?";
    $params=[$ws_id];
    if($coin_report_start){$sql.=" AND $dateExpr BETWEEN ? AND ?";$params[]=$coin_report_start;$params[]=$coin_report_end;}
    $sql.=" GROUP BY u.id,u.full_name ORDER BY received DESC,net DESC,u.full_name";
    $st=$pdo->prepare($sql);$st->execute($params);$coin_admin_report=$st->fetchAll();
}catch(Throwable $e){}
?>
<div id="coin-admin" class="coin-section-title" style="margin-top:34px"><h3 style="margin:0">🛡️ <?= t('مدیریت') ?> <?= t('nav_arash_coins') ?></h3><span class="text-muted" style="font-size:11px"><?= t('ضرایب') ?> <?= t('روزانه') ?>، <?= t('فروشگاه') ?>، <?= t('آیکون') ?>، <?= t('nav_leaves') ?> <?= t('و') ?> <?= t('درخواست') ?>‌<?= t('ها') ?></span></div><div class="coin-admin-grid">
<section class="coin-form-card animate-in"><h3>⚡ <?= t('قانون') ?> <?= t('sal_bonus') ?> <?= t('یا') ?> <?= t('ضریب') ?> <?= t('sal_daily') ?></h3><form method="post"><?=get_csrf_field()?><input type="hidden" name="action" value="save_rule"><div class="form-group"><label class="form-label"><?= t('عنوان') ?> <?= t('قانون') ?> *</label><input name="title" class="form-control" required placeholder="<?= t('مثلاً') ?>: <?= t('پویش ویژهٔ') ?> <?= t('wh_today') ?>"></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('wh_actions') ?></label><select name="event_type" class="form-control"><?php foreach($event_labels as $key=>$label): ?><option value="<?=$key?>"><?=$label?></option><?php endforeach;?></select></div><div class="form-group"><label class="form-label"><?= t('واحد') ?> (<?= t('اختیاری') ?>)</label><select name="target_team" class="form-control"><option value=""><?= t('همه واحدها') ?></option><option value="ejra"><?= t('اجرا') ?></option><option value="hse">HSE</option><option value="anbar"><?= t('nav_inventory') ?></option></select></div></div><div class="form-group"><label class="form-label"><?= t('کلیدواژهٔ کار') ?> (<?= t('اختیاری') ?>)</label><input name="task_keyword" class="form-control" placeholder="<?= t('مثلاً') ?>: <?= t('داربست') ?>"></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('wh_date') ?></label><input name="start_date" class="form-control jalali-date"></div><div class="form-group"><label class="form-label"><?= t('lea_to') ?> <?= t('wh_date') ?></label><input name="end_date" class="form-control jalali-date"></div></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('sal_bonus') ?> <?= t('sal_base_pay') ?> (۰ = <?= t('نرخ') ?> <?= t('ثابت') ?> <?= t('wh_actions') ?>)</label><input name="base_coins" class="form-control" type="number" min="0" value="0"></div><div class="form-group"><label class="form-label"><?= t('ضریب') ?></label><input name="multiplier" class="form-control" type="number" min="0.1" step="0.1" value="2"></div><div class="form-group"><label class="form-label"><?= t('اولویت') ?></label><input name="priority" class="form-control" type="number" min="0" value="0"></div></div><button class="btn btn-primary"><?= t('ذخیرهٔ') ?> <?= t('قانون') ?></button></form><div class="table-container mt-3"><table><thead><tr><th><?= t('قانون') ?></th><th><?= t('wh_actions') ?></th><th><?= t('ضریب') ?></th><th></th></tr></thead><tbody><?php foreach($rules as $rule): ?><tr><td><strong><?=htmlspecialchars($rule['title'])?></strong><div class="text-muted" style="font-size:10px"><?=$rule['start_date']?to_jalali($rule['start_date']):t('همیشه')?> <?= t('تا') ?> <?=$rule['end_date']?to_jalali($rule['end_date']):'∞'?></div></td><td style="font-size:10px"><?=htmlspecialchars($event_labels[$rule['event_type']??'all']??($rule['event_type']??'همه'))?></td><td><?= (int)$rule['base_coins']>0?(int)$rule['base_coins'].' × ':''?>×<?=rtrim(rtrim(number_format((float)$rule['multiplier'],2),'0'),'.')?></td><td style="white-space:nowrap"><form method="post" style="display:inline"><?=get_csrf_field()?><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="rule_id" value="<?=$rule['id']?>"><button class="btn btn-ghost btn-sm"><?=$rule['status']==='active'?t('btn_deactivate'):t('فعال')?></button></form><form method="post" style="display:inline" onsubmit="return confirm('<?= t('قانون') ?> <?= t('chat_delete') ?>')"><?=get_csrf_field()?><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="rule_id" value="<?=$rule['id']?>"><button class="btn btn-ghost btn-sm text-danger">🗑</button></form></td></tr><?php endforeach;?><?php if(empty($rules)):?><tr><td colspan="4" class="text-center text-muted"><?= t('قانونی ثبت نشده است.') ?></td></tr><?php endif;?></tbody></table></div></section>
<section class="coin-form-card animate-in"><h3>🎁 <?= t('btn_add') ?> <?= t('جایزه') ?> <?= t('به') ?> <?= t('فروشگاه') ?></h3><form method="post" enctype="multipart/form-data"><?=get_csrf_field()?><input type="hidden" name="action" value="save_reward"><div class="form-group"><label class="form-label"><?= t('wh_name') ?> <?= t('جایزه') ?> *</label><input name="title" class="form-control" required></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('pet_expense') ?> <?= t('سکه *') ?></label><input name="cost" type="number" min="1" class="form-control" required></div><div class="form-group"><label class="form-label"><?= t('inv_stock') ?></label><input name="stock" type="number" min="0" class="form-control" placeholder="<?= t('خالی') ?> = <?= t('نامحدود') ?>"></div></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('sal_type') ?> <?= t('جایزه') ?></label><select name="reward_type" id="newRewardType" class="form-control" onchange="toggleLeaveFields('new')"><option value="generic"><?= t('جایزه') ?> <?= t('sal_regular') ?></option><option value="paid_leave"><?= t('nav_leaves') ?> <?= t('با') ?> <?= t('nav_arash_coins') ?></option></select></div><div class="form-group" id="newLeaveDays" style="display:none"><label class="form-label"><?= t('wh_day') ?> <?= t('nav_leaves') ?></label><input name="leave_days" type="number" min="1" value="1" class="form-control"></div></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('ایموجی / آیکون متنی') ?></label><input name="icon_text" class="form-control" placeholder="<?= t('مثلاً') ?>: 🎁 <?= t('یا') ?> 📅"></div><div class="form-group"><label class="form-label"><?= t('btn_upload') ?> <?= t('آیکون تصویر') ?></label><input type="file" name="icon_file" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif"></div></div><div class="form-group"><label class="form-label"><?= t('توضیح') ?></label><textarea name="description" class="form-control" rows="2"></textarea></div><button class="btn btn-warning"><?= t('btn_add') ?> <?= t('جایزه') ?></button></form><div class="table-container mt-3"><table><thead><tr><th><?= t('آیکون') ?> / <?= t('جایزه') ?></th><th><?= t('pet_expense') ?></th><th><?= t('inv_stock') ?></th><th></th></tr></thead><tbody><?php foreach($all_rewards as $reward): ?><tr><td><span style="vertical-align:middle;margin-left:5px"><?=arash_reward_icon_value($reward['icon']??'')?></span><?=htmlspecialchars($reward['title'])?><?=($reward['reward_type']??'')==='paid_leave'?' <small class="text-muted">('.(int)$reward['leave_days'].' ' . t('روز مرخصی') . ')</small>':''?><?=$reward['status'] !== 'active' ? ' <span class="text-muted">(' . t('غیرفعال') . ')</span>' : ''?></td><td><?=number_format((int)$reward['cost'])?></td><td><?=$reward['stock']===null?'∞':(int)$reward['stock']?></td><td style="white-space:nowrap"><button type="button" class="btn btn-ghost btn-sm" onclick='editReward(<?=js_arg($reward)?>)'>✏️</button><form method="post" style="display:inline"><?=get_csrf_field()?><input type="hidden" name="action" value="toggle_reward"><input type="hidden" name="reward_id" value="<?=$reward['id']?>"><button class="btn btn-ghost btn-sm"><?=$reward['status'] === 'active' ? t('btn_deactivate') : t('فعال')?></button></form><form method="post" style="display:inline" onsubmit="return confirm('<?= t('جایزه') ?> <?= t('chat_delete') ?>')"><?=get_csrf_field()?><input type="hidden" name="action" value="delete_reward"><input type="hidden" name="reward_id" value="<?=$reward['id']?>"><button class="btn btn-ghost btn-sm text-danger">🗑</button></form></td></tr><?php endforeach;?><?php if(empty($all_rewards)):?><tr><td colspan="4" class="text-center text-muted"><?= t('جایزه') ?><?= t('‌ای') ?> <?= t('تعریف نشده') ?> <?= t('است') ?>.</td></tr><?php endif;?></tbody></table></div></section>
<section class="coin-form-card animate-in"><h3>➕/➖ <?= t('اصلاح') ?> <?= t('دستی') ?> <?= t('کیف پول') ?></h3><form method="post"><?=get_csrf_field()?><input type="hidden" name="action" value="manual_adjustment"><div class="form-group"><label class="form-label"><?= t('nav_personnel') ?></label><select name="target_user_id" class="form-control" required><option value=""><?= t('انتخاب کنید') ?></option><?php foreach($people as $person):?><option value="<?=$person['id']?>"><?=htmlspecialchars($person['full_name'])?> (<?=number_format(arash_coin_balance($pdo,(int)$person['id']))?> <?= t('nav_arash_coins') ?>)</option><?php endforeach;?></select></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('wh_actions') ?></label><select name="direction" class="form-control"><option value="add"><?= t('افزایش') ?> <?= t('سکه') ?></option><option value="subtract"><?= t('کسر / خرج دستی') ?></option></select></div><div class="form-group"><label class="form-label"><?= t('تعداد') ?></label><input name="amount" type="number" min="1" class="form-control" required></div></div><div class="form-group"><label class="form-label"><?= t('adv_reason') ?> *</label><input name="reason" class="form-control" required></div><button class="btn btn-primary"><?= t('ثبت اصلاح') ?></button></form></section>
<section class="coin-form-card animate-in"><h3>📦 <?= t('مدیریت درخواست جایزه') ?></h3><div style="max-height:330px;overflow:auto"><?php foreach($redemptions as $request):?><form method="post" enctype="multipart/form-data" style="padding:10px 0;border-bottom:1px solid var(--border)"><?=get_csrf_field()?><input type="hidden" name="action" value="set_redemption_status"><input type="hidden" name="redemption_id" value="<?=$request['id']?>"><strong style="font-size:12px"><?=htmlspecialchars($request['full_name'])?> — <?=htmlspecialchars($request['reward_title'])?></strong><span class="text-muted" style="font-size:10px"> (<?=number_format((int)$request['cost'])?> <?= arash_coin_icon_markup('arash-coin-tiny') ?>)</span><?php if(($request['reward_type']??'')==='paid_leave'):?><div class="text-muted" style="font-size:10px"><?= t('nav_leaves') ?>: <?= $request['leave_date'] ? to_jalali($request['leave_date']) : t('تاریخ ثبت نشده')?></div><?php endif;?><div class="d-flex gap-2 mt-1"><select name="status" class="form-control" style="font-size:11px;flex:1"><?php foreach($status_labels as $key=>$label):?><option value="<?=$key?>" <?=$request['status']===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select><input name="note" class="form-control" style="font-size:11px;flex:1" value="<?=htmlspecialchars($request['note']??'')?>" placeholder="<?= t('wh_note') ?>"><button class="btn btn-ghost btn-sm"><?= t('btn_save') ?></button></div><div style="margin-top:5px;font-size:10px"><label><?= t('رسید') ?> <?= t('inv_checkout') ?>: <input type="file" name="delivery_attachment" accept="image/png,image/jpeg,application/pdf"></label><?php if(!empty($request['delivery_attachment'])): ?> <a href="<?=htmlspecialchars($request['delivery_attachment'])?>" target="_blank">📎 <?= t('رسید') ?> <?= t('فعلی') ?></a><?php endif; ?></div></form><?php endforeach;?><?php if(empty($redemptions)):?><p class="text-muted text-center" style="font-size:12px;padding:20px"><?= t('درخواستی نیست.') ?></p><?php endif;?></div></section>
</div>

<div class="coin-section-title" style="margin-top:24px"><h3 style="margin:0">🚀 <?= t('چالش') ?>‌<?= t('های') ?> <?= t('کوتاه‌') ?><?= t('wh_duration') ?></h3><span class="text-muted" style="font-size:11px"><?= t('چالش نمونه: گزارش‌های HSE این هفته سه‌برابر کوین دارند.') ?></span></div>
<div class="coin-admin-grid">
    <section class="coin-form-card animate-in">
        <h3>＋ <?= t('ایجاد') ?> <?= t('چالش') ?></h3>
        <form method="post">
            <?= get_csrf_field() ?><input type="hidden" name="action" value="save_challenge">
            <div class="form-group"><label class="form-label"><?= t('عنوان') ?> <?= t('چالش') ?> *</label><input name="title" class="form-control" required placeholder="<?= t('مثلاً') ?>: <?= t('پویش ایمنی این هفته') ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('توضیح') ?></label><textarea name="description" class="form-control" rows="2" placeholder="<?= t('مثلاً') ?>: <?= t('هر') ?> <?= t('گزارش') ?> HSE <?= t('سه‌برابر') ?> <?= t('کوین') ?> <?= t('دارد') ?>."></textarea></div>
            <div class="form-row"><div class="form-group"><label class="form-label"><?= t('wh_actions') ?></label><select name="event_type" class="form-control"><?php foreach($event_labels as $key=>$label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label"><?= t('واحد') ?></label><select name="target_team" class="form-control"><option value=""><?= t('همه واحدها') ?></option><option value="ejra"><?= t('اجرا') ?></option><option value="hse">HSE</option><option value="anbar"><?= t('nav_inventory') ?></option></select></div></div>
            <div class="form-row"><div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('wh_date') ?> *</label><input name="start_date" class="form-control jalali-date" value="<?= jalali_now() ?>" required></div><div class="form-group"><label class="form-label"><?= t('lea_to') ?> <?= t('wh_date') ?> *</label><input name="end_date" class="form-control jalali-date" value="<?= to_jalali(date('Y-m-d',strtotime('+7 day'))) ?>" required></div><div class="form-group"><label class="form-label"><?= t('ضریب') ?> *</label><input name="multiplier" class="form-control" type="number" min="0.1" step="0.1" value="3" required></div></div>
            <div class="form-group"><label class="form-label"><?= t('کلیدواژهٔ کار') ?> (<?= t('اختیاری') ?>)</label><input name="task_keyword" class="form-control" placeholder="<?= t('مثلاً') ?>: <?= t('بازدید') ?> <?= t('ایمنی') ?>"></div>
            <button class="btn btn-info"><?= t('wh_start') ?> <?= t('چالش') ?></button>
        </form>
    </section>
    <section class="coin-form-card animate-in">
        <h3><?= t('چالش') ?>‌<?= t('های') ?> <?= t('ثبت') ?><?= t('‌شده') ?></h3>
        <div class="table-container"><table><thead><tr><th><?= t('چالش') ?></th><th><?= t('بازه') ?> / <?= t('ضریب') ?></th><th></th></tr></thead><tbody>
        <?php foreach($challenges as $challenge): ?><tr><td><strong><?= htmlspecialchars($challenge['title']) ?></strong><div class="text-muted" style="font-size:10px"><?= htmlspecialchars($event_labels[$challenge['event_type']] ?? $challenge['event_type']) ?></div></td><td style="font-size:11px"><?= to_jalali($challenge['start_date']) ?> <?= t('تا') ?> <?= to_jalali($challenge['end_date']) ?><br><strong>×<?= rtrim(rtrim(number_format((float)$challenge['multiplier'],2),'0'),'.') ?></strong> <?= $challenge['status']==='active'?t('فعال'):t('btn_deactivate') ?></td><td style="white-space:nowrap"><form method="post" style="display:inline"><?=get_csrf_field()?><input type="hidden" name="action" value="toggle_challenge"><input type="hidden" name="challenge_id" value="<?= $challenge['id'] ?>"><button class="btn btn-ghost btn-sm"><?= $challenge['status']==='active'?t('btn_deactivate'):t('فعال') ?></button></form><form method="post" style="display:inline" onsubmit="return confirm('<?= t('چالش') ?> <?= t('chat_delete') ?>')"><?=get_csrf_field()?><input type="hidden" name="action" value="delete_challenge"><input type="hidden" name="challenge_id" value="<?= $challenge['id'] ?>"><button class="btn btn-ghost btn-sm text-danger">🗑</button></form></td></tr><?php endforeach; ?>
        <?php if(empty($challenges)): ?><tr><td colspan="3" class="text-center text-muted"><?= t('چالشی') ?> <?= t('ثبت نشده') ?> <?= t('است') ?>.</td></tr><?php endif; ?></tbody></table></div>
    </section>
</div>

<div class="coin-section-title" style="margin-top:24px"><h3 style="margin:0">📊 <?= t('گزارش مدیریتی') ?> <?= t('nav_arash_coins') ?></h3><span class="text-muted" style="font-size:11px"><?= t('pet_income') ?> <?= t('و') ?> <?= t('خرج') ?>‌<?= t('شدهٔ') ?> <?= t('هر') ?> <?= t('فرد') ?> <?= t('در') ?> <?= t('کارگاه') ?> <?= t('فعلی') ?></span></div>
<div class="coin-admin-grid">
    <section class="coin-form-card animate-in">
        <h3>🛡️ <?= t('کنترل') ?> <?= t('سقف') ?> <?= t('sal_daily') ?></h3>
        <form method="post" class="d-flex gap-2 align-center" style="flex-wrap:wrap"><?=get_csrf_field()?><input type="hidden" name="action" value="save_coin_settings"><div class="form-group" style="margin:0;flex:1;min-width:180px"><label class="form-label"><?= t('حداکثر') ?> <?= t('کوین') ?> <?= t('pet_income') ?> <?= t('خودکار') ?> <?= t('در') ?> <?= t('هر') ?> <?= t('wh_day') ?></label><input name="daily_limit" type="number" min="0" class="form-control" value="<?= $daily_limit_setting ?>"><small class="text-muted" style="font-size:10px"><?= t('صفر') ?> = <?= t('بدون سقف') ?>. <?= t('sal_bonus') ?>‌<?= t('های') ?> <?= t('دستی') ?> <?= t('و') ?> <?= t('btn_back') ?> <?= t('سکه') ?> <?= t('محدود') ?> <?= t('نمی‌') ?><?= t('شوند') ?>.</small></div><button class="btn btn-primary" style="margin-top:23px"><?= t('btn_save') ?></button></form>
    </section>
    <section class="coin-form-card animate-in">
        <h3><?= t('btn_filter') ?> <?= t('گزارش') ?></h3>
        <form method="get" class="d-flex gap-2 align-center"><input type="hidden" name="page" value="arash_coins"><select name="coin_report" class="form-control"><option value="week" <?= $coin_admin_period==='week'?'selected':'' ?>><?= t('هفته') ?> <?= t('جاری') ?></option><option value="month" <?= $coin_admin_period==='month'?'selected':'' ?>><?= t('rep_month') ?> <?= t('جاری') ?></option><option value="all" <?= $coin_admin_period==='all'?'selected':'' ?>><?= t('همه دوره‌ها') ?></option></select><button class="btn btn-ghost"><?= t('btn_show') ?></button></form>
    </section>
</div>
<div class="card mt-3 animate-in"><div class="table-container"><table><thead><tr><th><?= t('نیرو') ?></th><th><?= t('کوین') ?> <?= t('pet_income') ?></th><th><?= t('کوین خرج‌شده') ?></th><th><?= t('sal_net') ?> <?= t('دوره') ?></th><th><?= t('تعداد تراکنش') ?></th></tr></thead><tbody><?php foreach($coin_admin_report as $row): ?><tr><td><strong><?= htmlspecialchars($row['full_name']) ?></strong></td><td class="arash-ledger-positive">+<?= number_format((int)$row['received']) ?></td><td class="arash-ledger-negative">−<?= number_format((int)$row['spent']) ?></td><td><?= number_format((int)$row['net']) ?></td><td><?= number_format((int)$row['tx_count']) ?></td></tr><?php endforeach; ?><?php if(empty($coin_admin_report)): ?><tr><td colspan="5" class="text-center text-muted"><?= t('در این بازه تراکنشی ثبت نشده است.') ?></td></tr><?php endif; ?></tbody></table></div></div>

<div class="coin-section-title" style="margin-top:24px"><h3 style="margin:0">🏁 <?= t('فصل') ?>‌<?= t('ها') ?> <?= t('و') ?> <?= t('مسابقات') ?> <?= t('nav_arash_coins') ?></h3><span class="text-muted" style="font-size:11px"><?= t('هر') ?> <?= t('فصل') ?> <?= t('رتبه‌بندی') ?> <?= t('مستقل') ?> <?= t('خودش') ?> <?= t('را') ?> <?= t('دارد') ?>.</span></div>
<div class="coin-admin-grid"><section class="coin-form-card animate-in"><h3>＋ <?= t('ایجاد فصل') ?></h3><form method="post"><?=get_csrf_field()?><input type="hidden" name="action" value="save_season"><div class="form-group"><label class="form-label"><?= t('عنوان') ?> *</label><input name="title" class="form-control" required placeholder="<?= t('مثلاً') ?>: <?= t('فصل') ?> <?= t('پاییز') ?> ۱۴۰۵"></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('lea_from') ?> <?= t('wh_date') ?> *</label><input name="start_date" class="form-control jalali-date" value="<?=jalali_now()?>" required></div><div class="form-group"><label class="form-label"><?= t('lea_to') ?> <?= t('wh_date') ?> *</label><input name="end_date" class="form-control jalali-date" value="<?=to_jalali(date('Y-m-d',strtotime('+90 day')))?>" required></div></div><div class="form-group"><label class="form-label"><?= t('توضیح') ?></label><textarea name="description" class="form-control" rows="2"></textarea></div><button class="btn btn-warning"><?= t('ثبت فصل') ?></button></form></section><section class="coin-form-card animate-in"><h3><?= t('فصل‌های ثبت‌شده') ?></h3><div class="table-container"><table><thead><tr><th><?= t('فصل') ?></th><th><?= t('بازه') ?></th><th></th></tr></thead><tbody><?php foreach($seasons as $season):?><tr><td><strong><?=htmlspecialchars($season['title'])?></strong><div class="text-muted" style="font-size:10px"><?=htmlspecialchars($season['description']?:'')?></div></td><td style="font-size:11px"><?=to_jalali($season['start_date'])?> <?= t('تا') ?> <?=to_jalali($season['end_date'])?><br><?=$season['status']==='active'?t('فعال'):t('غیرفعال')?></td><td><form method="post" style="display:inline"><?=get_csrf_field()?><input type="hidden" name="action" value="toggle_season"><input type="hidden" name="season_id" value="<?=$season['id']?>"><button class="btn btn-ghost btn-sm"><?=$season['status']==='active'?t('btn_deactivate'):t('فعال')?></button></form></td></tr><?php endforeach;?><?php if(empty($seasons)):?><tr><td colspan="3" class="text-center text-muted"><?= t('فصلی ثبت نشده است.') ?></td></tr><?php endif;?></tbody></table></div></section></div>

<div class="coin-section-title" style="margin-top:24px"><h3 style="margin:0">🛡️ <?= t('ضدتقلب و کنترل کیف پول') ?></h3><span class="text-muted" style="font-size:11px"><?= t('هشدارها') ?> <?= t('خودکار') ?> <?= t('lea_from') ?> <?= t('دریافت') ?> <?= t('غیرعادی') ?> <?= t('sal_daily') ?> <?= t('ساخته') ?> <?= t('می‌') ?><?= t('شوند') ?>.</span></div>
<div class="coin-admin-grid"><section class="coin-form-card animate-in"><h3><?= t('قفل یا بازکردن کیف پول') ?></h3><form method="post"><?=get_csrf_field()?><input type="hidden" name="action" value="wallet_control"><div class="form-group"><label class="form-label"><?= t('nav_personnel') ?></label><select name="user_id" class="form-control" required><?php foreach($people as $person):?><option value="<?=$person['id']?>"><?=htmlspecialchars($person['full_name'])?></option><?php endforeach;?></select></div><label class="form-check"><input type="checkbox" name="is_locked" value="1"><span><?= t('قفل') ?> <?= t('دریافت') ?> <?= t('کوین') ?> <?= t('خودکار') ?></span></label><div class="form-group mt-2"><label class="form-label"><?= t('adv_reason') ?></label><input name="reason" class="form-control" placeholder="<?= t('مثلاً') ?>: <?= t('نیاز') ?> <?= t('به') ?> <?= t('بررسی') ?> <?= t('رویدادها') ?>"></div><button class="btn btn-danger"><?= t('اعمال') ?> <?= t('wh_status') ?></button></form><div class="mt-3" style="font-size:11px"><?php foreach($wallet_controls as $c):?><div style="padding:5px;border-bottom:1px solid var(--border)"><strong><?=htmlspecialchars($c['full_name'])?></strong>: <?= $c['is_locked'] ? '🔒 ' . t('قفل') : '🔓 ' . t('باز') ?> <?=htmlspecialchars($c['reason']?:'')?></div><?php endforeach;?></div></section><section class="coin-form-card animate-in"><h3><?= t('هشدارهای') ?> <?= t('wh_open') ?></h3><?php foreach($coin_flags as $flag):?><form method="post" style="padding:8px;border-bottom:1px solid var(--border)"><?=get_csrf_field()?><input type="hidden" name="action" value="review_coin_flag"><input type="hidden" name="flag_id" value="<?=$flag['id']?>"><strong><?=htmlspecialchars($flag['full_name'])?></strong><div class="text-muted" style="font-size:11px"><?=htmlspecialchars($flag['reason'])?> · <?=to_jalali($flag['flag_date'])?></div><select name="status" class="form-control" style="font-size:11px;margin-top:4px"><option value="reviewed"><?= t('بررسی شد') ?></option><option value="dismissed"><?= t('مورد مجاز بود') ?></option></select><button class="btn btn-ghost btn-sm mt-1"><?= t('ثبت نتیجه') ?></button></form><?php endforeach;?><?php if(empty($coin_flags)):?><p class="text-muted text-center" style="padding:20px"><?= t('warning') ?> <?= t('بازی وجود ندارد ✅') ?></p><?php endif;?></section></div>

<div id="editRewardModal" class="modal-overlay hidden"><div class="modal glass-modal" style="max-width:560px"><div class="modal-header"><h3 class="modal-title">✏️ <?= t('btn_edit') ?> <?= t('جایزه') ?></h3><button type="button" onclick="closeModal('editRewardModal')" class="modal-close">✕</button></div><form method="post" enctype="multipart/form-data"><?=get_csrf_field()?><input type="hidden" name="action" value="update_reward"><input type="hidden" name="reward_id" id="editRewardId"><div class="modal-body"><div class="form-group"><label class="form-label"><?= t('wh_name') ?> <?= t('جایزه') ?></label><input name="title" id="editRewardTitle" class="form-control" required></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('pet_expense') ?></label><input name="cost" id="editRewardCost" type="number" min="1" class="form-control" required></div><div class="form-group"><label class="form-label"><?= t('inv_stock') ?></label><input name="stock" id="editRewardStock" type="number" min="0" class="form-control" placeholder="<?= t('خالی') ?> = <?= t('نامحدود') ?>"></div></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('sal_type') ?> <?= t('جایزه') ?></label><select name="reward_type" id="editRewardType" class="form-control" onchange="toggleLeaveFields('edit')"><option value="generic"><?= t('جایزه') ?> <?= t('sal_regular') ?></option><option value="paid_leave"><?= t('nav_leaves') ?> <?= t('با') ?> <?= t('nav_arash_coins') ?></option></select></div><div class="form-group" id="editLeaveDays"><label class="form-label"><?= t('wh_day') ?> <?= t('nav_leaves') ?></label><input name="leave_days" id="editRewardLeaveDays" type="number" min="1" class="form-control"></div></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('ایموجی/') ?><?= t('آیکون') ?> <?= t('جدید') ?></label><input name="icon_text" id="editRewardIconText" class="form-control" placeholder="<?= t('خالی') ?> = <?= t('آیکون') ?> <?= t('فعلی') ?>"></div><div class="form-group"><label class="form-label"><?= t('تصویر') ?> <?= t('آیکون') ?> <?= t('جدید') ?></label><input type="file" name="icon_file" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif"></div></div><label class="form-check"><input type="checkbox" name="remove_icon" value="1"><span><?= t('btn_delete') ?> <?= t('آیکون') ?> <?= t('فعلی') ?></span></label><div class="form-group mt-2"><label class="form-label"><?= t('توضیح') ?></label><textarea name="description" id="editRewardDescription" class="form-control" rows="2"></textarea></div></div><div class="modal-footer"><button class="btn btn-primary"><?= t('ذخیرهٔ') ?> <?= t('btn_edit') ?></button><button type="button" onclick="closeModal('editRewardModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button></div></form></div></div>
<?php endif; ?>
<script>
function toggleLeaveFields(prefix){var type=document.getElementById(prefix==='new'?'newRewardType':'editRewardType');var box=document.getElementById(prefix==='new'?'newLeaveDays':'editLeaveDays');if(box)box.style.display=type&&type.value==='paid_leave'?'':'none';}
function editReward(r){document.getElementById('editRewardId').value=r.id;document.getElementById('editRewardTitle').value=r.title||'';document.getElementById('editRewardCost').value=r.cost||0;document.getElementById('editRewardStock').value=r.stock===null?'':r.stock;document.getElementById('editRewardType').value=r.reward_type||'generic';document.getElementById('editRewardLeaveDays').value=r.leave_days||1;document.getElementById('editRewardDescription').value=r.description||'';document.getElementById('editRewardIconText').value=(r.icon&&!String(r.icon).startsWith('uploads/'))?r.icon:'';toggleLeaveFields('edit');openModal('editRewardModal');}
toggleLeaveFields('new');
</script>
<?php include 'includes/footer.php'; ?>
