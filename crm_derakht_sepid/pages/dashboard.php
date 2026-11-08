<?php
$pageTitle = t('nav_dashboard');
$ws_id = (int)($_SESSION['workshop_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$today = date('Y-m-d');
// نخستین بازدید روز، بکاپ زمان‌بندی‌شده و اسکن ضدتقلب را اجرا می‌کند (بدون نیاز به کرون جداگانه).
$auto_backup = operations_auto_backups($pdo);
$backup_health = operations_backup_health();
operations_scan_coin_anomalies($pdo, (int)($_SESSION['workshop_id'] ?? 0), $today);
$is_yaser = (($_SESSION['username'] ?? '') === 'yaser');

// آمار اصلی
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT wh.user_id) FROM work_hours wh JOIN users u ON u.id=wh.user_id WHERE wh.workshop_id=? AND wh.work_date=? AND u.username!='yaser'");
$stmt->execute([$ws_id, $today]);
$today_present = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT wh.user_id) FROM work_hours wh JOIN users u ON u.id=wh.user_id WHERE wh.workshop_id=? AND wh.work_date=? AND u.is_karigar=0 AND u.username!='yaser'");
$stmt->execute([$ws_id, $today]); $today_employees = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT wh.user_id) FROM work_hours wh JOIN users u ON u.id=wh.user_id WHERE wh.workshop_id=? AND wh.work_date=? AND u.is_karigar=1 AND u.username!='yaser'");
$stmt->execute([$ws_id, $today]); $today_workers = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT wh.user_id) FROM work_hours wh JOIN users u ON u.id=wh.user_id WHERE wh.workshop_id=? AND wh.work_date=? AND u.is_karigar=2 AND u.username!='yaser'");
$stmt->execute([$ws_id, $today]); $today_subcontractors = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM work_hours WHERE workshop_id=? AND work_date=? AND HOUR(start_time)>=7 AND HOUR(start_time)<21");
$stmt->execute([$ws_id, $today]); $today_day_shift = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM work_hours WHERE workshop_id=? AND work_date=? AND (HOUR(start_time)>=21 OR HOUR(start_time)<7 OR (end_time='00:00:00' AND HOUR(start_time)>=7 AND HOUR(start_time)<21))");
$stmt->execute([$ws_id, $today]); $today_night_shift = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM advances WHERE workshop_id=? AND status='pending'");
$stmt->execute([$ws_id]); $pending_advances = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE workshop_id=? AND status='pending'");
$stmt->execute([$ws_id]); $pending_leaves = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notification_recipients WHERE user_id=? AND is_read=0");
$stmt->execute([$user_id]); $unread_notifs = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM work_hours WHERE workshop_id=? AND protest_status='pending'");
$stmt->execute([$ws_id]); $pending_protests = (int)$stmt->fetchColumn();
// در اولین بازدید ماه جدید، پاداش مرخصیِ استفاده‌نشدهٔ ماه قبل به‌صورت idempotent ثبت می‌شود.
arash_coin_run_monthly_leave_rewards($pdo, $ws_id, $today);
$current_coin_balance = arash_coin_balance($pdo, $user_id);
$coin_multiplier_notices = arash_coin_active_multiplier_notices($pdo, $ws_id, $today);

// اطلاعات پنل‌های بازشوندهٔ داشبورد
$present_people = $pending_advance_rows = $pending_leave_rows = $unread_rows = $recent_chat = $dashboard_channels = [];
try {
    $st = $pdo->prepare("SELECT wh.*, u.full_name, u.is_karigar FROM work_hours wh JOIN users u ON u.id=wh.user_id WHERE wh.workshop_id=? AND wh.work_date=? AND u.username!='yaser' ORDER BY u.full_name, wh.start_time");
    $st->execute([$ws_id, $today]);
    foreach ($st->fetchAll() as $row) $present_people[(int)$row['user_id']][] = $row;

    $st = $pdo->prepare("SELECT a.*, u.full_name FROM advances a JOIN users u ON u.id=a.user_id WHERE a.workshop_id=? AND a.status='pending' ORDER BY a.created_at DESC LIMIT 50");
    $st->execute([$ws_id]); $pending_advance_rows = $st->fetchAll();
    $st = $pdo->prepare("SELECT l.*, u.full_name FROM leave_requests l JOIN users u ON u.id=l.user_id WHERE l.workshop_id=? AND l.status='pending' ORDER BY l.created_at DESC LIMIT 50");
    $st->execute([$ws_id]); $pending_leave_rows = $st->fetchAll();
    $st = $pdo->prepare("SELECT n.title,n.message,n.type,n.created_at FROM notification_recipients nr JOIN notifications n ON n.id=nr.notification_id WHERE nr.user_id=? AND nr.is_read=0 ORDER BY n.created_at DESC LIMIT 50");
    $st->execute([$user_id]); $unread_rows = $st->fetchAll();
    // کارت پایین داشبورد: گفت‌وگوی عمومی چت‌روم، نه آخرین ساعات ثبت‌شده.
    $chatFilter = db_has_column($pdo,'chat_messages','channel_id') ? " AND (cm.channel_id IS NULL OR cm.channel_id=0) AND (cm.workshop_id IS NULL OR cm.workshop_id=".(int)$ws_id.")" : '';
    $st = $pdo->query("SELECT cm.*, u.full_name, u.avatar FROM chat_messages cm JOIN users u ON u.id=cm.sender_id WHERE cm.receiver_id=0{$chatFilter} ORDER BY cm.created_at DESC LIMIT 6");
    $recent_chat = array_reverse($st->fetchAll());
    // خلاصهٔ کانال‌های کارگاه و اعضای هر کانال، کنار چت‌روم نمایش داده می‌شود.
    try {
        if (db_has_column($pdo,'chat_channels','id') && db_has_column($pdo,'chat_channel_members','id')) {
            $channelStmt = $pdo->prepare("SELECT c.id,c.name,c.icon,c.created_at,COUNT(DISTINCT cm.user_id) AS member_count,GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR '، ') AS member_names FROM chat_channels c LEFT JOIN chat_channel_members cm ON cm.channel_id=c.id LEFT JOIN users u ON u.id=cm.user_id WHERE c.workshop_id=? AND c.status='active' GROUP BY c.id,c.name,c.icon,c.created_at ORDER BY c.created_at DESC LIMIT 10");
            $channelStmt->execute([$ws_id]);
            $dashboard_channels = $channelStmt->fetchAll();
        }
    } catch (Throwable $channelError) { $dashboard_channels = []; }
} catch (Throwable $e) { /* صفحه حتی اگر یکی از جدول‌های قدیمی ناقص باشد باز می‌ماند. */ }

