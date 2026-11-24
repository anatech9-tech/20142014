<?php
/** Administrator-only read-only catalogue replicas, not the CRM product bank. */
$pageTitle='کالاهای فروشگاه'; require_login();
require_once __DIR__.'/../includes/shop_catalog_sync.php';
require_once __DIR__.'/../includes/shop_product_mapping.php';
$me=(int)($_SESSION['user_id'] ?? 0);
try {
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$ready=crm_shop_catalog_ensure($pdo);
$mappingReady=$ready && shop_product_mapping_ensure($pdo);
$readOnly=is_nazer_kol() || (!access_is_special_account() && access_mode('crm_customers')==='viewer');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $replica=(int)($_POST['replica_id'] ?? 0);
    try {
        if (!$ready || !$mappingReady || $readOnly || (string)($_POST['action'] ?? '')!=='map') throw new DomainException('اتصال کالا مجاز یا آماده نیست.');
        foreach (['target','version','revision'] as $key) if (!preg_match('/^(0|[1-9][0-9]{0,17})$/D',(string)($_POST[$key] ?? ''))) throw new DomainException('درخواست نامعتبر است؛ صفحه را دوباره بارگذاری کنید.');
        shop_catalog_product_mapping_apply($pdo,$replica,$me,true,(int)$_POST['target'],(int)$_POST['version'],(int)$_POST['revision']);
        try { operations_audit($pdo,$me,'shop_catalog_map','shop_catalog',$replica,'اتصال دستی کالا از کاتالوگ فروشگاه'); } catch (Throwable $e) { error_log('Shop catalogue global audit failed'); }
        showToast('اتصال دستی کالا ثبت شد؛ اطلاعات فروشگاه و قیمت‌ها تغییر نکردند.');
    } catch (Throwable $e) { error_log('Shop catalogue mapping failed: '.get_class($e)); showToast($e instanceof DomainException ? $e->getMessage() : 'ثبت اتصال ممکن نشد.','error'); }
    redirect('index.php?page=shop_catalog&map_id='.$replica);
}
$h=static fn($v)=>htmlspecialchars((string)($v ?? ''),ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
$q=mb_substr(trim((string)($_GET['q'] ?? '')),0,100); $active=(string)($_GET['active'] ?? 'all');
if (!in_array($active,['all','active','inactive'],true)) $active='all';
$where=['1=1']; $args=[];
if ($q!=='') { $where[]='(name_fa LIKE ? OR sku LIKE ?)'; $args[]='%'.$q.'%'; $args[]='%'.$q.'%'; }
if ($active!=='all') { $where[]='is_active=?'; $args[]=$active==='active'?1:0; }
$items=[]; $page=1; $pages=1; $total=0; $selected=null; $currentLink=null; $options=[];
$mapId=max(0,(int)($_GET['map_id'] ?? 0)); $productSearch=mb_substr(trim((string)($_GET['product_search'] ?? '')),0,100);
if ($ready) {
    $sql=implode(' AND ',$where); $st=$pdo->prepare("SELECT COUNT(*) FROM crm_shop_catalog WHERE $sql"); $st->execute($args); $total=(int)$st->fetchColumn();
    $pages=max(1,(int)ceil($total/30)); $page=min($pages,max(1,(int)($_GET['p'] ?? 1))); $offset=($page-1)*30;
    $st=$pdo->prepare("SELECT * FROM crm_shop_catalog WHERE $sql ORDER BY id DESC LIMIT 30 OFFSET $offset"); $st->execute($args); $items=$st->fetchAll(PDO::FETCH_ASSOC);
    if ($mapId && $mappingReady) {
        $st=$pdo->prepare('SELECT * FROM crm_shop_catalog WHERE id=?'); $st->execute([$mapId]); $selected=$st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selected) {
            $st=$pdo->prepare('SELECT l.*,p.name AS crm_product_name FROM crm_shop_product_links l LEFT JOIN crm_products p ON p.id=l.crm_product_id WHERE l.source_id=? AND l.shop_product_id=?');
            $st->execute([$selected['source_id'],$selected['shop_product_id']]); $currentLink=$st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$readOnly && mb_strlen($productSearch)>=2) {
                $st=$pdo->prepare('SELECT id,name,supplier FROM crm_products WHERE name LIKE ? OR specs LIKE ? OR supplier LIKE ? ORDER BY name LIMIT 30');
                $like='%'.$productSearch.'%'; $st->execute([$like,$like,$like]); $options=$st->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>کالاهای فروشگاه</h1><p>نسخهٔ فقط‌خواندنی با تأخیر از کالاهای فعال و غیرفعال؛ موجودی و قیمت قطعی لحظه‌ای نیست. اتصال هویت فقط با بررسی و تأیید دستی مدیر انجام می‌شود؛ قیمت یا موجودی را تغییر نمی‌دهد.</p><p><a class="btn" href="index.php?page=crm_products">بازگشت به بانک کالا</a></p></div>
<?php if (!$ready): ?><div class="card" style="padding:16px">جدول کاتالوگ آماده نیست؛ مجوز دیتابیس را بررسی کنید.</div><?php else: ?>
<div class="card" style="padding:16px">
<?php if (!crm_shop_catalog_configured()): ?><p><strong>همگام‌سازی کاتالوگ در CRM فعال یا پیکربندی نشده است؛ نبود کالا در فهرست به معنی خالی بودن فروشگاه نیست.</strong></p><?php endif; ?>
<p>حذف کالا از فروشگاه فعلاً به این فهرست منتقل نمی‌شود و باید دستی تطبیق شود. تصاویر، توضیحات کامل و نرخ تبدیل ارز در این نسخه منتقل نمی‌شوند.</p>
<form method="get"><input type="hidden" name="page" value="shop_catalog"><label>نام یا کد کالا <input name="q" maxlength="100" value="<?= $h($q) ?>"></label> <label>وضعیت <select name="active"><option value="all" <?= $active==='all'?'selected':'' ?>>همه</option><option value="active" <?= $active==='active'?'selected':'' ?>>فعال</option><option value="inactive" <?= $active==='inactive'?'selected':'' ?>>غیرفعال</option></select></label> <button class="btn" type="submit">جست‌وجو</button></form>
<div style="overflow-x:auto;margin-top:16px"><table class="data-table"><thead><tr><th>شناسه فروشگاه</th><th>کالا / کد</th><th>دسته</th><th>قیمت پایه ثبت‌شده</th><th>موجودی ثبت‌شده</th><th>نمایش</th><th>آخرین دریافت</th><th>اتصال به بانک کالا</th></tr></thead><tbody>
<?php foreach ($items as $r): $p=json_decode((string)$r['payload'],true); if (!is_array($p)) continue; $price=($p['currency'] ?? '')==='usd' ? ($p['price_usd'] ?? null) : ($p['price_toman'] ?? null); ?>
<tr><td><?= (int)$r['shop_product_id'] ?><br><small>منبع: <?= $h(substr((string)$r['source_id'],0,8)) ?>…</small></td><td><?= $h($p['name_fa'] ?? '') ?><br><small><?= $h($p['sku'] ?: 'بدون کد') ?><?= !empty($p['brand'])?' — '.$h($p['brand']):'' ?></small></td><td><?= $h($p['category_name'] ?? 'بدون دسته') ?></td><td><?= $price===null ? 'ثبت نشده' : $h($price).' '.(($p['currency'] ?? '')==='usd'?'دلار':'تومان') ?></td><td><?= (int)($p['stock'] ?? 0) ?></td><td><?= (int)$r['is_active']===1?'فعال':'غیرفعال' ?></td><td><?= $h($r['received_at']) ?></td><td><?php if ($mappingReady): ?><a class="btn" href="index.php?page=shop_catalog&amp;map_id=<?= (int)$r['id'] ?>">بررسی اتصال</a><?php else: ?>در دسترس نیست<?php endif; ?></td></tr>
<?php endforeach; ?><?php if (!$items): ?><tr><td colspan="8">کالایی در نتایج دریافت‌شده نیست.</td></tr><?php endif; ?></tbody></table></div>
<p>صفحه <?= $page ?> از <?= $pages ?> — <?= $total ?> کالا
<?php if ($page>1): ?><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_catalog','q'=>$q,'active'=>$active,'p'=>$page-1])) ?>">قبلی</a><?php endif; ?>
<?php if ($page<$pages): ?><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_catalog','q'=>$q,'active'=>$active,'p'=>$page+1])) ?>">بعدی</a><?php endif; ?></p>
</div>
<?php if ($mapId && !$mappingReady): ?><div class="card" style="padding:16px;margin-top:14px">بانک کالا یا جدول اتصال آماده نیست.</div>
<?php elseif ($mapId && !$selected): ?><div class="card" style="padding:16px;margin-top:14px">کالا در کاتالوگ دریافتی یافت نشد.</div>
<?php elseif ($selected): $sp=json_decode((string)$selected['payload'],true); if (!is_array($sp)) $sp=[]; $version=(int)($currentLink['version'] ?? 0); ?>
<div class="card" style="padding:18px;margin-top:14px"><h3>بررسی اتصال «<?= $h($sp['name_fa'] ?? '') ?>»</h3>
<p>شناسه فروشگاه <?= (int)$selected['shop_product_id'] ?> | منبع <?= $h($selected['source_id']) ?> | نسخه دریافتی <?= (int)$selected['revision'] ?> | آخرین دریافت <?= $h($selected['received_at']) ?></p>
<p>این تصویر ممکن است قدیمی یا کالای مبدأ حذف شده باشد. مشخصات را در فروشگاه و بانک کالا تطبیق دهید. این اتصال برای همه سفارش‌های همان منبع و شناسه اعمال می‌شود، تأیید تاریخی نیست و قیمت، موجودی یا انتشار را تغییر نمی‌دهد.</p>
<?php if (!empty($currentLink['crm_product_id']) && !empty($currentLink['crm_product_name'])): ?><p>اتصال فعلی: <a href="index.php?page=crm_products&amp;view=<?= (int)$currentLink['crm_product_id'] ?>"><?= $h($currentLink['crm_product_name']) ?></a></p><?php elseif (!empty($currentLink['crm_product_id'])): ?><p>کالای متصل در بانک یافت نشد؛ اتصال را بررسی کنید.</p><?php else: ?><p>اتصال فعالی ثبت نشده است.</p><?php endif; ?>
<?php if (!$readOnly): ?><form method="get"><input type="hidden" name="page" value="shop_catalog"><input type="hidden" name="map_id" value="<?= (int)$selected['id'] ?>"><label>نام یا تأمین‌کننده در بانک کالا <input name="product_search" required minlength="2" maxlength="100" value="<?= $h($productSearch) ?>"></label> <button class="btn" type="submit">جست‌وجو</button></form>
<?php if (mb_strlen($productSearch)>=2): ?><?php if ($options): ?><form method="post" onsubmit="return confirm('مشخصات کالاها تطبیق داده شده و اتصال برای همه سفارش‌های دارای این شناسه ثبت شود؟');"><?= get_csrf_field() ?><input type="hidden" name="action" value="map"><input type="hidden" name="replica_id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="version" value="<?= $version ?>"><input type="hidden" name="revision" value="<?= (int)$selected['revision'] ?>"><label>کالای CRM <select name="target" required><?php foreach ($options as $option): ?><option value="<?= (int)$option['id'] ?>"><?= $h($option['name']) ?> — <?= $h($option['supplier'] ?: 'بدون تأمین‌کننده') ?></option><?php endforeach; ?></select></label> <button class="btn btn-primary" type="submit">تأیید اتصال</button></form><?php else: ?><p>کالایی در بانک پیدا نشد.</p><?php endif; ?><?php endif; ?>
<?php if (!empty($currentLink['crm_product_id'])): ?><form method="post" style="margin-top:10px" onsubmit="return confirm('اتصال کالا برای همه سفارش‌های دارای این شناسه قطع شود؟');"><?= get_csrf_field() ?><input type="hidden" name="action" value="map"><input type="hidden" name="replica_id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="target" value="0"><input type="hidden" name="version" value="<?= $version ?>"><input type="hidden" name="revision" value="<?= (int)$selected['revision'] ?>"><button class="btn" type="submit">قطع اتصال</button></form><?php endif; ?><?php endif; ?>
</div><?php endif; ?>
<?php endif; include 'includes/footer.php'; ?>
