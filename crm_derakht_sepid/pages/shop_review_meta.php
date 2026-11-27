<?php
/** Administrator-only minimal review metadata and separately opt-in hide command. */
$pageTitle='نمای کلی دیدگاه‌های فروشگاه'; require_login();
header('Cache-Control: no-store');
require_once __DIR__.'/../includes/shop_review_meta_sync.php';
require_once __DIR__.'/../includes/shop_review_commands.php';
$me=(int)($_SESSION['user_id'] ?? 0);
try {
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
    if (!is_strict_admin() || !$actor || $actor['status']!=='active' || $actor['role']!=='admin') { http_response_code(403); echo 'دسترسی مجاز نیست.'; return; }
} catch (Throwable $e) { http_response_code(503); echo 'بررسی دسترسی ممکن نیست.'; return; }
$ready=false;
// Do not create a table merely by visiting this page while the receiver is disabled.
if (crm_review_meta_configured()) $ready=crm_review_meta_ensure($pdo);
else { try { $pdo->query('SELECT 1 FROM crm_shop_review_meta LIMIT 1'); $ready=true; } catch (Throwable $e) {} }
$readonly=is_nazer_kol() || (!access_is_special_account() && access_mode('crm_customers')==='viewer');
$commandReady=false;
if ($ready) {
    if (crm_review_command_configured()) $commandReady=crm_review_command_ensure($pdo);
    else { try { $pdo->query('SELECT 1 FROM crm_shop_review_commands LIMIT 1'); $pdo->query('SELECT 1 FROM crm_shop_review_command_events LIMIT 1'); $commandReady=true; } catch (Throwable $e) {} }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $rawId=$_POST['replica_id'] ?? null; $rawRevision=$_POST['revision'] ?? null;
    $id=is_string($rawId) && preg_match('/^[1-9][0-9]{0,17}$/D',$rawId)?(int)$rawId:0;
    try {
        if (!$ready || !$commandReady || $readonly || ($_POST['action'] ?? null)!=='hide_review' || !$id || !is_string($rawRevision) || !preg_match('/^[1-9][0-9]{0,17}$/D',$rawRevision)) throw new DomainException('فرمان مجاز یا آماده نیست.');
        crm_review_hide_enqueue($pdo,$id,$me,true,(int)$rawRevision,time(),($_POST['checked_review'] ?? null)==='1' && ($_POST['confirmed_hide'] ?? null)==='1');
        try { operations_audit($pdo,$me,'shop_review_hide','shop_review',$id,'فرمان مخفی‌کردن دیدگاه در صف'); } catch (Throwable $e) { error_log('Review command global audit failed'); }
        showToast('فرمان در صف است؛ مخفی‌شدن هنوز تأیید نشده است.');
    } catch (Throwable $e) { error_log('Review command enqueue failed: '.get_class($e)); showToast($e instanceof DomainException?$e->getMessage():'ثبت فرمان ممکن نشد.','error'); }
    redirect('index.php?page=shop_review_meta&review='.$id);
}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
$status=is_string($_GET['status'] ?? null)?$_GET['status']:'all'; if (!in_array($status,['all','active','inactive','missing'],true)) $status='all';
$raw=$_GET['before'] ?? ''; $before=is_string($raw) && preg_match('/^[1-9][0-9]{0,17}$/D',$raw) ? (int)$raw : 0;
$where=[]; $args=[];
if ($status==='missing') $where[]='product_id=0 AND rating=0 AND is_active=0';
elseif ($status!=='all') { $where[]='product_id>0 AND is_active=?'; $args[]=$status==='active'?1:0; }
if ($before>0) { $where[]='id<?'; $args[]=$before; }
$rows=[]; $next=0; $selected=null; $reviewCommands=[]; $pendingCommand=false;
$reviewRaw=$_GET['review'] ?? ''; $reviewId=is_string($reviewRaw) && preg_match('/^[1-9][0-9]{0,17}$/D',$reviewRaw)?(int)$reviewRaw:0;
if ($ready) {
    $st=$pdo->prepare('SELECT id,source_id,review_id,product_id,rating,is_active,received_at FROM crm_shop_review_meta'.($where ? ' WHERE '.implode(' AND ',$where) : '').' ORDER BY id DESC LIMIT 31');
    $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)>30) { array_pop($rows); $next=(int)$rows[count($rows)-1]['id']; }
    if ($reviewId>0) {
        $st=$pdo->prepare('SELECT * FROM crm_shop_review_meta WHERE id=?'); $st->execute([$reviewId]); $selected=$st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selected && $commandReady) {
            $st=$pdo->prepare('SELECT state,source_id,review_id,created_at FROM crm_shop_review_commands WHERE replica_id=? ORDER BY created_at DESC,command_id DESC LIMIT 10'); $st->execute([$reviewId]); $reviewCommands=$st->fetchAll(PDO::FETCH_ASSOC);
            $st=$pdo->prepare("SELECT command_id FROM crm_shop_review_commands WHERE replica_id=? AND state='queued' LIMIT 1"); $st->execute([$reviewId]); $pendingCommand=(bool)$st->fetchColumn();
        }
    }
}
include 'includes/header.php';
?>
<div class="page-header"><h1>نمای کلی دیدگاه‌های فروشگاه</h1><p>فقط شناسه، امتیاز و وضعیت ثبت‌شده با تأخیر؛ متن و نام مشتری منتقل نمی‌شود؛ فرمان آزمایشی مخفی‌سازی جداگانه و پیش‌فرض خاموش است. این صفحه ابزار انتشار، حذف یا پاسخ نیست.</p><p><a class="btn" href="index.php?page=shop_catalog">بازگشت به کالاهای فروشگاه</a></p></div>
<div class="card" style="padding:16px">
<p><strong>فهرست ممکن است ناقص یا قدیمی باشد؛ مشاهدهٔ نبود در سایت فقط در صورت فعال‌بودن تطبیق مستقل و با تأخیر منتقل می‌شود و اثبات حذف عمدی نیست. قبل از هر اقدام، شناسه، متن، هویت و وضعیت واقعی را در پنل فروشگاه جداگانه بررسی کنید.</strong></p>
<?php if (!crm_review_meta_configured()): ?><p>دریافت در CRM غیرفعال یا پیکربندی نشده است؛ رکوردهای قبلی در صورت وجود باقی می‌مانند.</p><?php endif; ?>
<?php if (!crm_review_meta_deletion_enabled()): ?><p>دریافت رویداد نبود دیدگاه در CRM غیرفعال است؛ وضعیت‌های قبلی ممکن است قدیمی باشند.</p><?php endif; ?>
<?php if (!$ready): ?><p>جدول نمای دیدگاه آماده نیست.</p><?php else: ?>
<?php if ($selected): ?>
<div style="border:1px solid var(--border);padding:14px;margin-bottom:16px"><h3>دیدگاه فروشگاه #<?= (int)$selected['review_id'] ?></h3><p>منبع <?= $h($selected['source_id']) ?>، کالای فروشگاه <?= (int)$selected['product_id'] ?>، نسخه <?= (int)$selected['revision'] ?>. متن در CRM موجود نیست.</p>
<?php if ($pendingCommand): ?><p><strong>فرمان در صف است؛ نتیجه هنوز نامشخص است.</strong></p><?php endif; ?>
<?php foreach ($reviewCommands as $cmd): if ($cmd['source_id']!==$selected['source_id'] || (int)$cmd['review_id']!==(int)$selected['review_id']) continue; $labels=['queued'=>'در صف؛ نتیجه نامشخص','applied'=>'مخفی‌سازی در آن زمان تأیید شد؛ وضعیت فعلی را تطبیق دهید','conflict'=>'رد شد؛ هویت یا نسخه تغییر کرده است','expired'=>'مهلت تمام شد']; ?><p><?= $h($labels[$cmd['state']] ?? 'نامشخص') ?> — <?= $h($cmd['created_at']) ?></p><?php endforeach; ?>
<?php if (!$readonly && $commandReady && crm_review_command_configured() && crm_review_meta_configured() && crm_review_hide_enabled() && !$pendingCommand && (int)$selected['is_active']===1 && (int)$selected['product_id']>0 && (int)$selected['rating']>=1 && (int)$selected['rating']<=5 && $selected['source_id']===(string)SHOP_CRM_SOURCE_ID): ?>
<p><strong>این فرمان دیدگاه را در فروشگاه مخفی می‌کند. سایت فقط در صورت فعال‌بودن محافظ محتوای مستقل، متن و هویت فعلی را با آخرین اسکن مقایسه می‌کند؛ CRM از فعال‌بودن یا تازگی آن مطمئن نیست. بررسی متن در پنل سایت همچنان ضروری است.</strong></p>
<form method="post" onsubmit="return confirm('این دیدگاه از نمایش فروشگاه مخفی شود؟ نتیجه تا رسید نامشخص است.');"><?= get_csrf_field() ?><input type="hidden" name="action" value="hide_review"><input type="hidden" name="replica_id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="revision" value="<?= (int)$selected['revision'] ?>"><label><input type="checkbox" name="checked_review" value="1" required> متن، شناسه و کالا را در پنل سایت مستقل بررسی کردم</label> <label><input type="checkbox" name="confirmed_hide" value="1" required> می‌دانم این فرمان دیدگاه را در فروشگاه مخفی می‌کند و نتیجه ممکن است نامشخص باشد</label> <button class="btn" type="submit">ثبت فرمان مخفی‌سازی</button></form>
<?php endif; ?></div><?php endif; ?>
<form method="get"><input type="hidden" name="page" value="shop_review_meta"><label>وضعیت ثبت‌شده <select name="status"><option value="all" <?= $status==='all'?'selected':'' ?>>همه</option><option value="active" <?= $status==='active'?'selected':'' ?>>نمایش</option><option value="inactive" <?= $status==='inactive'?'selected':'' ?>>مخفی</option><option value="missing" <?= $status==='missing'?'selected':'' ?>>نبود مشاهده‌شده</option></select></label> <button class="btn" type="submit">فیلتر</button></form>
<div style="overflow-x:auto;margin-top:16px"><table class="data-table"><thead><tr><th>شناسه دیدگاه در فروشگاه</th><th>شناسه کالا در فروشگاه</th><th>امتیاز ثبت‌شده</th><th>وضعیت ثبت‌شده</th><th>آخرین دریافت در CRM</th></tr></thead><tbody>
<?php foreach ($rows as $r): $missing=(int)$r['product_id']===0 && (int)$r['rating']===0 && (int)$r['is_active']===0; ?><tr><td><a href="index.php?page=shop_review_meta&amp;review=<?= (int)$r['id'] ?>"><?= (int)$r['review_id'] ?></a><br><small>منبع <?= $h($r['source_id']) ?></small></td><td><?= $missing?'—':(int)$r['product_id'] ?></td><td><?= $missing?'—':((int)$r['rating'].' از ۵') ?></td><td><?= $missing?'نبود مشاهده‌شده':((int)$r['is_active']===1?'نمایش':((int)$r['is_active']===0?'مخفی':'نامعتبر')) ?></td><td><?= $h($r['received_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5">نسخه‌ای برای این فیلتر دریافت نشده است؛ این به معنی نبود دیدگاه در فروشگاه نیست.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($next): ?><p><a class="btn" href="index.php?<?= $h(http_build_query(['page'=>'shop_review_meta','status'=>$status,'before'=>$next])) ?>">صفحه بعد</a></p><?php endif; ?>
<?php endif; ?></div>
<?php include 'includes/footer.php'; ?>
