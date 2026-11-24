<?php
$pageTitle='سفارش‌های فروشگاه';
require_login();
require_once __DIR__.'/../includes/shop_order_sync.php';
require_once __DIR__.'/../includes/shop_order_work.php';
require_once __DIR__.'/../includes/shop_product_mapping.php';
require_once __DIR__.'/../includes/shop_order_requests.php';
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
$requestsReady=$ready && $admin && shop_status_request_ensure($pdo); $statusRequests=[]; $openRequests=[]; $nextRequest=0;
$productReady=false; $productLinks=[]; $productOptions=[]; $productHistory=[]; $selectedProduct=0; $productSearch='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id=(int)($_POST['id'] ?? 0); $action=(string)($_POST['action'] ?? '');
    try {
        if (!$ready || $readonly) throw new DomainException('دسترسی برای تغییر مجاز نیست.');
        if (in_array($action,['request_status','close_status_request'],true)) {
            if (!$admin || !$requestsReady) throw new DomainException('ثبت درخواست وضعیت مجاز یا آماده نیست.');
            $num=(string)($_POST['request_number'] ?? '');
            if (!preg_match('/^[1-9][0-9]{0,17}$/D',$num)) throw new DomainException('صفحه را دوباره بارگذاری کنید.');
            if ($action==='request_status') shop_status_request_create($pdo,$id,$me,$admin,(int)$num,(string)($_POST['target_status'] ?? ''));
            else shop_status_request_close($pdo,$id,(int)$num,$me,$admin);
            try { operations_audit($pdo,$me,'shop_order_'.$action,'shop_order',$id,'درخواست داخلی وضعیت سفارش'); } catch (Throwable $e) { error_log('Shop request global audit failed'); }
            showToast($action==='request_status' ? 'درخواست داخلی ثبت شد؛ وضعیت فروشگاه تغییر نکرد.' : 'درخواست بسته شد؛ این کار انجام شدن آن را تأیید نمی‌کند.');
            redirect('index.php?page=shop_orders&view='.$id);
        }
        $version=(string)($_POST['version'] ?? '');
        if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$version)) throw new DomainException('صفحه را دوباره بارگذاری کنید.');
        $raw=(string)($_POST['value'] ?? '');
        if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$raw)) throw new DomainException('مقدار نامعتبر است.');
        if ($action==='map_product') {
            $shopId=(string)($_POST['shop_product_id'] ?? '');
            if (!$admin || !preg_match('/^[1-9][0-9]{0,9}$/D',$shopId) || !shop_product_mapping_ensure($pdo)) throw new DomainException('اتصال کالا مجاز یا آماده نیست.');
            shop_product_mapping_apply($pdo,$id,$me,$admin,(int)$shopId,(int)$raw,(int)$version);
        } else {
            shop_work_apply($pdo,$id,$me,$admin,$managed,$action,(int)$raw,(int)$version);
        }
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
        if ($requestsReady) {
            $st=$pdo->prepare("SELECT q.*,u.full_name AS creator_name,c.full_name AS closer_name FROM crm_shop_status_requests q LEFT JOIN users u ON u.id=q.created_by LEFT JOIN users c ON c.id=q.closed_by WHERE q.replica_id=? ORDER BY CASE WHEN q.state='open' THEN 0 ELSE 1 END,q.id DESC LIMIT 20");
            $st->execute([$view]); $statusRequests=$st->fetchAll();
        }
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
        $snapshot=json_decode((string)$detail['payload'],true);
        if (!is_array($snapshot)) $snapshot=[];
        $productReady=shop_product_mapping_ensure($pdo);
        if ($productReady) {
            $productLinks=shop_product_links_for_order($pdo,(string)$detail['source_id'],$snapshot);
            $selectedProduct=(int)($_GET['map_product'] ?? 0);
            if (!$admin || $readonly || !shop_order_contains_product($snapshot,$selectedProduct)) $selectedProduct=0;
            if ($selectedProduct) {
                $productSearch=trim((string)($_GET['product_search'] ?? ''));
                if (mb_strlen($productSearch)>=2 && mb_strlen($productSearch)<=100) {
                    $st=$pdo->prepare('SELECT id,name,supplier FROM crm_products WHERE name LIKE ? OR specs LIKE ? OR supplier LIKE ? ORDER BY name LIMIT 30');
                    $like='%'.$productSearch.'%'; $st->execute([$like,$like,$like]); $productOptions=$st->fetchAll();
                }
                $st=$pdo->prepare('SELECT e.old_crm_product_id,e.new_crm_product_id,e.created_at,u.full_name FROM crm_shop_product_link_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.source_id=? AND e.shop_product_id=? ORDER BY e.id DESC LIMIT 20');
                $st->execute([$detail['source_id'],$selectedProduct]); $productHistory=$st->fetchAll();
            }
        }
    }
} elseif ($ready) {
    if ($requestsReady) {
        $requestBefore=max(0,(int)($_GET['request_before'] ?? 0));
        $requestQuery="SELECT q.id,q.replica_id,q.requested_status,q.created_at,r.payload FROM crm_shop_status_requests q JOIN crm_shop_orders r ON r.id=q.replica_id WHERE q.state='open'".($requestBefore>0 ? ' AND q.id<?' : '').' ORDER BY q.id DESC LIMIT 31';
        $st=$pdo->prepare($requestQuery); $st->execute($requestBefore>0 ? [$requestBefore] : []); $openRequests=$st->fetchAll();
        if (count($openRequests)>30) { array_pop($openRequests); $nextRequest=(int)end($openRequests)['id']; }
    }
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
  <div style="overflow-x:auto"><table class="data-table"><thead><tr><th>کالا</th><th>شناسه فروشگاه</th><th>تعداد</th><th>قیمت واحد (تومان)</th><th>جمع (تومان)</th><th>اتصال فعلی به بانک کالا</th></tr></thead><tbody>
  <?php foreach (($o['items'] ?? []) as $item): $pid=(int)($item['product_id'] ?? 0); $mapped=$productLinks[$pid] ?? null; ?><tr><td><?= shop_replica_h($item['product_name'] ?? '') ?></td><td><?= $pid ?: '—' ?></td><td><?= (int)($item['qty'] ?? 0) ?></td><td><?= number_format((int)($item['price_toman'] ?? 0)) ?></td><td><?= number_format((int)($item['total_toman'] ?? 0)) ?></td><td>
    <?php if (!$productReady): ?>در دسترس نیست<?php elseif ($mapped && !empty($mapped['crm_product_id']) && !empty($mapped['crm_product_name'])): ?><a href="index.php?page=crm_products&amp;view=<?= (int)$mapped['crm_product_id'] ?>"><?= shop_replica_h($mapped['crm_product_name']) ?></a><?php elseif ($mapped && !empty($mapped['crm_product_id'])): ?>کالای CRM یافت نشد<?php else: ?><?= $pid ? 'متصل نشده' : 'شناسه کالا موجود نیست' ?><?php endif; ?>
    <?php if ($admin && !$readonly && $productReady && $pid>0): ?> <a class="btn btn-sm" href="index.php?page=shop_orders&amp;view=<?= $view ?>&amp;map_product=<?= $pid ?>">بررسی اتصال</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p>جمع کالاها: <?= number_format((int)($o['subtotal_toman'] ?? 0)) ?> | تخفیف: <?= number_format((int)($o['discount_toman'] ?? 0)) ?> | ارسال: <?= number_format((int)($o['shipping_fee'] ?? 0)) ?> | مبلغ کل: <strong><?= number_format((int)($o['total_toman'] ?? 0)) ?> تومان</strong></p>
  <p>آدرس، یادداشت مشتری و توکن سفارش منتقل نمی‌شوند. تغییر وضعیت و مرجوعی همچنان در فروشگاه انجام می‌شود.</p>
</div>
<?php if ($admin): ?>
<div class="card" style="padding:18px;margin-top:14px"><h3>درخواست داخلی تغییر وضعیت</h3>
<p><strong>این بخش فقط ثبت پیگیری در CRM است و هیچ تغییری در فروشگاه ایجاد نمی‌کند یا به آن ارسال نمی‌شود.</strong> قبل از اقدام دستی در پنل فروشگاه، وضعیت و پرداخت فعلی را همان‌جا بررسی کنید؛ اطلاعات این صفحه ممکن است با تأخیر باشد. بستن درخواست هم تأیید انجام یا وصول وجه نیست.</p>
<?php if (!$requestsReady): ?><p>جدول درخواست‌ها آماده نیست؛ مجوز دیتابیس را بررسی کنید.</p>
<?php else: $hasOpen=false; foreach ($statusRequests as $request): if ($request['state']==='open') $hasOpen=true; ?>
<div style="border-top:1px solid #ddd;padding:12px 0">
<strong><?= $request['state']==='open'?'درخواست باز':'درخواست بسته' ?> #<?= (int)$request['id'] ?></strong> — از <?= shop_replica_h($statusLabels[$request['expected_status']] ?? 'نامشخص') ?> به <?= shop_replica_h($statusLabels[$request['requested_status']] ?? 'نامشخص') ?>؛ پرداخت در زمان درخواست: <?= shop_replica_h($payLabels[$request['expected_payment']] ?? 'نامشخص') ?><br>
<small>ثبت‌کننده: <?= shop_replica_h($request['creator_name'] ?: 'کاربر حذف‌شده') ?> | ثبت: <?= shop_replica_h($request['created_at']) ?> | نسخه مبنا: <?= (int)$request['replica_revision'] ?> | نسخه فعلی CRM: <?= (int)$detail['revision'] ?></small>
<?php if ((int)$request['replica_revision']!==(int)$detail['revision']): ?><p>نسخهٔ دریافتی پس از ثبت درخواست تغییر کرده است؛ پیش از اقدام دستی، وضعیت را مجدداً تطبیق دهید. تطابق وضعیت اثبات انجام این درخواست نیست.</p><?php endif; ?>
<?php if ($request['state']==='closed'): ?><p>بسته‌شده توسط <?= shop_replica_h($request['closer_name'] ?: 'کاربر حذف‌شده') ?> در <?= shop_replica_h($request['closed_at']) ?>؛ انجام سفارش تأیید نشده است.</p>
<?php elseif (!$readonly): ?><form method="post" onsubmit="return confirm('درخواست صرفاً در CRM بسته شود؟ این کار انجام شدن آن را تأیید نمی‌کند.');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="close_status_request"><input type="hidden" name="request_number" value="<?= (int)$request['id'] ?>"><button class="btn" type="submit">بستن درخواست داخلی</button></form><?php endif; ?>
</div><?php endforeach; ?>
<?php $targets=shop_status_request_targets((string)($o['order_status'] ?? '')); if (!$readonly && !$hasOpen && $targets): ?>
<form method="post" onsubmit="return confirm('درخواست فقط در CRM ثبت می‌شود و وضعیت فروشگاه تغییر نمی‌کند. ادامه می‌دهید؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="request_status"><input type="hidden" name="request_number" value="<?= (int)$detail['revision'] ?>"><label>وضعیت درخواستی <select name="target_status" required><?php foreach ($targets as $target): ?><option value="<?= shop_replica_h($target) ?>"><?= shop_replica_h($statusLabels[$target]) ?></option><?php endforeach; ?></select></label> <button class="btn btn-primary" type="submit">ثبت درخواست داخلی</button></form>
<p>لغو در پنل فروشگاه ممکن است موجودی را بازگرداند و مصرف کوپن را بازنمی‌گرداند؛ سفارش ارسال‌شده یا تحویل‌شده از این مسیر لغو نمی‌شود.</p>
<?php elseif ($hasOpen): ?><p>برای ثبت درخواست جدید، ابتدا درخواست باز را بررسی و ببندید.</p><?php else: ?><p>برای این وضعیت، گام بعدی در این بخش تعریف نشده است.</p><?php endif; ?>
<?php endif; ?></div><?php endif; ?>
<?php if ($admin && !$productReady): ?><div class="card" style="padding:16px;margin-top:14px">اتصال کالا آماده نیست؛ ابتدا بانک کالا و مجوز ساخت جدول را بررسی کنید.</div><?php endif; ?>
<?php if ($selectedProduct && $productReady && $admin && !$readonly): $mapping=$productLinks[$selectedProduct] ?? null; $mapVersion=(int)($mapping['version'] ?? 0); ?>
<div class="card" style="padding:18px;margin-top:14px"><h3>اتصال کالای فروشگاه #<?= $selectedProduct ?> به بانک کالا</h3>
<p>این اتصال هویتِ فعلی کالا را برای همه سفارش‌های دارای همین شناسه تغییر می‌دهد، نه صرفاً این سفارش. قیمت، موجودی، اطلاعات فروشگاه و انتشار را تغییر نمی‌دهد؛ تطبیق مشخصات را دستی بررسی کنید.</p>
<form method="get"><input type="hidden" name="page" value="shop_orders"><input type="hidden" name="view" value="<?= $view ?>"><input type="hidden" name="map_product" value="<?= $selectedProduct ?>"><input name="product_search" minlength="2" maxlength="100" value="<?= shop_replica_h($productSearch) ?>" placeholder="نام یا تأمین‌کننده در بانک کالا" required> <button class="btn" type="submit">جست‌وجو</button></form>
<?php if ($productSearch!=='' && mb_strlen($productSearch)>=2): ?><p>حداکثر ۳۰ نتیجه:</p>
<?php if ($productOptions): ?><form method="post" onsubmit="return confirm('مشخصات دو کالا بررسی شده و اتصال فعلی برای همه سفارش‌ها ثبت شود؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="map_product"><input type="hidden" name="shop_product_id" value="<?= $selectedProduct ?>"><input type="hidden" name="version" value="<?= $mapVersion ?>"><select name="value" required><?php foreach ($productOptions as $product): ?><option value="<?= (int)$product['id'] ?>"><?= shop_replica_h($product['name']) ?> — <?= shop_replica_h($product['supplier'] ?: 'بدون تأمین‌کننده') ?></option><?php endforeach; ?></select> <button class="btn btn-primary" type="submit">تأیید اتصال کالا</button></form><?php else: ?><p>کالایی یافت نشد.</p><?php endif; ?><?php endif; ?>
<?php if ($mapping && !empty($mapping['crm_product_id'])): ?><form method="post" style="margin-top:10px" onsubmit="return confirm('اتصال فعلی کالا برای همه سفارش‌ها قطع شود؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="map_product"><input type="hidden" name="shop_product_id" value="<?= $selectedProduct ?>"><input type="hidden" name="version" value="<?= $mapVersion ?>"><input type="hidden" name="value" value="0"><button class="btn" type="submit">قطع اتصال کالا</button></form><?php endif; ?>
<?php if ($productHistory): ?><h4>تاریخچه اتصال</h4><?php foreach ($productHistory as $e): ?><p>از شناسه CRM <?= (int)($e['old_crm_product_id'] ?? 0) ?: '—' ?> به <?= (int)($e['new_crm_product_id'] ?? 0) ?: '—' ?> | <?= shop_replica_h($e['full_name'] ?: 'کاربر حذف‌شده') ?> | <?= shop_replica_h(to_jalali(substr($e['created_at'],0,10))) ?> <?= shop_replica_h(substr($e['created_at'],11,5)) ?></p><?php endforeach; ?><?php endif; ?>
</div><?php endif; ?>
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
<?php if ($requestsReady && $openRequests): ?><div class="card" style="padding:16px;margin-bottom:14px"><h3>درخواست‌های داخلی باز (حداکثر ۳۰ مورد اخیر)</h3><p>این درخواست‌ها به فروشگاه ارسال نشده‌اند و به‌تنهایی وضعیتی را تغییر نمی‌دهند.</p><?php foreach ($openRequests as $req): $reqOrder=json_decode((string)$req['payload'],true); ?><p><a href="index.php?page=shop_orders&amp;view=<?= (int)$req['replica_id'] ?>"><?= shop_replica_h(is_array($reqOrder) ? ($reqOrder['order_no'] ?? '') : '') ?></a> — درخواست <?= shop_replica_h($statusLabels[$req['requested_status']] ?? 'نامشخص') ?> — <?= shop_replica_h($req['created_at']) ?></p><?php endforeach; ?><?php if ($nextRequest): ?><a class="btn" href="index.php?page=shop_orders&amp;request_before=<?= $nextRequest ?>">درخواست‌های باز قدیمی‌تر</a><?php endif; ?><?php if (!empty($_GET['request_before'])): ?> <a href="index.php?page=shop_orders">بازگشت به درخواست‌های جدیدتر</a><?php endif; ?></div><?php endif; ?>
<?php if (!$rows): ?><div class="card" style="padding:16px">سفارشی در محدوده دسترسی شما همگام نشده است.</div><?php endif; ?>
<div style="display:grid;gap:12px"><?php foreach ($rows as $r): $o=json_decode((string)$r['payload'],true); if (!is_array($o)) continue; ?>
<div class="card" style="padding:16px;overflow-wrap:anywhere"><strong><?= shop_replica_h($o['order_no'] ?? '') ?></strong> — <?= shop_replica_h($o['customer_name'] ?? '') ?> | <?= shop_replica_h($statusLabels[$o['order_status'] ?? ''] ?? 'نامشخص') ?> | <?= number_format((int)($o['total_toman'] ?? 0)) ?> تومان | مسئول: <?= shop_replica_h($r['owner_name'] ?: 'تعیین نشده') ?> | آخرین دریافت: <?= shop_replica_h(to_jalali(substr($r['received_at'],0,10))) ?> <?= shop_replica_h(substr($r['received_at'],11,5)) ?> <a class="btn" href="index.php?page=shop_orders&amp;view=<?= (int)$r['id'] ?>">جزئیات</a></div>
<?php endforeach; ?></div><?php if ($next): ?><p><a class="btn" href="index.php?page=shop_orders&amp;before=<?= $next ?>">سفارش‌های قدیمی‌تر</a></p><?php endif; ?>
<?php endif; include 'includes/footer.php'; ?>
