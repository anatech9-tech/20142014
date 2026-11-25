<?php
$pageTitle = 'درخواست‌های سایت';
require_login();
require_once __DIR__ . '/../includes/site_inquiries.php';
$me=(int)($_SESSION['user_id'] ?? 0);
try {
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!$actor || ($actor['status'] ?? '')!=='active' || ($actor['role'] ?? '')==='karfarma') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$admin=is_strict_admin() && ($actor['role'] ?? '')==='admin';
$readonly=is_nazer_kol() || in_array($actor['role'] ?? '',['nazer','nazer_kol'],true) || (!access_is_special_account() && access_mode('crm_leads')==='viewer');
$managed=managed_team_ids($pdo);
[$scope,$scopeParams]=site_inquiry_scope($me,$admin,$managed);
if (!ensure_site_inquiries($pdo)) {
    include 'includes/header.php'; echo '<div class="alert alert-danger">صندوق درخواست‌ها آماده نیست؛ مجوز ساخت جدول را بررسی کنید.</div>'; include 'includes/footer.php'; return;
}
try { $pdo->query('SELECT id,name,owner_user_id,team_id FROM crm_leads LIMIT 1'); }
catch (Throwable $e) { http_response_code(503); echo 'بررسی سرنخ‌ها ممکن نیست؛ صندوق موقتاً در دسترس نیست.'; return; }
$reconcileReady=$admin && site_inquiry_reconcile_ensure($pdo);
$assignees=[];
if ($admin) $assignees=$pdo->query("SELECT id,full_name,team_id FROM users WHERE status='active' AND role<>'karfarma' AND username NOT IN ('yaser','javidhse') ORDER BY full_name")->fetchAll();
elseif ($managed) {
    $st=$pdo->prepare("SELECT id,full_name,team_id FROM users WHERE status='active' AND role<>'karfarma' AND username NOT IN ('yaser','javidhse') AND team_id IN (".implode(',',array_fill(0,count($managed),'?')).") ORDER BY full_name");
    $st->execute($managed); $assignees=$st->fetchAll();
}
$allowed=[]; foreach ($assignees as $person) $allowed[(int)$person['id']]=$person;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id=(int)($_POST['id'] ?? 0); $action=(string)($_POST['action'] ?? '');
    try {
        if ($readonly) throw new DomainException('دسترسی برای تغییر مجاز نیست.');
        if ($action==='reconcile') {
            if (!$admin || !$reconcileReady) throw new DomainException('تطبیق ارجاع مجاز یا آماده نیست.');
            foreach (['lead_id','lead_owner','lead_team'] as $key) {
                if (!is_string($_POST[$key] ?? null) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D',$_POST[$key])) throw new DomainException('صفحه را دوباره بارگذاری کنید.');
            }
            site_inquiry_reconcile($pdo,$id,$me,$admin,(int)$_POST['lead_id'],(int)$_POST['lead_owner'],(int)$_POST['lead_team']);
            try { operations_audit($pdo,$me,'site_inquiry_reconcile','site_inquiry',$id,'تطبیق دستی ارجاع صندوق با سرنخ'); } catch (Throwable $e) { error_log('Site inbox global audit failed'); }
            showToast('ارجاع صندوق پس از بررسی مدیر تطبیق داده شد.');
            redirect('index.php?page=site_inquiries');
        }
        site_inquiry_require_innodb($pdo,['users','crm_leads','crm_site_inquiries']);
        $pdo->beginTransaction();
        $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$me]); $lockedActor=$st->fetch(PDO::FETCH_ASSOC);
        if (!$lockedActor || $lockedActor['status']!=='active' || $lockedActor['role']!==$actor['role']) throw new DomainException('دسترسی تغییر کرده است.');
        $st=$pdo->prepare("SELECT i.* FROM crm_site_inquiries i WHERE i.id=? AND ($scope) FOR UPDATE");
        $st->execute(array_merge([$id],$scopeParams)); $row=$st->fetch();
        if (!$row) throw new DomainException('دسترسی به درخواست مجاز نیست.');
        $lead=null;
        if (!empty($row['lead_id'])) {
            $st=$pdo->prepare('SELECT id,owner_user_id,team_id FROM crm_leads WHERE id=? FOR UPDATE'); $st->execute([(int)$row['lead_id']]); $lead=$st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$lead || !site_inquiry_in_scope($row,$lead,$me,$admin,$managed)) throw new DomainException('سرنخ در دسترس نیست یا محدودهٔ ارجاع آن تغییر کرده است.');
        }
        if ($action==='assign') {
            $uid=(int)($_POST['owner'] ?? 0);
            $team=(int)($row['team_id'] ?? 0);
            if ((!$admin && !in_array($team,$managed,true)) || !isset($allowed[$uid])) throw new DomainException('ارجاع خارج از محدودهٔ مجاز است.');
            if (!empty($row['lead_id'])) throw new DomainException('پس از ساخت سرنخ، ارجاع این درخواست قفل است؛ مسئول سرنخ را از صفحه سرنخ‌ها تغییر دهید.');
            $target=$allowed[$uid];
            $st=$pdo->prepare('SELECT status,team_id,role,username FROM users WHERE id=? FOR UPDATE'); $st->execute([$uid]); $currentTarget=$st->fetch(PDO::FETCH_ASSOC);
            if (!$currentTarget || $currentTarget['status']!=='active' || $currentTarget['role']==='karfarma' || in_array($currentTarget['username'],['yaser','javidhse'],true) || (int)$currentTarget['team_id']!==(int)$target['team_id']) throw new DomainException('مسئول یا تیم او تغییر کرده است.');
            $pdo->prepare("UPDATE crm_site_inquiries SET owner_user_id=?,team_id=?,assigned_at=?,assigned_by=?,status='assigned' WHERE id=?")
                ->execute([$uid,(int)$target['team_id'] ?: null,date('Y-m-d H:i:s'),$me,$id]);
        } elseif ($action==='status') {
            if (empty($row['owner_user_id'])) throw new DomainException('ابتدا مسئول درخواست را مشخص کنید.');
            $status=(string)($_POST['status'] ?? '');
            if (!in_array($status,['assigned','done'],true)) throw new DomainException('وضعیت نامعتبر است.');
            $pdo->prepare('UPDATE crm_site_inquiries SET status=? WHERE id=?')->execute([$status,$id]);
        } elseif ($action==='lead') {
            if (empty($row['owner_user_id'])) throw new DomainException('ابتدا مسئول درخواست را مشخص کنید.');
            if (!empty($row['lead_id'])) throw new DomainException('برای این درخواست قبلاً سرنخ ساخته شده است.');
            $notes="ایمیل: ".$row['email']."\nموضوع: ".$row['subject']."\n\n".$row['message']."\nشناسه درخواست سایت: ".$id;
            $pdo->prepare("INSERT INTO crm_leads (name,company,phone,mobile,source,product_interest,notes,status,owner_user_id,team_id,created_by) VALUES (?, '', ?, '', 'فرم سایت', '', ?, 'new', ?, ?, ?)")
                ->execute([$row['name'],$row['phone'],$notes,$row['owner_user_id'],$row['team_id'],$me]);
            $lead=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE crm_site_inquiries SET lead_id=? WHERE id=?')->execute([$lead,$id]);
        } else throw new DomainException('عملیات نامعتبر است.');
        $pdo->commit();
        try { operations_audit($pdo,$me,'site_inquiry_'.$action,'site_inquiry',$id,'درخواست سایت #'.$id); } catch (Throwable $e) { error_log('Site inbox audit failed'); }
        showToast('عملیات ثبت شد.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Site inbox action failed: '.get_class($e));
        showToast($e instanceof DomainException ? $e->getMessage() : 'ثبت عملیات ممکن نشد.', 'error');
    }
    redirect('index.php?page=site_inquiries');
}
$st=$pdo->prepare("SELECT i.*,u.full_name AS owner_name,t.name AS team_name,l.id AS actual_lead_id,l.name AS actual_lead_name,l.owner_user_id AS lead_owner_id,l.team_id AS lead_team_id,lu.full_name AS lead_owner_name,lt.name AS lead_team_name FROM crm_site_inquiries i LEFT JOIN users u ON u.id=i.owner_user_id LEFT JOIN teams t ON t.id=i.team_id LEFT JOIN crm_leads l ON l.id=i.lead_id LEFT JOIN users lu ON lu.id=l.owner_user_id LEFT JOIN teams lt ON lt.id=l.team_id WHERE $scope ORDER BY i.id DESC LIMIT 200");
$st->execute($scopeParams); $rows=$st->fetchAll();
function inquiry_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
include 'includes/header.php';
?>
<div class="page-header"><h1>درخواست‌های سایت</h1><p>درخواست بدون مسئول فقط برای مدیر کل قابل مشاهده است. پس از ساخت سرنخ، دسترسی غیرمدیر باید هم در ارجاع صندوق و هم در ارجاع فعلی سرنخ مجاز باشد. تغییر مسئول سرنخ، صندوق را خودکار جابه‌جا نمی‌کند. ایجاد سرنخ، مشتری تکراری را خودکار تشخیص نمی‌دهد؛ متن پیام در یادداشت سرنخ کپی می‌شود و مجوز سرنخ و مشتری ممکن است از صندوق گسترده‌تر باشد.</p></div>
<?php if (!$rows): ?><div class="card">درخواستی در محدودهٔ دسترسی شما وجود ندارد.</div><?php endif; ?>
<?php foreach ($rows as $row): ?>
<div class="card" style="padding:18px;margin-bottom:16px;overflow-wrap:anywhere">
 <strong><?= inquiry_h($row['name']) ?></strong> — <?= inquiry_h($row['subject'] ?: 'بدون موضوع') ?>
 <p>دریافت: <?= inquiry_h(to_jalali(substr($row['received_at'],0,10))) ?> <?= inquiry_h(substr($row['received_at'],11,5)) ?> | مسئول: <?= inquiry_h($row['owner_name'] ?: 'تعیین نشده') ?> | تیم: <?= inquiry_h($row['team_name'] ?: '—') ?> | وضعیت: <?= $row['status']==='done'?'پاسخ داده شد':($row['status']==='assigned'?'در حال پیگیری':'جدید') ?></p>
 <p>ایمیل: <?= inquiry_h($row['email']) ?> | تلفن: <?= inquiry_h($row['phone'] ?: '—') ?></p>
 <p style="white-space:pre-wrap"><?= inquiry_h($row['message']) ?></p>
 <?php if ($admin && !empty($row['lead_id'])): ?>
 <?php if (empty($row['actual_lead_id'])): ?><p><strong>سرنخ مرتبط یافت نشد؛ دسترسی کاربران غیرمدیرکل به این پیام بسته است. حذف، بازیابی و شناسه را بررسی کنید.</strong></p>
 <?php elseif ((int)$row['owner_user_id']!==(int)$row['lead_owner_id'] || (int)$row['team_id']!==(int)$row['lead_team_id']): ?>
 <p><strong>ارجاع صندوق و سرنخ متفاوت است.</strong> سرنخ #<?= (int)$row['actual_lead_id'] ?>: <?= inquiry_h($row['actual_lead_name']) ?> — مسئول فعلی: <?= inquiry_h($row['lead_owner_name'] ?: 'نامشخص') ?> — تیم: <?= inquiry_h($row['lead_team_name'] ?: '—') ?>. قبل از تطبیق، هویت سرنخ و سابقه حذف/بازیابی آن را دستی بررسی کنید؛ شباهت شناسه به تنهایی کافی نیست.</p>
 <?php if (!$readonly && $reconcileReady && !empty($row['lead_owner_id'])): ?><form method="post" onsubmit="return confirm('هویت سرنخ و مسئول جدید بررسی شده است؟ دسترسی پیام به ارجاع فعلی سرنخ محدود می‌شود.');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="reconcile"><input type="hidden" name="lead_id" value="<?= (int)$row['actual_lead_id'] ?>"><input type="hidden" name="lead_owner" value="<?= (int)$row['lead_owner_id'] ?>"><input type="hidden" name="lead_team" value="<?= (int)$row['lead_team_id'] ?>"><button class="btn" type="submit">تطبیق دستی ارجاع با سرنخ</button></form><?php endif; ?>
 <?php endif; ?><?php endif; ?>
 <?php if (!$readonly && ($admin || in_array((int)$row['team_id'],$managed,true)) && empty($row['lead_id'])): ?>
 <form method="post" style="margin:8px 0"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="assign"><label>ارجاع به <select name="owner" required><?php foreach ($assignees as $person): ?><option value="<?= (int)$person['id'] ?>" <?= (int)$row['owner_user_id']===(int)$person['id']?'selected':'' ?>><?= inquiry_h($person['full_name']) ?></option><?php endforeach; ?></select></label> <button class="btn btn-primary" type="submit">ثبت ارجاع</button></form>
 <?php endif; ?>
 <?php if (!$readonly && !empty($row['owner_user_id']) && (empty($row['lead_id']) || !empty($row['actual_lead_id']))): ?>
 <form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?= $row['status']==='done'?'assigned':'done' ?>"><button class="btn" type="submit"><?= $row['status']==='done'?'بازگشت به پیگیری':'ثبت پاسخ‌داده‌شده' ?></button></form>
 <?php if (empty($row['lead_id'])): ?><form method="post" style="display:inline" onsubmit="return confirm('متن پیام در یادداشت سرنخ کپی می‌شود و دسترسی آن ممکن است گسترده‌تر باشد. مشتریان و مجوزها را بررسی کرده‌اید؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="lead"><button class="btn" type="submit">ساخت سرنخ</button></form>
 <?php else: ?><span>سرنخ #<?= (int)$row['lead_id'] ?> (ارجاع صندوق پس از ساخت سرنخ قفل است)</span><?php endif; ?>
 <?php endif; ?>
</div>
<?php endforeach; include 'includes/footer.php'; ?>
