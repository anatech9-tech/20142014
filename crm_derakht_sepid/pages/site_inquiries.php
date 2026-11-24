<?php
$pageTitle = 'درخواست‌های سایت';
require_login();
require_once __DIR__ . '/../includes/site_inquiries.php';
$me=(int)$_SESSION['user_id'];
$admin=is_strict_admin();
$managed=managed_team_ids($pdo);
// Unassigned inquiries are visible only to the administrator. Managers see only
// their teams, and experts only their own assignments; finance is not global here.
$scope='i.owner_user_id=?'; $scopeParams=[$me];
if ($admin) { $scope='1=1'; $scopeParams=[]; }
elseif ($managed) { $scope='(i.owner_user_id=? OR i.team_id IN ('.implode(',',array_fill(0,count($managed),'?')).'))'; $scopeParams=array_merge([$me],$managed); }
if (!ensure_site_inquiries($pdo)) {
    include 'includes/header.php'; echo '<div class="alert alert-danger">صندوق درخواست‌ها آماده نیست؛ مجوز ساخت جدول را بررسی کنید.</div>'; include 'includes/footer.php'; return;
}
$assignees=[];
if ($admin) $assignees=$pdo->query("SELECT id,full_name,team_id FROM users WHERE status='active' ORDER BY full_name")->fetchAll();
elseif ($managed) {
    $st=$pdo->prepare("SELECT id,full_name,team_id FROM users WHERE status='active' AND team_id IN (".implode(',',array_fill(0,count($managed),'?')).") ORDER BY full_name");
    $st->execute($managed); $assignees=$st->fetchAll();
}
$allowed=[]; foreach ($assignees as $person) $allowed[(int)$person['id']]=$person;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id=(int)($_POST['id'] ?? 0); $action=(string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();
        $st=$pdo->prepare("SELECT i.* FROM crm_site_inquiries i WHERE i.id=? AND ($scope) FOR UPDATE");
        $st->execute(array_merge([$id],$scopeParams)); $row=$st->fetch();
        if (!$row) throw new DomainException('دسترسی به درخواست مجاز نیست.');
        if ($action==='assign') {
            $uid=(int)($_POST['owner'] ?? 0);
            $team=(int)($row['team_id'] ?? 0);
            if ((!$admin && !in_array($team,$managed,true)) || !isset($allowed[$uid])) throw new DomainException('ارجاع خارج از محدودهٔ مجاز است.');
            if (!empty($row['lead_id'])) throw new DomainException('پس از ساخت سرنخ، ارجاع این درخواست قفل است؛ مسئول سرنخ را از صفحه سرنخ‌ها تغییر دهید.');
            $target=$allowed[$uid];
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
$st=$pdo->prepare("SELECT i.*,u.full_name AS owner_name,t.name AS team_name FROM crm_site_inquiries i LEFT JOIN users u ON u.id=i.owner_user_id LEFT JOIN teams t ON t.id=i.team_id WHERE $scope ORDER BY i.id DESC LIMIT 200");
$st->execute($scopeParams); $rows=$st->fetchAll();
function inquiry_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
include 'includes/header.php';
?>
<div class="page-header"><h1>درخواست‌های سایت</h1><p>درخواست‌های بدون مسئول فقط برای مدیر کل قابل مشاهده است. ایجاد سرنخ، مشتری تکراری را خودکار تشخیص نمی‌دهد.</p></div>
<?php if (!$rows): ?><div class="card">درخواستی در محدودهٔ دسترسی شما وجود ندارد.</div><?php endif; ?>
<?php foreach ($rows as $row): ?>
<div class="card" style="padding:18px;margin-bottom:16px;overflow-wrap:anywhere">
 <strong><?= inquiry_h($row['name']) ?></strong> — <?= inquiry_h($row['subject'] ?: 'بدون موضوع') ?>
 <p>دریافت: <?= inquiry_h(to_jalali(substr($row['received_at'],0,10))) ?> <?= inquiry_h(substr($row['received_at'],11,5)) ?> | مسئول: <?= inquiry_h($row['owner_name'] ?: 'تعیین نشده') ?> | تیم: <?= inquiry_h($row['team_name'] ?: '—') ?> | وضعیت: <?= $row['status']==='done'?'پاسخ داده شد':($row['status']==='assigned'?'در حال پیگیری':'جدید') ?></p>
 <p>ایمیل: <?= inquiry_h($row['email']) ?> | تلفن: <?= inquiry_h($row['phone'] ?: '—') ?></p>
 <p style="white-space:pre-wrap"><?= inquiry_h($row['message']) ?></p>
 <?php if (($admin || in_array((int)$row['team_id'],$managed,true)) && empty($row['lead_id'])): ?>
 <form method="post" style="margin:8px 0"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="assign"><label>ارجاع به <select name="owner" required><?php foreach ($assignees as $person): ?><option value="<?= (int)$person['id'] ?>" <?= (int)$row['owner_user_id']===(int)$person['id']?'selected':'' ?>><?= inquiry_h($person['full_name']) ?></option><?php endforeach; ?></select></label> <button class="btn btn-primary" type="submit">ثبت ارجاع</button></form>
 <?php endif; ?>
 <?php if (!empty($row['owner_user_id'])): ?>
 <form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?= $row['status']==='done'?'assigned':'done' ?>"><button class="btn" type="submit"><?= $row['status']==='done'?'بازگشت به پیگیری':'ثبت پاسخ‌داده‌شده' ?></button></form>
 <?php if (empty($row['lead_id'])): ?><form method="post" style="display:inline" onsubmit="return confirm('برای این درخواست سرنخ ساخته شود؟ ابتدا مشتریان موجود را بررسی کنید.');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="lead"><button class="btn" type="submit">ساخت سرنخ</button></form>
 <?php else: ?><span>سرنخ #<?= (int)$row['lead_id'] ?> (ارجاع صندوق پس از ساخت سرنخ قفل است)</span><?php endif; ?>
 <?php endif; ?>
</div>
<?php endforeach; include 'includes/footer.php'; ?>
