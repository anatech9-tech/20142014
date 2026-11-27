<?php
/** Active administrators only; internal plain-text drafts, never published to the website. */
$pageTitle='پیش‌نویس محتوای سایت'; require_login(); header('Cache-Control: no-store');
require_once __DIR__.'/../includes/site_content_drafts.php';
$me=(int)($_SESSION['user_id'] ?? 0);
try {
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$ready=content_draft_ensure($pdo); $readonly=is_nazer_kol() || (!access_is_special_account() && access_mode('crm_customers')==='viewer');
$parse=static function($v,bool $zero=false): int { if (!is_string($v) || !preg_match($zero?'/^(0|[1-9][0-9]{0,17})$/D':'/^[1-9][0-9]{0,17}$/D',$v)) return -1; return (int)$v; };
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    $id=$parse($_POST['draft_id'] ?? null,true); $version=$parse($_POST['version'] ?? null,true); $action=$_POST['action'] ?? null;
    try {
        if (!$ready || $readonly || $id<0 || $version<0 || !is_string($action) || !in_array($action,['create','edit','restore'],true)) throw new DomainException('عملیات مجاز یا آماده نیست.');
        $title=$_POST['title'] ?? ''; $body=$_POST['body'] ?? '';
        if (!is_string($title) || !is_string($body)) throw new DomainException('متن نامعتبر است.');
        $target=0;
        if ($action==='restore') { $target=$parse($_POST['restore_version'] ?? null); if ($target<=0 || ($_POST['confirm_restore'] ?? null)!=='1') throw new DomainException('تأیید نسخهٔ بازگشتی لازم است.'); }
        $id=content_draft_write($pdo,$me,true,$id,$version,$action,$title,$body,$target);
        try { operations_audit($pdo,$me,'site_content_draft_'.$action,'site_content_draft',$id,'تغییر پیش‌نویس داخلی محتوا'); } catch (Throwable $e) { error_log('Content draft global audit failed'); }
        showToast('پیش‌نویس داخلی ثبت شد؛ در سایت منتشر نشده است.');
    } catch (Throwable $e) { error_log('Content draft failed: '.get_class($e)); showToast($e instanceof DomainException?$e->getMessage():'ثبت پیش‌نویس ممکن نشد.','error'); }
    redirect('index.php?page=site_content_drafts'.($id>0?'&draft='.$id:''));
}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
$draftRaw=$_GET['draft'] ?? ''; $id=$parse($draftRaw); if ($id<0) $id=0;
$oldRaw=$_GET['history'] ?? ''; $oldVersion=$parse($oldRaw); if ($oldVersion<0) $oldVersion=0;
$beforeRaw=$_GET['before'] ?? ''; $before=$parse($beforeRaw); if ($before<0) $before=0;
$rows=[]; $selected=null; $versions=[]; $old=null; $next=0;
if ($ready) {
    $st=$pdo->prepare('SELECT id,title,version,updated_at FROM crm_site_content_drafts'.($before?' WHERE id<?':'').' ORDER BY id DESC LIMIT 31'); $st->execute($before?[$before]:[]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)>30) { array_pop($rows); $next=(int)$rows[count($rows)-1]['id']; }
    if ($id>0) {
        $st=$pdo->prepare('SELECT id,title,body,version,updated_at FROM crm_site_content_drafts WHERE id=?'); $st->execute([$id]); $selected=$st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selected) {
            $st=$pdo->prepare('SELECT version,actor_id,action,restored_version,created_at FROM crm_site_content_draft_versions WHERE draft_id=? ORDER BY version DESC LIMIT 100'); $st->execute([$id]); $versions=$st->fetchAll(PDO::FETCH_ASSOC);
            if ($oldVersion>0) { $st=$pdo->prepare('SELECT version,title,body FROM crm_site_content_draft_versions WHERE draft_id=? AND version=?'); $st->execute([$id,$oldVersion]); $old=$st->fetch(PDO::FETCH_ASSOC) ?: null; }
        }
    }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>پیش‌نویس محتوای سایت</h1><p>این متن‌ها فقط در CRM ذخیره می‌شوند؛ ارجاع دستی به شناسهٔ مقاله سایت ممکن است، اما متن نه وارد سایت می‌شود و نه منتشر می‌شود. ارجاع اثبات همسانی محتوا نیست.</p></div>
