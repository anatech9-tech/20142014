<?php
/** Active administrators only; grouping changes no CRM product fields and never writes to the shop. */
$pageTitle='گروه‌بندی کالاهای CRM'; require_login(); header('Cache-Control: no-store');
require_once __DIR__.'/../includes/crm_product_groups.php';
$me=(int)($_SESSION['user_id'] ?? 0);
try { $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$ready=crm_product_groups_ensure($pdo); $readonly=is_nazer_kol() || (!access_is_special_account() && access_mode('crm_customers')==='viewer');
$parse=static function($v,bool $zero=false): int { return is_string($v) && preg_match($zero?'/^(0|[1-9][0-9]{0,17})$/D':'/^[1-9][0-9]{0,17}$/D',$v)?(int)$v:-1; };
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    $product=$parse($_POST['product_id'] ?? null); if ($product<0) $product=0; $action=$_POST['action'] ?? null;
    try {
        if (!$ready || $readonly || !is_string($action)) throw new DomainException('عملیات مجاز یا آماده نیست.');
        if ($action==='link') {
            $group=$parse($_POST['group_id'] ?? null); $groupVersion=$parse($_POST['group_version'] ?? null); $version=$parse($_POST['version'] ?? null,true); $name=$_POST['product_name'] ?? null;
            if ($product<=0 || $group<=0 || $groupVersion<=0 || $version<0 || !is_string($name)) throw new DomainException('درخواست اتصال معتبر نیست.');
            crm_product_group_link($pdo,$me,true,$product,$name,$group,$groupVersion,$version,($_POST['checked_product'] ?? null)==='1' && ($_POST['checked_group'] ?? null)==='1'); $entity=$product;
        } elseif ($action==='unlink') {
            $id=$parse($_POST['link_id'] ?? null); $version=$parse($_POST['version'] ?? null);
            if ($id<=0 || $version<=0) throw new DomainException('درخواست قطع اتصال معتبر نیست.');
            crm_product_group_unlink($pdo,$me,true,$id,$version,($_POST['confirmed'] ?? null)==='1'); $entity=$id;
        } else throw new DomainException('عملیات نامعتبر است.');
        try { operations_audit($pdo,$me,'crm_product_group_'.$action,'crm_product_group',$entity,'گروه‌بندی دستی کالا'); } catch (Throwable $e) { error_log('Product grouping global audit failed'); }
        showToast('ارجاع داخلی ثبت شد؛ مشخصات کالا و فروشگاه تغییر نکردند.');
    } catch (Throwable $e) { error_log('Product grouping failed: '.get_class($e)); showToast($e instanceof DomainException?$e->getMessage():'ثبت ارجاع ممکن نشد.','error'); }
    redirect('index.php?page=crm_product_groups'.($product>0?'&product='.$product:''));
}
$h=static fn($v)=>htmlspecialchars((string)($v ?? ''),ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
$product=$parse($_GET['product'] ?? null); if ($product<0) $product=0; $group=$parse($_GET['group'] ?? null); if ($group<0) $group=0;
$productBefore=$parse($_GET['product_before'] ?? null); if ($productBefore<0) $productBefore=0;
$groupBefore=$parse($_GET['group_before'] ?? null); if ($groupBefore<0) $groupBefore=0;
$linkBefore=$parse($_GET['link_before'] ?? null); if ($linkBefore<0) $linkBefore=0;
$filterGroup=$parse($_GET['filter_group'] ?? null); if ($filterGroup<0) $filterGroup=0;
$eventBefore=$parse($_GET['event_before'] ?? null); if ($eventBefore<0) $eventBefore=0;
$q=$_GET['q'] ?? ''; if (!is_string($q) || strlen($q)>400 || preg_match('//u',$q)!==1 || preg_match('/[\x00-\x1f\x7f]/',$q) || preg_match_all('/./us',$q)>100) $q=''; else $q=trim($q);
$productReady=false; $groupReady=false;
try { $pdo->query('SELECT id,name FROM crm_products LIMIT 1'); $productReady=true; } catch (Throwable $e) {}
try { $pdo->query('SELECT id,name,version,is_active FROM crm_shop_category_groups LIMIT 1'); $groupReady=true; } catch (Throwable $e) {}
$products=[]; $groups=[]; $links=[]; $events=[]; $selectedProduct=null; $selectedGroup=null; $current=null; $pn=0; $gn=0; $ln=0; $en=0;
if ($ready) {
    if ($productReady) {
        $where=[]; $args=[]; if ($q!=='') { $where[]='name LIKE ?'; $args[]='%'.$q.'%'; } if ($productBefore) { $where[]='id<?'; $args[]=$productBefore; }
        $st=$pdo->prepare('SELECT id,name FROM crm_products'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC LIMIT 31'); $st->execute($args); $products=$st->fetchAll(PDO::FETCH_ASSOC);
        if (count($products)>30) { array_pop($products); $pn=(int)$products[count($products)-1]['id']; }
        if ($product>0) { $st=$pdo->prepare('SELECT id,name FROM crm_products WHERE id=?'); $st->execute([$product]); $selectedProduct=$st->fetch(PDO::FETCH_ASSOC) ?: null; }
    }
    if ($groupReady) {
        $st=$pdo->prepare('SELECT id,name,version,is_active FROM crm_shop_category_groups'.($groupBefore?' WHERE id<?':'').' ORDER BY id DESC LIMIT 31'); $st->execute($groupBefore?[$groupBefore]:[]); $groups=$st->fetchAll(PDO::FETCH_ASSOC);
        if (count($groups)>30) { array_pop($groups); $gn=(int)$groups[count($groups)-1]['id']; }
        if ($group>0) { $st=$pdo->prepare('SELECT id,name,version,is_active FROM crm_shop_category_groups WHERE id=?'); $st->execute([$group]); $selectedGroup=$st->fetch(PDO::FETCH_ASSOC) ?: null; }
    }
    if ($selectedProduct && $selectedGroup) { $st=$pdo->prepare('SELECT * FROM crm_product_group_links WHERE product_id=? AND group_id=?'); $st->execute([$product,$group]); $current=$st->fetch(PDO::FETCH_ASSOC) ?: null; }
    $select=($productReady?',p.name AS current_name':',NULL AS current_name').($groupReady?',g.name AS group_name,g.version AS current_group_version,g.is_active AS group_active':',NULL AS group_name,NULL AS current_group_version,NULL AS group_active');
    $joins=($productReady?' LEFT JOIN crm_products p ON p.id=l.product_id':'').($groupReady?' LEFT JOIN crm_shop_category_groups g ON g.id=l.group_id':'');
    $linkWhere=['l.is_linked=1']; $linkArgs=[]; if ($filterGroup) { $linkWhere[]='l.group_id=?'; $linkArgs[]=$filterGroup; } if ($linkBefore) { $linkWhere[]='l.id<?'; $linkArgs[]=$linkBefore; }
    $st=$pdo->prepare('SELECT l.*'.$select.' FROM crm_product_group_links l'.$joins.' WHERE '.implode(' AND ',$linkWhere).' ORDER BY l.id DESC LIMIT 31'); $st->execute($linkArgs); $links=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($links)>30) { array_pop($links); $ln=(int)$links[count($links)-1]['id']; }
    $st=$pdo->prepare('SELECT id,link_id,product_id,group_id,version,group_version,action,actor_id,created_at FROM crm_product_group_events'.($eventBefore?' WHERE id<?':'').' ORDER BY id DESC LIMIT 31'); $st->execute($eventBefore?[$eventBefore]:[]); $events=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($events)>30) { array_pop($events); $en=(int)$events[count($events)-1]['id']; }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>گروه‌بندی کالاهای CRM</h1><p>گروه‌بندی دستی و داخلی، فقط برای مدیر فعال؛ نه مشخصات کالا تغییر می‌کند و نه دسته یا کالای فروشگاه. اتصال دستهٔ فروشگاه به همین گروه به معنی تعلق خودکار کالا نیست.</p><p><a class="btn" href="index.php?page=shop_category_groups">گروه‌ها و ارجاع دسته فروشگاه</a> <a class="btn" href="index.php?page=crm_products">بانک کالا</a></p></div>
<div class="card" style="padding:16px"><p><strong>فقط نام فعلی کالا و نسخه گروه هنگام ثبت بررسی می‌شوند؛ مشخصات، تأمین‌کننده، قیمت یا تغییر و بازگشت نام و استفاده مجدد شناسه قابل تشخیص نیست. نبود هشدار اثبات صحت نیست.</strong></p>
<?php if (!$ready): ?><p>جدول اتصال داخلی کالا آماده نیست.</p><?php else: ?>
<?php if (!$productReady || !$groupReady): ?><p>بانک کالا یا گروه آماده نیست؛ قطع ارجاع موجود همچنان ممکن است.</p><?php endif; ?>
<?php if ($selectedProduct && $selectedGroup): ?><h3>انتخاب فعلی</h3><p>کالا #<?= (int)$selectedProduct['id'] ?>: <?= $h($selectedProduct['name']) ?> — گروه #<?= (int)$selectedGroup['id'] ?>: <?= $h($selectedGroup['name']) ?>، نسخه <?= (int)$selectedGroup['version'] ?> (<?= (int)$selectedGroup['is_active']===1?'فعال':'غیرفعال' ?>)</p><p>اتصال فعلی: <?= $current && (int)$current['is_linked']===1?'ثبت‌شده':'ثبت نشده یا قطع‌شده' ?>، نسخه <?= (int)($current['version'] ?? 0) ?>.</p>
<?php if (!$readonly && (int)$selectedGroup['is_active']===1 && crm_product_groups_valid_name($selectedProduct['name'])): ?><form method="post" onsubmit="return confirm('ارجاع داخلی این کالا به گروه ثبت شود؟ سایت و مشخصات کالا تغییر نمی‌کنند.');"><?= get_csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="product_id" value="<?= (int)$selectedProduct['id'] ?>"><input type="hidden" name="product_name" value="<?= $h($selectedProduct['name']) ?>"><input type="hidden" name="group_id" value="<?= (int)$selectedGroup['id'] ?>"><input type="hidden" name="group_version" value="<?= (int)$selectedGroup['version'] ?>"><input type="hidden" name="version" value="<?= (int)($current['version'] ?? 0) ?>"><label><input type="checkbox" name="checked_product" value="1" required> کالا را بررسی کردم</label><br><label><input type="checkbox" name="checked_group" value="1" required> ارجاع داخلی گروه را تأیید می‌کنم</label><br><button class="btn" type="submit">ثبت یا بازبینی ارجاع کالا</button></form><?php endif; ?><?php endif; ?>
<h3>انتخاب کالا</h3><form method="get"><input type="hidden" name="page" value="crm_product_groups"><input type="hidden" name="group" value="<?= (int)$group ?>"><label>نام کالا <input name="q" maxlength="100" value="<?= $h($q) ?>"></label> <button class="btn" type="submit">جست‌وجو</button></form><ul><?php foreach ($products as $p): ?><li><a href="index.php?<?= $h(http_build_query(['page'=>'crm_product_groups','product'=>(int)$p['id'],'group'=>$group,'q'=>$q,'product_before'=>$productBefore])) ?>"><?= $h($p['name']) ?> — #<?= (int)$p['id'] ?></a></li><?php endforeach; ?><?php if (!$products): ?><li>کالایی در این صفحه نیست.</li><?php endif; ?></ul><?php if ($pn): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'crm_product_groups','product'=>$product,'group'=>$group,'q'=>$q,'product_before'=>$pn])) ?>">کالاهای بعدی</a></p><?php endif; ?><p><a href="index.php?<?= $h(http_build_query(['page'=>'crm_product_groups','product'=>$product,'group'=>$group,'q'=>$q])) ?>">صفحه اول کالاها</a></p>
<h3>انتخاب گروه</h3><ul><?php foreach ($groups as $g): ?><li><?= $h($g['name']) ?> — #<?= (int)$g['id'] ?> — <?= (int)$g['is_active']===1?'فعال':'غیرفعال' ?><?php if ((int)$g['is_active']===1): ?> <a href="index.php?<?= $h(http_build_query(['page'=>'crm_product_groups','product'=>$product,'group'=>(int)$g['id'],'group_before'=>$groupBefore,'q'=>$q])) ?>">انتخاب</a><?php endif; ?></li><?php endforeach; ?><?php if (!$groups): ?><li>گروهی در این صفحه نیست.</li><?php endif; ?></ul><?php if ($gn): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'crm_product_groups','product'=>$product,'group'=>$group,'group_before'=>$gn,'q'=>$q])) ?>">گروه‌های بعدی</a></p><?php endif; ?><p><a href="index.php?<?= $h(http_build_query(['page'=>'crm_product_groups','product'=>$product,'group'=>$group,'q'=>$q])) ?>">صفحه اول گروه‌ها</a></p>
<h3>ارجاع‌های فعال</h3><p><?php if ($filterGroup): ?>فیلتر گروه #<?= (int)$filterGroup ?> — <a href="index.php?<?= $h(http_build_query(['page'=>'crm_product_groups','product'=>$product,'group'=>$group])) ?>">همهٔ ارجاع‌ها</a><?php elseif ($selectedGroup): ?><a href="index.php?<?= $h(http_build_query(['page'=>'crm_product_groups','product'=>$product,'group'=>$group,'filter_group'=>$group])) ?>">فقط ارجاع‌های گروه انتخاب‌شده</a><?php endif; ?></p><ul><?php foreach ($links as $l): $stale=$l['current_name']===null || $l['current_name']!==$l['product_name'] || $l['group_name']===null || (int)$l['group_active']!==1 || (int)$l['current_group_version']!==(int)$l['group_version']; ?><li>کالا #<?= (int)$l['product_id'] ?>: <?= $l['current_name']===null?'ناموجود':$h($l['current_name']) ?> ← گروه #<?= (int)$l['group_id'] ?>: <?= $l['group_name']===null?'ناموجود':$h($l['group_name']) ?> — نسخه <?= (int)$l['version'] ?> — <?= $stale?'نیازمند بررسی مجدد':'نام و نسخه گروه بدون تغییر' ?>
<?php if (!$readonly): ?><form method="post" onsubmit="return confirm('ارجاع داخلی این کالا قطع شود؟');"><?= get_csrf_field() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="product_id" value="<?= (int)$product ?>"><input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="version" value="<?= (int)$l['version'] ?>"><label><input type="checkbox" name="confirmed" value="1" required> قطع ارجاع داخلی را تأیید می‌کنم</label> <button class="btn" type="submit">قطع ارجاع</button></form><?php endif; ?></li><?php endforeach; ?><?php if (!$links): ?><li>ارجاعی در این صفحه نیست.</li><?php endif; ?></ul><?php if ($ln): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'crm_product_groups','product'=>$product,'group'=>$group,'filter_group'=>$filterGroup,'link_before'=>$ln])) ?>">ارجاع‌های بعدی</a></p><?php endif; ?>
<h3>تاریخچه ارجاع کالا</h3><ul><?php foreach ($events as $e): ?><li>ارجاع #<?= (int)$e['link_id'] ?> نسخه <?= (int)$e['version'] ?> — کالا #<?= (int)$e['product_id'] ?> — گروه #<?= (int)$e['group_id'] ?> — <?= $e['action']==='link'?'ثبت/بازبینی':($e['action']==='unlink'?'قطع':'نامعتبر') ?> — نسخه گروه <?= $e['group_version']===null?'—':(int)$e['group_version'] ?> — مدیر <?= (int)$e['actor_id'] ?> — <?= $h($e['created_at']) ?></li><?php endforeach; ?><?php if (!$events): ?><li>رویدادی در این صفحه نیست.</li><?php endif; ?></ul><?php if ($en): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'crm_product_groups','product'=>$product,'group'=>$group,'event_before'=>$en])) ?>">رویدادهای بعدی</a></p><?php endif; ?>
<?php endif; ?></div><?php include 'includes/footer.php'; ?>
