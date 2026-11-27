<?php
/** Admin-only metadata and manual internal references; never changes the site. */
$pageTitle='مقاله‌های سایت و تطبیق پیش‌نویس'; require_login(); header('Cache-Control: no-store');
require_once __DIR__.'/../includes/site_blog_draft_links.php';
$me=(int)($_SESSION['user_id'] ?? 0);
try { $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$readonly=is_nazer_kol() || (!access_is_special_account() && access_mode('crm_customers')==='viewer');
$ready=false; if (crm_site_blog_sync_configured()) $ready=crm_site_blog_sync_ensure($pdo);
else { try { $pdo->query('SELECT 1 FROM crm_site_blog_meta LIMIT 1'); $ready=true; } catch (Throwable $e) {} }
$draftReady=content_draft_ensure($pdo); $linkReady=$ready && $draftReady && site_blog_draft_links_ensure($pdo);
$parse=static function($v): int { return is_string($v) && preg_match('/^[1-9][0-9]{0,17}$/D',$v)?(int)$v:0; };
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    $draft=$parse($_POST['draft'] ?? null); $version=$parse($_POST['draft_version'] ?? null); $action=$_POST['action'] ?? null;
    try {
        if (!$linkReady || $readonly || !$draft || !$version || !is_string($action)) throw new DomainException('عملیات مجاز یا آماده نیست.');
        $confirmed=($_POST['confirm_identity'] ?? null)==='1' && ($_POST['confirm_internal'] ?? null)==='1';
        if ($action==='link') site_blog_draft_link($pdo,$draft,$version,$parse($_POST['replica'] ?? null),$parse($_POST['revision'] ?? null),$me,true,$confirmed);
        elseif ($action==='unlink') site_blog_draft_unlink($pdo,$draft,$version,$parse($_POST['link_id'] ?? null),$me,true,$confirmed);
        else throw new DomainException('عملیات نامعتبر است.');
        try { operations_audit($pdo,$me,'site_blog_draft_'.$action,'site_content_draft',$draft,'تغییر اتصال داخلی مقاله و پیش‌نویس'); } catch (Throwable $e) { error_log('Blog link global audit failed'); }
        showToast('اتصال داخلی تغییر کرد؛ محتوای سایت تغییر نکرده است.');
    } catch (Throwable $e) { error_log('Blog draft link failed: '.get_class($e)); showToast($e instanceof DomainException?$e->getMessage():'ثبت تطبیق ممکن نشد.','error'); }
    redirect('index.php?page=site_blog_metadata'.($draft?'&draft='.$draft:''));
}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
$draft=$parse($_GET['draft'] ?? null); $before=$parse($_GET['before'] ?? null); $rows=[]; $next=0; $selected=null; $link=null;
if ($draftReady && $draft) { $st=$pdo->prepare('SELECT id,title,version FROM crm_site_content_drafts WHERE id=?'); $st->execute([$draft]); $selected=$st->fetch(PDO::FETCH_ASSOC) ?: null; }
$linkPost=null;
if ($linkReady && $selected) {
    $st=$pdo->prepare('SELECT * FROM crm_site_blog_draft_links WHERE draft_id=?'); $st->execute([$draft]); $link=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($link) { $st=$pdo->prepare('SELECT source_id,post_id,revision,is_published FROM crm_site_blog_meta WHERE id=?'); $st->execute([$link['replica_id']]); $linkPost=$st->fetch(PDO::FETCH_ASSOC) ?: null; }
}
if ($ready) {
    $st=$pdo->prepare('SELECT id,source_id,post_id,revision,is_published,received_at FROM crm_site_blog_meta'.($before?' WHERE id<?':'').' ORDER BY id DESC LIMIT 31'); $st->execute($before?[$before]:[]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)>30) { array_pop($rows); $next=(int)$rows[count($rows)-1]['id']; }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>مقاله‌های سایت و تطبیق پیش‌نویس</h1><p>فقط شناسه مقاله و برچسب انتشار با تأخیر منتقل می‌شود؛ عنوان، متن و تصویر منتقل نمی‌شوند. این صفحه فقط ارجاع داخلی می‌سازد و هیچ تغییری در سایت ایجاد نمی‌کند.</p><p><a class="btn" href="index.php?page=site_content_drafts">پیش‌نویس‌های داخلی</a></p></div>
