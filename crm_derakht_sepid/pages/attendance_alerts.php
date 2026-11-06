<?php
$pageTitle = t('page_attendance_alerts');
$ws_id = (int)($_SESSION['workshop_id'] ?? 0);
$can_manage_attendance = is_strict_admin() || is_workshop_manager() || has_permission('manage_work_hours') || has_permission('office_manager') || access_mode('work_hours') === 'editor';
if (!$can_manage_attendance) { showToast(t('دسترسی ندارید'), 'error'); redirect('index.php?page=dashboard'); }

$raw_date = $_GET['date'] ?? jalali_now();
$gdate = to_gregorian($raw_date);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$gdate)) { $gdate=date('Y-m-d'); $raw_date=to_jalali($gdate); }
$settings = get_workshop_settings($pdo, $ws_id);
$scheduled_start=$settings['morning_start']??'08:00';

$staff=[];$hoursByUser=[];$leaveByUser=[];
try {
    $st=$pdo->prepare("SELECT u.id,u.full_name,u.is_karigar,u.work_role FROM users u JOIN workshop_personnel wp ON wp.user_id=u.id WHERE wp.workshop_id=? AND wp.status='active' AND u.username!='yaser' ORDER BY u.full_name");$st->execute([$ws_id]);$staff=$st->fetchAll();
    $st=$pdo->prepare("SELECT * FROM work_hours WHERE workshop_id=? AND work_date=? ORDER BY user_id,start_time");$st->execute([$ws_id,$gdate]);foreach($st->fetchAll() as $r)$hoursByUser[(int)$r['user_id']][]=$r;
    $st=$pdo->prepare("SELECT * FROM leave_requests WHERE workshop_id=? AND user_id IN (SELECT user_id FROM workshop_personnel WHERE workshop_id=?) AND status='approved' AND start_date<=? AND end_date>=?");$st->execute([$ws_id,$ws_id,$gdate,$gdate]);foreach($st->fetchAll() as $r)$leaveByUser[(int)$r['user_id']][]=$r;
}catch(Throwable $e){}

