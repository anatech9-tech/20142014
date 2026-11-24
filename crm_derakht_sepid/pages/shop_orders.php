<?php
$pageTitle='سفارش‌های فروشگاه';
require_login();
require_once __DIR__.'/../includes/shop_order_sync.php';
require_once __DIR__.'/../includes/shop_order_work.php';
function shop_replica_h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'); }
$me=(int)$_SESSION['user_id'];
// A deactivated or demoted account's existing session must not expose order data.
try {
    $actorStmt=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $actorStmt->execute([$me]); $actor=$actorStmt->fetch(PDO::FETCH_ASSOC);
    if (!$actor || ($actor['status'] ?? '')!=='active' || ($actor['role'] ?? '')==='karfarma') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$admin=is_strict_admin() && ($actor['role'] ?? '')==='admin'; $managed=managed_team_ids($pdo);
[$scope,$scopeArgs]=shop_work_scope($me,$admin,$managed);
$readonly=is_nazer_kol() || in_array($actor['role'] ?? '', ['nazer','nazer_kol'],true) || (!access_is_special_account() && access_mode('crm_leads')==='viewer');
$statusLabels=['pending'=>'در انتظار','confirmed'=>'تأیید شده','packed'=>'آماده ارسال','shipped'=>'ارسال شده','delivered'=>'تحویل شده','cancelled'=>'لغو شده'];
$payLabels=['pending'=>'در انتظار','paid'=>'ثبت دستی پرداخت‌شده','failed'=>'ناموفق','refunded'=>'ثبت دستی بازپرداخت'];
$ready=crm_shop_orders_ensure($pdo) && shop_work_ensure($pdo);
$view=(int)($_GET['view'] ?? 0); $detail=null; $rows=[]; $next=0; $assignees=[]; $linkedCustomer=null; $customerOptions=[]; $history=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id=(int)($_POST['id'] ?? 0); $action=(string)($_POST['action'] ?? '');
    try {
        if (!$ready || $readonly) throw new DomainException('دسترسی برای تغییر مجاز نیست.');
        $version=(string)($_POST['version'] ?? '');
        if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$version)) throw new DomainException('صفحه را دوباره بارگذاری کنید.');
        $raw=(string)($_POST['value'] ?? '');
        if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$raw)) throw new DomainException('مقدار نامعتبر است.');
        shop_work_apply($pdo,$id,$me,$admin,$managed,$action,(int)$raw,(int)$version);
        try { operations_audit($pdo,$me,'shop_order_'.$action,'shop_order',$id,'ثبت پیگیری سفارش فروشگاه'); } catch (Throwable $e) { error_log('Shop order global audit failed'); }
        showToast('تغییر ثبت شد.');
    } catch (Throwable $e) {
        error_log('Shop order action failed: '.get_class($e));
        showToast($e instanceof DomainException ? $e->getMessage() : 'ثبت تغییر ممکن نشد.', 'error');
    }
    redirect('index.php?page=shop_orders&view='.$id);
}
$select='SELECT r.*,w.owner_user_id,w.team_id,w.crm_customer_id,w.version,u.full_name AS owner_name,t.name AS team_name FROM crm_shop_orders r LEFT JOIN crm_shop_order_work w ON w.replica_id=r.id LEFT JOIN users u ON u.id=w.owner_user_id LEFT JOIN teams t ON t.id=w.team_id';
if ($ready && $view>0) {
    $st=$pdo->prepare("$select WHERE r.id=? AND ($scope)"); $st->execute(array_merge([$view],$scopeArgs)); $detail=$st->fetch() ?: null;
    if ($detail) {
        $canAssign=$admin || in_array((int)$detail['team_id'],$managed,true);
        if ($canAssign && !$readonly) {
            if ($admin) $assignees=$pdo->query("SELECT id,full_name FROM users WHERE status='active' AND role<>'karfarma' AND username NOT IN ('yaser','javidhse') ORDER BY full_name")->fetchAll();
            elseif ($managed) { $st=$pdo->prepare("SELECT id,full_name FROM users WHERE status='active' AND role<>'karfarma' AND username NOT IN ('yaser','javidhse') AND team_id IN (".implode(',',array_fill(0,count($managed),'?')).") ORDER BY full_name"); $st->execute($managed); $assignees=$st->fetchAll(); }
        }
        if (!empty($detail['crm_customer_id'])) $linkedCustomer=shop_visible_customer($pdo,(int)$detail['crm_customer_id'],$me,$admin,$managed);
        $canLink=!$readonly && ($admin || empty($detail['crm_customer_id']) || $linkedCustomer);
        $search=trim((string)($_GET['customer_search'] ?? ''));
        if ($canLink && mb_strlen($search)>=2 && mb_strlen($search)<=100) {
            [$customerScope,$customerArgs]=shop_customer_scope($me,$admin,$managed);
            $st=$pdo->prepare("SELECT c.id,c.name,c.mobile,c.phone FROM crm_customers c WHERE c.deleted_at IS NULL AND ($customerScope) AND (c.name LIKE ? OR c.phone LIKE ? OR c.mobile LIKE ?) ORDER BY c.name LIMIT 30");
            $like='%'.$search.'%'; $st->execute(array_merge($customerArgs,[$like,$like,$like])); $customerOptions=$st->fetchAll();
        }
        $st=$pdo->prepare('SELECT e.action,e.created_at,u.full_name FROM crm_shop_order_work_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.replica_id=? ORDER BY e.id DESC LIMIT 20');
        $st->execute([$view]); $history=$st->fetchAll();
    }
} elseif ($ready) {
    $before=(int)($_GET['before'] ?? 0); $condition=$before>0 ? ' AND r.id<?' : '';
    $st=$pdo->prepare("$select WHERE ($scope)$condition ORDER BY r.id DESC LIMIT 51");
    $st->execute($before>0 ? array_merge($scopeArgs,[$before]) : $scopeArgs);
    $rows=$st->fetchAll(); if (count($rows)>50) { array_pop($rows); $next=(int)end($rows)['id']; }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>سفارش‌های فروشگاه</h1><p>اطلاعات فروشگاه فقط‌خواندنی و با تأخیر است؛ پرداخت ثبت‌شده به معنی وصول یا بازپرداخت تأییدشده نیست. ارجاع و اتصال مشتری فقط در CRM نگهداری می‌شود.</p></div>
<?php if (!$ready): ?><div class="card" style="padding:16px">جدول‌های سفارش آماده نیستند؛ مجوز دیتابیس را بررسی کنید.</div>
<?php elseif ($view>0): ?>
<p><a href="index.php?page=shop_orders">← بازگشت به فهرست</a></p>
<?php if (!$detail): ?><div class="card" style="padding:16px">سفارش یافت نشد یا در محدوده دسترسی شما نیست.</div>
<?php else: $o=json_decode((string)$detail['payload'],true); if (!is_array($o)) $o=[]; $version=(int)($detail['version'] ?? 0); ?>
<div class="card" style="padding:18px;overflow-wrap:anywhere">
  <h2>سفارش <?= shop_replica_h($o['order_no'] ?? '') ?></h2>
  <p>نام: <?= shop_replica_h($o['customer_name'] ?? '') ?> | تلفن: <?= shop_replica_h($o['customer_phone'] ?? '') ?> | ایمیل: <?= shop_replica_h($o['customer_email'] ?? '') ?></p>
  <p>شناسه سفارش فروشگاه: <?= (int)$detail['shop_order_id'] ?> | شناسه مشتری فروشگاه: <?= isset($o['customer_id']) ? (int)$o['customer_id'] : 'مهمان' ?></p>
  <p>مسئول: <?= shop_replica_h($detail['owner_name'] ?: 'تعیین نشده') ?> | تیم: <?= shop_replica_h($detail['team_name'] ?: '—') ?></p>
  <p>وضعیت سفارش: <?= shop_replica_h($statusLabels[$o['order_status'] ?? ''] ?? 'نامشخص') ?> | پرداخت: <?= shop_replica_h($payLabels[$o['payment_status'] ?? ''] ?? 'نامشخص') ?></p>
  <p>ثبت در فروشگاه: <?= shop_replica_h(to_jalali(substr((string)($o['created_at'] ?? ''),0,10))) ?> <?= shop_replica_h(substr((string)($o['created_at'] ?? ''),11,5)) ?> | آخرین دریافت در CRM: <?= shop_replica_h(to_jalali(substr($detail['received_at'],0,10))) ?> <?= shop_replica_h(substr($detail['received_at'],11,5)) ?></p>
  <div style="overflow-x:auto"><table class="data-table"><thead><tr><th>کالا</th><th>شناسه فروشگاه</th><th>تعداد</th><th>قیمت واحد (تومان)</th><th>جمع (تومان)</th></tr></thead><tbody>
  <?php foreach (($o['items'] ?? []) as $item): ?><tr><td><?= shop_replica_h($item['product_name'] ?? '') ?></td><td><?= isset($item['product_id']) ? (int)$item['product_id'] : '—' ?></td><td><?= (int)($item['qty'] ?? 0) ?></td><td><?= number_format((int)($item['price_toman'] ?? 0)) ?></td><td><?= number_format((int)($item['total_toman'] ?? 0)) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p>جمع کالاها: <?= number_format((int)($o['subtotal_toman'] ?? 0)) ?> | تخفیف: <?= number_format((int)($o['discount_toman'] ?? 0)) ?> | ارسال: <?= number_format((int)($o['shipping_fee'] ?? 0)) ?> | مبلغ کل: <strong><?= number_format((int)($o['total_toman'] ?? 0)) ?> تومان</strong></p>
  <p>آدرس، یادداشت مشتری و توکن سفارش منتقل نمی‌شوند. تغییر وضعیت و مرجوعی همچنان در فروشگاه انجام می‌شود.</p>
</div>
<?php if ($canAssign && !$readonly && $assignees): ?><div class="card" style="padding:18px;margin-top:14px"><h3>ارجاع سفارش</h3><form method="post"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="version" value="<?= $version ?>"><input type="hidden" name="action" value="assign"><select name="value" required><?php foreach ($assignees as $person): ?><option value="<?= (int)$person['id'] ?>" <?= (int)$detail['owner_user_id']===(int)$person['id']?'selected':'' ?>><?= shop_replica_h($person['full_name']) ?></option><?php endforeach; ?></select> <button class="btn btn-primary" type="submit">ثبت مسئول</button></form><p>مدیر تیم فقط به اعضای تیم‌های تحت مدیریت خود ارجاع می‌دهد.</p></div><?php endif; ?>
<div class="card" style="padding:18px;margin-top:14px"><h3>اتصال دستی به مشتری CRM</h3>
<?php if ($linkedCustomer): ?><p>مشتری متصل: <a href="index.php?page=crm_customers&amp;view=<?= (int)$linkedCustomer['id'] ?>"><?= shop_replica_h($linkedCustomer['name']) ?></a></p>
<?php elseif (!empty($detail['crm_customer_id'])): ?><p>مشتری متصل در محدوده دید نیست یا بایگانی شده است؛ مدیر کل می‌تواند اتصال را اصلاح کند.</p>
<?php else: ?><p>هنوز مشتری CRM متصل نشده است.</p><?php endif; ?>
<?php if ($canLink): ?>
<p>پیش از اتصال، هویت مشتری را بررسی کنید؛ اطلاعات یا حساب‌ها خودکار ادغام نمی‌شوند.</p>
<form method="get"><input type="hidden" name="page" value="shop_orders"><input type="hidden" name="view" value="<?= $view ?>"><input name="customer_search" minlength="2" maxlength="100" value="<?= shop_replica_h($search) ?>" placeholder="نام یا تلفن مشتری CRM" required> <button class="btn" type="submit">جست‌وجو</button></form>
<?php if ($search!=='' && mb_strlen($search)>=2): ?><p>حداکثر ۳۰ نتیجه در محدوده دسترسی شما:</p>
<?php if ($customerOptions): ?><form method="post" onsubmit="return confirm('هویت مشتری بررسی شد و این سفارش به او متصل شود؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="version" value="<?= $version ?>"><input type="hidden" name="action" value="link"><select name="value" required><?php foreach ($customerOptions as $cust): ?><option value="<?= (int)$cust['id'] ?>"><?= shop_replica_h($cust['name']) ?> — <?= shop_replica_h($cust['mobile'] ?: ($cust['phone'] ?: 'بدون تلفن')) ?></option><?php endforeach; ?></select> <button class="btn btn-primary" type="submit">اتصال مشتری</button></form><?php else: ?><p>موردی یافت نشد.</p><?php endif; ?><?php endif; ?>
<?php if ($linkedCustomer || ($admin && !empty($detail['crm_customer_id']))): ?><form method="post" style="margin-top:10px" onsubmit="return confirm('اتصال مشتری قطع شود؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="version" value="<?= $version ?>"><input type="hidden" name="action" value="link"><input type="hidden" name="value" value="0"><button class="btn" type="submit">قطع اتصال</button></form><?php endif; ?>
<?php endif; ?></div>
<?php if ($history): ?><div class="card" style="padding:18px;margin-top:14px"><h3>تاریخچه پیگیری در CRM</h3><?php foreach ($history as $event): ?><p><?= $event['action']==='assign'?'ارجاع تغییر کرد':'اتصال مشتری تغییر کرد' ?> — <?= shop_replica_h($event['full_name'] ?: 'کاربر حذف‌شده') ?> — <?= shop_replica_h(to_jalali(substr($event['created_at'],0,10))) ?> <?= shop_replica_h(substr($event['created_at'],11,5)) ?></p><?php endforeach; ?></div><?php endif; ?>
<?php endif; ?>
<?php else: ?>
<?php if (!$rows): ?><div class="card" style="padding:16px">سفارشی در محدوده دسترسی شما همگام نشده است.</div><?php endif; ?>
<div style="display:grid;gap:12px"><?php foreach ($rows as $r): $o=json_decode((string)$r['payload'],true); if (!is_array($o)) continue; ?>
<div class="card" style="padding:16px;overflow-wrap:anywhere"><strong><?= shop_replica_h($o['order_no'] ?? '') ?></strong> — <?= shop_replica_h($o['customer_name'] ?? '') ?> | <?= shop_replica_h($statusLabels[$o['order_status'] ?? ''] ?? 'نامشخص') ?> | <?= number_format((int)($o['total_toman'] ?? 0)) ?> تومان | مسئول: <?= shop_replica_h($r['owner_name'] ?: 'تعیین نشده') ?> | آخرین دریافت: <?= shop_replica_h(to_jalali(substr($r['received_at'],0,10))) ?> <?= shop_replica_h(substr($r['received_at'],11,5)) ?> <a class="btn" href="index.php?page=shop_orders&amp;view=<?= (int)$r['id'] ?>">جزئیات</a></div>
<?php endforeach; ?></div><?php if ($next): ?><p><a class="btn" href="index.php?page=shop_orders&amp;before=<?= $next ?>">سفارش‌های قدیمی‌تر</a></p><?php endif; ?>
<?php endif; include 'includes/footer.php'; ?>
