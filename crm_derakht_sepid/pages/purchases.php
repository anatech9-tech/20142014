<?php
$pageTitle=t('page_purchases');
$ws_id=(int)($_SESSION['workshop_id']??0);
// FIXED: اطمینان از وجود پوشه آپلود و دسترسی‌ها + لاگ بهتر
$__purchase_upload_dir = __DIR__ . '/../uploads/purchases';
if (!is_dir($__purchase_upload_dir)) {
    @mkdir($__purchase_upload_dir, 0755, true);
}
$can_purchase=is_strict_admin()||is_workshop_manager()||has_permission('manage_purchases')||has_permission('manage_inventory')||access_mode('purchases')==='editor';
if(!$can_purchase){
    // لاگ برای دیباگ
    error_log('purchase_requests: access denied for user ' . ($_SESSION['user_id'] ?? 0) . ' ws ' . $ws_id . ' perms: ' . json_encode($_SESSION['permissions'] ?? []));
    showToast(t('دسترسی ندارید - برای درخواست خرید باید دسترسی انبار یا مدیر داشته باشید'),'error');
    redirect('index.php?page=dashboard');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';
    if($action==='create'){
        try{
            $item_id=(int)($_POST['item_id']??0)?:null;
            $name=sanitize($_POST['item_name']??'');
            $unit=sanitize($_POST['unit']??'عدد');
            if($item_id){
                $st=$pdo->prepare("SELECT name,unit FROM inventory_items WHERE id=? AND workshop_id=?");
                $st->execute([$item_id,$ws_id]);
                $item=$st->fetch();
                if($item){
                    $name=$item['name'];
                    $unit=$item['unit'];
                } else {
                    // اگر آیتم پیدا نشد، لاگ بزن
                    error_log('purchase_requests: item_id ' . $item_id . ' not found for ws ' . $ws_id);
                    $item_id = null;
                }
            }
            if($name==='')throw new RuntimeException(t('نام کالا را وارد کنید - یا از لیست موجود انتخاب کنید یا نام جدید تایپ کنید.'));
            // FIXED: چک حجم و نوع فایل با پیام واضح
            $attachment = null;
            try {
                $attachment=operations_upload_file($_FILES['attachment']??[],'uploads/purchases',['image/png'=>'png','image/jpeg'=>'jpg','application/pdf'=>'pdf'],12_000_000);
            } catch (Throwable $ue) {
                throw new RuntimeException('خطا در آپلود فایل: ' . $ue->getMessage());
            }
            $st=$pdo->prepare("INSERT INTO purchase_requests (workshop_id,item_id,item_name,quantity,unit,expected_unit_price,vendor_name,description,attachment,requested_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $qty = max(0.01,(float)($_POST['quantity'] ?? 1));
            $price = max(0,(float)($_POST['expected_unit_price'] ?? 0));
            $st->execute([$ws_id,$item_id,$name,$qty,$unit,$price,sanitize($_POST['vendor_name']??''),sanitize($_POST['description']??''),$attachment,$_SESSION['user_id']]);
            $id=(int)$pdo->lastInsertId();
            operations_audit($pdo,(int)$_SESSION['user_id'],'purchase_request','purchase_request',$id,'درخواست خرید '.$name);
            operations_system_chat_message($pdo,$ws_id,(int)$_SESSION['user_id'],strtr(t('🛒 درخواست خرید جدید: «{name}» ثبت شد.'), ['{name}' => $name]),null,true);
            showToast(t('درخواست خرید ثبت شد - در لیست زیر مشاهده می‌شود ✅'));
        }catch(Throwable $e){
            error_log('purchase_requests create failed: ' . $e->getMessage() . ' POST: ' . json_encode($_POST));
            showToast('خطا در ثبت درخواست: ' . $e->getMessage(),'error');
        }
        redirect('index.php?page=inventory&tab=purchases');
    }
    if(in_array($action,['approve','reject','order','receive'],true)){
        $id=(int)($_POST['id']??0);$st=$pdo->prepare("SELECT * FROM purchase_requests WHERE id=? AND workshop_id=?");$st->execute([$id,$ws_id]);$req=$st->fetch();
        if(!$req){showToast(t('درخواست یافت نشد'),'error');redirect('index.php?page=inventory&tab=purchases');}
        if($action==='approve'){
            $pdo->prepare("UPDATE purchase_requests SET status='approved',approved_by=?,approved_at=NOW() WHERE id=?")->execute([$_SESSION['user_id'],$id]);operations_system_chat_message($pdo,$ws_id,(int)$_SESSION['user_id'],strtr(t('✅ درخواست خرید «{name}» تأیید شد.'), ['{name}' => $req['item_name']]),null,true);showToast(t('درخواست تأیید شد.'));
        }elseif($action==='reject'){
            $pdo->prepare("UPDATE purchase_requests SET status='rejected',rejection_reason=?,approved_by=?,approved_at=NOW() WHERE id=?")->execute([sanitize($_POST['reason']??''),$_SESSION['user_id'],$id]);operations_system_chat_message($pdo,$ws_id,(int)$_SESSION['user_id'],strtr(t('❌ درخواست خرید «{name}» رد شد.'), ['{name}' => $req['item_name']]),null,true);showToast(t('درخواست رد شد.'));
        }elseif($action==='order'){
            $pdo->prepare("UPDATE purchase_requests SET status='ordered' WHERE id=? AND status IN ('approved','pending')")->execute([$id]);showToast(t('وضعیت سفارش ثبت شد.'));
        }elseif($action==='receive'){
            if(!in_array($req['status'],['approved','ordered'],true)){showToast(t('ابتدا درخواست باید تأیید یا سفارش‌گذاری شود.'),'error');redirect('index.php?page=inventory&tab=purchases');}
            $item_id=(int)$req['item_id'];
            if(!$item_id){
                $q=$pdo->prepare("SELECT id FROM inventory_items WHERE workshop_id=? AND name=? AND status='active' LIMIT 1");$q->execute([$ws_id,$req['item_name']]);$item_id=(int)$q->fetchColumn();
                if(!$item_id){$q=$pdo->prepare("INSERT INTO inventory_items (workshop_id,name,unit,quantity) VALUES (?,?,?,0)");$q->execute([$ws_id,$req['item_name'],$req['unit']?:'عدد']);$item_id=(int)$pdo->lastInsertId();}
            }
            $qty=(float)$req['quantity'];$pdo->prepare("UPDATE inventory_items SET quantity=quantity+? WHERE id=? AND workshop_id=?")->execute([$qty,$item_id,$ws_id]);
            try{$q=$pdo->prepare("INSERT INTO inventory_transactions (workshop_id,item_id,type,quantity,person_name,checkout_date,is_consumable,notes,created_by,out_type) VALUES (?,?,'restock',?,'',?,0,?,?, 'in')");$q->execute([$ws_id,$item_id,$qty,date('Y-m-d'),'دریافت از درخواست خرید #'.$id,$_SESSION['user_id']]);$tx=(int)$pdo->lastInsertId();arash_coin_award_inventory_activity($pdo,(int)$_SESSION['user_id'],$ws_id,'purchase:receive:'.$id,'دریافت خرید «'.$req['item_name'].'»',date('Y-m-d'));}catch(Throwable $ignore){}
            $pdo->prepare("UPDATE purchase_requests SET status='received',received_by=?,received_at=NOW(),item_id=? WHERE id=?")->execute([$_SESSION['user_id'],$item_id,$id]);operations_system_chat_message($pdo,$ws_id,(int)$_SESSION['user_id'],strtr(t('📦 خرید «{name}» دریافت و وارد انبار شد.'), ['{name}' => $req['item_name']]),null,true);showToast(t('کالا دریافت و موجودی انبار افزایش یافت.'));
        }
        operations_audit($pdo,(int)$_SESSION['user_id'],'purchase_'.$action,'purchase_request',$id,$action.' درخواست خرید');redirect('index.php?page=inventory&tab=purchases');
    }
}
$items=[];$requests=[];
try{$st=$pdo->prepare("SELECT id,name,unit,quantity,min_quantity FROM inventory_items WHERE workshop_id=? AND status='active' ORDER BY name");$st->execute([$ws_id]);$items=$st->fetchAll();$st=$pdo->prepare("SELECT pr.*,u.full_name requester,ua.full_name approver FROM purchase_requests pr LEFT JOIN users u ON u.id=pr.requested_by LEFT JOIN users ua ON ua.id=pr.approved_by WHERE pr.workshop_id=? ORDER BY FIELD(pr.status,'pending','approved','ordered','received','rejected'),pr.created_at DESC");$st->execute([$ws_id]);$requests=$st->fetchAll();}catch(Throwable $e){}
include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4 animate-in"><h2>🛒 <?= t('page_purchases') ?></h2><button type="button" class="btn btn-primary" onclick="openModal('purchaseModal')"><i data-lucide="shopping-cart"></i> <?= t('درخواست') ?> <?= t('جدید') ?></button></div>
<div class="tabs mb-4 animate-in inventory-tools-tabs"><a href="index.php?page=inventory&tab=stock" class="tab"><?= t('inv_stock') ?></a><a href="index.php?page=inventory&tab=active" class="tab"><?= t('خارج‌شده') ?></a><a href="index.php?page=inventory&tab=history" class="tab"><?= t('سابقه') ?></a><a href="index.php?page=inventory&tab=serials" class="tab">🔖 <?= t('سریال ابزار') ?></a><a href="index.php?page=inventory&tab=purchases" class="tab active">🛒 <?= t('page_purchases') ?></a><a href="index.php?page=inventory&tab=depreciation" class="tab">📉 <?= t('استهلاک') ?></a></div>
<div class="stats-grid animate-in"><div class="stat-card"><div class="stat-icon warning"><i data-lucide="clock"></i></div><div class="stat-info"><h3><?= count(array_filter($requests,fn($r)=>$r['status']==='pending')) ?></h3><p><?= t('در انتظار') ?> <?= t('تأیید') ?></p></div></div><div class="stat-card"><div class="stat-icon info"><i data-lucide="truck"></i></div><div class="stat-info"><h3><?= count(array_filter($requests,fn($r)=>$r['status']==='ordered')) ?></h3><p><?= t('در انتظار') ?> <?= t('دریافت') ?></p></div></div><div class="stat-card"><div class="stat-icon danger"><i data-lucide="package-minus"></i></div><div class="stat-info"><h3><?= count(array_filter($items,fn($i)=>(float)$i['min_quantity']>0&&(float)$i['quantity']<=(float)$i['min_quantity'])) ?></h3><p><?= t('کالای کم‌موجود') ?></p></div></div></div>
<div class="card mt-4 animate-in"><div class="table-container"><table><thead><tr><th><?= t('کالا') ?></th><th><?= t('تعداد') ?> / <?= t('واحد') ?></th><th><?= t('فروشنده') ?></th><th><?= t('مبلغ') ?> <?= t('برآوردی') ?></th><th><?= t('درخواست') ?><?= t('‌کننده') ?></th><th><?= t('wh_status') ?></th><th><?= t('پیوست') ?></th><th><?= t('wh_actions') ?></th></tr></thead><tbody><?php foreach($requests as $r): ?><tr><td><strong><?=htmlspecialchars($r['item_name'])?></strong><div class="text-muted" style="font-size:10px"><?=htmlspecialchars($r['description']?:'')?></div></td><td><?=$r['quantity']?> <?=htmlspecialchars($r['unit']?:'')?></td><td><?=htmlspecialchars($r['vendor_name']?:'—')?></td><td><?=number_format((float)$r['expected_unit_price']*(float)$r['quantity'])?> <?= t('ت') ?></td><td><?=htmlspecialchars($r['requester']?:'—')?></td><td><span class="tag <?= $r['status']==='rejected'?'tag-rejected':($r['status']==='received'?'tag-active':'tag-pending') ?>"><?=['pending'=>t('در انتظار'),'approved'=>t('تأیید شده'),'ordered'=>t('سفارش شده'),'received'=>t('دریافت شد'),'rejected'=>t('adv_rejected') ][$r['status']]??$r['status']?></span></td><td><?php if($r['attachment']):?><a href="<?=htmlspecialchars($r['attachment'])?>" target="_blank" class="btn btn-ghost btn-sm">📎</a><?php else:?>—<?php endif;?></td><td style="white-space:nowrap"><?php if($r['status']==='pending'):?><form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-success btn-sm"><?= t('تأیید') ?></button></form><form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="reason" value="<?= t('btn_reject') ?> <?= t('مدیر') ?>"><button class="btn btn-danger btn-sm"><?= t('btn_reject') ?></button></form><?php elseif($r['status']==='approved'):?><form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="order"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-info btn-sm"><?= t('سفارش شد') ?></button></form><form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="receive"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-success btn-sm"><?= t('دریافت کالا') ?></button></form><?php elseif($r['status']==='ordered'):?><form method="post" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="receive"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-success btn-sm"><?= t('دریافت کالا') ?></button></form><?php endif;?></td></tr><?php endforeach;?><?php if(empty($requests)):?><tr><td colspan="8" class="text-center text-muted"><?= t('درخواست') ?> <?= t('خریدی') ?> <?= t('ثبت نشده') ?> <?= t('است') ?>.</td></tr><?php endif;?></tbody></table></div></div>
<div id="purchaseModal" class="modal-overlay hidden"><div class="modal glass-modal"><div class="modal-header"><h3 class="modal-title"><?= t('page_purchases') ?> <?= t('جدید') ?></h3><button class="modal-close" onclick="closeModal('purchaseModal')">✕</button></div><form method="post" enctype="multipart/form-data"><?= get_csrf_field() ?><input type="hidden" name="action" value="create"><div class="modal-body"><div class="form-group"><label class="form-label"><?= t('کالای') ?> <?= t('موجود') ?></label><select name="item_id" class="form-control"><option value=""><?= t('کالای') ?> <?= t('جدید') ?> / <?= t('تایپ') ?> <?= t('دستی') ?></option><?php foreach($items as $it):?><option value="<?=$it['id']?>"><?=htmlspecialchars($it['name'])?> — <?= t('موجود') ?> <?=$it['quantity']?> <?=$it['unit']?></option><?php endforeach;?></select></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('wh_name') ?> <?= t('کالا') ?> <?= t('در صورت جدیدبودن') ?></label><input name="item_name" class="form-control"></div><div class="form-group"><label class="form-label"><?= t('واحد') ?></label><input name="unit" class="form-control" value="<?= t('عدد') ?>"></div></div><div class="form-row"><div class="form-group"><label class="form-label"><?= t('تعداد') ?> *</label><input name="quantity" type="number" min="0.01" step="0.01" class="form-control" required></div><div class="form-group"><label class="form-label"><?= t('قیمت') ?> <?= t('تقریبی') ?> <?= t('واحد') ?></label><input name="expected_unit_price" type="number" min="0" class="form-control"></div></div><div class="form-group"><label class="form-label"><?= t('فروشنده') ?> / <?= t('پیمانکار') ?> <?= t('پیشنهادی') ?></label><input name="vendor_name" class="form-control"></div><div class="form-group"><label class="form-label"><?= t('شرح') ?></label><textarea name="description" class="form-control" rows="2"></textarea></div><div class="form-group"><label class="form-label"><?= t('پیش') ?>‌<?= t('فاکتور') ?> <?= t('یا') ?> <?= t('فایل') ?></label><input type="file" name="attachment" class="form-control" accept="image/png,image/jpeg,application/pdf"></div></div><div class="modal-footer"><button class="btn btn-primary"><?= t('ثبت') ?> <?= t('درخواست') ?></button></div></form></div></div>
<script>
// Fallback for openModal if app.js not loaded (FIXED)
if (typeof openModal === 'undefined') {
    window.openModal = function(id){
        var m = document.getElementById(id);
        if (m) { m.classList.remove('hidden'); m.style.display='flex'; }
    };
}
if (typeof closeModal === 'undefined') {
    window.closeModal = function(id){
        var m = document.getElementById(id);
        if (m) { m.classList.add('hidden'); m.style.display = ''; }
    };
}
// Debug helper
document.addEventListener('DOMContentLoaded', function(){
    var btn = document.querySelector('[onclick*="purchaseModal"]');
    if (btn) console.log('purchaseModal button found', btn);
    var modal = document.getElementById('purchaseModal');
    console.log('purchaseModal exists?', !!modal);
});
</script>
<?php include 'includes/footer.php'; ?>