$absent=[];$late=[];$open=[];$present=[];
foreach($staff as $person){
    $uid=(int)$person['id'];$rows=$hoursByUser[$uid]??[];$leaves=$leaveByUser[$uid]??[];
    $hasDailyLeave=false;foreach($leaves as $lv)if(($lv['leave_type']??'daily')!=='hourly')$hasDailyLeave=true;
    if(empty($rows) && !$hasDailyLeave){$absent[]=$person;continue;}
    if(!empty($rows)){
        $present[]=$person;
        $first=$rows[0];
        if(substr($first['start_time'],0,5)>$scheduled_start)$late[]=['person'=>$person,'start'=>substr($first['start_time'],0,5),'minutes'=>(strtotime($first['start_time'])-strtotime($scheduled_start))/60];
        foreach($rows as $wh)if(($wh['end_time']??'')==='00:00:00'){$open[]=['person'=>$person,'start'=>substr($wh['start_time'],0,5),'id'=>$wh['id']];break;}
    }
}
include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4 animate-in"><h2>🚨 <?= t('page_attendance_alerts') ?></h2></div>
<div class="tabs mb-4 animate-in work-hours-tabs"><a href="index.php?page=work_hours&date=<?= urlencode($raw_date) ?>" class="tab">⏱ <?= t('nav_work_hours') ?></a><a href="index.php?page=work_hours&tab=alerts&date=<?= urlencode($raw_date) ?>" class="tab active">🚨 <?= t('page_attendance_alerts') ?></a></div>
<div class="card mb-4 animate-in"><form method="get" class="d-flex gap-2 align-center"><input type="hidden" name="page" value="work_hours"><input type="hidden" name="tab" value="alerts"><label class="form-label" style="margin:0"><?= t('wh_date') ?>:</label><input name="date" class="form-control jalali-date" value="<?= htmlspecialchars($raw_date) ?>" style="width:150px"><button class="btn btn-primary"><?= t('btn_show') ?></button><span class="text-muted" style="font-size:11px"><?= t('wh_start') ?> <?= t('برنامهٔ') ?> <?= t('wh_day') ?>: <?= htmlspecialchars($scheduled_start) ?></span></form></div>
<div class="stats-grid animate-in"><div class="stat-card"><div class="stat-icon success"><i data-lucide="user-check"></i></div><div class="stat-info"><h3><?= count($present) ?></h3><p><?= t('حاضر') ?></p></div></div><div class="stat-card"><div class="stat-icon danger"><i data-lucide="user-x"></i></div><div class="stat-info"><h3><?= count($absent) ?></h3><p><?= t('غیبت بدون') ?> <?= t('nav_leaves') ?></p></div></div><div class="stat-card"><div class="stat-icon warning"><i data-lucide="clock-3"></i></div><div class="stat-info"><h3><?= count($late) ?></h3><p><?= t('btn_checkin') ?> <?= t('با تأخیر') ?></p></div></div><div class="stat-card"><div class="stat-icon info"><i data-lucide="log-out"></i></div><div class="stat-info"><h3><?= count($open) ?></h3><p><?= t('wh_has_open') ?></p></div></div></div>
<div class="grid-2 mt-4">
<section class="card animate-in"><div class="card-header"><h3 class="card-title">❌ <?= t('غیبت بدون') ?> <?= t('nav_leaves') ?></h3></div><?php foreach($absent as $p): ?><div style="padding:9px;border-bottom:1px solid var(--border)"><strong><?= htmlspecialchars($p['full_name']) ?></strong><span class="text-muted" style="font-size:11px"> · <?= htmlspecialchars($p['work_role']?:'—') ?></span></div><?php endforeach; ?><?php if(empty($absent)): ?><p class="text-muted text-center" style="padding:22px"><?= t('موردی نیست ✅') ?></p><?php endif; ?></section>
<section class="card animate-in"><div class="card-header"><h3 class="card-title">⏰ <?= t('btn_checkin') ?> <?= t('با تأخیر') ?></h3></div><?php foreach($late as $r): ?><div style="padding:9px;border-bottom:1px solid var(--border)"><strong><?= htmlspecialchars($r['person']['full_name']) ?></strong> — <?= t('btn_checkin') ?> <?= $r['start'] ?> <span class="tag tag-pending"><?= round($r['minutes']) ?> <?= t('دقیقه تأخیر') ?></span></div><?php endforeach; ?><?php if(empty($late)): ?><p class="text-muted text-center" style="padding:22px"><?= t('موردی نیست ✅') ?></p><?php endif; ?></section>
<section class="card animate-in"><div class="card-header"><h3 class="card-title">🔓 <?= t('ورودهای') ?> <?= t('wh_open') ?></h3></div><?php foreach($open as $r): ?><div style="padding:9px;border-bottom:1px solid var(--border)"><strong><?= htmlspecialchars($r['person']['full_name']) ?></strong> — <?= t('lea_from') ?> <?= $r['start'] ?> <a class="btn btn-ghost btn-sm" href="index.php?page=work_hours&date=<?= urlencode($raw_date) ?>"><?= t('wh_checkout_title') ?></a></div><?php endforeach; ?><?php if(empty($open)): ?><p class="text-muted text-center" style="padding:22px"><?= t('موردی نیست ✅') ?></p><?php endif; ?></section>
<section class="card animate-in"><div class="card-header"><h3 class="card-title">ℹ️ <?= t('قانون') ?> <?= t('btn_calculate') ?></h3></div><p class="text-muted" style="font-size:12px;line-height:2"><?= t('این') ?> <?= t('صفحه') ?> <?= t('برای') ?> <?= t('نیروهای') ?> <?= t('فعال') ?> <?= t('کارگاه') ?> <?= t('بررسی') ?> <?= t('می‌کند') ?>: <?= t('حضور') ?> <?= t('ثبت') ?>‌<?= t('نشده') ?> + <?= t('نبود') ?> <?= t('nav_leaves') ?> <?= t('تأییدشده') ?> = <?= t('غیبت') ?>. <?= t('nav_leaves') ?> <?= t('تأییدشده') ?> <?= t('در') ?> <?= t('این') ?> <?= t('گزارش') ?> <?= t('غیبت') ?> <?= t('محسوب') ?> <?= t('نمی‌شود') ?>. <?= t('wh_hours') ?> <?= t('wh_start') ?> <?= t('قابل') ?> <?= t('تنظیم') ?> <?= t('lea_from') ?> <?= t('بخش') ?> <?= t('nav_settings') ?> <?= t('خوانده') ?> <?= t('می‌شود') ?>.</p></section>
</div>
<?php include 'includes/footer.php'; ?>
