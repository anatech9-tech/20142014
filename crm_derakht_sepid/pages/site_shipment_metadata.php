<?php
/** Active administrators only; read-only, non-authoritative site shipment metadata. */
$pageTitle='محموله‌های مستقل سایت'; require_login(); header('Cache-Control: no-store');
require_once __DIR__.'/../includes/site_shipment_metadata_sync.php';
try {
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([(int)($_SESSION['user_id'] ?? 0)]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$ready=false;
if (crm_site_shipment_sync_configured()) $ready=crm_site_shipment_sync_ensure($pdo);
else { try { $pdo->query('SELECT 1 FROM crm_site_shipment_meta LIMIT 1'); $ready=true; } catch (Throwable $e) {} }
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
$statuses=['registered'=>'ثبت شده','picked_up'=>'جمع‌آوری شده','in_transit'=>'در حال حمل','customs_clearance'=>'ترخیص گمرک','out_for_delivery'=>'آماده تحویل','delivered'=>'تحویل شده','cancelled'=>'لغو شده','on_hold'=>'در انتظار'];
$services=['air'=>'هوایی','sea'=>'دریایی','land'=>'زمینی','multimodal'=>'چندوجهی'];
$status=is_string($_GET['status'] ?? null)?$_GET['status']:''; if (!isset($statuses[$status])) $status='';
$raw=$_GET['before'] ?? ''; $before=is_string($raw) && preg_match('/^[1-9][0-9]{0,17}$/D',$raw)?(int)$raw:0;
$where=[]; $args=[]; if ($status!=='') { $where[]='status=?'; $args[]=$status; } if ($before) { $where[]='id<?'; $args[]=$before; }
$rows=[]; $next=0;
if ($ready) {
    $st=$pdo->prepare('SELECT id,source_id,shipment_id,service_type,status,is_public,revision,received_at FROM crm_site_shipment_meta'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC LIMIT 31'); $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)>30) { array_pop($rows); $next=(int)$rows[count($rows)-1]['id']; }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>محموله‌های مستقل سایت</h1><p>فقط نمای آزمایشی و با تأخیر شناسه، سرویس، وضعیت و پرچم عمومی؛ این محموله‌ها سفارش فروشگاه نیستند و به آن وصل نشده‌اند. هیچ تغییری از این صفحه در سایت انجام نمی‌شود.</p></div>
<div class="card" style="padding:16px">
<p><strong>حذف محموله در سایت منتقل نمی‌شود؛ فهرست ممکن است ناقص، قدیمی یا شامل رکورد حذف‌شده باشد. برای اطلاعات مشتری، رهگیری و وضعیت قطعی به پنل سایت مراجعه کنید. عمومی بودن مجوز انتشار یا انتقال اطلاعات مشتری نیست.</strong></p>
<?php if (!crm_site_shipment_sync_configured()): ?><p>دریافت در CRM غیرفعال یا پیکربندی نشده است؛ رکوردهای قبلی ممکن است باقی بمانند.</p><?php endif; ?>
<?php if (!$ready): ?><p>جدول نمای محموله آماده نیست.</p><?php else: ?>
<form method="get"><input type="hidden" name="page" value="site_shipment_metadata"><label>وضعیت ثبت‌شده <select name="status"><option value="">همه</option><?php foreach ($statuses as $key=>$label): ?><option value="<?= $h($key) ?>" <?= $status===$key?'selected':'' ?>><?= $h($label) ?></option><?php endforeach; ?></select></label> <button class="btn" type="submit">فیلتر</button></form>
<div style="overflow-x:auto;margin-top:16px"><table class="data-table"><thead><tr><th>شناسه سایت</th><th>منبع</th><th>سرویس</th><th>وضعیت ثبت‌شده</th><th>پرچم رهگیری عمومی</th><th>نسخه</th><th>آخرین دریافت</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= (int)$r['shipment_id'] ?></td><td><?= $h($r['source_id']) ?></td><td><?= $h($services[$r['service_type']] ?? 'نامعتبر') ?></td><td><?= $h($statuses[$r['status']] ?? 'نامعتبر') ?></td><td><?= (string)$r['is_public']==='1'?'بله':((string)$r['is_public']==='0'?'خیر':'نامعتبر') ?></td><td><?= (int)$r['revision'] ?></td><td><?= $h($r['received_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7">رکوردی دریافت نشده است؛ این به معنی نبود محموله در سایت نیست.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($next): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'site_shipment_metadata','status'=>$status,'before'=>$next])) ?>">صفحه بعد</a></p><?php endif; ?>
<?php endif; ?></div>
<?php include 'includes/footer.php'; ?>
