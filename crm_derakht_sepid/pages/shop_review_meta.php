<?php
/** Strictly administrator-only, read-only minimal review metadata, not moderation. */
$pageTitle='نمای کلی دیدگاه‌های فروشگاه'; require_login();
header('Cache-Control: no-store');
require_once __DIR__.'/../includes/shop_review_meta_sync.php';
$me=(int)($_SESSION['user_id'] ?? 0);
try {
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$ready=false;
// Do not create a table merely by visiting this page while the receiver is disabled.
if (crm_review_meta_configured()) $ready=crm_review_meta_ensure($pdo);
else { try { $pdo->query('SELECT 1 FROM crm_shop_review_meta LIMIT 1'); $ready=true; } catch (Throwable $e) {} }
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
$status=is_string($_GET['status'] ?? null)?$_GET['status']:'all'; if (!in_array($status,['all','active','inactive','missing'],true)) $status='all';
$raw=$_GET['before'] ?? ''; $before=is_string($raw) && preg_match('/^[1-9][0-9]{0,17}$/D',$raw) ? (int)$raw : 0;
$where=[]; $args=[];
if ($status==='missing') $where[]='product_id=0 AND rating=0 AND is_active=0';
elseif ($status!=='all') { $where[]='product_id>0 AND is_active=?'; $args[]=$status==='active'?1:0; }
if ($before>0) { $where[]='id<?'; $args[]=$before; }
$rows=[]; $next=0;
if ($ready) {
    $st=$pdo->prepare('SELECT id,source_id,review_id,product_id,rating,is_active,received_at FROM crm_shop_review_meta'.($where ? ' WHERE '.implode(' AND ',$where) : '').' ORDER BY id DESC LIMIT 31');
    $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)>30) { array_pop($rows); $next=(int)$rows[count($rows)-1]['id']; }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>نمای کلی دیدگاه‌های فروشگاه</h1><p>فقط شناسه، امتیاز و وضعیت ثبت‌شده با تأخیر؛ این صفحه متن و نام مشتری را منتقل نمی‌کند و ابزار تأیید، حذف یا پاسخ به دیدگاه نیست.</p><p><a class="btn" href="index.php?page=shop_catalog">بازگشت به کالاهای فروشگاه</a></p></div>
<div class="card" style="padding:16px">
<p><strong>فهرست ممکن است ناقص یا قدیمی باشد؛ مشاهدهٔ نبود در سایت فقط در صورت فعال‌بودن تطبیق مستقل و با تأخیر منتقل می‌شود و اثبات حذف عمدی نیست. قبل از هر اقدام، شناسه، متن، هویت و وضعیت واقعی را در پنل فروشگاه جداگانه بررسی کنید.</strong></p>
<?php if (!crm_review_meta_configured()): ?><p>دریافت در CRM غیرفعال یا پیکربندی نشده است؛ رکوردهای قبلی در صورت وجود باقی می‌مانند.</p><?php endif; ?>
<?php if (!crm_review_meta_deletion_enabled()): ?><p>دریافت رویداد نبود دیدگاه در CRM غیرفعال است؛ وضعیت‌های قبلی ممکن است قدیمی باشند.</p><?php endif; ?>
<?php if (!$ready): ?><p>جدول نمای دیدگاه آماده نیست.</p><?php else: ?>
<form method="get"><input type="hidden" name="page" value="shop_review_meta"><label>وضعیت ثبت‌شده <select name="status"><option value="all" <?= $status==='all'?'selected':'' ?>>همه</option><option value="active" <?= $status==='active'?'selected':'' ?>>نمایش</option><option value="inactive" <?= $status==='inactive'?'selected':'' ?>>مخفی</option><option value="missing" <?= $status==='missing'?'selected':'' ?>>نبود مشاهده‌شده</option></select></label> <button class="btn" type="submit">فیلتر</button></form>
<div style="overflow-x:auto;margin-top:16px"><table class="data-table"><thead><tr><th>شناسه دیدگاه در فروشگاه</th><th>شناسه کالا در فروشگاه</th><th>امتیاز ثبت‌شده</th><th>وضعیت ثبت‌شده</th><th>آخرین دریافت در CRM</th></tr></thead><tbody>
<?php foreach ($rows as $r): $missing=(int)$r['product_id']===0 && (int)$r['rating']===0 && (int)$r['is_active']===0; ?><tr><td><?= (int)$r['review_id'] ?><br><small>منبع <?= $h($r['source_id']) ?></small></td><td><?= $missing?'—':(int)$r['product_id'] ?></td><td><?= $missing?'—':((int)$r['rating'].' از ۵') ?></td><td><?= $missing?'نبود مشاهده‌شده':((int)$r['is_active']===1?'نمایش':((int)$r['is_active']===0?'مخفی':'نامعتبر')) ?></td><td><?= $h($r['received_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5">نسخه‌ای برای این فیلتر دریافت نشده است؛ این به معنی نبود دیدگاه در فروشگاه نیست.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($next): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_review_meta','status'=>$status,'before'=>$next])) ?>">صفحه بعد</a></p><?php endif; ?>
<?php endif; ?></div>
<?php include 'includes/footer.php'; ?>