// مأموریت‌ها
$mission_people = [];
try {
    $st = $pdo->prepare("SELECT m.*, u.full_name, sws.name AS source_name, tws.name AS target_name FROM missions m JOIN users u ON u.id=m.user_id LEFT JOIN workshops sws ON sws.id=m.source_workshop_id LEFT JOIN workshops tws ON tws.id=m.target_workshop_id WHERE m.status='active' AND (m.source_workshop_id=? OR m.target_workshop_id=?) ORDER BY m.id DESC");
    $st->execute([$ws_id, $ws_id]); $mission_people = $st->fetchAll();
} catch (Throwable $e) {}
$on_mission = count($mission_people);

// فرم‌های منتظر تأیید کارفرما
$pending_reports = 0;
if ($is_yaser) {
    $report_folder = __DIR__ . '/../uploads/reports/';
    foreach (is_dir($report_folder) ? glob($report_folder . 'daily_*.json') : [] as $file) {
        $data = json_decode(file_get_contents($file), true) ?: [];
        if (preg_match('/daily_\d{4}-\d{2}-\d{2}_(\d+)\.json$/', basename($file), $match) && (int)$match[1] === $ws_id && !empty($data['approvals'][1]) && !empty($data['approvals'][2]) && empty($data['approvals'][3])) $pending_reports++;
    }
}