<div class="card" style="padding:16px"><p><strong>پیش از تطبیق، مدیر باید شناسه، عنوان و متن مقاله و مالکیت محتوا را در پنل سایت مستقل بررسی کند. revision فقط تغییر شناسه/برچسب مشاهده‌شده را نشان می‌دهد، نه تغییر متن؛ این اتصال اثبات همسانی محتوا نیست و اجازه انتشار نیست. تغییر و بازگشت، حذف و استفادهٔ مجدد شناسه و تأخیر همگام‌سازی ممکن است شناسایی نشوند.</strong></p>
<?php if (!crm_site_blog_sync_configured()): ?><p>دریافت در CRM غیرفعال یا پیکربندی نشده است؛ داده‌های قدیمی ممکن است باقی بمانند.</p><?php endif; ?>
<?php if (!crm_site_blog_sync_deletion_enabled()): ?><p>تطبیق نبود مقاله غیرفعال است؛ مقالهٔ حذف‌شده ممکن است نمایش داده شود.</p><?php endif; ?>
<?php if ($selected): ?><h3>پیش‌نویس داخلی #<?= (int)$selected['id'] ?>: <?= $h($selected['title']) ?> — نسخه <?= (int)$selected['version'] ?></h3><p><a href="index.php?page=site_content_drafts&amp;draft=<?= (int)$selected['id'] ?>">بازگشت به پیش‌نویس</a></p>
<?php if ($link): ?><p>ارجاع داخلی فعلی: مقاله سایت #<?= (int)$link['post_id'] ?>، منبع <?= $h($link['source_id']) ?>، نسخه پیش‌نویس در زمان اتصال <?= (int)$link['draft_version'] ?> و revision مشاهده‌شده <?= (int)$link['post_revision'] ?>. این‌ها تضمین وضعیت فعلی نیستند.</p>
<?php if ((int)$link['draft_version']!==(int)$selected['version'] || !$linkPost || $linkPost['source_id']!==$link['source_id'] || (int)$linkPost['post_id']!==(int)$link['post_id'] || (int)$linkPost['revision']!==(int)$link['post_revision'] || !in_array((string)$linkPost['is_published'],['0','1'],true)): ?><p><strong>نسخه یا هویت ثبت‌شده تغییر کرده یا موجود نیست؛ تطبیق دستی لازم است.</strong></p><?php endif; ?>
<?php if (!$readonly && $linkReady): ?><form method="post" onsubmit="return confirm('فقط ارجاع داخلی حذف شود؟ محتوای سایت تغییر نمی‌کند.');"><?= get_csrf_field() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="draft" value="<?= (int)$draft ?>"><input type="hidden" name="draft_version" value="<?= (int)$selected['version'] ?>"><input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>"><label><input type="checkbox" name="confirm_identity" value="1" required> ارجاع را بررسی کردم</label> <label><input type="checkbox" name="confirm_internal" value="1" required> می‌دانم فقط اتصال داخلی قطع می‌شود</label> <button class="btn">قطع ارجاع داخلی</button></form><?php endif; ?>
<?php endif; ?><?php elseif ($draft): ?><p>پیش‌نویس انتخاب‌شده یافت نشد.</p><?php else: ?><p>برای اتصال دستی، یک پیش‌نویس را از صفحه پیش‌نویس‌ها انتخاب کنید.</p><?php endif; ?>
<?php if (!$ready): ?><p>جدول نمای مقاله آماده نیست.</p><?php else: ?><div style="overflow-x:auto"><table class="data-table"><thead><tr><th>شناسه سایت</th><th>منبع</th><th>برچسب ثبت‌شده</th><th>revision</th><th>آخرین دریافت</th><th>تطبیق داخلی</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= (int)$r['post_id'] ?></td><td><?= $h($r['source_id']) ?></td><td><?= (string)$r['is_published']==='1'?'منتشر':((string)$r['is_published']==='0'?'منتشرنشده':((string)$r['is_published']==='2'?'نبود مشاهده‌شده':'نامعتبر')) ?></td><td><?= (int)$r['revision'] ?></td><td><?= $h($r['received_at']) ?></td><td>
<?php if ($selected && !$link && !$readonly && $linkReady && crm_site_blog_sync_configured() && $r['source_id']===(string)SITE_BLOG_SOURCE_ID && in_array((string)$r['is_published'],['0','1'],true) && (int)$r['revision']>0): ?><form method="post" onsubmit="return confirm('فقط ارجاع داخلی ثبت شود؟ محتوا بررسی یا منتشر نمی‌شود.');"><?= get_csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="draft" value="<?= (int)$draft ?>"><input type="hidden" name="draft_version" value="<?= (int)$selected['version'] ?>"><input type="hidden" name="replica" value="<?= (int)$r['id'] ?>"><input type="hidden" name="revision" value="<?= (int)$r['revision'] ?>"><label><input type="checkbox" name="confirm_identity" value="1" required> شناسه، عنوان و متن را مستقل بررسی کردم</label><br><label><input type="checkbox" name="confirm_internal" value="1" required> می‌دانم فقط ارجاع داخلی است و محتوا تطبیق یا منتشر نمی‌شود</label><br><button class="btn">ثبت ارجاع داخلی</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6">رکوردی دریافت نشده؛ این به معنی نبود مقاله در سایت نیست.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($next): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'site_blog_metadata','draft'=>$draft,'before'=>$next])) ?>">صفحه بعد</a></p><?php endif; ?><?php endif; ?></div><?php include 'includes/footer.php'; ?>
