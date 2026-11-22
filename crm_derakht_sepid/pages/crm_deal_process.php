<?php
/** پروندهٔ مرحله‌ای یک معامله: انتقال صریح، تاریخچه و اسناد مجاز. */
$pageTitle='پروندهٔ معامله'; require_login();
require_once __DIR__.'/../includes/deal_process.php';
require_once __DIR__.'/../includes/quotes.php';
if (!ensure_deal_process_schema($pdo) || !ensure_quote_schema($pdo)) { include 'includes/header.php'; echo '<div class="alert alert-danger">ساخت جدول‌های پرونده ممکن نشد. دسترسی ساخت جدول در دیتابیس را بررسی کنید.</div>'; include 'includes/footer.php'; return; }
class DealProcessError extends RuntimeException {}
$me=(int)($_SESSION['user_id'] ?? 0); $id=(int)($_GET['id'] ?? 0);
$canAll=is_strict_admin() || is_finance_officer(); $managed=managed_team_ids($pdo); $manager=$canAll || !empty($managed);
$viewer=access_mode('crm_deals')==='viewer' || is_nazer_kol();
$st=$pdo->prepare('SELECT d.*,c.name customer_name,u.full_name owner_name FROM crm_deals d LEFT JOIN crm_customers c ON c.id=d.customer_id LEFT JOIN users u ON u.id=d.owner_user_id WHERE d.id=?');
$st->execute([$id]); $deal=$st->fetch(PDO::FETCH_ASSOC);
$allowed=$deal && ($canAll || (int)($deal['owner_user_id'] ?? 0)===$me || (!empty($managed) && in_array((int)($deal['team_id'] ?? 0),$managed,true)));
if (!$allowed) { http_response_code(403); include 'includes/header.php'; echo '<div class="alert alert-danger">پرونده پیدا نشد یا دسترسی ندارید.</div>'; include 'includes/footer.php'; return; }
$back='index.php?page=crm_deal_process&id='.$id; $stages=deal_process_stages(); $keys=array_keys($stages);
$h=static fn($v)=>htmlspecialchars((string)($v ?? ''),ENT_QUOTES,'UTF-8');
// سند هرگز به شکل فایل عمومی نگهداری نمی‌شود؛ دسترسی همان دسترسی پرونده است.
if (isset($_GET['document'])) {
    $st=$pdo->prepare('SELECT original_name,file_size,file_data FROM crm_deal_documents WHERE id=? AND deal_id=?'); $st->execute([(int)$_GET['document'],$id]); $doc=$st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { http_response_code(404); exit('File not found'); }
    header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="document"; filename*=UTF-8\'\''.rawurlencode($doc['original_name']));
    header('Content-Length: '.(int)$doc['file_size']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    if (is_resource($doc['file_data'])) fpassthru($doc['file_data']); else echo $doc['file_data']; exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && !$viewer) {
    $action=(string)($_POST['action'] ?? '');
    try {
        if ($action==='start') {
            $stage=(string)($_POST['initial_stage'] ?? 'contact'); $note=trim((string)($_POST['note'] ?? ''));
            if (!isset($stages[$stage]) || ($stage!=='contact' && (!$manager || $note===''))) throw new DealProcessError('شروع از مرحلهٔ قدیمی فقط برای مدیر و با توضیح مجاز است.');
            if ($stage==='quotation') throw new DealProcessError('برای پروندهٔ قدیمی، از مرحلهٔ اطلاعات کالا شروع و استعلام مرتبط را انتخاب کنید.');
            $pdo->beginTransaction();
            $lock=$pdo->prepare('SELECT id FROM crm_deals WHERE id=? FOR UPDATE'); $lock->execute([$id]);
            $check=$pdo->prepare('SELECT stage_key FROM crm_deal_process WHERE deal_id=?'); $check->execute([$id]);
            if ($check->fetch()) throw new DealProcessError('پرونده قبلاً شروع شده است.');
            $pdo->prepare('INSERT INTO crm_deal_process (deal_id,stage_key,created_by,updated_by) VALUES (?,?,?,?)')->execute([$id,$stage,$me,$me]);
            deal_process_add_event($pdo,$id,null,$stage,$stage==='contact'?'start':'legacy_start',$me,$note); $pdo->commit();
            operations_audit($pdo,$me,'deal_process_start','crm_deal',$id,$stage); showToast('پروندهٔ مرحله‌ای شروع شد.'); redirect($back);
        }
        if ($action==='advance' || $action==='back') {
            $expected=(string)($_POST['expected_stage'] ?? ''); $note=mb_substr(trim((string)($_POST['note'] ?? '')),0,4000);
            if ($deal['status']==='lost') throw new DealProcessError('معامله باخته است؛ ابتدا وضعیت آن را در پایپ‌لاین اصلاح کنید.');
            $pdo->beginTransaction(); $st=$pdo->prepare('SELECT * FROM crm_deal_process WHERE deal_id=? FOR UPDATE'); $st->execute([$id]); $process=$st->fetch(PDO::FETCH_ASSOC);
            if (!$process || $process['stage_key']!==$expected || !isset($stages[$expected])) throw new DealProcessError('مرحله تغییر کرده است؛ صفحه را تازه کنید.');
            $index=array_search($expected,$keys,true); $nextIndex=$index+($action==='advance'?1:-1);
            if (!isset($keys[$nextIndex])) throw new DealProcessError('انتقال به این مرحله ممکن نیست.');
            if ($action==='back' && $note==='') throw new DealProcessError('دلیل بازگشت را بنویسید.');
            $quoteId=(int)($process['quote_id'] ?? 0);
            if ($action==='advance' && $expected==='requirements') {
                $quoteId=(int)($_POST['quote_id'] ?? 0);
                $st=$pdo->prepare("SELECT id FROM crm_quotes WHERE id=? AND deal_id=? AND status IN ('in_flow','fixed') LIMIT 1"); $st->execute([$quoteId,$id]);
                if (!$st->fetchColumn()) throw new DealProcessError('یک استعلام معتبر مربوط به همین معامله انتخاب کنید.');
            }
            if ($action==='advance' && $expected==='quotation') {
                $st=$pdo->prepare("SELECT status FROM crm_quotes WHERE id=? AND deal_id=?"); $st->execute([$quoteId,$id]);
                if ($st->fetchColumn()!=='fixed') throw new DealProcessError('ابتدا مراحل استعلام را تکمیل و قیمت را فیکس کنید.');
            }
            if ($action==='advance' && $expected==='customer_decision' && empty($_POST['confirmed'])) throw new DealProcessError('تأیید مشتری را ثبت کنید؛ بدون تأیید، پیش‌فاکتور صادرشده فرض نمی‌شود.');
            if ($action==='advance' && in_array($expected,['proforma','contract','settlement','delivery'],true) && empty($_POST['confirmed'])) throw new DealProcessError('انجام این مرحله را تأیید کنید.');
            $score=null;
            if ($action==='advance' && $expected==='satisfaction') { $score=(int)($_POST['score'] ?? 0); if ($score<1 || $score>5) throw new DealProcessError('امتیاز رضایت از ۱ تا ۵ الزامی است.'); }
            $next=$keys[$nextIndex]; $pdo->prepare('UPDATE crm_deal_process SET stage_key=?,quote_id=?,updated_by=? WHERE deal_id=?')->execute([$next,$quoteId ?: null,$me,$id]);
            deal_process_add_event($pdo,$id,$expected,$next,$action,$me,$note,$score,$quoteId ?: null); $pdo->commit();
            operations_audit($pdo,$me,'deal_process_'.$action,'crm_deal',$id,$expected.' → '.$next); showToast('مرحله و تاریخچه ثبت شد.'); redirect($back);
        }
        if ($action==='upload') {
            $file=$_FILES['document'] ?? null; $size=(int)($file['size'] ?? 0); $name=$file ? basename(str_replace('\\','/',(string)$file['name'])) : '';
            $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
            $types=['jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp'],'pdf'=>['application/pdf'],'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
            $mime=($file && function_exists('mime_content_type') && ($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) ? (string)@mime_content_type($file['tmp_name']) : '';
            if ($size<1 || $size>8*1024*1024 || !isset($types[$ext]) || !in_array($mime,$types[$ext],true)) throw new DealProcessError('فایل باید عکس، PDF، DOCX یا XLSX و حداکثر ۸ مگابایت باشد.');
            $bytes=file_get_contents($file['tmp_name']); if ($bytes===false || strlen($bytes)!==$size) throw new DealProcessError('خواندن فایل ناموفق بود.');
            $pdo->beginTransaction(); $st=$pdo->prepare('SELECT stage_key FROM crm_deal_process WHERE deal_id=? FOR UPDATE'); $st->execute([$id]); $stage=$st->fetchColumn();
            if (!$stage) throw new DealProcessError('ابتدا پرونده را شروع کنید.');
            $st=$pdo->prepare('INSERT INTO crm_deal_documents (deal_id,stage_key,original_name,mime_type,file_size,file_data,uploaded_by) VALUES (?,?,?,?,?,?,?)');
            $st->bindValue(1,$id,PDO::PARAM_INT); $st->bindValue(2,$stage); $st->bindValue(3,mb_substr($name,0,255)); $st->bindValue(4,$mime); $st->bindValue(5,$size,PDO::PARAM_INT); $st->bindValue(6,$bytes,PDO::PARAM_LOB); $st->bindValue(7,$me,PDO::PARAM_INT); $st->execute();
            $pdo->commit(); operations_audit($pdo,$me,'deal_document_add','crm_deal',$id,$name); showToast('سند به پرونده افزوده شد.'); redirect($back);
        }
        throw new DealProcessError('عملیات نامعتبر است.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!($e instanceof DealProcessError)) error_log('Deal process: '.$e->getMessage());
        showToast($e instanceof DealProcessError ? $e->getMessage() : 'عملیات انجام نشد؛ محدودیت دیتابیس یا دسترسی را بررسی کنید.', 'error'); redirect($back);
    }
}
$st=$pdo->prepare('SELECT * FROM crm_deal_process WHERE deal_id=?'); $st->execute([$id]); $process=$st->fetch(PDO::FETCH_ASSOC) ?: null;
$st=$pdo->prepare('SELECT id,title,status,current_step,workflow_json FROM crm_quotes WHERE deal_id=? ORDER BY id DESC'); $st->execute([$id]); $quotes=$st->fetchAll(PDO::FETCH_ASSOC);
$selectedQuote=null; foreach($quotes as $q) if ((int)$q['id']===(int)($process['quote_id'] ?? 0)) $selectedQuote=$q;
$st=$pdo->prepare('SELECT e.*,u.full_name FROM crm_deal_process_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.deal_id=? ORDER BY e.id DESC'); $st->execute([$id]); $events=$st->fetchAll(PDO::FETCH_ASSOC);
$st=$pdo->prepare('SELECT a.id,a.stage_key,a.original_name,a.file_size,a.created_at,u.full_name FROM crm_deal_documents a LEFT JOIN users u ON u.id=a.uploaded_by WHERE a.deal_id=? ORDER BY a.id DESC'); $st->execute([$id]); $documents=$st->fetchAll(PDO::FETCH_ASSOC);
$current=(string)($process['stage_key'] ?? ''); $currentIndex=array_search($current,$keys,true); $next=$currentIndex!==false ? ($keys[$currentIndex+1] ?? null) : null;
include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4" style="flex-wrap:wrap;gap:10px"><div><h2>پروندهٔ معامله: <?= $h($deal['title']) ?></h2><span class="text-muted">مشتری: <?= $h($deal['customer_name'] ?: '—') ?> | مسئول: <?= $h($deal['owner_name'] ?: '—') ?></span></div><a class="btn btn-ghost" href="index.php?page=crm_deals">بازگشت به پایپ‌لاین</a></div>
<div class="alert alert-info" style="font-size:12px">وضعیت‌ها و رویدادهای قابل اطلاع‌رسانی ثبت می‌شوند؛ پنل پیامک هنوز متصل نیست و هیچ پیامکی ارسال نمی‌شود. پیش‌فاکتور و قرارداد فقط پس از اقدام و تأیید شما ثبت می‌شوند.</div>
<?php if (!$process): ?><div class="card" style="padding:18px"><h3>شروع پروندهٔ مرحله‌ای</h3><p>معامله‌های قدیمی بدون تغییر حفظ شده‌اند. برای شروع از تماس، دکمه را بزنید.</p><?php if (!$viewer): ?><form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="start"><button class="btn btn-primary">شروع از تماس اولیه</button></form><?php if ($manager): ?><details style="margin-top:15px"><summary>این پرونده قدیمی است و قبلاً به مرحله‌ای رسیده</summary><form method="post" style="margin-top:10px"><?= get_csrf_field() ?><input type="hidden" name="action" value="start"><label class="form-label">مرحلهٔ فعلی واقعی</label><select class="form-control" name="initial_stage"><?php foreach($stages as $k=>$meta): if(in_array($k,['contact','quotation'],true))continue; ?><option value="<?= $h($k) ?>"><?= $h($meta[0]) ?></option><?php endforeach ?></select><label class="form-label">دلیل و سابقهٔ مرحله (الزامی)</label><textarea name="note" class="form-control" required rows="2"></textarea><button class="btn btn-ghost" style="margin-top:8px">ثبت مرحلهٔ پروندهٔ قدیمی</button></form><small class="text-muted">این انتخاب برای انتقال اطلاعات قبلی است، نه تأیید خودکار مراحل یا صدور سند.</small></details><?php endif; endif; ?></div>
<?php else: ?>
<div class="card" style="padding:16px"><div class="d-flex justify-between" style="flex-wrap:wrap;gap:10px"><div><span class="text-muted">مرحلهٔ جاری</span><h3><?= $h($stages[$current][0] ?? 'مرحلهٔ نامعتبر') ?></h3></div><span class="text-muted">مرحله <?= $currentIndex===false ? '—' : $currentIndex+1 ?> از <?= count($keys) ?></span></div><div class="dp-stages"><?php foreach($stages as $k=>$meta): $idx=array_search($k,$keys,true); ?><span class="dp-step <?= $k===$current?'active':($currentIndex!==false && $idx<$currentIndex?'done':'') ?>"><?= $h($meta[0]) ?></span><?php endforeach ?></div></div>
<div class="grid-2" style="gap:12px;margin-top:14px"><div class="card" style="padding:16px"><h3>استعلام‌های مرتبط</h3><p class="text-muted">اطلاعات کالا و پیوست‌ها داخل استعلام ثبت می‌شوند؛ تأیید مدیر فروش، قیمت نت و سود نیز همان‌جا انجام می‌شوند.</p>
<?php if (!$viewer && access_mode('quotes')!=='viewer'): ?><form method="post" action="index.php?page=quotes&amp;new=1&amp;deal_id=<?= $id ?>"><?= get_csrf_field() ?><button class="btn btn-ghost btn-sm">ایجاد استعلام جدید</button></form><?php endif ?>
<?php foreach($quotes as $quote): $step=quote_current_step($quote); ?><div class="dp-row"><a href="index.php?page=quote_view&amp;id=<?= (int)$quote['id'] ?>">#<?= (int)$quote['id'] ?> <?= $h($quote['title']) ?></a> — <?= $h($quote['status']==='fixed'?'فیکس‌شده':($quote['status']==='cancelled'?'لغوشده':($step['title'] ?? 'در گردش'))) ?> <?= (int)($process['quote_id'] ?? 0)===(int)$quote['id']?'✅ انتخاب‌شده':'' ?> <?php if($quote['status']==='fixed'): ?><a href="index.php?page=quote_print&amp;id=<?= (int)$quote['id'] ?>" target="_blank" rel="noopener">چاپ پیش‌فاکتور</a><?php endif ?></div><?php endforeach ?><?php if (!$quotes): ?><p class="text-muted">هنوز استعلامی ثبت نشده است.</p><?php endif ?></div>
<div class="card" style="padding:16px"><h3>اقدام مرحلهٔ جاری</h3><?php if($deal['status']==='lost'): ?><p>معامله باخته است؛ برای ادامه وضعیت آن را اصلاح کنید.</p><?php elseif($next && !$viewer): ?><p class="text-muted">پس از انجام «<?= $h($stages[$current][0]) ?>»، پرونده به «<?= $h($stages[$next][0]) ?>» می‌رود.</p><form method="post"><?= get_csrf_field() ?><input type="hidden" name="action" value="advance"><input type="hidden" name="expected_stage" value="<?= $h($current) ?>">
<?php if($current==='requirements'): ?><label class="form-label">استعلام همین معامله *</label><select name="quote_id" class="form-control" required><option value="">انتخاب استعلام</option><?php foreach($quotes as $quote): if($quote['status']==='cancelled')continue; ?><option value="<?= (int)$quote['id'] ?>" <?= (int)($process['quote_id'] ?? 0)===(int)$quote['id']?'selected':'' ?>>#<?= (int)$quote['id'] ?> — <?= $h($quote['title']) ?></option><?php endforeach ?></select><?php endif ?>
<?php if($current==='quotation'): ?><p class="text-muted"><?= $selectedQuote ? 'استعلام انتخابی #'.(int)$selectedQuote['id'].'؛ قبل از ادامه باید فیکس شود.' : 'استعلام انتخاب نشده است؛ به مرحله قبل برگردید.' ?></p><?php endif ?>
<?php if(in_array($current,['customer_decision','proforma','contract','settlement','delivery'],true)): ?><label class="form-check"><input type="checkbox" name="confirmed" value="1" required> <span><?= $current==='customer_decision'?'مشتری قیمت را تأیید کرده است.':'انجام این مرحله را تأیید می‌کنم.' ?></span></label><?php endif ?>
<?php if($current==='satisfaction'): ?><label class="form-label">امتیاز رضایت مشتری *</label><select name="score" class="form-control" required><option value="">انتخاب کنید</option><?php for($i=1;$i<=5;$i++): ?><option value="<?= $i ?>"><?= $i ?> از ۵</option><?php endfor ?></select><?php endif ?>
<label class="form-label">یادداشت / نتیجهٔ مرحله</label><textarea class="form-control" name="note" rows="2" maxlength="4000"></textarea><button class="btn btn-primary" style="margin-top:10px" onclick="return confirm('انجام این مرحله ثبت و پرونده به مرحله بعد منتقل شود؟')">ثبت انجام و انتقال به مرحلهٔ بعد</button></form>
<?php elseif(!$next): ?><p>✅ پرونده به پایان رسیده است.</p><?php endif ?>
<?php if($currentIndex!==false && $currentIndex>0 && !$viewer): ?><details style="margin-top:14px"><summary>بازگشت به مرحلهٔ قبل</summary><form method="post" style="margin-top:10px"><?= get_csrf_field() ?><input type="hidden" name="action" value="back"><input type="hidden" name="expected_stage" value="<?= $h($current) ?>"><textarea class="form-control" name="note" required placeholder="دلیل بازگشت" rows="2"></textarea><button class="btn btn-ghost" style="margin-top:8px">ثبت بازگشت</button></form></details><?php endif ?></div></div>
<div class="grid-2" style="gap:12px;margin-top:14px"><div class="card" style="padding:16px"><h3>اسناد پرونده</h3><p class="text-muted">برای قرارداد، اسناد حمل، صادرات و تحویل؛ هر فایل حداکثر ۸ مگابایت.</p><?php if(!$viewer): ?><form method="post" enctype="multipart/form-data"><?= get_csrf_field() ?><input type="hidden" name="action" value="upload"><input type="file" name="document" class="form-control" required accept=".jpg,.jpeg,.png,.webp,.pdf,.docx,.xlsx"><button class="btn btn-ghost btn-sm" style="margin-top:8px">افزودن سند به مرحلهٔ جاری</button></form><?php endif ?><?php foreach($documents as $doc): ?><div class="dp-row"><a href="index.php?page=crm_deal_process&amp;id=<?= $id ?>&amp;document=<?= (int)$doc['id'] ?>"><?= $h($doc['original_name']) ?></a><div class="text-muted"><?= $h($stages[$doc['stage_key']][0] ?? $doc['stage_key']) ?> • <?= $h($doc['full_name'] ?: '—') ?> • <?= $h(to_jalali(substr($doc['created_at'],0,10))) ?></div></div><?php endforeach ?><?php if (!$documents): ?><p class="text-muted">سندی ثبت نشده است.</p><?php endif ?></div>
<div class="card" style="padding:16px"><h3>تاریخچهٔ مراحل</h3><?php foreach($events as $event): ?><div class="dp-row"><b><?= $h($stages[$event['to_stage']][0] ?? $event['to_stage']) ?></b> — <?= $h(['start'=>'شروع','legacy_start'=>'شروع پروندهٔ قدیمی','advance'=>'انتقال','back'=>'بازگشت'][$event['action']] ?? $event['action']) ?><div class="text-muted"><?= $h($event['full_name'] ?: '—') ?> • <?= $h(to_jalali(substr($event['created_at'],0,10))) ?> <?= $h(substr($event['created_at'],11,5)) ?></div><?php if(!empty($event['quote_id'])): ?><div class="text-muted">استعلام مرتبط: #<?= (int)$event['quote_id'] ?></div><?php endif ?><?php if($event['note']): ?><div><?= nl2br($h($event['note'])) ?></div><?php endif ?><?php if($event['satisfaction_score']): ?><div>امتیاز رضایت: <?= (int)$event['satisfaction_score'] ?> از ۵</div><?php endif ?></div><?php endforeach ?></div></div>
<?php endif ?>
<style>.dp-stages{display:flex;gap:7px;overflow-x:auto;padding:12px 0 2px}.dp-step{white-space:nowrap;border-radius:20px;background:#f1f5f9;color:#64748b;padding:7px 12px;font-size:11px}.dp-step.done{background:#ecfdf5;color:#047857}.dp-step.active{background:#0f766e;color:#fff;font-weight:800}.dp-row{padding:9px 0;border-bottom:1px solid var(--border);font-size:12px;line-height:1.8;overflow-wrap:anywhere}</style>
<?php include 'includes/footer.php'; ?>