// مرکز هشدارهای هوشمند داشبورد
$smart_alerts = [];
$attendance_trend = []; $coin_trend = [];
try {
    $activeStmt=$pdo->prepare("SELECT u.id,u.full_name FROM users u JOIN workshop_personnel wp ON wp.user_id=u.id WHERE wp.workshop_id=? AND wp.status='active' AND u.username!='yaser'");
    $activeStmt->execute([$ws_id]);$activePeople=$activeStmt->fetchAll();
    $presentIds=array_keys($present_people);
    foreach($activePeople as $person){
        if(in_array((int)$person['id'],$presentIds,true))continue;
        $lv=$pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id=? AND workshop_id=? AND status='approved' AND leave_type!='hourly' AND start_date<=? AND end_date>=?");
        $lv->execute([(int)$person['id'],$ws_id,$today,$today]);
        if(!(int)$lv->fetchColumn())$smart_alerts[]=['type'=>'danger','icon'=>'user-x','title'=>t('غیبت بدون ثبت'),'text'=>strtr(t('{name} امروز ورود نزده و مرخصی تأییدشده ندارد.'), ['{name}' => $person['full_name']]),'link'=>'index.php?page=work_hours&tab=alerts'];
    }
    $low=$pdo->prepare("SELECT name,quantity,min_quantity FROM inventory_items WHERE workshop_id=? AND status='active' AND min_quantity>0 AND quantity<=min_quantity ORDER BY quantity ASC LIMIT 6");$low->execute([$ws_id]);
    foreach($low->fetchAll() as $row)$smart_alerts[]=['type'=>'warning','icon'=>'package-minus','title'=>t('موجودی کم'),'text'=>strtr(t('{name} — موجودی {stock} از حداقل {min}'), ['{name}' => $row['name'], '{stock}' => $row['quantity'], '{min}' => $row['min_quantity']]),'link'=>'index.php?page=inventory&tab=stock'];
    $over=$pdo->prepare("SELECT i.name,t.id,t.person_name,t.checkout_date FROM inventory_transactions t JOIN inventory_items i ON i.id=t.item_id WHERE t.workshop_id=? AND t.deleted_at IS NULL AND t.return_date IS NULL AND t.is_consumable=0 AND COALESCE(t.out_type,'normal') NOT IN ('custody','consumable') AND t.checkout_date < DATE_SUB(CURDATE(),INTERVAL 7 DAY) LIMIT 6");$over->execute([$ws_id]);
    foreach($over->fetchAll() as $row)$smart_alerts[]=['type'=>'warning','icon'=>'wrench','title'=>t('ابزار برگشت‌نخورده'),'text'=>strtr(t('{name} نزد {person} از {date}'), ['{name}' => $row['name'], '{person}' => $row['person_name'], '{date}' => to_jalali($row['checkout_date'])]),'link'=>'index.php?page=inventory&tab=active&highlight_tx='.(int)$row['id'].'#tx-'.(int)$row['id']];
    $rep=$pdo->prepare("SELECT COUNT(*) FROM daily_reports WHERE workshop_id=? AND status='pending'");$rep->execute([$ws_id]);$pendingReports=(int)$rep->fetchColumn();
    if($pendingReports)$smart_alerts[]=['type'=>'info','icon'=>'clipboard-check','title'=>t('گزارش در انتظار تأیید'),'text'=>strtr(t('{count} گزارش کار هنوز تأیید نشده است.'), ['{count}' => $pendingReports]),'link'=>'index.php?page=daily_reports'];
    $contractors=$pdo->prepare("SELECT s.name,s.total_contract,COALESCE((SELECT SUM(f.amount) FROM subcontractor_face_items f WHERE f.subcontractor_id=s.id),0)+COALESCE((SELECT SUM(di.total_price) FROM subcontractor_daily_entry_items di JOIN subcontractor_daily_entries de ON di.entry_id=de.id WHERE de.subcontractor_id=s.id),0) AS claims FROM subcontractors s WHERE s.workshop_id=? AND s.status='active' AND s.total_contract>0");$contractors->execute([$ws_id]);
    foreach($contractors->fetchAll() as $c){$pct=((float)$c['claims']/(float)$c['total_contract'])*100;if($pct>=80)$smart_alerts[]=['type'=>$pct>=100?'danger':'warning','icon'=>'briefcase-business','title'=>t('سقف قرارداد پیمانکار'),'text'=>strtr(t('{name} به {pct}٪ سقف قرارداد رسیده است.'), ['{name}' => $c['name'], '{pct}' => number_format($pct,1)]),'link'=>'index.php?page=subcontractors'];}
    if(function_exists('db_has_column')&&db_has_column($pdo,'purchase_requests','id')){
        $p=$pdo->prepare("SELECT COUNT(*) FROM purchase_requests WHERE workshop_id=? AND status='pending'");$p->execute([$ws_id]);$n=(int)$p->fetchColumn();if($n)$smart_alerts[]=['type'=>'info','icon'=>'shopping-cart','title'=>t('page_purchases'),'text'=>strtr(t('{count} درخواست خرید در انتظار تأیید است.'), ['{count}' => $n]),'link'=>'index.php?page=inventory&tab=purchases'];
    }
    if(function_exists('db_has_column')&&db_has_column($pdo,'personnel_documents','id')){
        $d=$pdo->prepare("SELECT u.full_name,pd.title,pd.expiry_date FROM personnel_documents pd JOIN users u ON u.id=pd.user_id WHERE pd.workshop_id=? AND pd.expiry_date IS NOT NULL AND pd.expiry_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) ORDER BY pd.expiry_date LIMIT 6");$d->execute([$ws_id]);
        foreach($d->fetchAll() as $row)$smart_alerts[]=['type'=>'danger','icon'=>'file-warning','title'=>t('مدرک نزدیک انقضا'),'text'=>strtr(t('{title} برای {name} تا {date}'), ['{title}' => $row['title'], '{name}' => $row['full_name'], '{date}' => to_jalali($row['expiry_date'])]),'link'=>'index.php?page=personnel'];
    }
    if(!$backup_health['healthy'])$smart_alerts[]=['type'=>'danger','icon'=>'database-zap','title'=>t('بکاپ قدیمی یا ناموجود'),'text'=>t('بکاپ خودکار ۲۴ ساعت اخیر پیدا نشد؛ دسترسی پوشه backups را بررسی کنید.'),'link'=>'index.php?page=settings'];
    if(function_exists('db_has_column')&&db_has_column($pdo,'arash_coin_flags','id')){
        $f=$pdo->prepare("SELECT COUNT(*) FROM arash_coin_flags WHERE workshop_id=? AND status='open'");$f->execute([$ws_id]);$n=(int)$f->fetchColumn();if($n)$smart_alerts[]=['type'=>'warning','icon'=>'shield-alert','title'=>t('هشدار ضدتقلب کوین'),'text'=>strtr(t('{count} هشدار دریافت غیرعادی کوین نیاز به بررسی دارد.'), ['{count}' => $n]),'link'=>'index.php?page=arash_coins#coin-admin'];
    }
}catch(Throwable $e){}
try {
    // روند حضور هم یک‌جا خوانده می‌شود؛ هر روز query جداگانه به‌خصوص روی سابقهٔ بزرگ کند است.
    $trend_start=date('Y-m-d',strtotime($today.' -6 day'));
    $attendance_by_date=[];
    $q=$pdo->prepare("SELECT wh.work_date, COUNT(DISTINCT wh.user_id) AS total FROM work_hours wh WHERE wh.workshop_id=? AND wh.work_date BETWEEN ? AND ? GROUP BY wh.work_date");
    $q->execute([$ws_id,$trend_start,$today]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $attendance_row)$attendance_by_date[(string)$attendance_row['work_date']]=(int)$attendance_row['total'];
    for($i=6;$i>=0;$i--){$d=date('Y-m-d',strtotime($today." -{$i} day"));$attendance_trend[]=['date'=>$d,'count'=>$attendance_by_date[$d]??0];}
    if(function_exists('arash_coin_tx_date_expression') && function_exists('arash_coin_tables_ready') && arash_coin_tables_ready($pdo)){
        // همهٔ هفت روز در یک query خوانده می‌شوند تا با بزرگ‌شدن دفترکل، داشبورد معطل چند اسکن کامل نشود.
        $coin_start=date('Y-m-d',strtotime($today.' -6 day'));
        $coin_end_exclusive=date('Y-m-d',strtotime($today.' +1 day'));
        $coin_by_date=[];
        if(arash_coin_has_event_date($pdo)){
            // شرط تاریخ روی خود ستون‌هاست تا ایندکس workshop/event_date قابل استفاده بماند.
            $coin_date_expr="COALESCE(t.event_date, DATE(t.created_at))";
            $q=$pdo->prepare("SELECT $coin_date_expr AS coin_date, COALESCE(SUM(t.amount),0) AS total FROM arash_coin_transactions t WHERE t.workshop_id=? AND t.amount>0 AND ((t.event_date BETWEEN ? AND ?) OR (t.event_date IS NULL AND t.created_at>=? AND t.created_at<?)) GROUP BY $coin_date_expr");
            $q->execute([$ws_id,$coin_start,$today,$coin_start.' 00:00:00',$coin_end_exclusive.' 00:00:00']);
        }else{
            $q=$pdo->prepare("SELECT DATE(t.created_at) AS coin_date, COALESCE(SUM(t.amount),0) AS total FROM arash_coin_transactions t WHERE t.workshop_id=? AND t.amount>0 AND t.created_at>=? AND t.created_at<? GROUP BY DATE(t.created_at)");
            $q->execute([$ws_id,$coin_start.' 00:00:00',$coin_end_exclusive.' 00:00:00']);
        }
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $coin_row)$coin_by_date[(string)$coin_row['coin_date']]=(int)$coin_row['total'];
        for($i=6;$i>=0;$i--){$d=date('Y-m-d',strtotime($today." -{$i} day"));$coin_trend[]=['label'=>to_jalali($d),'count'=>$coin_by_date[$d]??0];}
    }
}catch(Throwable $e){}

