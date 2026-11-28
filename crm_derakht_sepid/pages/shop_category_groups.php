<?php
/** Only active administrators: local groups and manual references, no shop or product writes. */
$pageTitle='گروه‌های داخلی دسته‌ها'; require_login(); header('Cache-Control: no-store');
require_once __DIR__.'/../includes/shop_category_groups.php';
$me=(int)($_SESSION['user_id'] ?? 0);
try { $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$ready=shop_category_groups_ensure($pdo); $readonly=is_nazer_kol() || (!access_is_special_account() && access_mode('crm_customers')==='viewer');
$parse=static function($v,bool $zero=false): int { return is_string($v) && preg_match($zero?'/^(0|[1-9][0-9]{0,17})$/D':'/^[1-9][0-9]{0,17}$/D',$v)?(int)$v:-1; };
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    $action=$_POST['action'] ?? null; $selected=$parse($_POST['replica'] ?? null); if ($selected<0) $selected=0;
    try {
        if (!$ready || $readonly || !is_string($action)) throw new DomainException('عملیات مجاز یا آماده نیست.');
        if ($action==='group_save') {
            $id=$parse($_POST['group_id'] ?? null,true); $version=$parse($_POST['version'] ?? null,true); $name=$_POST['name'] ?? null; $active=$_POST['active'] ?? null;
            if ($id<0 || $version<0 || !is_string($name) || !is_string($active) || !in_array($active,['0','1'],true)) throw new DomainException('درخواست گروه نامعتبر است.');
            $entity=shop_category_group_save($pdo,$me,true,$id,$version,$name,(int)$active,($_POST['confirmed'] ?? null)==='1');
        } elseif ($action==='link') {
            $revision=$parse($_POST['revision'] ?? null); $group=$parse($_POST['group_id'] ?? null); $groupVersion=$parse($_POST['group_version'] ?? null); $version=$parse($_POST['version'] ?? null,true);
            if ($selected<=0 || $revision<=0 || $group<=0 || $groupVersion<=0 || $version<0) throw new DomainException('درخواست اتصال نامعتبر است.');
            shop_category_group_link($pdo,$me,true,$selected,$revision,$group,$groupVersion,$version,($_POST['checked_source'] ?? null)==='1' && ($_POST['checked_group'] ?? null)==='1'); $entity=$selected;
        } elseif ($action==='unlink') {
            $id=$parse($_POST['link_id'] ?? null); $version=$parse($_POST['version'] ?? null);
            if ($id<=0 || $version<=0) throw new DomainException('درخواست قطع اتصال نامعتبر است.');
            shop_category_group_unlink($pdo,$me,true,$id,$version,($_POST['confirmed'] ?? null)==='1'); $entity=$id;
        } else throw new DomainException('عملیات نامعتبر است.');
        try { operations_audit($pdo,$me,'shop_category_group_'.$action,'shop_category_group',$entity,'گروه‌بندی دستی داخلی'); } catch (Throwable $e) { error_log('Category grouping global audit failed'); }
        showToast('عملیات داخلی ثبت شد؛ سایت و کالاها تغییر نکردند.');
    } catch (Throwable $e) { error_log('Category grouping failed: '.get_class($e)); showToast($e instanceof DomainException?$e->getMessage():'ثبت ممکن نشد؛ نام تکراری یا دسترسی جدول‌ها را بررسی کنید.','error'); }
    redirect('index.php?page=shop_category_groups'.($selected>0?'&replica='.$selected:''));
}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
$replica=$parse($_GET['replica'] ?? null); if ($replica<0) $replica=0;
$chosen=$parse($_GET['group'] ?? null); if ($chosen<0) $chosen=0;
$before=$parse($_GET['before'] ?? null); if ($before<0) $before=0;
$linkBefore=$parse($_GET['link_before'] ?? null); if ($linkBefore<0) $linkBefore=0;
$eventBefore=$parse($_GET['event_before'] ?? null); if ($eventBefore<0) $eventBefore=0;
$groupEventBefore=$parse($_GET['group_event_before'] ?? null); if ($groupEventBefore<0) $groupEventBefore=0;
$groups=[]; $links=[]; $events=[]; $groupEvents=[]; $selected=null; $group=null; $current=null; $next=0; $linkNext=0; $eventNext=0; $groupEventNext=0; $replicaReady=false;
try { $pdo->query('SELECT 1 FROM crm_shop_category_meta LIMIT 1'); $replicaReady=true; } catch (Throwable $e) {}
if ($ready) {
    $st=$pdo->prepare('SELECT id,name,is_active,version,updated_at FROM crm_shop_category_groups'.($before?' WHERE id<?':'').' ORDER BY id DESC LIMIT 31'); $st->execute($before?[$before]:[]); $groups=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($groups)>30) { array_pop($groups); $next=(int)$groups[count($groups)-1]['id']; }
    if ($chosen>0) { $st=$pdo->prepare('SELECT id,name,is_active,version FROM crm_shop_category_groups WHERE id=?'); $st->execute([$chosen]); $group=$st->fetch(PDO::FETCH_ASSOC) ?: null; }
    if ($replicaReady && $replica>0) {
        $st=$pdo->prepare('SELECT id,source_id,category_id,category_name,is_active,revision,received_at FROM crm_shop_category_meta WHERE id=?'); $st->execute([$replica]); $selected=$st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selected) { $st=$pdo->prepare('SELECT * FROM crm_shop_category_group_links WHERE source_id=? AND category_id=?'); $st->execute([$selected['source_id'],$selected['category_id']]); $current=$st->fetch(PDO::FETCH_ASSOC) ?: null; }
    }
    $metaSelect=$replicaReady?',m.id AS current_replica_id,m.revision AS current_revision,m.category_name AS current_name':',NULL AS current_replica_id,NULL AS current_revision,NULL AS current_name';
    $join=$replicaReady?' LEFT JOIN crm_shop_category_meta m ON m.source_id=l.source_id AND m.category_id=l.category_id':'';
    $st=$pdo->prepare('SELECT l.*,g.name AS group_name,g.version AS current_group_version,g.is_active AS group_active'.$metaSelect.' FROM crm_shop_category_group_links l LEFT JOIN crm_shop_category_groups g ON g.id=l.group_id'.$join.' WHERE l.group_id IS NOT NULL'.($linkBefore?' AND l.id<?':'').' ORDER BY l.id DESC LIMIT 31'); $st->execute($linkBefore?[$linkBefore]:[]); $links=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($links)>30) { array_pop($links); $linkNext=(int)$links[count($links)-1]['id']; }
    $st=$pdo->prepare('SELECT id,link_id,version,source_id,category_id,old_group_id,new_group_id,source_revision,group_version,actor_id,created_at FROM crm_shop_category_group_link_events'.($eventBefore?' WHERE id<?':'').' ORDER BY id DESC LIMIT 31'); $st->execute($eventBefore?[$eventBefore]:[]); $events=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($events)>30) { array_pop($events); $eventNext=(int)$events[count($events)-1]['id']; }
    $st=$pdo->prepare('SELECT id,group_id,version,old_name,new_name,old_active,new_active,actor_id,created_at FROM crm_shop_category_group_events'.($groupEventBefore?' WHERE id<?':'').' ORDER BY id DESC LIMIT 31'); $st->execute($groupEventBefore?[$groupEventBefore]:[]); $groupEvents=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($groupEvents)>30) { array_pop($groupEvents); $groupEventNext=(int)$groupEvents[count($groupEvents)-1]['id']; }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>گروه‌های داخلی دسته‌ها</h1><p>گروه‌ها و ارجاع‌ها فقط در CRM هستند؛ دسته یا کالا در سایت و بانک کالای CRM تغییر نمی‌کند. چند دستهٔ فروشگاه می‌توانند به یک گروه اشاره کنند؛ این کار طبقه‌بندی خودکار کالا نیست؛ کالاهای CRM جداگانه و دستی ارجاع می‌شوند.</p><p><a class="btn" href="index.php?page=crm_product_groups">گروه‌بندی کالاهای CRM</a> <a class="btn" href="index.php?page=shop_category_metadata">نمای دسته‌های فروشگاه</a></p></div>
