<?php
$pageTitle='سفارش‌های فروشگاه';
require_login();
// No staff/team mapping exists yet. Deny by default, including finance/project roles.
if (!is_strict_admin()) { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
require_once __DIR__.'/../includes/shop_order_sync.php';
function shop_replica_h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'); }
$statusLabels=['pending'=>'در انتظار','confirmed'=>'تأیید شده','packed'=>'آماده ارسال','shipped'=>'ارسال شده','delivered'=>'تحویل شده','cancelled'=>'لغو شده'];
$payLabels=['pending'=>'در انتظار','paid'=>'ثبت دستی پرداخت‌شده','failed'=>'ناموفق','refunded'=>'ثبت دستی بازپرداخت'];
$ready=crm_shop_orders_ensure($pdo);
$view=(int)($_GET['view'] ?? 0); $detail=null; $rows=[]; $next=0;
if ($ready && $view>0) {
    $st=$pdo->prepare('SELECT * FROM crm_shop_orders WHERE id=?'); $st->execute([$view]); $detail=$st->fetch();
} elseif ($ready) {
    $before=(int)($_GET['before'] ?? 0);
    if ($before>0) { $st=$pdo->prepare('SELECT * FROM crm_shop_orders WHERE id<? ORDER BY id DESC LIMIT 51'); $st->execute([$before]); }
    else { $st=$pdo->query('SELECT * FROM crm_shop_orders ORDER BY id DESC LIMIT 51'); }
    $rows=$st->fetchAll();
    if (count($rows)>50) { array_pop($rows); $next=(int)end($rows)['id']; }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>سفارش‌های فروشگاه</h1><p>نمایش فقط‌خواندنی و با تأخیر؛ اطلاعات مرجع در فروشگاه است. پرداخت ثبت‌شده به معنی وصول یا بازپرداخت تأییدشده نیست.</p></div>
<?php if (!$ready): ?><div class="card" style="padding:16px">جدول سفارش‌های CRM آماده نیست؛ تنظیمات و مجوز دیتابیس را بررسی کنید.</div>
<?php elseif ($view>0): ?>
    <p><a href="index.php?page=shop_orders">← بازگشت به فهرست</a></p>
    <?php if (!$detail): ?><div class="card" style="padding:16px">سفارش یافت نشد.</div>
    <?php else: $o=json_decode((string)$detail['payload'],true); if (!is_array($o)) $o=[]; ?>
    <div class="card" style="padding:18px;overflow-wrap:anywhere">
      <h2>سفارش <?= shop_replica_h($o['order_no'] ?? '') ?></h2>
      <p>نام: <?= shop_replica_h($o['customer_name'] ?? '') ?> | تلفن: <?= shop_replica_h($o['customer_phone'] ?? '') ?> | ایمیل: <?= shop_replica_h($o['customer_email'] ?? '') ?></p>
      <p>شناسه سفارش فروشگاه: <?= (int)$detail['shop_order_id'] ?> | شناسه مشتری فروشگاه: <?= isset($o['customer_id']) ? (int)$o['customer_id'] : 'مهمان' ?></p>
      <p>وضعیت سفارش: <?= shop_replica_h($statusLabels[$o['order_status'] ?? ''] ?? 'نامشخص') ?> | پرداخت: <?= shop_replica_h($payLabels[$o['payment_status'] ?? ''] ?? 'نامشخص') ?></p>
      <p>ثبت در فروشگاه: <?= shop_replica_h(to_jalali(substr((string)($o['created_at'] ?? ''),0,10))) ?> <?= shop_replica_h(substr((string)($o['created_at'] ?? ''),11,5)) ?> | آخرین دریافت در CRM: <?= shop_replica_h(to_jalali(substr($detail['received_at'],0,10))) ?> <?= shop_replica_h(substr($detail['received_at'],11,5)) ?></p>
      <div style="overflow-x:auto"><table class="data-table"><thead><tr><th>کالا</th><th>شناسه فروشگاه</th><th>تعداد</th><th>قیمت واحد (تومان)</th><th>جمع (تومان)</th></tr></thead><tbody>
      <?php foreach (($o['items'] ?? []) as $item): ?><tr><td><?= shop_replica_h($item['product_name'] ?? '') ?></td><td><?= isset($item['product_id']) ? (int)$item['product_id'] : '—' ?></td><td><?= (int)($item['qty'] ?? 0) ?></td><td><?= number_format((int)($item['price_toman'] ?? 0)) ?></td><td><?= number_format((int)($item['total_toman'] ?? 0)) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <p>جمع کالاها: <?= number_format((int)($o['subtotal_toman'] ?? 0)) ?> | تخفیف: <?= number_format((int)($o['discount_toman'] ?? 0)) ?> | ارسال: <?= number_format((int)($o['shipping_fee'] ?? 0)) ?> | مبلغ کل: <strong><?= number_format((int)($o['total_toman'] ?? 0)) ?> تومان</strong></p>
      <p>آدرس، یادداشت مشتری و توکن سفارش عمداً به این نسخه منتقل نمی‌شوند. تغییر وضعیت و مرجوعی همچنان در فروشگاه انجام می‌شود.</p>
    </div>
    <?php endif; ?>
<?php else: ?>
    <?php if (!$rows): ?><div class="card" style="padding:16px">سفارشی همگام نشده است.</div><?php endif; ?>
    <div style="display:grid;gap:12px">
    <?php foreach ($rows as $r): $o=json_decode((string)$r['payload'],true); if (!is_array($o)) continue; ?>
      <div class="card" style="padding:16px;overflow-wrap:anywhere"><strong><?= shop_replica_h($o['order_no'] ?? '') ?></strong> — <?= shop_replica_h($o['customer_name'] ?? '') ?> | <?= shop_replica_h($statusLabels[$o['order_status'] ?? ''] ?? 'نامشخص') ?> | <?= number_format((int)($o['total_toman'] ?? 0)) ?> تومان | آخرین دریافت: <?= shop_replica_h(to_jalali(substr($r['received_at'],0,10))) ?> <?= shop_replica_h(substr($r['received_at'],11,5)) ?> <a class="btn" href="index.php?page=shop_orders&amp;view=<?= (int)$r['id'] ?>">جزئیات</a></div>
    <?php endforeach; ?></div>
    <?php if ($next): ?><p><a class="btn" href="index.php?page=shop_orders&amp;before=<?= $next ?>">سفارش‌های قدیمی‌تر</a></p><?php endif; ?>
<?php endif; include 'includes/footer.php'; ?>