// ═══ §phase2: خلاصهٔ CRM (در صورت هر خطا، کارت با صفر نمایش می‌یابد) ═══
$crm_pipe_count = 0; $crm_pipe_value = 0.0; $crm_overdue_tasks = 0; $crm_new_leads = 0; $crm_default_currency = 'USD';
try {
    [$__ds, $__dp] = crm_scope_where($pdo, 'd');
    $__st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(d.estimated_value),0) FROM crm_deals d WHERE d.status = 'open' AND ($__ds)");
    $__st->execute($__dp);
    $__row = $__st->fetch(PDO::FETCH_NUM);
    if ($__row) { $crm_pipe_count = (int)$__row[0]; $crm_pipe_value = (float)$__row[1]; }

    $__st = $pdo->prepare("SELECT COUNT(*) FROM crm_tasks WHERE user_id = ? AND status = 'pending' AND due_date IS NOT NULL AND due_date < CURDATE()");
    $__st->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $crm_overdue_tasks = (int)$__st->fetchColumn();

    [$__ls, $__lp] = crm_scope_where($pdo, 'l');
    $__st = $pdo->prepare("SELECT COUNT(*) FROM crm_leads l WHERE l.created_at >= (NOW() - INTERVAL 7 DAY) AND ($__ls)");
    $__st->execute($__lp);
    $crm_new_leads = (int)$__st->fetchColumn();

    $__st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'crm_default_currency'");
    $__st->execute();
    $__v = trim((string)$__st->fetchColumn());
    if ($__v !== '') $crm_default_currency = $__v;
} catch (Throwable $e) {
    // جداول CRM هنوز موجود نیست یا خطایی رخ داد — کارت با صفر نمایش می‌یابد.
}

