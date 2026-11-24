<?php
/** کاتالوگ قابل جست‌وجوی کالاهای سورسیابی‌شده. */
$pageTitle = 'بانک کالاهای سورسیابی';
require_login();
require_once __DIR__ . '/../includes/crm_resources.php';
if (!ensure_crm_resources($pdo)) { include 'includes/header.php'; echo '<div class="alert alert-danger">ساخت جدول بانک کالا ممکن نشد. دسترسی ساخت جدول دیتابیس را بررسی کنید.</div>'; include 'includes/footer.php'; return; }
$me = (int)($_SESSION['user_id'] ?? 0);
$h = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$currencies = ['USD'=>'دلار','CNY'=>'یوان','TOM'=>'تومان','EUR'=>'یورو','AED'=>'درهم','IRR'=>'ریال'];
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='unlink_shop_product') {
    $productId=(int)($_POST['id'] ?? 0);
    try {
        if (!is_strict_admin() || is_nazer_kol() || (!access_is_special_account() && access_mode('crm_customers')==='viewer')) throw new DomainException('دسترسی برای تغییر مجاز نیست.');
        $source=(string)($_POST['source_id'] ?? ''); $shopId=(string)($_POST['shop_product_id'] ?? ''); $version=(string)($_POST['version'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,9}$/D',$shopId) || !preg_match('/^[1-9][0-9]{0,9}$/D',$version)) throw new DomainException('درخواست نامعتبر است.');
        require_once __DIR__.'/../includes/shop_product_mapping.php';
        if (!shop_product_mapping_ensure($pdo)) throw new DomainException('اتصال کالا آماده نیست.');
        shop_product_unlink_from_bank($pdo,$me,true,$source,(int)$shopId,$productId,(int)$version);
        try { operations_audit($pdo,$me,'shop_product_unlink','crm_product',$productId,'قطع اتصال کالای فروشگاه'); } catch (Throwable $e) { error_log('Shop product audit failed'); }
        showToast('اتصال کالا قطع شد.');
    } catch (Throwable $e) {
        error_log('Shop product unlink failed: '.get_class($e));
        showToast($e instanceof DomainException ? $e->getMessage() : 'قطع اتصال ممکن نشد.','error');
    }
    redirect('index.php?page=crm_products&view='.$productId);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200);
    $currency = (string)($_POST['currency'] ?? 'USD');
    if ($name === '' || !isset($currencies[$currency])) { showToast('نام کالا و ارز معتبر الزامی است.', 'error'); redirect('index.php?page=crm_products'); }
    $current = null;
    if ($id) { $st = $pdo->prepare('SELECT * FROM crm_products WHERE id=?'); $st->execute([$id]); $current = $st->fetch(); if (!$current) { showToast('کالا پیدا نشد.', 'error'); redirect('index.php?page=crm_products'); } }
    $price = [];
    foreach (['factory_price','customer_price'] as $key) {
        $v = str_replace([',','،','٬',' '], '', normalize_digits((string)($_POST[$key] ?? '')));
        if ($v !== '' && (!is_numeric($v) || (float)$v < 0 || (float)$v > 9999999999999999)) { showToast('قیمت باید عددی غیرمنفی باشد.', 'error'); redirect('index.php?page=crm_products' . ($id ? '&edit='.$id : '&new=1')); }
        $price[$key] = $v === '' ? null : $v;
    }
    $dateInput = trim((string)($_POST['price_date'] ?? ''));
    $date = $dateInput !== '' ? to_gregorian($dateInput) : null;
    if ($dateInput !== '' && (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) { showToast('تاریخ قیمت معتبر نیست.', 'error'); redirect('index.php?page=crm_products' . ($id ? '&edit='.$id : '&new=1')); }
    $image = $current['image_path'] ?? null; $newImage = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['image']; $info = $f['error'] === UPLOAD_ERR_OK ? @getimagesize($f['tmp_name']) : false;
        $types = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!$info || !isset($types[$info['mime'] ?? '']) || (int)$f['size'] > 5*1024*1024) { showToast('عکس باید JPG، PNG یا WebP و حداکثر ۵ مگابایت باشد.', 'error'); redirect('index.php?page=crm_products' . ($id ? '&edit='.$id : '&new=1')); }
        $dir = __DIR__ . '/../uploads/products/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { showToast('پوشهٔ عکس قابل ساخت نیست.', 'error'); redirect('index.php?page=crm_products'); }
        $newImage = 'uploads/products/' . bin2hex(random_bytes(16)) . '.' . $types[$info['mime']];
        if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $newImage)) { showToast('ذخیرهٔ عکس ممکن نشد.', 'error'); redirect('index.php?page=crm_products'); }
        $image = $newImage;
    }
    $args = [$name, trim((string)($_POST['specs'] ?? '')), mb_substr(trim((string)($_POST['supplier'] ?? '')),0,200), $price['factory_price'], $price['customer_price'], $currency, mb_substr(trim((string)($_POST['unit'] ?? '')),0,30), $date, $image, trim((string)($_POST['notes'] ?? '')), $me];
    try {
        if ($id) { $args[] = $id; $pdo->prepare('UPDATE crm_products SET name=?, specs=?, supplier=?, factory_price=?, customer_price=?, currency=?, unit=?, price_date=?, image_path=?, notes=?, updated_by=? WHERE id=?')->execute($args); }
        else { $args[] = $me; $pdo->prepare('INSERT INTO crm_products (name,specs,supplier,factory_price,customer_price,currency,unit,price_date,image_path,notes,updated_by,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($args); $id = (int)$pdo->lastInsertId(); }
        operations_audit($pdo, $me, 'crm_product_save', 'crm_product', $id, $name);
        showToast('کالا ذخیره شد. قیمت‌ها را قبل از اعلام مجدد بررسی کنید.');
        redirect('index.php?page=crm_products&view='.$id);
    } catch (Throwable $e) { if ($newImage) @unlink(__DIR__ . '/../' . $newImage); error_log('Product save: '.$e->getMessage()); showToast('ذخیرهٔ کالا ممکن نشد.', 'error'); redirect('index.php?page=crm_products'); }
}
$id = (int)($_GET['view'] ?? $_GET['edit'] ?? 0); $item = null;
if ($id) { $st = $pdo->prepare('SELECT p.*, u.full_name AS creator_name FROM crm_products p LEFT JOIN users u ON u.id=p.created_by WHERE p.id=?'); $st->execute([$id]); $item=$st->fetch() ?: null; }
// Only a current, active administrator sees cross-system mapping identifiers.
$shopMappings=[]; $canSeeMappings=false;
if ($item && is_strict_admin()) {
    try {
        $auth=$pdo->prepare('SELECT status,role FROM users WHERE id=?'); $auth->execute([$me]); $actor=$auth->fetch(PDO::FETCH_ASSOC);
        if ($actor && $actor['status']==='active' && $actor['role']==='admin') {
            $stMap=$pdo->prepare('SELECT shop_product_id,source_id,version,updated_at FROM crm_shop_product_links WHERE crm_product_id=? ORDER BY updated_at DESC LIMIT 50');
            $stMap->execute([$id]); $shopMappings=$stMap->fetchAll(); $canSeeMappings=true;
        }
    } catch (Throwable $e) { /* Order mapping is an optional module. */ }
}
$q = trim((string)($_GET['q'] ?? '')); $where = '1=1'; $args=[];
if ($q !== '') { $where='(p.name LIKE ? OR p.specs LIKE ? OR p.supplier LIKE ? OR p.notes LIKE ?)'; $args=array_fill(0,4,'%'.$q.'%'); }
$st=$pdo->prepare("SELECT COUNT(*) FROM crm_products p WHERE $where"); $st->execute($args); $total=(int)$st->fetchColumn();
$page=max(1,(int)($_GET['p'] ?? 1)); $page=min($page,max(1,(int)ceil($total/30))); $offset=($page-1)*30;
$st=$pdo->prepare("SELECT p.* FROM crm_products p WHERE $where ORDER BY p.id DESC LIMIT 30 OFFSET $offset"); $st->execute($args); $items=$st->fetchAll();
include 'includes/header.php';
?>
<div class="d-flex justify-between align-center mb-4" style="gap:10px;flex-wrap:wrap"><div><h2>📦 بانک کالاهای سورسیابی</h2><span class="text-muted">اطلاعات و قیمت‌های ثبت‌شده برای استفادهٔ مجدد؛ قیمت تاریخی است و قبل از اعلام باید بررسی شود.</span></div><div><?php if (is_strict_admin()): ?><a href="index.php?page=shop_catalog" class="btn">کالاهای فروشگاه</a> <?php endif; ?><a href="index.php?page=crm_products&new=1" class="btn btn-primary">افزودن کالا</a></div></div>
<?php if (isset($_GET['new']) || (isset($_GET['edit']) && $item)): $v=$item ?: []; ?>
<div class="card"><div class="card-header"><h3 class="card-title"><?= $item ? 'ویرایش کالا' : 'ثبت کالا' ?></h3></div>
<form method="post" enctype="multipart/form-data" style="padding:16px"><?= get_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($v['id'] ?? 0) ?>">
<div class="grid-2"><div class="form-group"><label class="form-label">نام کالا *</label><input class="form-control" name="name" required maxlength="200" value="<?= $h($v['name'] ?? '') ?>"></div><div class="form-group"><label class="form-label">تأمین‌کننده / کارخانه</label><input class="form-control" name="supplier" maxlength="200" value="<?= $h($v['supplier'] ?? '') ?>"></div></div>
<div class="form-group"><label class="form-label">مشخصات فنی</label><textarea class="form-control" name="specs" rows="3"><?= $h($v['specs'] ?? '') ?></textarea></div>
<div class="grid-2"><div class="form-group"><label class="form-label">قیمت درب کارخانه</label><input type="number" min="0" step="any" class="form-control" dir="ltr" name="factory_price" value="<?= $h($v['factory_price'] ?? '') ?>"></div><div class="form-group"><label class="form-label">قیمت اعلام‌شده به مشتری</label><input type="number" min="0" step="any" class="form-control" dir="ltr" name="customer_price" value="<?= $h($v['customer_price'] ?? '') ?>"></div></div>
<div class="grid-2"><div class="form-group"><label class="form-label">ارز</label><select class="form-control" name="currency"><?php foreach($currencies as $k=>$label): ?><option value="<?= $h($k) ?>" <?= ($v['currency'] ?? 'USD') === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach ?></select></div><div class="form-group"><label class="form-label">واحد قیمت (مثلاً عدد / کیلو)</label><input name="unit" class="form-control" maxlength="30" value="<?= $h($v['unit'] ?? '') ?>"></div></div>
<div class="grid-2"><div class="form-group"><label class="form-label">تاریخ قیمت (شمسی، مثال ۱۴۰۵/۰۸/۳۰)</label><input name="price_date" class="form-control" dir="ltr" value="<?= $h(!empty($v['price_date']) ? to_jalali($v['price_date']) : '') ?>"></div><div class="form-group"><label class="form-label">عکس کالا (حداکثر ۵ مگابایت)</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control"></div></div>
<div class="form-group"><label class="form-label">یادداشت</label><textarea name="notes" class="form-control" rows="2"><?= $h($v['notes'] ?? '') ?></textarea></div><button class="btn btn-primary">ذخیره</button> <a class="btn btn-ghost" href="index.php?page=crm_products">انصراف</a></form></div>
<?php elseif ($item): ?>
<div class="card" style="padding:18px"><div class="d-flex justify-between" style="gap:12px;flex-wrap:wrap"><h3><?= $h($item['name']) ?></h3><a href="index.php?page=crm_products&edit=<?= (int)$item['id'] ?>" class="btn btn-primary btn-sm">ویرایش</a></div>
<?php if (!empty($item['image_path']) && preg_match('~^uploads/products/[a-f0-9]+\.(jpg|png|webp)$~', $item['image_path']) && is_file(__DIR__.'/../'.$item['image_path'])): ?><img src="<?= $h($item['image_path']) ?>" alt="عکس کالا" style="width:220px;max-width:100%;height:180px;object-fit:cover;border-radius:12px;margin:12px 0"><?php endif ?>
<p>کارخانه / تأمین‌کننده: <?= $h($item['supplier'] ?: '—') ?> | تاریخ قیمت: <?= $h($item['price_date'] ? to_jalali($item['price_date']) : 'ثبت نشده') ?></p><p>قیمت درب کارخانه: <b><?= $h($item['factory_price'] ?? '—') ?></b> | قیمت مشتری: <b><?= $h($item['customer_price'] ?? '—') ?></b> <?= $h($currencies[$item['currency']] ?? $item['currency']) ?> / <?= $h($item['unit'] ?: '—') ?></p><p>مشخصات: <?= nl2br($h($item['specs'] ?: '—')) ?></p><p>یادداشت: <?= nl2br($h($item['notes'] ?: '—')) ?></p><small>ثبت‌کننده: <?= $h($item['creator_name'] ?: '—') ?></small></div>
<?php if ($canSeeMappings): ?><div class="card" style="padding:16px;margin-top:12px"><h4>اتصال‌های فعلی به کالاهای فروشگاه</h4><p>فقط کالاهای دیده‌شده در سفارش و تأییدشده توسط مدیر؛ این ارتباط قیمت، موجودی یا انتشار فروشگاه را تغییر نمی‌دهد.</p><?php if (!$shopMappings): ?><p>اتصالی ثبت نشده است.</p><?php endif; ?><?php foreach ($shopMappings as $map): ?><div style="margin:8px 0">شناسه کالای فروشگاه: <?= (int)$map['shop_product_id'] ?> | منبع: <?= $h(substr((string)$map['source_id'],0,8)) ?>… | بروزرسانی: <?= !empty($map['updated_at']) ? $h(to_jalali(substr($map['updated_at'],0,10))) : '—' ?>
<?php if (!is_nazer_kol()): ?><form method="post" style="display:inline" onsubmit="return confirm('اتصال فعلی این کالا برای همه سفارش‌ها قطع شود؟');"><?= get_csrf_field() ?><input type="hidden" name="action" value="unlink_shop_product"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="source_id" value="<?= $h($map['source_id']) ?>"><input type="hidden" name="shop_product_id" value="<?= (int)$map['shop_product_id'] ?>"><input type="hidden" name="version" value="<?= (int)$map['version'] ?>"><button type="submit" class="btn btn-sm">قطع اتصال</button></form><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
<?php endif ?>
<form method="get" class="d-flex gap-2" style="margin:16px 0;flex-wrap:wrap"><input type="hidden" name="page" value="crm_products"><input name="q" class="form-control" style="max-width:400px" placeholder="جست‌وجوی نام، مشخصات یا تأمین‌کننده" value="<?= $h($q) ?>"><button class="btn btn-ghost">جست‌وجو</button></form>
<div class="card overflow-x-auto"><table class="table"><thead><tr><th>کالا</th><th>تأمین‌کننده</th><th>قیمت کارخانه</th><th>قیمت مشتری</th><th>تاریخ قیمت</th><th></th></tr></thead><tbody><?php foreach ($items as $row): ?><tr><td><?= $h($row['name']) ?></td><td><?= $h($row['supplier'] ?: '—') ?></td><td dir="ltr"><?= $h($row['factory_price'] ?? '—') ?> <?= $h($row['currency']) ?></td><td dir="ltr"><?= $h($row['customer_price'] ?? '—') ?> <?= $h($row['currency']) ?></td><td><?= $h($row['price_date'] ? to_jalali($row['price_date']) : '—') ?></td><td><a href="index.php?page=crm_products&view=<?= (int)$row['id'] ?>" class="btn btn-ghost btn-sm">جزئیات</a></td></tr><?php endforeach ?><?php if (!$items): ?><tr><td colspan="6">کالایی پیدا نشد.</td></tr><?php endif ?></tbody></table></div>
<div style="margin:12px 0">صفحه <?= $page ?> از <?= max(1,(int)ceil($total/30)) ?> (<?= $total ?> کالا) <?php if ($page>1): ?><a class="btn btn-ghost btn-sm" href="index.php?page=crm_products&q=<?= urlencode($q) ?>&p=<?= $page-1 ?>">قبلی</a><?php endif ?> <?php if ($page*30<$total): ?><a class="btn btn-ghost btn-sm" href="index.php?page=crm_products&q=<?= urlencode($q) ?>&p=<?= $page+1 ?>">بعدی</a><?php endif ?></div>
<?php include 'includes/footer.php'; ?>
