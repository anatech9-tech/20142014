<?php
$pageTitle='سفارش‌های فروشگاه';
require_login();
require_once __DIR__.'/../includes/shop_order_sync.php';
require_once __DIR__.'/../includes/shop_order_work.php';
require_once __DIR__.'/../includes/shop_product_mapping.php';
require_once __DIR__.'/../includes/shop_order_requests.php';
require_once __DIR__.'/../includes/shop_return_requests.php';
require_once __DIR__.'/../includes/shop_shipping_tracking.php';
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
$requestsReady=$ready && $admin && shop_status_request_ensure($pdo);
$commandsReady=$requestsReady && crm_shop_command_ensure($pdo); $commandsEnabled=$commandsReady && crm_shop_command_configured();
$statusRequests=[]; $openRequests=[]; $nextRequest=0;
$returnReady=$ready && $admin && shop_return_request_ensure($pdo);
$returnRequests=[]; $openReturns=[]; $nextReturn=0; $returnReasons=shop_return_reasons();
$shippingReady=$ready && $admin && shop_shipping_ensure($pdo); $shipping=null; $shippingMismatch=false; $shippingEvents=[];
$productReady=false; $productLinks=[]; $productOptions=[]; $productHistory=[]; $selectedProduct=0; $productSearch='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id=(int)($_POST['id'] ?? 0); $action=(string)($_POST['action'] ?? '');
    try {
        if (!$ready || $readonly) throw new DomainException('دسترسی برای تغییر مجاز نیست.');
        if (in_array($action,['save_shipping','clear_shipping'],true)) {
            if (!$admin || !$shippingReady) throw new DomainException('ثبت رهگیری مجاز یا آماده نیست.');
            foreach (['shipping_revision','shipping_version'] as $key) {
                if (!is_string($_POST[$key] ?? null) || !preg_match('/^(0|[1-9][0-9]{0,17})$/D',$_POST[$key])) throw new DomainException('صفحه را دوباره بارگذاری کنید.');
            }
            $carrier=$_POST['carrier'] ?? ''; $tracking=$_POST['tracking_code'] ?? '';
            if (!is_string($carrier) || !is_string($tracking)) throw new DomainException('اطلاعات رهگیری نامعتبر است.');
            shop_shipping_apply($pdo,$id,$me,$admin,(int)$_POST['shipping_revision'],(int)$_POST['shipping_version'],$action==='save_shipping'?'save':'clear',$carrier,$tracking);
            try { operations_audit($pdo,$me,'shop_order_'.$action,'shop_order',$id,'تغییر رهگیری داخلی'); } catch (Throwable $e) { error_log('Shop shipping global audit failed'); }
            showToast('رهگیری فقط در CRM تغییر کرد؛ وضعیت فروشگاه و ارسال واقعی تغییر نکرد.');
            redirect('index.php?page=shop_orders&view='.$id);
        }
        if (in_array($action,['request_return','close_return'],true)) {
            if (!$admin || !$returnReady) throw new DomainException('بررسی مرجوعی مجاز یا آماده نیست.');
            $num=$_POST['return_number'] ?? null;
            if (!is_string($num) || !preg_match('/^[1-9][0-9]{0,17}$/D',$num)) throw new DomainException('صفحه را دوباره بارگذاری کنید.');
            if ($action==='request_return') {
                $reason=$_POST['reason'] ?? null;
                if (!is_string($reason)) throw new DomainException('دلیل نامعتبر است.');
                shop_return_request_create($pdo,$id,$me,$admin,(int)$num,$reason);
            } else shop_return_request_close($pdo,$id,(int)$num,$me,$admin);
            try { operations_audit($pdo,$me,'shop_order_'.$action,'shop_order',$id,'پیگیری داخلی مرجوعی'); } catch (Throwable $e) { error_log('Shop return global audit failed'); }
            showToast($action==='request_return' ? 'درخواست بررسی در CRM ثبت شد؛ مرجوعی یا بازپرداخت انجام نشده است.' : 'پیگیری داخلی بسته شد؛ انجام مرجوعی یا بازپرداخت تأیید نمی‌شود.');
            redirect('index.php?page=shop_orders&view='.$id);
        }
        if (in_array($action,['request_status','close_status_request','send_status_command'],true)) {
            if (!$admin || !$requestsReady) throw new DomainException('ثبت درخواست وضعیت مجاز یا آماده نیست.');
            $num=(string)($_POST['request_number'] ?? '');
            if (!preg_match('/^[1-9][0-9]{0,17}$/D',$num)) throw new DomainException('صفحه را دوباره بارگذاری کنید.');
            if ($action==='request_status') shop_status_request_create($pdo,$id,$me,$admin,(int)$num,(string)($_POST['target_status'] ?? ''));
            elseif ($action==='send_status_command') {
                if (!$commandsEnabled) throw new DomainException('ارسال فرمان فعال یا آماده نیست.');
                crm_shop_command_enqueue($pdo,$id,(int)$num,$me,$admin,time(),(string)SHOP_CRM_SOURCE_ID,($_POST['cancel_unpaid'] ?? null)==='1' && ($_POST['cancel_unshipped'] ?? null)==='1');
            } else shop_status_request_close($pdo,$id,(int)$num,$me,$admin);
            try { operations_audit($pdo,$me,'shop_order_'.$action,'shop_order',$id,'درخواست داخلی وضعیت سفارش'); } catch (Throwable $e) { error_log('Shop request global audit failed'); }
            showToast($action==='request_status' ? 'درخواست داخلی ثبت شد؛ وضعیت فروشگاه تغییر نکرد.' : ($action==='send_status_command' ? 'فرمان در صف قرار گرفت؛ هنوز اجرا تأیید نشده است.' : 'درخواست بسته شد؛ این کار انجام شدن آن را تأیید نمی‌کند.'));
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
            $st=$pdo->prepare("SELECT q.*,u.full_name AS creator_name,c.full_name AS closer_name".($commandsReady ? ',cmd.state AS command_state,cmd.command_id' : ',NULL AS command_state,NULL AS command_id')." FROM crm_shop_status_requests q LEFT JOIN users u ON u.id=q.created_by LEFT JOIN users c ON c.id=q.closed_by".($commandsReady ? ' LEFT JOIN crm_shop_order_commands cmd ON cmd.request_id=q.id' : '')." WHERE q.replica_id=? ORDER BY CASE WHEN q.state='open' THEN 0 ELSE 1 END,q.id DESC LIMIT 20");
            $st->execute([$view]); $statusRequests=$st->fetchAll();
        }
        if ($shippingReady) {
            $st=$pdo->prepare('SELECT * FROM crm_shop_shipping_tracking WHERE replica_id=?'); $st->execute([$view]); $shipping=$st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($shipping && ($shipping['source_id']!==$detail['source_id'] || (int)$shipping['shop_order_id']!==(int)$detail['shop_order_id'])) { $shipping=null; $shippingMismatch=true; }
            else {
                $st=$pdo->prepare('SELECT e.action,e.version,e.replica_revision,e.created_at,u.full_name FROM crm_shop_shipping_tracking_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.replica_id=? AND e.source_id=? AND e.shop_order_id=? ORDER BY e.id DESC LIMIT 20');
                $st->execute([$view,$detail['source_id'],$detail['shop_order_id']]); $shippingEvents=$st->fetchAll();
            }
        }
        if ($returnReady) {
            $st=$pdo->prepare("SELECT q.*,u.full_name AS creator_name,c.full_name AS closer_name FROM crm_shop_return_requests q LEFT JOIN users u ON u.id=q.created_by LEFT JOIN users c ON c.id=q.closed_by WHERE q.replica_id=? ORDER BY CASE WHEN q.state='open' THEN 0 ELSE 1 END,q.id DESC LIMIT 20");
            $st->execute([$view]); $returnRequests=$st->fetchAll();
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
    if ($returnReady) {
        $returnBefore=max(0,(int)($_GET['return_before'] ?? 0));
        $sql="SELECT q.id,q.replica_id,q.reason,q.created_at,q.source_id,q.shop_order_id,r.source_id AS current_source,r.shop_order_id AS current_order,r.payload FROM crm_shop_return_requests q JOIN crm_shop_orders r ON r.id=q.replica_id WHERE q.state='open'".($returnBefore>0 ? ' AND q.id<?' : '').' ORDER BY q.id DESC LIMIT 31';
        $st=$pdo->prepare($sql); $st->execute($returnBefore>0 ? [$returnBefore] : []); $openReturns=$st->fetchAll();
        if (count($openReturns)>30) { array_pop($openReturns); $nextReturn=(int)end($openReturns)['id']; }
    }
    $before=(int)($_GET['before'] ?? 0); $condition=$before>0 ? ' AND r.id<?' : '';
    $st=$pdo->prepare("$select WHERE ($scope)$condition ORDER BY r.id DESC LIMIT 51");
    $st->execute($before>0 ? array_merge($scopeArgs,[$before]) : $scopeArgs);
    $rows=$st->fetchAll(); if (count($rows)>50) { array_pop($rows); $next=(int)end($rows)['id']; }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>سفارش‌های فروشگاه</h1><p>نسخه سفارش فروشگاه با تأخیر دریافت می‌شود؛ فرمان‌های محدود در صورت فعال‌سازی جداگانه‌اند و پرداخت ثبت‌شده به معنی وصول یا بازپرداخت تأییدشده نیست. ارجاع و اتصال مشتری فقط در CRM نگهداری می‌شود.</p></div>
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
  <p>آدرس، یادداشت مشتری و توکن سفارش منتقل نمی‌شوند. پرداخت، ارسال و مرجوعی باید در فروشگاه بررسی شوند؛ لغو محدود فقط با فعال‌سازی مستقل و بررسی مدیر در دسترس است.</p>
</div>
<?php if ($admin): ?>
<div class="card" style="padding:18px;margin-top:14px"><h3>درخواست داخلی تغییر وضعیت</h3>
<p><strong>ثبت درخواست به‌تنهایی تغییری در فروشگاه ایجاد نمی‌کند.</strong> ارسال فرمان فقط در صورت فعال‌سازی و تأیید جداگانه مدیر ممکن است؛ لغو محدود کلید فعال‌سازی مستقل و بررسی مضاعف دارد. اطلاعات CRM ممکن است با تأخیر باشد؛ بستن درخواست تأیید انجام یا وصول وجه نیست.</p>
<?php if (!$requestsReady): ?><p>جدول درخواست‌ها آماده نیست؛ مجوز دیتابیس را بررسی کنید.</p>
<?php else: if (!$commandsReady): ?><p>بررسی صف فرمان ممکن نیست؛ بستن درخواست تا رفع مشکل مجاز نیست.</p><?php endif; $hasOpen=false; foreach ($statusRequests as $request): if ($request['state']==='open') $hasOpen=true; ?>
<div style="border-top:1px solid #ddd;padding:12px 0">
<strong><?= $request['state']==='open'?'درخواست باز':'درخواست بسته' ?> #<?= (int)$request['id'] ?></strong> — از <?= shop_replica_h($statusLabels[$request['expected_status']] ?? 'نامشخص') ?> به <?= shop_replica_h($statusLabels[$request['requested_status']] ?? 'نامشخص') ?>؛ پرداخت در زمان درخواست: <?= shop_replica_h($payLabels[$request['expected_payment']] ?? 'نامشخص') ?><br>
<small>ثبت‌کننده: <?= shop_replica_h($request['creator_name'] ?: 'کاربر حذف‌شده') ?> | ثبت: <?= shop_replica_h($request['created_at']) ?> | نسخه مبنا: <?= (int)$request['replica_revision'] ?> | نسخه فعلی CRM: <?= (int)$detail['revision'] ?></small>
<?php if ((int)$request['replica_revision']!==(int)$detail['revision']): ?><p>نسخهٔ دریافتی پس از ثبت درخواست تغییر کرده است؛ پیش از اقدام دستی، وضعیت را مجدداً تطبیق دهید. تطابق وضعیت اثبات انجام این درخواست نیست.</p><?php endif; ?>
<?php if (!empty($request['command_state'])): $commandLabels=['queued'=>'در صف؛ هنوز نتیجه مشخص نیست','applied'=>'اجرای فرمان در فروشگاه تأیید شد؛ وضعیت فعلی را مجدداً تطبیق دهید','conflict'=>'رد شد: سفارش یا شرایط لازم، مانند نسخه، کوپن یا موجودی، نیاز به بررسی دارد','expired'=>'رد شد: مهلت فرمان تمام شد']; ?><p>فرمان: <?= shop_replica_h($commandLabels[$request['command_state']] ?? 'نامشخص') ?> | شناسه: <?= shop_replica_h($request['command_id']) ?></p><?php endif; ?>
<?php if ($commandsEnabled && !$readonly && $request['state']==='open' && empty($request['command_state']) && (int)$request['replica_revision']===(int)$detail['revision'] && (in_array($request['requested_status'],['confirmed','packed'],true) || (crm_shop_cancel_enabled() && $request['requested_status']==='cancelled' && $request['expected_payment']==='pending')) && !empty($o['order_event_id'])): ?><form method="post" onsubmit="return confirm('<?= $request['requested_status']==='cancelled' ? 'فقط پس از بررسی مستقل: وجهی دریافت نشده و سفارش ارسال نشده است؟ در صورت اجرای لغو، موجودی بازمی‌گردد؛ هیچ بازپرداختی انجام نمی‌شود و نتیجه ممکن است نامشخص بماند.' : 'فرمان تغییر وضعیت غیرمالی به فروشگاه ارسال شود؟ اجرای آن قطعی نیست و نتیجه پس از پردازش نمایش داده می‌شود.' ?>');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="send_status_command"><input type="hidden" name="request_number" value="<?= (int)$request['id'] ?>"><?php if ($request['requested_status']==='cancelled'): ?><label><input type="checkbox" name="cancel_unpaid" value="1" required> بررسی مستقل: وجهی دریافت نشده است</label> <label><input type="checkbox" name="cancel_unshipped" value="1" required> بررسی مستقل: سفارش ارسال نشده است</label> <?php endif; ?><button class="btn btn-primary" type="submit">تأیید و ثبت فرمان در صف</button></form><?php endif; ?>
<?php if ($request['state']==='closed'): ?><p>بسته‌شده توسط <?= shop_replica_h($request['closer_name'] ?: 'کاربر حذف‌شده') ?> در <?= shop_replica_h($request['closed_at']) ?>؛ انجام سفارش تأیید نشده است.</p>
<?php elseif (!$readonly && ($request['command_state'] ?? '')!=='queued'): ?><form method="post" onsubmit="return confirm('درخواست صرفاً در CRM بسته شود؟ این کار انجام شدن آن را تأیید نمی‌کند.');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="close_status_request"><input type="hidden" name="request_number" value="<?= (int)$request['id'] ?>"><button class="btn" type="submit">بستن درخواست داخلی</button></form><?php endif; ?>
</div><?php endforeach; ?>
<?php $targets=shop_status_request_targets((string)($o['order_status'] ?? '')); if (!$readonly && !$hasOpen && $targets): ?>
<form method="post" onsubmit="return confirm('درخواست فقط در CRM ثبت می‌شود و وضعیت فروشگاه تغییر نمی‌کند. ادامه می‌دهید؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="request_status"><input type="hidden" name="request_number" value="<?= (int)$detail['revision'] ?>"><label>وضعیت درخواستی <select name="target_status" required><?php foreach ($targets as $target): ?><option value="<?= shop_replica_h($target) ?>"><?= shop_replica_h($statusLabels[$target]) ?></option><?php endforeach; ?></select></label> <button class="btn btn-primary" type="submit">ثبت درخواست داخلی</button></form>
<p>لغو محدود از CRM فقط با فعال‌سازی مستقل و بررسی دستی مدیر ممکن است و سفارش دارای کوپن، تخفیف، محصول حذف‌شده یا پرداخت ثبت‌شده را نمی‌پذیرد. پرداخت واقعی و عدم ارسال باید جداگانه بررسی شوند؛ هیچ بازپرداختی انجام نمی‌شود. لغو در پنل فروشگاه ممکن است موجودی را بازگرداند و مصرف کوپن را بازنمی‌گرداند.</p>
<?php elseif ($hasOpen): ?><p>برای ثبت درخواست جدید، ابتدا درخواست باز را بررسی و ببندید.</p><?php else: ?><p>برای این وضعیت، گام بعدی در این بخش تعریف نشده است.</p><?php endif; ?>
<?php endif; ?></div><?php endif; ?>
<?php if ($admin): ?><div class="card" style="padding:18px;margin-top:14px"><h3>رهگیری داخلی ارسال</h3>
<p>این اطلاعات فقط در CRM ثبت می‌شود؛ به فروشگاه، مشتری یا شرکت حمل ارسال نمی‌شود و اثبات ارسال یا تحویل نیست. جدا از محموله‌های مستقل سایت است. قبل از هر اقدام، اطلاعات و وضعیت را در فروشگاه و شرکت حمل بررسی کنید. آدرس، تلفن و اطلاعات محرمانه در این کادرها وارد نکنید.</p>
<?php if (!$shippingReady): ?><p>جدول رهگیری آماده نیست.</p>
<?php elseif ($shippingMismatch): ?><p><strong>هویت رهگیری با سفارش فعلی ناسازگار است؛ برای حفظ اطلاعات، نمایش و تغییر مسدود است. مدیر باید پس از بکاپ تطبیق کند.</strong></p>
<?php else: $shippingVersion=(int)($shipping['version'] ?? 0); ?>
<?php if ($shipping && $shipping['tracking_code']!==null): ?><p>شرکت حمل: <?= shop_replica_h($shipping['carrier']) ?> | کد: <strong><?= shop_replica_h($shipping['tracking_code']) ?></strong> | آخرین ثبت: <?= shop_replica_h($shipping['updated_at']) ?> | نسخه سفارش هنگام ثبت: <?= (int)$shipping['replica_revision'] ?></p>
<?php if ((int)$shipping['replica_revision']!==(int)$detail['revision']): ?><p><strong>نسخهٔ دریافتی سفارش پس از ثبت تغییر کرده؛ اطلاعات رهگیری و وضعیت را تطبیق دهید.</strong></p><?php endif; ?>
<?php else: ?><p>کد رهگیری داخلی فعالی ثبت نشده است.</p><?php endif; ?>
<?php if (!$readonly && in_array($o['order_status'] ?? '',['packed','shipped','delivered'],true)): ?><form method="post" onsubmit="return confirm('اطلاعات فقط در CRM ثبت می‌شود و نشانه ارسال یا تحویل قطعی نیست. ادامه می‌دهید؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="save_shipping"><input type="hidden" name="shipping_revision" value="<?= (int)$detail['revision'] ?>"><input type="hidden" name="shipping_version" value="<?= $shippingVersion ?>"><label>شرکت حمل <input name="carrier" required maxlength="80" value="<?= shop_replica_h($shipping['carrier'] ?? '') ?>"></label> <label>کد رهگیری <input name="tracking_code" required maxlength="100" value="<?= shop_replica_h($shipping['tracking_code'] ?? '') ?>" dir="ltr"></label> <button class="btn btn-primary" type="submit">ثبت رهگیری داخلی</button></form>
<?php elseif (!$readonly): ?><p>ثبت یا ویرایش کد برای نسخهٔ سفارش در وضعیت آماده ارسال، ارسال‌شده یا تحویل‌شده مجاز است.</p><?php endif; ?>
<?php if (!$readonly && $shipping && $shipping['tracking_code']!==null): ?><form method="post" onsubmit="return confirm('فقط کد داخلی پاک می‌شود؛ در فروشگاه یا شرکت حمل تغییری نمی‌کند. ادامه می‌دهید؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="clear_shipping"><input type="hidden" name="shipping_revision" value="<?= (int)$detail['revision'] ?>"><input type="hidden" name="shipping_version" value="<?= $shippingVersion ?>"><button class="btn" type="submit">پاک‌کردن رهگیری داخلی</button></form><?php endif; ?>
<?php if ($shippingEvents): ?><h4>تاریخچهٔ تغییر رهگیری</h4><?php foreach ($shippingEvents as $evt): ?><p><?= $evt['action']==='clear'?'پاک‌شدن':'ثبت/ویرایش' ?> — نسخه <?= (int)$evt['version'] ?> — <?= shop_replica_h($evt['full_name'] ?: 'کاربر حذف‌شده') ?> — <?= shop_replica_h($evt['created_at']) ?></p><?php endforeach; ?><p>تاریخچه، کدهای قبلی را نگه نمی‌دارد.</p><?php endif; ?>
<?php endif; ?></div><?php endif; ?>
<?php if ($admin): ?><div class="card" style="padding:18px;margin-top:14px"><h3>پیگیری داخلی بررسی مرجوعی</h3>
<p>فقط برای پیگیری مدیریتی است؛ ثبت یا بستن آن به معنی پذیرش یا دریافت کالا، تغییر سفارش، برگشت موجودی، بازپرداخت یا اطلاع‌رسانی به مشتری نیست. وضعیت و نسخهٔ فروشگاه را جداگانه بررسی کنید.</p>
<?php if (!$returnReady): ?><p>جدول پیگیری مرجوعی آماده نیست.</p><?php else: $hasOpenReturn=false; ?>
<?php foreach ($returnRequests as $ret): if ($ret['state']==='open') $hasOpenReturn=true; if ($ret['source_id']!==$detail['source_id'] || (int)$ret['shop_order_id']!==(int)$detail['shop_order_id'] || !is_int($o['id'] ?? null) || $o['id']!==(int)$detail['shop_order_id']): ?><p>هویت پیگیری مرجوعی با سفارش فعلی ناسازگار است؛ تطبیق دستی لازم است و هیچ تغییری مجاز نیست.</p><?php continue; endif; ?><div style="border-top:1px solid var(--border);padding:10px 0">
<p>درخواست #<?= (int)$ret['id'] ?> — <?= shop_replica_h($returnReasons[$ret['reason']] ?? 'نامشخص') ?> — <?= $ret['state']==='open'?'باز':'بسته' ?> | وضعیت ثبت‌شده: <?= shop_replica_h($statusLabels[$ret['observed_status']] ?? 'نامشخص') ?> | نسخه <?= (int)$ret['replica_revision'] ?> | <?= shop_replica_h($ret['creator_name'] ?: 'کاربر حذف‌شده') ?> | <?= shop_replica_h($ret['created_at']) ?></p>
<?php if ((int)$ret['replica_revision']!==(int)$detail['revision']): ?><p>نسخه سفارش از زمان ثبت تغییر کرده؛ پیش از هر اقدام آن را تطبیق دهید.</p><?php endif; ?>
<?php if ($ret['state']==='closed'): ?><p>بسته‌شده توسط <?= shop_replica_h($ret['closer_name'] ?: 'کاربر حذف‌شده') ?> در <?= shop_replica_h($ret['closed_at']) ?></p><?php elseif (!$readonly): ?><form method="post" onsubmit="return confirm('فقط پیگیری داخلی بسته می‌شود؛ مرجوعی یا پرداخت تأیید نمی‌شود. ادامه می‌دهید؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="close_return"><input type="hidden" name="return_number" value="<?= (int)$ret['id'] ?>"><button class="btn" type="submit">بستن پیگیری مرجوعی</button></form><?php endif; ?></div><?php endforeach; ?>
<?php if (!$readonly && !$hasOpenReturn && in_array($o['order_status'] ?? '',['shipped','delivered'],true)): ?><form method="post" onsubmit="return confirm('فقط درخواست بررسی در CRM ثبت می‌شود؛ مرجوعی یا بازپرداخت انجام نمی‌شود. ادامه می‌دهید؟');"><?= get_csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="action" value="request_return"><input type="hidden" name="return_number" value="<?= (int)$detail['revision'] ?>"><label>دلیل کلی <select name="reason" required><?php foreach ($returnReasons as $code=>$label): ?><option value="<?= shop_replica_h($code) ?>"><?= shop_replica_h($label) ?></option><?php endforeach; ?></select></label> <button class="btn btn-primary" type="submit">ثبت درخواست بررسی مرجوعی</button></form>
<?php elseif (!$hasOpenReturn): ?><p>در این بخش فقط سفارش ارسال‌شده یا تحویل‌شده قابل ثبت است.</p><?php endif; ?>
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
<?php if ($returnReady && $openReturns): ?><div class="card" style="padding:16px;margin-bottom:14px"><h3>پیگیری‌های باز بررسی مرجوعی</h3><p>فقط درخواست داخلی است، نه تأیید مرجوعی یا بازپرداخت.</p><?php foreach ($openReturns as $ret): $retOrder=json_decode((string)$ret['payload'],true); if ($ret['source_id']!==$ret['current_source'] || (int)$ret['shop_order_id']!==(int)$ret['current_order'] || !is_array($retOrder) || !is_int($retOrder['id'] ?? null) || $retOrder['id']!==(int)$ret['current_order']): ?><p>یک پیگیری باز با هویت ناسازگار یافت شد؛ تطبیق دستی لازم است.</p><?php continue; endif; ?><p><a href="index.php?page=shop_orders&amp;view=<?= (int)$ret['replica_id'] ?>"><?= shop_replica_h(is_array($retOrder) ? ($retOrder['order_no'] ?? '') : '') ?></a> — <?= shop_replica_h($returnReasons[$ret['reason']] ?? 'نامشخص') ?> — <?= shop_replica_h($ret['created_at']) ?></p><?php endforeach; ?><?php if ($nextReturn): ?><a class="btn" href="index.php?page=shop_orders&amp;return_before=<?= $nextReturn ?>">پیگیری‌های قدیمی‌تر</a><?php endif; ?><?php if (!empty($_GET['return_before'])): ?> <a href="index.php?page=shop_orders">بازگشت به پیگیری‌های جدیدتر</a><?php endif; ?></div><?php endif; ?>
<?php if ($requestsReady && $openRequests): ?><div class="card" style="padding:16px;margin-bottom:14px"><h3>درخواست‌های داخلی باز (حداکثر ۳۰ مورد اخیر)</h3><p>درخواست به‌تنهایی وضعیتی را تغییر نمی‌دهد؛ وضعیت فرمان هر درخواست در جزئیات آن مشخص است.</p><?php foreach ($openRequests as $req): $reqOrder=json_decode((string)$req['payload'],true); ?><p><a href="index.php?page=shop_orders&amp;view=<?= (int)$req['replica_id'] ?>"><?= shop_replica_h(is_array($reqOrder) ? ($reqOrder['order_no'] ?? '') : '') ?></a> — درخواست <?= shop_replica_h($statusLabels[$req['requested_status']] ?? 'نامشخص') ?> — <?= shop_replica_h($req['created_at']) ?></p><?php endforeach; ?><?php if ($nextRequest): ?><a class="btn" href="index.php?page=shop_orders&amp;request_before=<?= $nextRequest ?>">درخواست‌های باز قدیمی‌تر</a><?php endif; ?><?php if (!empty($_GET['request_before'])): ?> <a href="index.php?page=shop_orders">بازگشت به درخواست‌های جدیدتر</a><?php endif; ?></div><?php endif; ?>
<?php if (!$rows): ?><div class="card" style="padding:16px">سفارشی در محدوده دسترسی شما همگام نشده است.</div><?php endif; ?>
<div style="display:grid;gap:12px"><?php foreach ($rows as $r): $o=json_decode((string)$r['payload'],true); if (!is_array($o)) continue; ?>
<div class="card" style="padding:16px;overflow-wrap:anywhere"><strong><?= shop_replica_h($o['order_no'] ?? '') ?></strong> — <?= shop_replica_h($o['customer_name'] ?? '') ?> | <?= shop_replica_h($statusLabels[$o['order_status'] ?? ''] ?? 'نامشخص') ?> | <?= number_format((int)($o['total_toman'] ?? 0)) ?> تومان | مسئول: <?= shop_replica_h($r['owner_name'] ?: 'تعیین نشده') ?> | آخرین دریافت: <?= shop_replica_h(to_jalali(substr($r['received_at'],0,10))) ?> <?= shop_replica_h(substr($r['received_at'],11,5)) ?> <a class="btn" href="index.php?page=shop_orders&amp;view=<?= (int)$r['id'] ?>">جزئیات</a></div>
<?php endforeach; ?></div><?php if ($next): ?><p><a class="btn" href="index.php?page=shop_orders&amp;before=<?= $next ?>">سفارش‌های قدیمی‌تر</a></p><?php endif; ?>
<?php endif; include 'includes/footer.php'; ?>