include 'includes/header.php';
?>
<style>
.dashboard-stat-button { border:0; width:100%; text-align:right; font:inherit; cursor:pointer; color:inherit; }
.dashboard-stat-button:hover { transform:translateY(-3px); }
.dash-expand-panel { display:none; border-right:4px solid var(--primary); }
.dash-panel-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.dashboard-chat-grid { display:grid; grid-template-columns:minmax(0,1.2fr) minmax(250px,.8fr); gap:16px; align-items:start; margin-top:18px; }
.dashboard-chat-card { min-width:0; }
.dashboard-chat-card .chat-preview { display:flex; gap:8px; align-items:flex-start; padding:7px 0; border-bottom:1px solid var(--border); }
.dashboard-chat-card .chat-preview:last-child { border-bottom:0; }
.dashboard-chat-card .chat-preview-avatar { width:26px; height:26px; border-radius:50%; flex:0 0 26px; object-fit:cover; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:800; }
.dashboard-chat-card .chat-preview-message { font-size:11px; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dashboard-channel-list { max-height:270px; overflow:auto; }
.dashboard-channel-row { display:flex; gap:8px; align-items:flex-start; padding:8px 0; border-bottom:1px solid var(--border); }
.dashboard-channel-row:last-child { border-bottom:0; }
.dashboard-channel-icon { width:28px; height:28px; flex:0 0 28px; display:flex; align-items:center; justify-content:center; border-radius:8px; background:rgba(99,102,241,.1); font-size:16px; }
.dashboard-channel-members { display:block; margin-top:3px; font-size:10px; line-height:1.8; color:var(--text-secondary); }
.dashboard-coin-stat-img { width:28px; height:28px; object-fit:contain; display:block; }
@media (max-width:850px) { .dashboard-chat-grid { grid-template-columns:1fr; } }
.dashboard-coin-notice-icon { display:inline-flex; align-items:center; gap:3px; line-height:1; }
.dashboard-coin-trend-icon { width:17px; height:17px; display:inline-block; vertical-align:-3px; margin-left:4px; }
</style>

<?php if (!empty($coin_multiplier_notices)): ?>
<div class="alert alert-warning animate-in" style="margin-bottom:16px;background:linear-gradient(135deg,#fff7d6,#fde68a);border-color:#f59e0b;color:#78350f">
    <span class="dashboard-coin-notice-icon"><?= arash_coin_icon_markup('arash-coin-tiny') ?>✨</span>
    <span><b><?= t('sal_bonus') ?> <?= t('ویژهٔ') ?> <?= t('nav_arash_coins') ?> <?= t('wh_today') ?> <?= t('فعال است') ?>:</b>
    <?php foreach ($coin_multiplier_notices as $i => $notice): ?><?= $i ? ' · ' : '' ?><strong><?= htmlspecialchars($notice['title']) ?></strong> — <?= t('ضریب') ?> <?= rtrim(rtrim(number_format((float)$notice['multiplier'],2), '0'), '.') ?><?= !empty($notice['event_type']) ? ' برای ' . htmlspecialchars(arash_coin_event_type_label($notice['event_type'])) : '' ?><?php endforeach; ?>
    </span>
</div>
<?php endif; ?>

<div class="stats-grid animate-in">
    <?php if ($is_yaser): ?>
        <button type="button" class="stat-card glass-gradient-2 dashboard-stat-button" onclick="toggleDashboardPanel('presentPanel')"><div class="stat-icon success"><i data-lucide="user-check"></i></div><div class="stat-info"><h3><?= $today_present ?></h3><p><?= t('dash_today_present') ?> <?= t('· برای مشاهده کلیک کنید') ?></p></div></button>
        <div class="stat-card glass-gradient-3"><div class="stat-icon" style="background:#fbbf24;color:#92400e"><i data-lucide="sun"></i></div><div class="stat-info"><h3><?= $today_day_shift ?></h3><p><?= t('wh_shift') ?> <?= t('wh_day') ?> (<?= t('۷') ?> <?= t('صبح') ?> <?= t('lea_to') ?> ۹ <?= t('wh_night') ?>)</p></div></div>
        <div class="stat-card" style="background:linear-gradient(135deg,#1e293b,#334155)"><div class="stat-icon" style="background:#e2e8f0;color:#1e293b"><i data-lucide="moon"></i></div><div class="stat-info"><h3 style="color:#e2e8f0"><?= $today_night_shift ?></h3><p style="color:#94a3b8"><?= t('wh_shift') ?> <?= t('wh_night') ?></p></div></div>
        <?php if ($pending_reports > 0): ?><a href="index.php?page=forms" class="stat-card" style="background:linear-gradient(135deg,#fef3c7,#fde68a);text-decoration:none;color:#92400e"><div class="stat-icon" style="background:#fbbf24"><i data-lucide="clipboard-list"></i></div><div class="stat-info"><h3><?= $pending_reports ?></h3><p><?= t('گزارش') ?> <?= t('در انتظار') ?> <?= t('تأیید') ?></p></div></a><?php endif; ?>
    <?php else: ?>
        <!-- کارت «پرسنل فعال» حذف شد و موجودی WTTP COIN جای آن را گرفت. -->
        <a href="index.php?page=arash_coins" class="stat-card glass-gradient-1" style="text-decoration:none;color:inherit"><div class="stat-icon primary" style="overflow:hidden"><img src="assets/icons/wttp-coin-small.png" class="dashboard-coin-stat-img" alt="WTTP COIN"></div><div class="stat-info"><h3 data-arash-coin-balance><?= number_format($current_coin_balance) ?></h3><p><?= t('inv_stock') ?> <?= t('nav_arash_coins') ?> <?= t('من') ?></p></div></a>
        <button type="button" class="stat-card glass-gradient-2 dashboard-stat-button" onclick="toggleDashboardPanel('presentPanel')"><div class="stat-icon success"><i data-lucide="user-check"></i></div><div class="stat-info"><h3><?= $today_present ?></h3><p><?= t('dash_today_present') ?> <?= t('· برای مشاهده کلیک کنید') ?></p></div></button>
        <button type="button" class="stat-card glass-gradient-3 dashboard-stat-button" onclick="toggleDashboardPanel('advancePanel')"><div class="stat-icon warning"><i data-lucide="banknote"></i></div><div class="stat-info"><h3><?= $pending_advances ?></h3><p><?= t('dash_pending_advances') ?> <?= t('· برای مشاهده کلیک کنید') ?></p></div></button>
        <button type="button" class="stat-card glass-gradient-4 dashboard-stat-button" onclick="toggleDashboardPanel('leavePanel')"><div class="stat-icon danger"><i data-lucide="calendar-off"></i></div><div class="stat-info"><h3><?= $pending_leaves ?></h3><p><?= t('dash_pending_leaves') ?> <?= t('· برای مشاهده کلیک کنید') ?></p></div></button>
        <button type="button" class="stat-card glass-gradient-5 dashboard-stat-button" onclick="toggleDashboardPanel('notificationPanel')"><div class="stat-icon info"><i data-lucide="bell"></i></div><div class="stat-info"><h3><?= $unread_notifs ?></h3><p><?= t('dash_unread_messages') ?> <?= t('· برای مشاهده کلیک کنید') ?></p></div></button>
        <?php if ($pending_protests > 0): ?><a href="index.php?page=work_hours" class="stat-card" style="background:linear-gradient(135deg,#fef3c7,#fde68a);text-decoration:none;color:#92400e"><div class="stat-icon" style="background:#fbbf24"><i data-lucide="alert-triangle"></i></div><div class="stat-info"><h3><?= $pending_protests ?></h3><p><?= t('btn_protest') ?> <?= t('در انتظار') ?> <?= t('بررسی') ?></p></div></a><?php endif; ?>
    <?php endif; ?>
    <button type="button" class="stat-card dashboard-stat-button" onclick="toggleDashboardPanel('missionPanel')" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#1d4ed8" title="<?= t('btn_show') ?> <?= t('نیروهای در حال مأموریت') ?>"><div class="stat-icon" style="background:#60a5fa"><i data-lucide="rocket"></i></div><div class="stat-info"><h3><?= $on_mission ?></h3><p><?= t('نیروهای در حال مأموریت') ?> <?= t('· برای مشاهده کلیک کنید') ?></p></div></button>
    <button type="button" class="stat-card dashboard-stat-button" onclick="toggleDashboardPanel('smartAlertPanel')" style="background:linear-gradient(135deg,#fff7d6,#fee2e2);color:#92400e"><div class="stat-icon" style="background:#fbbf24"><i data-lucide="siren"></i></div><div class="stat-info"><h3><?= count($smart_alerts) ?></h3><p><?= t('هشدارهای') ?> <?= t('هوشمند') ?> <?= t('wh_today') ?> <?= t('· برای مشاهده کلیک کنید') ?></p></div></button>
</div>

<div class="card mb-4 animate-in" style="border-right:4px solid var(--primary)">
    <div class="card-header d-flex justify-between align-center"><h3 class="card-title">🎯 پایپ‌لاین واردات</h3>
        <div class="d-flex gap-2"><a href="index.php?page=crm_deals" class="btn btn-ghost btn-sm">پایپ‌لاین <i data-lucide="arrow-left"></i></a><a href="index.php?page=crm_leads" class="btn btn-ghost btn-sm">سرنخ‌ها <i data-lucide="arrow-left"></i></a><a href="index.php?page=crm_tasks" class="btn btn-ghost btn-sm">وظایف من <i data-lucide="arrow-left"></i></a></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;font-size:12px">
        <div><div class="text-muted">معاملات باز</div><div style="font-size:22px;font-weight:800"><?= (int)$crm_pipe_count ?></div></div>
        <div><div class="text-muted">ارزش تخمینی معاملات باز</div><div dir="ltr" style="font-size:17px;font-weight:800"><?= number_format($crm_pipe_value) ?> <?= htmlspecialchars($crm_default_currency) ?></div></div>
        <div><div class="text-muted">کارهای عقب‌افتادهٔ من</div><div style="font-size:22px;font-weight:800;color:#dc2626"><?= (int)$crm_overdue_tasks ?></div></div>
        <div><div class="text-muted">سرنخ‌های ۷ روز اخیر</div><div style="font-size:22px;font-weight:800"><?= (int)$crm_new_leads ?></div></div>
    </div>
</div>

<!-- پنل‌های بازشوندهٔ کارت‌های داشبورد -->
<div id="presentPanel" class="dash-expand-panel card mb-4 animate-in">
    <div class="dash-panel-head card-header"><h3 class="card-title">👥 <?= t('dash_today_present') ?> (<?= $today_present ?> <?= t('per_count') ?>)</h3><button class="btn btn-ghost btn-sm" onclick="toggleDashboardPanel('presentPanel')"><?= t('jdp_close') ?></button></div>
    <div class="d-flex gap-3 mb-3" style="font-size:12px;flex-wrap:wrap"><span><?= t('sal_employee') ?>: <strong><?= $today_employees ?></strong></span><span><?= t('sal_worker') ?>: <strong><?= $today_workers ?></strong></span><span><?= t('پیمانکار: ') ?><strong><?= $today_subcontractors ?></strong></span><span>☀️ <?= t('wh_day') ?>: <strong><?= $today_day_shift ?></strong></span><span>🌙 <?= t('wh_night') ?>: <strong><?= $today_night_shift ?></strong></span></div>
    <div class="table-container"><table><thead><tr><th><?= t('wh_name') ?></th><th><?= t('sal_type') ?></th><th><?= t('wh_hours') ?>‌<?= t('های') ?> <?= t('ثبت') ?><?= t('‌شده') ?></th><th><?= t('wh_status') ?></th></tr></thead><tbody>
    <?php foreach ($present_people as $uid => $rows): $first=$rows[0]; $times=[]; foreach($rows as $wh) $times[]=substr($wh['start_time'],0,5).' تا '.($wh['end_time']==='00:00:00'?t('wh_open'):substr($wh['end_time'],0,5)); ?>
        <tr><td><strong><?= htmlspecialchars($first['full_name']) ?></strong></td><td><?= (int)$first['is_karigar']===2?t('پیمانکار'):((int)$first['is_karigar']===1?t('sal_worker'):t('sal_employee')) ?></td><td style="direction:ltr"><?= htmlspecialchars(implode(' / ', $times)) ?></td><td><span class="tag tag-active"><?= t('حاضر') ?></span></td></tr>
    <?php endforeach; ?>
    <?php if (empty($present_people)): ?><tr><td colspan="4" class="text-center text-muted" style="padding:24px"><?= t('برای') ?> <?= t('wh_today') ?> <?= t('حضور') ?> <?= t('ثبت نشده') ?> <?= t('است') ?>.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<div id="advancePanel" class="dash-expand-panel card mb-4 animate-in" style="border-right-color:#f59e0b">
    <div class="dash-panel-head card-header"><h3 class="card-title">💵 <?= t('sal_advance') ?>‌<?= t('های') ?> <?= t('در انتظار') ?> (<?= $pending_advances ?> <?= t('مورد') ?>)</h3><div><a href="index.php?page=advances" class="btn btn-ghost btn-sm"><?= t('مدیریت') ?> <?= t('sal_advance') ?></a><button class="btn btn-ghost btn-sm" onclick="toggleDashboardPanel('advancePanel')"><?= t('jdp_close') ?></button></div></div>
    <div class="table-container"><table><thead><tr><th><?= t('نیرو') ?></th><th><?= t('مبلغ') ?></th><th><?= t('adv_reason') ?></th><th><?= t('wh_date') ?> <?= t('درخواست') ?></th></tr></thead><tbody>
    <?php foreach($pending_advance_rows as $row): ?><tr><td><?= htmlspecialchars($row['full_name']) ?></td><td><?= number_format($row['amount']) ?> <?= t('ت') ?></td><td><?= htmlspecialchars($row['reason'] ?: '—') ?></td><td><?= to_jalali($row['created_at']) ?></td></tr><?php endforeach; ?>
    <?php if(empty($pending_advance_rows)): ?><tr><td colspan="4" class="text-center text-muted" style="padding:24px"><?= t('مساعدهٔ در انتظاری نیست.') ?></td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<div id="leavePanel" class="dash-expand-panel card mb-4 animate-in" style="border-right-color:#ef4444">
    <div class="dash-panel-head card-header"><h3 class="card-title">📅 <?= t('nav_leaves') ?>‌<?= t('های') ?> <?= t('در انتظار') ?> (<?= $pending_leaves ?> <?= t('مورد') ?>)</h3><div><a href="index.php?page=leaves" class="btn btn-ghost btn-sm"><?= t('مدیریت') ?> <?= t('nav_leaves') ?></a><button class="btn btn-ghost btn-sm" onclick="toggleDashboardPanel('leavePanel')"><?= t('jdp_close') ?></button></div></div>
    <div class="table-container"><table><thead><tr><th><?= t('نیرو') ?></th><th><?= t('lea_from') ?></th><th><?= t('lea_to') ?></th><th><?= t('adv_reason') ?></th></tr></thead><tbody>
    <?php foreach($pending_leave_rows as $row): ?><tr><td><?= htmlspecialchars($row['full_name']) ?></td><td><?= to_jalali($row['start_date']) ?></td><td><?= to_jalali($row['end_date']) ?></td><td><?= htmlspecialchars($row['reason'] ?: '—') ?></td></tr><?php endforeach; ?>
    <?php if(empty($pending_leave_rows)): ?><tr><td colspan="4" class="text-center text-muted" style="padding:24px"><?= t('مرخصی در انتظاری نیست.') ?></td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<div id="notificationPanel" class="dash-expand-panel card mb-4 animate-in" style="border-right-color:#3b82f6">
    <div class="dash-panel-head card-header"><h3 class="card-title">🔔 <?= t('پیام') ?>‌<?= t('های') ?> <?= t('خوانده‌نشده') ?> (<?= $unread_notifs ?> <?= t('مورد') ?>)</h3><div><a href="index.php?page=notifications" class="btn btn-ghost btn-sm"><?= t('همهٔ') ?> <?= t('nav_notifications') ?></a><button class="btn btn-ghost btn-sm" onclick="toggleDashboardPanel('notificationPanel')"><?= t('jdp_close') ?></button></div></div>
    <?php foreach($unread_rows as $row): ?><div style="padding:11px;border-bottom:1px solid var(--border)"><strong style="font-size:13px"><?= htmlspecialchars($row['title']) ?></strong><span class="text-muted" style="font-size:10px;margin-right:8px"><?= to_jalali($row['created_at']) ?></span><div style="font-size:12px;margin-top:4px"><?= nl2br(htmlspecialchars($row['message'])) ?></div></div><?php endforeach; ?>
    <?php if(empty($unread_rows)): ?><p class="text-center text-muted" style="padding:24px"><?= t('پیام خوانده‌نشده‌ای ندارید.') ?></p><?php endif; ?>
</div>

<div class="grid-2 mt-4 animate-in">
    <div class="card"><div class="card-header"><h3 class="card-title">📈 <?= t('روند') ?> <?= t('حضور') ?> <?= t('۷') ?> <?= t('wh_day') ?> <?= t('اخیر') ?></h3></div><div style="height:180px;display:flex;align-items:flex-end;gap:8px;padding:8px 0"><?php $maxAttend=max(1,...array_column($attendance_trend,'count')); foreach($attendance_trend as $d): ?><div style="flex:1;text-align:center"><div title="<?=to_jalali($d['date'])?>: <?=$d['count']?> <?= t('نفر') ?>" style="height:<?=max(4,round(($d['count']/$maxAttend)*135))?>px;background:linear-gradient(180deg,#6366f1,#a5b4fc);border-radius:7px 7px 2px 2px"></div><small style="font-size:9px"><?=substr(to_jalali($d['date']),-2)?></small><div style="font-size:10px;font-weight:700"><?=$d['count']?></div></div><?php endforeach;?></div></div>
    <div class="card"><div class="card-header"><h3 class="card-title"><i data-lucide="circle-dollar-sign" class="dashboard-coin-trend-icon"></i><?= t('روند') ?> <?= t('کوین') ?> <?= t('pet_income') ?> <?= t('۷') ?> <?= t('wh_day') ?> <?= t('اخیر') ?></h3></div><?php if($coin_trend): ?><div style="height:180px;display:flex;align-items:flex-end;gap:8px;padding:8px 0"><?php $maxCoin=max(1,...array_column($coin_trend,'count')); foreach($coin_trend as $d): ?><div style="flex:1;text-align:center"><div title="<?=$d['label']?>: <?=$d['count']?> <?= t('کوین') ?>" style="height:<?=max(4,round(($d['count']/$maxCoin)*135))?>px;background:linear-gradient(180deg,#f59e0b,#fde68a);border-radius:7px 7px 2px 2px"></div><small style="font-size:9px"><?=htmlspecialchars(mb_substr($d['label'],-2))?></small><div style="font-size:10px;font-weight:700"><?=$d['count']?></div></div><?php endforeach;?></div><?php else:?><p class="text-muted text-center" style="padding:45px"><?= t('دادهٔ') ?> <?= t('کوین') ?> <?= t('کافی') ?> <?= t('نیست') ?>.</p><?php endif;?></div>
</div>

<div id="smartAlertPanel" class="dash-expand-panel card mb-4 animate-in" style="border-right-color:#f59e0b">
    <div class="dash-panel-head card-header"><h3 class="card-title">🚨 <?= t('هشدارهای') ?> <?= t('هوشمند') ?> <?= t('wh_today') ?> (<?= count($smart_alerts) ?> <?= t('مورد') ?>)</h3><button class="btn btn-ghost btn-sm" onclick="toggleDashboardPanel('smartAlertPanel')"><?= t('jdp_close') ?></button></div>
    <?php foreach($smart_alerts as $alert): ?><a href="<?= htmlspecialchars($alert['link']) ?>" style="display:flex;gap:10px;align-items:center;padding:11px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit"><span class="tag tag-<?= $alert['type']==='danger'?'rejected':($alert['type']==='warning'?'pending':'info') ?>"><i data-lucide="<?= $alert['icon'] ?>" style="width:15px"></i></span><div><strong style="font-size:12px"><?= htmlspecialchars($alert['title']) ?></strong><div class="text-muted" style="font-size:11px;margin-top:2px"><?= htmlspecialchars($alert['text']) ?></div></div></a><?php endforeach; ?>
    <?php if(empty($smart_alerts)): ?><p class="text-center text-muted" style="padding:28px"><?= t('wh_today') ?> <?= t('warning') ?> <?= t('مهمی وجود ندارد ✅') ?></p><?php endif; ?>
</div>

<div id="missionPanel" class="dash-expand-panel card mb-4 animate-in" style="border-right-color:#3b82f6">
    <div class="dash-panel-head card-header"><h3 class="card-title">🚀 <?= t('نیروهای در حال مأموریت') ?> (<?= $on_mission ?> <?= t('per_count') ?>)</h3><button class="btn btn-ghost btn-sm" onclick="toggleDashboardPanel('missionPanel')"><?= t('jdp_close') ?></button></div>
    <?php foreach($mission_people as $m): ?><div style="padding:10px 14px;border-bottom:1px solid var(--border);font-size:13px"><strong><?= htmlspecialchars($m['full_name']) ?></strong> — <?= t('مبدأ') ?>: «<?= htmlspecialchars($m['source_name'] ?: '—') ?>» ← <?= t('مقصد') ?>: «<?= htmlspecialchars($m['target_name'] ?: '—') ?>» — <?= t('از') ?> <?= to_jalali($m['start_date']) ?><?= $m['start_time']?' ساعت '.substr($m['start_time'],0,5):'' ?> <a href="index.php?page=work_hours" class="btn btn-ghost btn-sm" style="margin-right:8px"><?= t('مدیریت') ?></a></div><?php endforeach; ?>
    <?php if(empty($mission_people)): ?><p class="text-center text-muted" style="padding:24px"><?= t('فعلاً کسی در مأموریت نیست.') ?></p><?php endif; ?>
</div>

<?php if (!$is_yaser && $pending_protests > 0 && can_see_others_hours()): ?>
<div class="card mb-4 animate-in" style="border-right:4px solid #f59e0b"><div class="card-header"><h3 class="card-title">⚠️ <?= t('wh_pending_protests') ?> (<?= $pending_protests ?>)</h3><a href="index.php?page=work_hours" class="btn btn-ghost btn-sm"><?= t('بررسی') ?> <?= t('در') ?> <?= t('nav_work_hours') ?></a></div><p class="text-muted" style="font-size:12px"><?= t('برای') ?> <?= t('تأیید') ?> <?= t('یا') ?> <?= t('btn_reject') ?> <?= t('و') ?> <?= t('btn_edit') ?> <?= t('wh_hours') ?>‌<?= t('ها') ?> <?= t('به') ?> <?= t('صفحهٔ') ?> <?= t('nav_work_hours') ?> <?= t('بروید') ?>.</p></div>
<?php endif; ?>

<?php if (!$is_yaser): ?>
<div class="dashboard-chat-grid mb-4">
    <section class="card dashboard-chat-card animate-in">
        <div class="card-header"><h3 class="card-title">💬 <?= t('چت‌روم عمومی') ?></h3><a href="index.php?page=chat" class="btn btn-ghost btn-sm"><?= t('btn_checkin') ?> <?= t('به') ?> <?= t('چت‌روم') ?></a></div>
        <?php foreach($recent_chat as $message): ?>
            <div class="chat-preview">
                <?php if (!empty($message['avatar']) && file_exists(__DIR__ . '/../' . $message['avatar'])): ?><img class="chat-preview-avatar" src="<?= htmlspecialchars($message['avatar']) ?>" alt=""><?php else: ?><div class="chat-preview-avatar"><?= htmlspecialchars(mb_substr($message['full_name'],0,1)) ?></div><?php endif; ?>
                <div style="min-width:0;flex:1"><div class="d-flex justify-between gap-2"><strong style="font-size:11px"><?= htmlspecialchars($message['full_name']) ?></strong><span class="text-muted" style="font-size:9px;white-space:nowrap"><?= to_jalali($message['created_at']) ?> <?= substr($message['created_at'],11,5) ?></span></div><div class="chat-preview-message"><?= htmlspecialchars($message['message'] ?: ('📎 ' . t('فایل ارسالی'))) ?></div></div>
            </div>
        <?php endforeach; ?>
        <?php if(empty($recent_chat)): ?><p class="text-center text-muted" style="padding:22px"><?= t('هنوز') ?> <?= t('پیامی') ?> <?= t('در') ?> <?= t('چت‌روم عمومی') ?> <?= t('ثبت نشده') ?> <?= t('است') ?>.</p><?php endif; ?>
        <form method="post" action="index.php?page=chat" class="d-flex gap-2 mt-2" style="align-items:center;border-top:1px solid var(--border);padding-top:9px"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="send_chat"><input type="hidden" name="receiver_id" value="0"><input type="hidden" name="return_to" value="dashboard">
            <input type="text" name="message_text" class="form-control" required maxlength="4000" placeholder="<?= t('پیام') ?> <?= t('در') ?> <?= t('چت‌روم') ?>…" autocomplete="off">
            <button type="submit" class="btn btn-primary btn-sm"><i data-lucide="send"></i> <?= t('btn_submit') ?></button>
        </form>
    </section>
    <aside class="card dashboard-chat-card animate-in">
        <div class="card-header"><h3 class="card-title">📣 <?= t('کانال‌ها') ?> <?= t('و') ?> <?= t('اعضا') ?></h3><a href="index.php?page=chat&view=channels" class="btn btn-ghost btn-sm"><?= t('مدیریت') ?></a></div>
        <div class="dashboard-channel-list">
            <?php foreach($dashboard_channels as $channel): ?>
                <a href="index.php?page=chat&view=channels&channel_id=<?= (int)$channel['id'] ?>" class="dashboard-channel-row" style="text-decoration:none;color:inherit">
                    <span class="dashboard-channel-icon"><?= htmlspecialchars($channel['icon'] ?: '💬') ?></span>
                    <span style="min-width:0;flex:1"><strong style="font-size:12px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($channel['name']) ?></strong><small class="dashboard-channel-members"><?= (int)$channel['member_count'] ?> <?= t('عضو') ?><?php if (!empty($channel['member_names'])): ?>: <?= htmlspecialchars($channel['member_names']) ?><?php endif; ?></small></span>
                </a>
            <?php endforeach; ?>
            <?php if(empty($dashboard_channels)): ?><p class="text-center text-muted" style="padding:22px 8px;font-size:12px"><?= t('هنوز') ?> <?= t('کانالی') ?> <?= t('برای') ?> <?= t('این') ?> <?= t('کارگاه') ?> <?= t('ثبت نشده') ?> <?= t('است') ?>.</p><?php endif; ?>
        </div>
    </aside>
</div>
<?php endif; ?>

<script>
function toggleDashboardPanel(id) {
    var target = document.getElementById(id);
    if (!target) return;
    var willOpen = target.style.display === 'none' || !target.style.display;
    document.querySelectorAll('.dash-expand-panel').forEach(function(panel) { panel.style.display = 'none'; });
    target.style.display = willOpen ? 'block' : 'none';
    if (willOpen) setTimeout(function(){ target.scrollIntoView({behavior:'smooth', block:'nearest'}); }, 30);
}
</script>
<?php include 'includes/footer.php'; ?>