<div class="card" style="padding:16px"><p><strong>متن را به‌صورت متن ساده و بدون اطلاعات محرمانه وارد کنید؛ HTML اجرا نمی‌شود. هر ویرایش نسخه‌ای کامل در CRM و بکاپ آن نگه می‌دارد. دسترسی، مالکیت محتوا و سیاست نگهداری را بررسی کنید. عنوان حداکثر ۲۰۰ نویسه و متن حداکثر ۳۲٬۰۰۰ بایت است. هر پیش‌نویس حداکثر ۱۰۰ نسخه دارد؛ ساخت تکراری خودکار تشخیص داده نمی‌شود.</strong></p>
<?php if (!$ready): ?><p>جدول پیش‌نویس آماده نیست.</p><?php else: ?>
<?php if ($selected): ?>
<h3>پیش‌نویس #<?= (int)$selected['id'] ?> — نسخه <?= (int)$selected['version'] ?></h3><p><a class="btn" href="index.php?page=site_blog_metadata&amp;draft=<?= (int)$selected['id'] ?>">تطبیق دستی با مقاله سایت</a></p>
<?php if (!$readonly): ?><form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="draft_id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="version" value="<?= (int)$selected['version'] ?>"><label>عنوان <input name="title" maxlength="200" required value="<?= $h($selected['title']) ?>"></label><br><label>متن ساده<br><textarea name="body" rows="12" style="width:100%" maxlength="32000"><?= $h($selected['body']) ?></textarea></label><br><button class="btn" type="submit">ذخیره نسخه جدید</button></form><?php else: ?><h4><?= $h($selected['title']) ?></h4><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $h($selected['body']) ?></pre><?php endif; ?>
<h4>تاریخچه داخلی</h4><ul><?php foreach ($versions as $v): ?><li><a href="index.php?<?= $h(http_build_query(['page'=>'site_content_drafts','draft'=>$id,'history'=>(int)$v['version']])) ?>">نسخه <?= (int)$v['version'] ?></a> — <?= $h($v['created_at']) ?> — شناسه مدیر <?= (int)$v['actor_id'] ?> — <?= $h($v['action']) ?><?php if ($v['restored_version']!==null): ?> (بازگردانی نسخه <?= (int)$v['restored_version'] ?>)<?php endif; ?></li><?php endforeach; ?></ul>
<?php if ($old): ?><h4>نسخه تاریخی <?= (int)$old['version'] ?>: <?= $h($old['title']) ?></h4><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $h($old['body']) ?></pre>
<?php if (!$readonly && (int)$selected['version']<100): ?><form method="post" onsubmit="return confirm('این نسخه به عنوان یک نسخه داخلی جدید بازگردانده شود؟ چیزی در سایت منتشر نمی‌شود.');"><?= get_csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="draft_id" value="<?= (int)$id ?>"><input type="hidden" name="version" value="<?= (int)$selected['version'] ?>"><input type="hidden" name="restore_version" value="<?= (int)$old['version'] ?>"><label><input type="checkbox" name="confirm_restore" value="1" required> نسخه تاریخی را بررسی و بازگردانی داخلی را تأیید می‌کنم</label> <button class="btn" type="submit">بازگردانی به نسخه جدید</button></form><?php endif; ?><?php endif; ?>
<?php elseif (!$readonly): ?><?php if ($id>0): ?><p>پیش‌نویس انتخاب‌شده یافت نشد.</p><?php endif; ?><h3>پیش‌نویس جدید</h3><form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="draft_id" value="0"><input type="hidden" name="version" value="0"><label>عنوان <input name="title" maxlength="200" required></label><br><label>متن ساده<br><textarea name="body" rows="8" style="width:100%" maxlength="32000"></textarea></label><br><button class="btn" type="submit">ساخت پیش‌نویس داخلی</button></form><?php endif; ?>
<h3>فهرست پیش‌نویس‌ها</h3><p><a href="index.php?page=site_content_drafts">پیش‌نویس جدید / صفحه اول</a></p><table class="data-table"><thead><tr><th>عنوان</th><th>نسخه</th><th>آخرین تغییر</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><a href="index.php?page=site_content_drafts&amp;draft=<?= (int)$r['id'] ?>"><?= $h($r['title']) ?></a></td><td><?= (int)$r['version'] ?></td><td><?= $h($r['updated_at']) ?></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="3">پیش‌نویسی ثبت نشده است.</td></tr><?php endif; ?></tbody></table>
<?php if ($next): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'site_content_drafts','before'=>$next])) ?>">صفحه بعد</a></p><?php endif; ?>
<?php endif; ?></div><?php include 'includes/footer.php'; ?>
