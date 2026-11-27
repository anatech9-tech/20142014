<?php
/** Administrator-only read-only catalogue replicas, not the CRM product bank. */
$pageTitle='کالاهای فروشگاه'; require_login();
require_once __DIR__.'/../includes/shop_catalog_sync.php';
require_once __DIR__.'/../includes/shop_product_mapping.php';
require_once __DIR__.'/../includes/shop_product_commands.php';
$me=(int)($_SESSION['user_id'] ?? 0);
try {
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$ready=crm_shop_catalog_ensure($pdo);
$mappingReady=$ready && shop_product_mapping_ensure($pdo);
$commandReady=false;
if ($ready) {
    if (crm_product_command_configured()) $commandReady=crm_product_command_ensure($pdo);
    else { try { $pdo->query('SELECT 1 FROM crm_shop_product_commands LIMIT 1'); $pdo->query('SELECT 1 FROM crm_shop_product_command_events LIMIT 1'); $commandReady=true; } catch (Throwable $e) {} }
}
$readOnly=is_nazer_kol() || (!access_is_special_account() && access_mode('crm_customers')==='viewer');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $replica=(int)($_POST['replica_id'] ?? 0);
    try {
        if (in_array((string)($_POST['action'] ?? ''),['hide_product','show_product'],true)) {
            $revision=$_POST['revision'] ?? null; $show=$_POST['action']==='show_product';
            if (!$ready || !$commandReady || $readOnly || !is_string($revision) || !preg_match('/^[1-9][0-9]{0,17}$/D',$revision)) throw new DomainException('فرمان کالا مجاز یا آماده نیست.');
            if ($show) crm_product_show_enqueue($pdo,$replica,$me,is_strict_admin(),(int)$revision,time(),($_POST['confirmed_show'] ?? null)==='1' && ($_POST['checked_price_stock'] ?? null)==='1');
            else crm_product_hide_enqueue($pdo,$replica,$me,is_strict_admin(),(int)$revision,time(),($_POST['confirmed_hide'] ?? null)==='1');
            try { operations_audit($pdo,$me,$show?'shop_catalog_show':'shop_catalog_hide','shop_catalog',$replica,'فرمان تغییر نمایش کالا در صف'); } catch (Throwable $e) { error_log('Product command global audit failed'); }
            showToast('فرمان در صف است؛ تغییر نمایش کالا هنوز تأیید نشده است.');
            redirect('index.php?page=shop_catalog&map_id='.$replica);
        }
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
// Categories are inferred from product snapshots, not an authoritative category replica.
$categorySource=(string)($_GET['category_source'] ?? ''); $categoryInput=(string)($_GET['category_id'] ?? '');
$categoryFilter=preg_match('/^[a-f0-9]{32}$/D',$categorySource)===1 && preg_match('/^[1-9][0-9]{0,17}$/D',$categoryInput)===1;
if (!$categoryFilter) { $categorySource=''; $categoryInput=''; }
$where=['1=1']; $args=[];
if ($q!=='') { $where[]='(name_fa LIKE ? OR sku LIKE ?)'; $args[]='%'.$q.'%'; $args[]='%'.$q.'%'; }
if ($active!=='all') { $where[]='is_active=?'; $args[]=$active==='active'?1:0; }
if ($categoryFilter) {
    // CASE prevents malformed historical JSON from aborting the entire page.
    $categoryType=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' ? "JSON_TYPE(payload, '$.category_id')" : "JSON_TYPE(JSON_EXTRACT(payload, '$.category_id'))";
    $where[]="source_id=? AND CASE WHEN JSON_VALID(payload) THEN CASE WHEN LOWER($categoryType)='integer' THEN CAST(JSON_EXTRACT(payload, '$.category_id') AS SIGNED) ELSE NULL END ELSE NULL END = CAST(? AS SIGNED)";
    $args[]=$categorySource; $args[]=(int)$categoryInput;
}
$items=[]; $page=1; $pages=1; $total=0; $selected=null; $currentLink=null; $options=[];
$productCommands=[]; $pendingProductCommand=null;
$mapId=max(0,(int)($_GET['map_id'] ?? 0)); $productSearch=mb_substr(trim((string)($_GET['product_search'] ?? '')),0,100);
if ($ready) {
    $sql=implode(' AND ',$where); $st=$pdo->prepare("SELECT COUNT(*) FROM crm_shop_catalog WHERE $sql"); $st->execute($args); $total=(int)$st->fetchColumn();
    $pages=max(1,(int)ceil($total/30)); $page=min($pages,max(1,(int)($_GET['p'] ?? 1))); $offset=($page-1)*30;
    $st=$pdo->prepare("SELECT * FROM crm_shop_catalog WHERE $sql ORDER BY id DESC LIMIT 30 OFFSET $offset"); $st->execute($args); $items=$st->fetchAll(PDO::FETCH_ASSOC);
    if ($mapId) {
        $st=$pdo->prepare('SELECT * FROM crm_shop_catalog WHERE id=?'); $st->execute([$mapId]); $selected=$st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selected) {
            if ($commandReady) {
                $st=$pdo->prepare('SELECT command_id,source_id,product_id,replica_revision,state,payload,created_at,completed_at FROM crm_shop_product_commands WHERE replica_id=? ORDER BY created_at DESC,command_id DESC LIMIT 10'); $st->execute([$mapId]); $productCommands=$st->fetchAll(PDO::FETCH_ASSOC);
                $st=$pdo->prepare("SELECT command_id FROM crm_shop_product_commands WHERE replica_id=? AND state='queued' LIMIT 1"); $st->execute([$mapId]); $pendingProductCommand=$st->fetchColumn() ?: null;
            }
            if ($mappingReady) {
            $st=$pdo->prepare('SELECT l.*,p.name AS crm_product_name FROM crm_shop_product_links l LEFT JOIN crm_products p ON p.id=l.crm_product_id WHERE l.source_id=? AND l.shop_product_id=?');
            $st->execute([$selected['source_id'],$selected['shop_product_id']]); $currentLink=$st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$readOnly && mb_strlen($productSearch)>=2) {
                $st=$pdo->prepare('SELECT id,name,supplier FROM crm_products WHERE name LIKE ? OR specs LIKE ? OR supplier LIKE ? ORDER BY name LIMIT 30');
                $like='%'.$productSearch.'%'; $st->execute([$like,$like,$like]); $options=$st->fetchAll(PDO::FETCH_ASSOC);
            }
            }
        }
    }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>کالاهای فروشگاه</h1><p>نسخهٔ دریافتی با تأخیر از کالاهای فعال و غیرفعال؛ فرمان‌های محدود نمایش و مخفی‌سازی جداگانه و پیش‌فرض خاموش‌اند؛ موجودی و قیمت قطعی لحظه‌ای نیست. اتصال هویت فقط با بررسی و تأیید دستی مدیر انجام می‌شود؛ قیمت یا موجودی را تغییر نمی‌دهد.</p><p><a class="btn" href="index.php?page=crm_products">بازگشت به بانک کالا</a> <a class="btn" href="index.php?page=shop_review_meta">نمای کلی دیدگاه‌های فروشگاه</a></p></div>
<?php if (!$ready): ?><div class="card" style="padding:16px">جدول کاتالوگ آماده نیست؛ مجوز دیتابیس را بررسی کنید.</div><?php else: ?>
<div class="card" style="padding:16px">
<?php if (!crm_shop_catalog_configured()): ?><p><strong>همگام‌سازی کاتالوگ در CRM فعال یا پیکربندی نشده است؛ نبود کالا در فهرست به معنی خالی بودن فروشگاه نیست.</strong></p><?php endif; ?>
<p>حذف کالا از فروشگاه فعلاً به این فهرست منتقل نمی‌شود و باید دستی تطبیق شود. تصاویر، توضیحات کامل و نرخ تبدیل ارز در این نسخه منتقل نمی‌شوند. فیلتر دسته فقط بر اساس تصویر دریافتی کالاهاست؛ دسته‌های خالی یا حذف‌شده را نشان نمی‌دهد و مدیریت دسته نیست.</p>
<?php if ($categoryFilter): ?><p>فیلتر دستهٔ فروشگاه: <?= $h($categoryInput) ?>، منبع <?= $h(substr($categorySource,0,8)) ?>… <a href="index.php?<?= $h(http_build_query(['page'=>'shop_catalog','q'=>$q,'active'=>$active])) ?>">حذف فیلتر دسته</a></p><?php endif; ?>
<form method="get"><input type="hidden" name="page" value="shop_catalog"><?php if ($categoryFilter): ?><input type="hidden" name="category_source" value="<?= $h($categorySource) ?>"><input type="hidden" name="category_id" value="<?= $h($categoryInput) ?>"><?php endif; ?><label>نام یا کد کالا <input name="q" maxlength="100" value="<?= $h($q) ?>"></label> <label>وضعیت <select name="active"><option value="all" <?= $active==='all'?'selected':'' ?>>همه</option><option value="active" <?= $active==='active'?'selected':'' ?>>فعال</option><option value="inactive" <?= $active==='inactive'?'selected':'' ?>>غیرفعال</option></select></label> <button class="btn" type="submit">جست‌وجو</button></form>
<div style="overflow-x:auto;margin-top:16px"><table class="data-table"><thead><tr><th>شناسه فروشگاه</th><th>کالا / کد</th><th>دسته</th><th>قیمت پایه ثبت‌شده</th><th>موجودی ثبت‌شده</th><th>نمایش</th><th>آخرین دریافت</th><th>اتصال به بانک کالا</th></tr></thead><tbody>
<?php foreach ($items as $r): $p=json_decode((string)$r['payload'],true); if (!is_array($p)) continue; $price=($p['currency'] ?? '')==='usd' ? ($p['price_usd'] ?? null) : ($p['price_toman'] ?? null); ?>
<tr><td><?= (int)$r['shop_product_id'] ?><br><small>منبع: <?= $h(substr((string)$r['source_id'],0,8)) ?>…</small></td><td><?= $h($p['name_fa'] ?? '') ?><br><small><?= $h($p['sku'] ?: 'بدون کد') ?><?= !empty($p['brand'])?' — '.$h($p['brand']):'' ?></small></td><td><?php if (is_int($p['category_id'] ?? null) && $p['category_id']>0 && preg_match('/^[a-f0-9]{32}$/D',(string)$r['source_id'])): ?><a href="index.php?<?= $h(http_build_query(['page'=>'shop_catalog','q'=>$q,'active'=>$active,'category_source'=>$r['source_id'],'category_id'=>$p['category_id']])) ?>"><?= $h($p['category_name'] ?? 'نام دسته ثبت نشده') ?></a><?php else: ?><?= $h($p['category_name'] ?? 'بدون دسته') ?><?php endif; ?></td><td><?= $price===null ? 'ثبت نشده' : $h($price).' '.(($p['currency'] ?? '')==='usd'?'دلار':'تومان') ?></td><td><?= (int)($p['stock'] ?? 0) ?></td><td><?= (int)$r['is_active']===1?'فعال':'غیرفعال' ?></td><td><?= $h($r['received_at']) ?></td><td><a class="btn" href="index.php?page=shop_catalog&amp;map_id=<?= (int)$r['id'] ?>">جزئیات و اتصال</a></td></tr>
<?php endforeach; ?><?php if (!$items): ?><tr><td colspan="8">کالایی در نتایج دریافت‌شده نیست.</td></tr><?php endif; ?></tbody></table></div>
<p>صفحه <?= $page ?> از <?= $pages ?> — <?= $total ?> کالا
<?php if ($page>1): ?><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_catalog','q'=>$q,'active'=>$active,'category_source'=>$categorySource,'category_id'=>$categoryInput,'p'=>$page-1])) ?>">قبلی</a><?php endif; ?>
<?php if ($page<$pages): ?><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_catalog','q'=>$q,'active'=>$active,'category_source'=>$categorySource,'category_id'=>$categoryInput,'p'=>$page+1])) ?>">بعدی</a><?php endif; ?></p>
</div>
<?php if ($mapId && !$selected): ?><div class="card" style="padding:16px;margin-top:14px">کالا در کاتالوگ دریافتی یافت نشد.</div>
<?php elseif ($selected): $sp=json_decode((string)$selected['payload'],true); if (!is_array($sp)) $sp=[]; $version=(int)($currentLink['version'] ?? 0); ?>
<div class="card" style="padding:18px;margin-top:14px"><h3>بررسی اتصال «<?= $h($sp['name_fa'] ?? '') ?>»</h3>
<p>شناسه فروشگاه <?= (int)$selected['shop_product_id'] ?> | منبع <?= $h($selected['source_id']) ?> | نسخه دریافتی <?= (int)$selected['revision'] ?> | آخرین دریافت <?= $h($selected['received_at']) ?></p>
<p>این تصویر ممکن است قدیمی یا کالای مبدأ حذف شده باشد. مشخصات را در فروشگاه و بانک کالا تطبیق دهید. این اتصال برای همه سفارش‌های همان منبع و شناسه اعمال می‌شود، تأیید تاریخی نیست و قیمت، موجودی یا انتشار را تغییر نمی‌دهد.</p>
<?php if ($commandReady && $productCommands): ?><div><h4>فرمان‌های کالا</h4><?php foreach ($productCommands as $cmd): if ($cmd['source_id']!==$selected['source_id'] || (int)$cmd['product_id']!==(int)$selected['shop_product_id']): ?><p>هویت فرمان قبلی با کالا ناسازگار است؛ تطبیق دستی لازم است.</p><?php continue; endif; $commandData=json_decode((string)$cmd['payload'],true); $commandType=is_array($commandData)?($commandData['type'] ?? ''):''; $commandName=$commandType==='shop_product_show_v1'?'انتشار':($commandType==='shop_product_hide_v1'?'مخفی‌سازی':'فرمان نامشخص'); $labels=['queued'=>'در صف؛ نتیجه نامشخص','applied'=>'فروشگاه تغییر نمایش را در آن زمان تأیید کرد؛ وضعیت فعلی را تطبیق دهید','conflict'=>'رد شد؛ کالا یا نسخه نیازمند بررسی است','expired'=>'مهلت فرمان تمام شد']; ?><p><?= $h($commandName) ?>: <?= $h($labels[$cmd['state']] ?? 'نامشخص') ?> — <?= $h($cmd['command_id']) ?> — <?= $h($cmd['created_at']) ?></p><?php endforeach; ?></div><?php endif; ?>
<?php if ($commandReady && crm_product_command_configured() && crm_product_hide_enabled() && crm_shop_catalog_configured() && !$readOnly && !$pendingProductCommand && (int)$selected['is_active']===1): ?><form method="post" onsubmit="return confirm('کالا در صورت تطابق نسخه، از نمایش فروشگاه مخفی شود؟ اجرا هنوز قطعی نیست.');"><?= get_csrf_field() ?><input type="hidden" name="action" value="hide_product"><input type="hidden" name="replica_id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="revision" value="<?= (int)$selected['revision'] ?>"><label><input type="checkbox" name="confirmed_hide" value="1" required> هویت کالا و اثر مخفی‌شدن آن بر فروش را بررسی کردم</label> <button class="btn btn-primary" type="submit">ثبت فرمان مخفی‌کردن کالا</button></form><p>آزمایشی: ارسال فرمان به معنای اجرای قطعی نیست؛ قیمت و موجودی تغییر نمی‌کند.</p><?php endif; ?>

<?php if ($commandReady && crm_product_command_configured() && crm_product_show_enabled() && crm_shop_catalog_configured() && !$readOnly && !$pendingProductCommand && (int)$selected['is_active']===0 && ($sp['currency'] ?? '')==='toman' && is_int($sp['price_toman'] ?? null) && $sp['price_toman']>0): ?><form method="post" onsubmit="return confirm('کالا در صورت تطابق نسخه در فروشگاه منتشر شود؟ هنوز اجرا قطعی نیست.');"><?= get_csrf_field() ?><input type="hidden" name="action" value="show_product"><input type="hidden" name="replica_id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="revision" value="<?= (int)$selected['revision'] ?>"><label><input type="checkbox" name="confirmed_show" value="1" required> هویت، محتوای محصول و مجوز انتشار آن را بررسی کردم</label> <label><input type="checkbox" name="checked_price_stock" value="1" required> قیمت تومانی و موجودی را مستقل در فروشگاه بررسی کردم</label> <button class="btn btn-primary" type="submit">ثبت فرمان انتشار کالا</button></form><p>این فقط برچسب فعال را تغییر می‌دهد؛ محتوای محصول، قیمت و موجودی را تأیید یا تغییر نمی‌دهد. کالاهای ارزی و قیمت صفر از این مسیر منتشر نمی‌شوند.</p><?php endif; ?>
<?php if ($commandReady && crm_product_command_configured() && crm_product_show_enabled() && !$readOnly && (int)$selected['is_active']===0 && (($sp['currency'] ?? '')!=='toman' || !is_int($sp['price_toman'] ?? null) || $sp['price_toman']<=0)): ?><p>انتشار از این مسیر فقط برای کالای با قیمت تومانی مثبت ممکن است؛ کالاهای ارزی و قیمت خالی یا صفر باید دستی بررسی شوند.</p><?php endif; ?>
<?php if (!$mappingReady): ?><p>بانک کالا یا جدول اتصال آماده نیست.</p><?php else: ?>
<?php if (!empty($currentLink['crm_product_id']) && !empty($currentLink['crm_product_name'])): ?><p>اتصال فعلی: <a href="index.php?page=crm_products&amp;view=<?= (int)$currentLink['crm_product_id'] ?>"><?= $h($currentLink['crm_product_name']) ?></a></p><?php elseif (!empty($currentLink['crm_product_id'])): ?><p>کالای متصل در بانک یافت نشد؛ اتصال را بررسی کنید.</p><?php else: ?><p>اتصال فعالی ثبت نشده است.</p><?php endif; ?>
<?php if (!$readOnly): ?><form method="get"><input type="hidden" name="page" value="shop_catalog"><input type="hidden" name="map_id" value="<?= (int)$selected['id'] ?>"><label>نام یا تأمین‌کننده در بانک کالا <input name="product_search" required minlength="2" maxlength="100" value="<?= $h($productSearch) ?>"></label> <button class="btn" type="submit">جست‌وجو</button></form>
<?php if (mb_strlen($productSearch)>=2): ?><?php if ($options): ?><form method="post" onsubmit="return confirm('مشخصات کالاها تطبیق داده شده و اتصال برای همه سفارش‌های دارای این شناسه ثبت شود؟');"><?= get_csrf_field() ?><input type="hidden" name="action" value="map"><input type="hidden" name="replica_id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="version" value="<?= $version ?>"><input type="hidden" name="revision" value="<?= (int)$selected['revision'] ?>"><label>کالای CRM <select name="target" required><?php foreach ($options as $option): ?><option value="<?= (int)$option['id'] ?>"><?= $h($option['name']) ?> — <?= $h($option['supplier'] ?: 'بدون تأمین‌کننده') ?></option><?php endforeach; ?></select></label> <button class="btn btn-primary" type="submit">تأیید اتصال</button></form><?php else: ?><p>کالایی در بانک پیدا نشد.</p><?php endif; ?><?php endif; ?>
<?php if (!empty($currentLink['crm_product_id'])): ?><form method="post" style="margin-top:10px" onsubmit="return confirm('اتصال کالا برای همه سفارش‌های دارای این شناسه قطع شود؟');"><?= get_csrf_field() ?><input type="hidden" name="action" value="map"><input type="hidden" name="replica_id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="target" value="0"><input type="hidden" name="version" value="<?= $version ?>"><input type="hidden" name="revision" value="<?= (int)$selected['revision'] ?>"><button class="btn" type="submit">قطع اتصال</button></form><?php endif; ?><?php endif; ?>
<?php endif; ?>
</div><?php endif; ?>
<?php endif; include 'includes/footer.php'; ?>
