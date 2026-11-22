<?php
/** صف شماره‌های سرد: ورود، تخصیص، پیگیری و تبدیل به مشتری. */
$pageTitle='شماره‌های سرد';
require_login();
require_once __DIR__ . '/../includes/crm_resources.php';
if (!ensure_crm_resources($pdo)) { include 'includes/header.php'; echo '<div class="alert alert-danger">ساخت جدول شماره‌ها ممکن نشد. دسترسی دیتابیس را بررسی کنید.</div>'; include 'includes/footer.php'; return; }
$me=(int)($_SESSION['user_id'] ?? 0); $managed=managed_team_ids($pdo);
$allManagers=is_strict_admin() || is_project_manager(); $isManager=$allManagers || !empty($managed);
$h=static fn($v)=>htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$states=['new'=>'جدید','contacted'=>'تماس گرفته شد','followup'=>'نیاز به پیگیری','qualified'=>'علاقه‌مند','lost'=>'عدم تمایل','converted'=>'تبدیل به مشتری'];
class ColdValidationException extends RuntimeException {}
function cold_phone(string $phone): string {
    $phone=strtr($phone, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    $phone=preg_replace('/\D/', '', $phone);
    if (strpos($phone,'0098')===0 && strlen($phone)===14) $phone='0'.substr($phone,4);
    elseif (strpos($phone,'98')===0 && strlen($phone)===12) $phone='0'.substr($phone,2);
    return strlen($phone)>=8 && strlen($phone)<=15 ? $phone : '';
}
function cold_can_work(array $r, bool $all, array $managed, int $me): bool {
    return $all || (int)($r['owner_user_id'] ?? 0)===$me || (!empty($managed) && in_array((int)($r['team_id'] ?? 0),$managed,true));
}
$staff=[];
if ($isManager) {
    if ($allManagers) $staff=$pdo->query("SELECT id,full_name,team_id FROM users WHERE status='active' AND team_id IS NOT NULL ORDER BY full_name")->fetchAll();
    else { $ph=implode(',',array_fill(0,count($managed),'?')); $st=$pdo->prepare("SELECT id,full_name,team_id FROM users WHERE status='active' AND team_id IN ($ph) ORDER BY full_name"); $st->execute($managed); $staff=$st->fetchAll(); }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=(string)($_POST['action'] ?? '');
    if ($action==='import' && $isManager) {
        $source=trim((string)($_POST['numbers'] ?? '')); $file=$_FILES['csv'] ?? null;
        if ($file && $file['error']!==UPLOAD_ERR_NO_FILE) {
            if ($file['error']!==UPLOAD_ERR_OK || (int)$file['size']>3*1024*1024) { showToast('فایل CSV باید حداکثر ۳ مگابایت باشد.', 'error'); redirect('index.php?page=crm_cold_numbers'); }
            $fp=fopen($file['tmp_name'],'rb');
        } else { if (strlen($source)>3*1024*1024) { showToast('فهرست بیش از حد بزرگ است.', 'error'); redirect('index.php?page=crm_cold_numbers'); } $fp=fopen('php://temp','w+'); fwrite($fp,$source); rewind($fp); }
        if (!$fp) { showToast('خواندن فهرست ممکن نشد.', 'error'); redirect('index.php?page=crm_cold_numbers'); }
        $added=0;$invalid=0;$duplicate=0;$count=0;
        try {
            $pdo->beginTransaction(); $insert=$pdo->prepare('INSERT IGNORE INTO crm_cold_numbers (phone,name,company,created_by) VALUES (?,?,?,?)');
            while (($row=fgetcsv($fp, 0, ','))!==false) {
                if (++$count>10000) throw new ColdValidationException('حداکثر ۱۰٬۰۰۰ ردیف در هر بار مجاز است.');
                if (count($row)===1 && trim((string)$row[0])==='') continue;
                $phone=cold_phone((string)($row[0] ?? '')); if (!$phone) { $invalid++; continue; }
                $insert->execute([$phone,mb_substr(trim((string)($row[1] ?? '')),0,150),mb_substr(trim((string)($row[2] ?? '')),0,200),$me]);
                if ($insert->rowCount()) $added++; else $duplicate++;
            }
            fclose($fp); $pdo->commit(); operations_audit($pdo,$me,'cold_import','crm_cold_numbers',0,"$added added");
            showToast("$added شماره ثبت شد؛ $duplicate تکراری و $invalid ردیف نامعتبر رد شد.");
        } catch (Throwable $e) { if (is_resource($fp)) fclose($fp); if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Cold import: '.$e->getMessage()); showToast($e instanceof ColdValidationException ? $e->getMessage() : 'ورود شماره‌ها ناموفق بود؛ تغییری ثبت نشد.', 'error'); }
        redirect('index.php?page=crm_cold_numbers');
    }
    if ($action==='assign' && $isManager) {
        $userId=(int)($_POST['user_id'] ?? 0); $amount=(int)($_POST['amount'] ?? 0); $target=null;
        foreach ($staff as $u) if ((int)$u['id']===$userId) { $target=$u; break; }
        if (!$target || $amount<1 || $amount>10000) { showToast('کارشناس یا تعداد معتبر نیست.', 'error'); redirect('index.php?page=crm_cold_numbers'); }
        try {
            $pdo->beginTransaction();
            // SELECT FOR UPDATE از تخصیص همزمان یک شماره به دو کارشناس جلوگیری می‌کند.
            $rows=$pdo->query("SELECT id FROM crm_cold_numbers WHERE owner_user_id IS NULL AND status='new' ORDER BY id LIMIT $amount FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            $update=$pdo->prepare("UPDATE crm_cold_numbers SET owner_user_id=?,team_id=? WHERE id=? AND owner_user_id IS NULL"); $n=0;
            foreach ($rows as $id) { $update->execute([$userId,(int)$target['team_id'],(int)$id]); $n += $update->rowCount(); }
            $pdo->commit(); operations_audit($pdo,$me,'cold_assign','user',$userId,"$n numbers"); showToast("$n شماره به کارشناس اختصاص یافت.");
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Cold assign: '.$e->getMessage()); showToast('تخصیص انجام نشد.', 'error'); }
        redirect('index.php?page=crm_cold_numbers');
    }
    if (in_array($action,['record','convert'],true)) {
        $id=(int)($_POST['id'] ?? 0);
        try {
            $pdo->beginTransaction(); $st=$pdo->prepare('SELECT * FROM crm_cold_numbers WHERE id=? FOR UPDATE'); $st->execute([$id]); $r=$st->fetch();
            if (!$r || !cold_can_work($r,$allManagers,$managed,$me) || empty($r['owner_user_id'])) throw new ColdValidationException('دسترسی ندارید یا شماره هنوز تخصیص داده نشده است.');
            if ($r['status']==='converted') throw new ColdValidationException('این شماره قبلاً تبدیل شده است.');
            if ($action==='record') {
                $outcome=(string)($_POST['outcome'] ?? ''); $note=trim((string)($_POST['note'] ?? ''));
                if (!isset($states[$outcome]) || in_array($outcome,['new','converted'],true) || $note==='') throw new ColdValidationException('نتیجه و توضیح تماس الزامی است.');
                $name=mb_substr(trim((string)($_POST['name'] ?? '')),0,150); $company=mb_substr(trim((string)($_POST['company'] ?? '')),0,200); $city=mb_substr(trim((string)($_POST['city'] ?? '')),0,100);
                $pdo->prepare('INSERT INTO crm_cold_calls (cold_id,user_id,outcome,note) VALUES (?,?,?,?)')->execute([$id,$me,$outcome,$note]);
                $pdo->prepare('UPDATE crm_cold_numbers SET name=?,company=?,city=?,status=? WHERE id=?')->execute([$name,$company,$city,$outcome,$id]);
                $pdo->commit(); showToast('نتیجهٔ تماس ثبت شد.'); redirect('index.php?page=crm_cold_numbers&view='.$id);
            }
            $name=trim((string)($r['company'] ?: $r['name']));
            if ($name==='') throw new ColdValidationException('ابتدا نام شخص یا شرکت را در نتیجهٔ تماس ثبت کنید.');
            $dupe=$pdo->prepare('SELECT id FROM crm_customers WHERE deleted_at IS NULL AND (phone=? OR mobile=?) LIMIT 1'); $dupe->execute([$r['phone'],$r['phone']]);
            if ($dupe->fetchColumn()) throw new ColdValidationException('این شماره در مشتریان وجود دارد؛ برای جلوگیری از ثبت تکراری تبدیل انجام نشد.');
            $pdo->prepare("INSERT INTO crm_customers (type,name,mobile,city,source,status,owner_user_id,team_id,created_by) VALUES (?,?,?,?,?,'active',?,?,?)")
                ->execute([$r['company'] ? 'company' : 'person',$name,$r['phone'],$r['city'],'شماره سرد',$r['owner_user_id'],$r['team_id'],$me]);
            $customerId=(int)$pdo->lastInsertId();
            if ($r['company'] && $r['name']) $pdo->prepare('INSERT INTO crm_contacts (customer_id,name,mobile,is_primary) VALUES (?,?,?,1)')->execute([$customerId,$r['name'],$r['phone']]);
            // تاریخچهٔ تماس به تایم‌لاین مشتری نیز منتقل می‌شود.
            $pdo->prepare("INSERT INTO crm_activities (customer_id,user_id,type,subject,body,activity_date,outcome) SELECT ?,user_id,'call','تماس از شماره سرد',note,created_at,outcome FROM crm_cold_calls WHERE cold_id=?")->execute([$customerId,$id]);
            $pdo->prepare("UPDATE crm_cold_numbers SET status='converted',customer_id=? WHERE id=?")->execute([$customerId,$id]);
            $pdo->commit(); operations_audit($pdo,$me,'cold_convert','crm_cold_numbers',$id,'customer #'.$customerId); showToast('شماره به مشتری تبدیل شد.'); redirect('index.php?page=crm_customers&view='.$customerId);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Cold action: '.$e->getMessage()); showToast($e instanceof ColdValidationException ? $e->getMessage() : 'ثبت اطلاعات انجام نشد.', 'error'); redirect('index.php?page=crm_cold_numbers&view='.$id); }
    }
    showToast('دسترسی به این عملیات وجود ندارد.', 'error'); redirect('index.php?page=crm_cold_numbers');
}
$where=$isManager ? '1=1' : 'n.owner_user_id=?'; $params=$isManager ? [] : [$me];
$q=trim((string)($_GET['q'] ?? '')); $status=(string)($_GET['status'] ?? '');
if ($q!=='') { $where.=' AND (n.phone LIKE ? OR n.name LIKE ? OR n.company LIKE ?)'; foreach(range(1,3) as $_) $params[]='%'.$q.'%'; }
if (isset($states[$status])) { $where.=' AND n.status=?'; $params[]=$status; }
$st=$pdo->prepare("SELECT COUNT(*) FROM crm_cold_numbers n WHERE $where"); $st->execute($params); $total=(int)$st->fetchColumn();
$page=max(1,min((int)($_GET['p'] ?? 1),max(1,(int)ceil($total/50)))); $offset=($page-1)*50;
$st=$pdo->prepare("SELECT n.*,u.full_name owner_name,t.name team_name FROM crm_cold_numbers n LEFT JOIN users u ON u.id=n.owner_user_id LEFT JOIN teams t ON t.id=n.team_id WHERE $where ORDER BY n.id DESC LIMIT 50 OFFSET $offset"); $st->execute($params); $rows=$st->fetchAll();
$item=null; $calls=[]; $id=(int)($_GET['view'] ?? 0);
if ($id) { $st=$pdo->prepare('SELECT n.*,u.full_name owner_name FROM crm_cold_numbers n LEFT JOIN users u ON u.id=n.owner_user_id WHERE n.id=?'); $st->execute([$id]); $item=$st->fetch() ?: null; if ($item && !$isManager && (int)$item['owner_user_id']!==$me) $item=null; if ($item) { $st=$pdo->prepare('SELECT c.*,u.full_name FROM crm_cold_calls c LEFT JOIN users u ON u.id=c.user_id WHERE c.cold_id=? ORDER BY c.id DESC'); $st->execute([$id]); $calls=$st->fetchAll(); } }
include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4" style="flex-wrap:wrap;gap:8px"><div><h2>📞 شماره‌های سرد</h2><span class="text-muted"><?= $isManager ? 'همهٔ شماره‌ها؛ تخصیص به اعضای تیم‌های تحت مدیریت' : 'شماره‌های تخصیص‌یافته به شما' ?></span></div></div>
<?php if ($isManager): ?><div class="grid-2">
<div class="card" style="padding:15px"><h3>ورود شماره‌ها</h3><p class="text-muted">فایل CSV با ستون‌های شماره، نام، شرکت (UTF-8)، یا یک شماره در هر خط. حداکثر ۱۰٬۰۰۰ ردیف در هر ورود؛ شمارهٔ تکراری ثبت نمی‌شود.</p><form method="post" enctype="multipart/form-data"><?= get_csrf_field() ?><input type="hidden" name="action" value="import"><input type="file" name="csv" accept=".csv,text/csv,text/plain" class="form-control"><p class="text-muted">یا شماره‌ها را اینجا بچسبانید:</p><textarea name="numbers" class="form-control" rows="3" placeholder="09120000000&#10;02112345678"></textarea><button class="btn btn-primary" style="margin-top:8px">ورود فهرست</button></form></div>
<div class="card" style="padding:15px"><h3>تقسیم شماره‌های تخصیص‌نیافته</h3><form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="assign"><label class="form-label">کارشناس تیم</label><select name="user_id" class="form-control" required><option value="">انتخاب کارشناس</option><?php foreach($staff as $u): ?><option value="<?= (int)$u['id'] ?>"><?= $h($u['full_name']) ?></option><?php endforeach ?></select><label class="form-label">تعداد</label><input type="number" name="amount" min="1" max="10000" required class="form-control" value="100"><button class="btn btn-primary" style="margin-top:8px">تخصیص</button></form><p class="text-muted">فقط شماره‌های جدید و بدون مسئول تقسیم می‌شوند؛ شمارهٔ تخصیص‌داده‌شده به‌صورت خودکار جابه‌جا نمی‌شود.</p></div></div><?php endif ?>
<?php if ($item): $work=cold_can_work($item,$allManagers,$managed,$me) && !empty($item['owner_user_id']); ?><div class="card" style="padding:18px;margin-top:15px"><div class="d-flex justify-between" style="gap:10px;flex-wrap:wrap"><h3><?= $h($item['company'] ?: ($item['name'] ?: 'بدون نام')) ?> — <span dir="ltr"><?= $h($item['phone']) ?></span></h3><a class="btn btn-ghost" href="index.php?page=crm_cold_numbers">بستن</a></div><p><a class="btn btn-ghost btn-sm" href="tel:<?= $h($item['phone']) ?>">تماس با این شماره</a> &nbsp; مسئول: <?= $h($item['owner_name'] ?: 'تخصیص‌نیافته') ?> | وضعیت: <?= $h($states[$item['status']] ?? $item['status']) ?> <?php if ($item['customer_id']): ?> | <a href="index.php?page=crm_customers&view=<?= (int)$item['customer_id'] ?>">مشاهده مشتری</a><?php endif ?></p>
<?php if ($work && $item['status']!=='converted'): ?><form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="record"><input type="hidden" name="id" value="<?= $id ?>"><div class="grid-2"><div class="form-group"><label class="form-label">نام شخص</label><input name="name" class="form-control" value="<?= $h($item['name']) ?>"></div><div class="form-group"><label class="form-label">نام شرکت</label><input name="company" class="form-control" value="<?= $h($item['company']) ?>"></div></div><div class="grid-2"><div class="form-group"><label class="form-label">شهر</label><input name="city" class="form-control" value="<?= $h($item['city']) ?>"></div><div class="form-group"><label class="form-label">نتیجه تماس</label><select name="outcome" class="form-control"><?php foreach(['contacted','followup','qualified','lost'] as $k): ?><option value="<?= $k ?>"><?= $h($states[$k]) ?></option><?php endforeach ?></select></div></div><label class="form-label">توضیح تماس *</label><textarea name="note" class="form-control" required rows="2"></textarea><button class="btn btn-primary" style="margin-top:8px">ثبت نتیجه و اطلاعات</button></form><form method="post" style="margin-top:10px" onsubmit="return confirm('این شماره به مشتری تبدیل شود؟')"><?= get_csrf_field() ?><input type="hidden" name="action" value="convert"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-ghost">تبدیل به مشتری</button></form><?php endif ?>
<h4 style="margin-top:16px">سوابق تماس</h4><?php foreach($calls as $call): ?><div style="padding:8px;border-bottom:1px solid var(--border)"><b><?= $h($call['full_name'] ?: '—') ?></b> — <?= $h($states[$call['outcome']] ?? $call['outcome']) ?> — <?= $h(to_jalali(substr($call['created_at'],0,10))) ?> <?= $h(substr($call['created_at'],11,5)) ?><br><?= nl2br($h($call['note'])) ?></div><?php endforeach ?><?php if (!$calls): ?><p class="text-muted">هنوز تماسی ثبت نشده است.</p><?php endif ?></div><?php endif ?>
<form method="get" class="d-flex gap-2" style="margin:16px 0;flex-wrap:wrap"><input type="hidden" name="page" value="crm_cold_numbers"><input name="q" class="form-control" style="max-width:280px" value="<?= $h($q) ?>" placeholder="شماره، نام یا شرکت"><select name="status" class="form-control" style="max-width:200px"><option value="">همه وضعیت‌ها</option><?php foreach($states as $k=>$label): ?><option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $h($label) ?></option><?php endforeach ?></select><button class="btn btn-ghost">جست‌وجو</button></form>
<div class="card overflow-x-auto"><table class="table"><thead><tr><th>شماره</th><th>نام / شرکت</th><th>مسئول</th><th>تیم</th><th>وضعیت</th><th></th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td dir="ltr"><?= $h($r['phone']) ?></td><td><?= $h($r['company'] ?: ($r['name'] ?: '—')) ?></td><td><?= $h($r['owner_name'] ?: '—') ?></td><td><?= $h($r['team_name'] ?: '—') ?></td><td><?= $h($states[$r['status']] ?? $r['status']) ?></td><td><a class="btn btn-ghost btn-sm" href="index.php?page=crm_cold_numbers&view=<?= (int)$r['id'] ?>">پیگیری</a></td></tr><?php endforeach ?><?php if (!$rows): ?><tr><td colspan="6">شماره‌ای پیدا نشد.</td></tr><?php endif ?></tbody></table></div>
<div style="margin:12px 0">صفحه <?= $page ?> از <?= max(1,(int)ceil($total/50)) ?> (<?= $total ?> شماره) <?php if($page>1): ?><a class="btn btn-ghost btn-sm" href="index.php?page=crm_cold_numbers&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>&p=<?= $page-1 ?>">قبلی</a><?php endif ?> <?php if($page*50<$total): ?><a class="btn btn-ghost btn-sm" href="index.php?page=crm_cold_numbers&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>&p=<?= $page+1 ?>">بعدی</a><?php endif ?></div>
<?php include 'includes/footer.php'; ?>
