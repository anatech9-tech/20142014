<?php
/** Active administrators only; read-only and delayed metadata, not coupon management. */
$pageTitle='نمای کوپن‌های فروشگاه'; require_login(); header('Cache-Control: no-store');
require_once __DIR__.'/../includes/shop_coupon_metadata_sync.php';
try {
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([(int)($_SESSION['user_id'] ?? 0)]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$ready=false;
if (crm_shop_coupon_sync_configured()) $ready=crm_shop_coupon_sync_ensure($pdo);
else { try { $pdo->query('SELECT 1 FROM crm_shop_coupon_meta LIMIT 1'); $ready=true; } catch (Throwable $e) {} }
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
$status=is_string($_GET['status'] ?? null)?$_GET['status']:''; if (!in_array($status,['','active','inactive','missing'],true)) $status='';
$raw=$_GET['before'] ?? ''; $before=is_string($raw) && preg_match('/^[1-9][0-9]{0,17}$/D',$raw)?(int)$raw:0;
$where=[]; $args=[];
if ($status==='missing') $where[]="coupon_type='' AND is_active=0 AND revision>0";
elseif ($status!=='') { $where[]="coupon_type<>'' AND is_active=?"; $args[]=$status==='active'?1:0; }
if ($before) { $where[]='id<?'; $args[]=$before; }
$rows=[]; $next=0;
if ($ready) {
    $st=$pdo->prepare('SELECT id,source_id,coupon_id,coupon_type,is_active,revision,received_at FROM crm_shop_coupon_meta'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC LIMIT 31'); $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)>30) { array_pop($rows); $next=(int)$rows[count($rows)-1]['id']; }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>نمای کوپن‌های فروشگاه</h1><p>فقط شناسه، نوع و برچسب فعال ثبت‌شده با تأخیر؛ کد، مبلغ، تاریخ انقضا، سقف و تعداد استفاده منتقل نمی‌شوند. این صفحه هیچ کوپنی ایجاد، حذف یا تغییر نمی‌دهد.</p></div>
<div class="card" style="padding:16px"><p><strong>برچسب فعال به معنی اعتبار فعلی یا امکان استفاده نیست. برای کد و تمام شرایط مالی، انقضا و محدودیت استفاده پنل فروشگاه را مستقل بررسی کنید. نبود مشاهده‌شده فقط در صورت فعال‌بودن تطبیق جداگانه و با تأخیر گزارش می‌شود و اثبات حذف عمدی نیست.</strong></p>
<?php if (!crm_shop_coupon_sync_configured()): ?><p>دریافت در CRM غیرفعال یا پیکربندی نشده است؛ رکوردهای قبلی ممکن است باقی بمانند.</p><?php endif; ?>
<?php if (!crm_shop_coupon_sync_deletion_enabled()): ?><p>دریافت رویداد نبود کوپن غیرفعال است؛ رکورد حذف‌شده ممکن است همچنان نمایش داده شود.</p><?php endif; ?>
<?php if (!$ready): ?><p>جدول نمای کوپن آماده نیست.</p><?php else: ?>
<form method="get"><input type="hidden" name="page" value="shop_coupon_metadata"><label>وضعیت ثبت‌شده <select name="status"><option value="">همه</option><option value="active" <?= $status==='active'?'selected':'' ?>>فعال</option><option value="inactive" <?= $status==='inactive'?'selected':'' ?>>غیرفعال</option><option value="missing" <?= $status==='missing'?'selected':'' ?>>نبود مشاهده‌شده</option></select></label> <button class="btn" type="submit">فیلتر</button></form>
<div style="overflow-x:auto;margin-top:16px"><table class="data-table"><thead><tr><th>شناسه فروشگاه</th><th>منبع</th><th>نوع</th><th>برچسب ثبت‌شده</th><th>نسخه</th><th>آخرین دریافت</th></tr></thead><tbody>
<?php foreach ($rows as $r): $missing=$r['coupon_type']==='' && (string)$r['is_active']==='0' && (int)$r['revision']>0; $types=['percent'=>'درصدی','fixed'=>'مبلغ ثابت']; ?><tr><td><?= (int)$r['coupon_id'] ?></td><td><?= $h($r['source_id']) ?></td><td><?= $missing?'—':$h($types[$r['coupon_type']] ?? 'نامعتبر') ?></td><td><?= $missing?'نبود مشاهده‌شده':((string)$r['is_active']==='1'?'فعال':((string)$r['is_active']==='0'?'غیرفعال':'نامعتبر')) ?></td><td><?= (int)$r['revision'] ?></td><td><?= $h($r['received_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6">رکوردی دریافت نشده است؛ این به معنی نبود کوپن در فروشگاه نیست.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($next): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_coupon_metadata','status'=>$status,'before'=>$next])) ?>">صفحه بعد</a></p><?php endif; ?>
<?php endif; ?></div><?php include 'includes/footer.php'; ?>