<div class="card" style="padding:16px"><p><strong>ارجاع اثبات یکسانی، موجود بودن فعلی یا تعلق کالاها نیست. دسته‌ها و کالاها جداگانه و با تأخیر اسکن می‌شوند؛ حذف، بازگشت یا استفادهٔ مجدد شناسه و restore را دستی بررسی کنید.</strong></p>
<?php if (!$ready): ?><p>جدول‌های گروه‌بندی آماده نیستند.</p><?php else: ?>
<?php if (!crm_shop_category_sync_configured()): ?><p>پل دسته غیرفعال است؛ ثبت اتصال جدید مجاز نیست، اما قطع اتصال داخلی ممکن است.</p><?php endif; ?>
<?php if (!$replicaReady): ?><p>نمای دریافتی دسته آماده نیست؛ قطع اتصال داخلی همچنان ممکن است.</p><?php endif; ?>
<?php if ($selected): ?><h3>دسته انتخاب‌شده #<?= (int)$selected['category_id'] ?></h3><p>نام: <?= $selected['category_name']===null?'نبود مشاهده‌شده':$h($selected['category_name']) ?> | منبع: <?= $h($selected['source_id']) ?> | وضعیت دریافتی: <?= (string)$selected['is_active']==='1'?'فعال':((string)$selected['is_active']==='0'?'غیرفعال':'نامعتبر') ?> | revision <?= (int)$selected['revision'] ?> | دریافت: <?= $h($selected['received_at']) ?></p>
<?php if ($current): ?><p>ارجاع فعلی: <?= $current['group_id']===null?'قطع‌شده':'گروه #'.(int)$current['group_id'] ?> — نسخه ارجاع <?= (int)$current['version'] ?> — revision تأییدشده <?= $current['source_revision']===null?'—':(int)$current['source_revision'] ?> — نسخه گروه تأییدشده <?= $current['group_version']===null?'—':(int)$current['group_version'] ?>. تغییر گروه نیازمند بررسی مجدد است.</p><?php else: ?><p>ارجاعی برای این دسته ثبت نشده است.</p><?php endif; ?>
<?php if ($group): ?><p>گروه انتخاب‌شده: <?= $h($group['name']) ?> — نسخه <?= (int)$group['version'] ?> — <?= (int)$group['is_active']===1?'فعال':'غیرفعال' ?></p>
<?php if (!$readonly && crm_shop_category_sync_configured() && $selected['category_name']!==null && (int)$group['is_active']===1): ?><form method="post" onsubmit="return confirm('این ارجاع داخلی ثبت شود؟ دسته و کالا تغییر نمی‌کنند.');"><?= get_csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="replica" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="revision" value="<?= (int)$selected['revision'] ?>"><input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>"><input type="hidden" name="group_version" value="<?= (int)$group['version'] ?>"><input type="hidden" name="version" value="<?= (int)($current['version'] ?? 0) ?>"><label><input type="checkbox" name="checked_source" value="1" required> شناسه و منبع دسته را جداگانه بررسی کردم</label><br><label><input type="checkbox" name="checked_group" value="1" required> ارجاع داخلی را تأیید می‌کنم؛ کالاها تغییر نمی‌کنند</label><br><button class="btn" type="submit">ثبت یا بازبینی ارجاع</button></form><?php endif; ?>
<?php else: ?><p>یک گروه فعال از فهرست زیر انتخاب کنید.</p><?php endif; ?><?php elseif ($replica>0): ?><p>دسته انتخاب‌شده موجود نیست.</p><?php endif; ?>
<h3>گروه‌های داخلی</h3>
<?php if (!$readonly): ?><form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="group_save"><input type="hidden" name="group_id" value="0"><input type="hidden" name="version" value="0"><input type="hidden" name="active" value="1"><input type="hidden" name="replica" value="<?= (int)$replica ?>"><label>نام گروه جدید <input name="name" maxlength="100" required></label> <label><input type="checkbox" name="confirmed" value="1" required> ساخت داخلی را تأیید می‌کنم</label> <button class="btn" type="submit">ساخت گروه</button></form><?php endif; ?>
<ul><?php foreach ($groups as $g): ?><li><strong><?= $h($g['name']) ?></strong> — #<?= (int)$g['id'] ?> — نسخه <?= (int)$g['version'] ?> — <?= (int)$g['is_active']===1?'فعال':'غیرفعال' ?><?php if ($selected && (int)$g['is_active']===1): ?> <a href="index.php?<?= $h(http_build_query(['page'=>'shop_category_groups','replica'=>$replica,'group'=>(int)$g['id'],'before'=>$before])) ?>">انتخاب برای ارجاع</a><?php endif; ?>
<?php if (!$readonly): ?><form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="group_save"><input type="hidden" name="replica" value="<?= (int)$replica ?>"><input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="version" value="<?= (int)$g['version'] ?>"><label>نام <input name="name" maxlength="100" required value="<?= $h($g['name']) ?>"></label> <label>وضعیت <select name="active"><option value="1" <?= (int)$g['is_active']===1?'selected':'' ?>>فعال</option><option value="0" <?= (int)$g['is_active']===0?'selected':'' ?>>غیرفعال</option></select></label> <label><input type="checkbox" name="confirmed" value="1" required> تغییر داخلی را تأیید می‌کنم</label> <button class="btn" type="submit">ثبت تغییر</button></form><?php endif; ?></li><?php endforeach; ?><?php if (!$groups): ?><li>گروهی در این صفحه نیست.</li><?php endif; ?></ul>
<?php if ($next): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_category_groups','replica'=>$replica,'before'=>$next])) ?>">گروه‌های بعدی</a></p><?php endif; ?><p><a href="index.php?<?= $h(http_build_query(['page'=>'shop_category_groups','replica'=>$replica])) ?>">صفحه اول گروه‌ها</a></p>
<h3>ارجاع‌های داخلی فعال</h3><p>تغییر revision دسته یا نسخه/وضعیت گروه نیازمند بررسی مجدد است؛ نبود هشدار اثبات تطبیق نیست.</p><ul>
<?php foreach ($links as $l): $stale=$l['current_replica_id']===null || (int)$l['current_replica_id']!==(int)$l['replica_id'] || $l['current_revision']===null || $l['current_name']===null || (int)$l['current_revision']!==(int)$l['source_revision'] || $l['group_name']===null || (int)$l['group_active']!==1 || (int)$l['current_group_version']!==(int)$l['group_version'] || !crm_shop_category_sync_configured() || $l['source_id']!==(defined('SHOP_CRM_SOURCE_ID')?(string)SHOP_CRM_SOURCE_ID:''); ?><li>دسته #<?= (int)$l['category_id'] ?>، منبع <?= $h($l['source_id']) ?> ← <?= $l['group_name']===null?'گروه ناموجود':$h($l['group_name']) ?> — نسخه اتصال <?= (int)$l['version'] ?> — <?= $stale?'نیازمند بررسی مجدد':'نسخه‌های دریافتی بدون تغییر' ?>
<?php if (!$readonly): ?><form method="post" onsubmit="return confirm('ارجاع داخلی قطع شود؟ دسته و کالا تغییر نمی‌کنند.');"><?= get_csrf_field() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="replica" value="<?= (int)$replica ?>"><input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="version" value="<?= (int)$l['version'] ?>"><label><input type="checkbox" name="confirmed" value="1" required> قطع ارجاع داخلی را تأیید می‌کنم</label> <button class="btn" type="submit">قطع ارجاع</button></form><?php endif; ?></li><?php endforeach; ?><?php if (!$links): ?><li>ارجاعی در این صفحه نیست.</li><?php endif; ?></ul>
<?php if ($linkNext): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_category_groups','replica'=>$replica,'link_before'=>$linkNext])) ?>">ارجاع‌های بعدی</a></p><?php endif; ?>
<h3>تاریخچه ارجاع‌ها</h3><ul><?php foreach ($events as $e): ?><li>ارجاع #<?= (int)$e['link_id'] ?> نسخه <?= (int)$e['version'] ?> — دسته <?= (int)$e['category_id'] ?> منبع <?= $h($e['source_id']) ?> — گروه قبلی <?= $e['old_group_id']===null?'—':(int)$e['old_group_id'] ?> ← گروه جدید <?= $e['new_group_id']===null?'قطع':(int)$e['new_group_id'] ?> — revision دسته <?= $e['source_revision']===null?'—':(int)$e['source_revision'] ?> و نسخه گروه <?= $e['group_version']===null?'—':(int)$e['group_version'] ?> — مدیر <?= (int)$e['actor_id'] ?> — <?= $h($e['created_at']) ?></li><?php endforeach; ?><?php if (!$events): ?><li>رویدادی در این صفحه نیست.</li><?php endif; ?></ul>
<?php if ($eventNext): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_category_groups','replica'=>$replica,'event_before'=>$eventNext])) ?>">رویدادهای ارجاع بعدی</a></p><?php endif; ?>
<h3>تاریخچه گروه‌ها</h3><ul><?php foreach ($groupEvents as $e): ?><li>گروه #<?= (int)$e['group_id'] ?> نسخه <?= (int)$e['version'] ?> — نام قبلی <?= $e['old_name']===null?'—':$h($e['old_name']) ?> ← نام جدید <?= $h($e['new_name']) ?> — وضعیت قبلی <?= $e['old_active']===null?'—':((int)$e['old_active']===1?'فعال':'غیرفعال') ?> ← وضعیت جدید <?= (int)$e['new_active']===1?'فعال':'غیرفعال' ?> — مدیر <?= (int)$e['actor_id'] ?> — <?= $h($e['created_at']) ?></li><?php endforeach; ?><?php if (!$groupEvents): ?><li>رویدادی در این صفحه نیست.</li><?php endif; ?></ul>
<?php if ($groupEventNext): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_category_groups','replica'=>$replica,'group_event_before'=>$groupEventNext])) ?>">رویدادهای گروه بعدی</a></p><?php endif; ?>
<?php endif; ?></div><?php include 'includes/footer.php'; ?>
