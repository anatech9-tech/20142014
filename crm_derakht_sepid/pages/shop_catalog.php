<?php
/** Administrator-only read-only catalogue replicas, not the CRM product bank. */
$pageTitle='کالاهای فروشگاه'; require_login();
require_once __DIR__.'/../includes/shop_catalog_sync.php';
$me=(int)($_SESSION['user_id'] ?? 0);
try {
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$ready=crm_shop_catalog_ensure($pdo); $h=static fn($v)=>htmlspecialchars((string)($v ?? ''),ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
$q=mb_substr(trim((string)($_GET['q'] ?? '')),0,100); $active=(string)($_GET['active'] ?? 'all');
if (!in_array($active,['all','active','inactive'],true)) $active='all';
$where=['1=1']; $args=[];
if ($q!=='') { $where[]='(name_fa LIKE ? OR sku LIKE ?)'; $args[]='%'.$q.'%'; $args[]='%'.$q.'%'; }
if ($active!=='all') { $where[]='is_active=?'; $args[]=$active==='active'?1:0; }
$items=[]; $page=1; $pages=1; $total=0;
if ($ready) {
    $sql=implode(' AND ',$where); $st=$pdo->prepare("SELECT COUNT(*) FROM crm_shop_catalog WHERE $sql"); $st->execute($args); $total=(int)$st->fetchColumn();
    $pages=max(1,(int)ceil($total/30)); $page=min($pages,max(1,(int)($_GET['p'] ?? 1))); $offset=($page-1)*30;
    $st=$pdo->prepare("SELECT * FROM crm_shop_catalog WHERE $sql ORDER BY id DESC LIMIT 30 OFFSET $offset"); $st->execute($args); $items=$st->fetchAll(PDO::FETCH_ASSOC);
}
include 'includes/header.php';
?>
<div class="page-header"><h1>کالاهای فروشگاه</h1><p>نسخهٔ فقط‌خواندنی با تأخیر از کالاهای فعال و غیرفعال؛ موجودی و قیمت قطعی لحظه‌ای نیست. این فهرست بانک کالاهای CRM را تغییر یا کالاها را ادغام نمی‌کند.</p><p><a class="btn" href="index.php?page=crm_products">بازگشت به بانک کالا</a></p></div>
<?php if (!$ready): ?><div class="card" style="padding:16px">جدول کاتالوگ آماده نیست؛ مجوز دیتابیس را بررسی کنید.</div><?php else: ?>
<div class="card" style="padding:16px">
<?php if (!crm_shop_catalog_configured()): ?><p><strong>همگام‌سازی کاتالوگ در CRM فعال یا پیکربندی نشده است؛ نبود کالا در فهرست به معنی خالی بودن فروشگاه نیست.</strong></p><?php endif; ?>
<p>حذف کالا از فروشگاه فعلاً به این فهرست منتقل نمی‌شود و باید دستی تطبیق شود. تصاویر، توضیحات کامل و نرخ تبدیل ارز در این نسخه منتقل نمی‌شوند.</p>
<form method="get"><input type="hidden" name="page" value="shop_catalog"><label>نام یا کد کالا <input name="q" maxlength="100" value="<?= $h($q) ?>"></label> <label>وضعیت <select name="active"><option value="all" <?= $active==='all'?'selected':'' ?>>همه</option><option value="active" <?= $active==='active'?'selected':'' ?>>فعال</option><option value="inactive" <?= $active==='inactive'?'selected':'' ?>>غیرفعال</option></select></label> <button class="btn" type="submit">جست‌وجو</button></form>
<div style="overflow-x:auto;margin-top:16px"><table class="data-table"><thead><tr><th>شناسه فروشگاه</th><th>کالا / کد</th><th>دسته</th><th>قیمت پایه ثبت‌شده</th><th>موجودی ثبت‌شده</th><th>نمایش</th><th>آخرین دریافت</th></tr></thead><tbody>
<?php foreach ($items as $r): $p=json_decode((string)$r['payload'],true); if (!is_array($p)) continue; $price=($p['currency'] ?? '')==='usd' ? ($p['price_usd'] ?? null) : ($p['price_toman'] ?? null); ?>
<tr><td><?= (int)$r['shop_product_id'] ?><br><small>منبع: <?= $h(substr((string)$r['source_id'],0,8)) ?>…</small></td><td><?= $h($p['name_fa'] ?? '') ?><br><small><?= $h($p['sku'] ?: 'بدون کد') ?><?= !empty($p['brand'])?' — '.$h($p['brand']):'' ?></small></td><td><?= $h($p['category_name'] ?? 'بدون دسته') ?></td><td><?= $price===null ? 'ثبت نشده' : $h($price).' '.(($p['currency'] ?? '')==='usd'?'دلار':'تومان') ?></td><td><?= (int)($p['stock'] ?? 0) ?></td><td><?= (int)$r['is_active']===1?'فعال':'غیرفعال' ?></td><td><?= $h($r['received_at']) ?></td></tr>
<?php endforeach; ?><?php if (!$items): ?><tr><td colspan="7">کالایی در نتایج دریافت‌شده نیست.</td></tr><?php endif; ?></tbody></table></div>
<p>صفحه <?= $page ?> از <?= $pages ?> — <?= $total ?> کالا
<?php if ($page>1): ?><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_catalog','q'=>$q,'active'=>$active,'p'=>$page-1])) ?>">قبلی</a><?php endif; ?>
<?php if ($page<$pages): ?><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_catalog','q'=>$q,'active'=>$active,'p'=>$page+1])) ?>">بعدی</a><?php endif; ?></p>
</div><?php endif; include 'includes/footer.php'; ?>
