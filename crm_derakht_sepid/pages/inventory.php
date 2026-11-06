<?php
$pageTitle = t('nav_inventory');
$ws_id = $_SESSION['workshop_id'] ?? 0;
$is_inventory = has_permission('manage_inventory');
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// بخش‌های تخصصی انبار داخل همین صفحه باز می‌شوند تا منوی اصلی خلوت بماند.
$inventory_subtab = (string)($_GET['tab'] ?? '');
$inventory_subpages = [
    'serials' => __DIR__ . '/inventory_serials.php',
    'purchases' => __DIR__ . '/purchases.php',
    'depreciation' => __DIR__ . '/depreciation.php',
];
if (isset($inventory_subpages[$inventory_subtab])) {
    include $inventory_subpages[$inventory_subtab];
    exit;
}

$upload_dir = __DIR__ . '/../uploads/attachments/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// ═══ نرمال‌سازی نام کالا برای یکتا/ادغام (§8): ي→ی، ك→ک، فاصله‌ها، حروف لاتین ═══
if (!function_exists('normalize_item_key')) {
    function normalize_item_key($name) {
        $n = trim((string)$name);
        $n = str_replace(
            ["\u{064A}", "\u{0643}", "\u{0629}", "\u{0624}", "\u{0625}", "\u{0623}", "\u{0671}", "\u{0649}", "\u{0626}", "\u{06C0}", "\u{200C}"],
            ["\u{06CC}", "\u{06A9}", "\u{0647}", "\u{0648}", "\u{0627}", "\u{0627}", "\u{0627}", "\u{06CC}", "\u{06CC}", "\u{0647}", ' '],
            $n
        );
        $n = preg_replace('/\s+/u', ' ', $n);
        return mb_strtolower($n, 'UTF-8');
    }
}

// ═══ نوع خروج مؤثر یک تراکنش — با سازگاری برای داده‌های قدیمی (بدون out_type) ═══
if (!function_exists('inv_out_type')) {
    function inv_out_type($t) {
        $type = $t['type'] ?? '';
        if ($type === 'restock') return 'in';
        if ($type === 'return')  return 'return';
        if (!empty($t['out_type'])) return $t['out_type']; // normal | consumable | repair | custody
        if (!empty($t['repair_subcontractor_id'])) return 'repair';
        if (!empty($t['is_consumable'])) return 'consumable';
        return 'normal';
    }
}

// ═══ پرسنل فعال کارگاه (برای پیشنهاد نام در خروج/امانی/گزارش شخص) ═══
$ws_personnel = [];
try {
    $stp = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN workshop_personnel wp ON wp.user_id = u.id AND wp.status = 'active' WHERE wp.workshop_id = ? AND u.status = 'active' ORDER BY u.full_name");
    $stp->execute([$ws_id]);
    $ws_personnel = $stp->fetchAll();
} catch (Throwable $e) { $ws_personnel = []; }

// تبدیل نام شخص به user_id (تطبیق نرمال‌شده)
if (!function_exists('inv_resolve_person_id')) {
    function inv_resolve_person_id($person_name, $ws_personnel) {
        $key = normalize_item_key($person_name);
        foreach ($ws_personnel as $p) {
            if (normalize_item_key($p['full_name']) === $key) return (int)$p['id'];
        }
        return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $name = sanitize($_POST['name']);
        $qty = (float)$_POST['quantity'];
        $key = normalize_item_key($name);
        // ادغام خودکار: اگر کالایی با نام نرمال‌شده موجود است، موجودی به آن اضافه می‌شود (§8)
        $st = $pdo->prepare("SELECT id, name, quantity FROM inventory_items WHERE workshop_id = ? AND status = 'active'");
        $st->execute([$ws_id]);
        $merged_to = null;
        foreach ($st->fetchAll() as $ex) {
            if (normalize_item_key($ex['name']) === $key) { $merged_to = $ex; break; }
        }
        if ($merged_to) {
            $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?")->execute([$qty, $merged_to['id']]);
            $activity_item_id = (int)$merged_to['id'];
            $activity_message = strtr(t('ورود/افزایش موجودی «{item}»'), ['{item}' => $merged_to['name']]);
            showToast(t('این کالا قبلاً با نام «') . $merged_to['name'] . '» ' . t('ثبت بود — ') . $qty . ' ' . t('به موجودی آن اضافه شد 🔄'));
        } else {
            $stmt = $pdo->prepare("INSERT INTO inventory_items (workshop_id, name, sku, barcode, category, unit, quantity, min_quantity, reissue_days, description, asset_purchase_price, asset_purchase_date, useful_life_months) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$ws_id, $name, sanitize($_POST['sku'] ?? ''), sanitize($_POST['barcode'] ?? ''), sanitize($_POST['category'] ?? ''), sanitize($_POST['unit'] ?? 'عدد'), $qty, (float)($_POST['min_quantity'] ?? 0), (int)($_POST['reissue_days'] ?? 0), sanitize($_POST['description'] ?? ''), (float)($_POST['asset_purchase_price'] ?? 0), to_gregorian($_POST['asset_purchase_date'] ?? '') ?: null, (int)($_POST['useful_life_months'] ?? 0)]);
            $activity_item_id = (int)$pdo->lastInsertId();
            $activity_message = strtr(t('ورود کالای جدید «{item}»'), ['{item}' => $name]);
            showToast(t('کالا اضافه شد'));
        }
        arash_coin_award_inventory_activity($pdo, (int)$_SESSION['user_id'], (int)$ws_id, 'inventory:add:' . $activity_item_id . ':' . uniqid(), $activity_message, date('Y-m-d'));
        redirect("index.php?page=inventory");
    }

    // ادغام موارد تکراری موجود (§8) — فقط ادمین
    if ($action === 'merge_duplicates' && $is_admin) {
        $st = $pdo->prepare("SELECT * FROM inventory_items WHERE workshop_id = ? AND status = 'active' ORDER BY id");
        $st->execute([$ws_id]);
        $groups = [];
        foreach ($st->fetchAll() as $it) {
            $groups[normalize_item_key($it['name'])][] = $it;
        }
        $merged_n = 0;
        foreach ($groups as $g) {
            if (count($g) < 2) continue;
            $keep = array_shift($g); // قدیمی‌ترین (کمترین id)
            foreach ($g as $dup) {
                $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ?, repair_quantity = repair_quantity + ? WHERE id = ?")
                    ->execute([(float)$dup['quantity'], (float)($dup['repair_quantity'] ?? 0), $keep['id']]);
                $pdo->prepare("UPDATE inventory_transactions SET item_id = ? WHERE item_id = ?")->execute([$keep['id'], $dup['id']]);
                $pdo->prepare("UPDATE inventory_items SET status = 'inactive', quantity = 0, repair_quantity = 0 WHERE id = ?")->execute([$dup['id']]);
                $merged_n++;
            }
        }
        showToast($merged_n ? strtr(t('{count} مورد تکراری ادغام شد ✅'), ['{count}' => $merged_n]) : t('مورد تکراری پیدا نشد'));
        redirect("index.php?page=inventory");
    }

    if ($action === 'edit_item') {
        $id = (int)$_POST['item_id'];
        $stmt = $pdo->prepare("UPDATE inventory_items SET name = ?, sku = ?, barcode = ?, category = ?, unit = ?, min_quantity = ?, reissue_days = ?, description = ?, quantity = ?, repair_quantity = ?, asset_purchase_price = ?, asset_purchase_date = ?, useful_life_months = ? WHERE id = ? AND workshop_id = ?");
        $stmt->execute([sanitize($_POST['name']), sanitize($_POST['sku'] ?? ''), sanitize($_POST['barcode'] ?? ''), sanitize($_POST['category'] ?? ''), sanitize($_POST['unit']), (float)($_POST['min_quantity'] ?? 0), (int)($_POST['reissue_days'] ?? 0), sanitize($_POST['description'] ?? ''), (float)$_POST['quantity'], (float)($_POST['repair_quantity'] ?? 0), (float)($_POST['asset_purchase_price'] ?? 0), to_gregorian($_POST['asset_purchase_date'] ?? '') ?: null, (int)($_POST['useful_life_months'] ?? 0), $id, $ws_id]);
        showToast(t('کالا ویرایش شد'));
        redirect("index.php?page=inventory");
    }

    if ($action === 'checkout') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $item_name_typed = sanitize($_POST['item_name'] ?? '');
        $qty = (float)$_POST['quantity'];
        $kind = $_POST['checkout_kind'] ?? 'normal'; // normal | consumable | custody | repair
        if (!in_array($kind, ['normal', 'consumable', 'custody', 'repair'], true)) $kind = 'normal';
        $person = sanitize($_POST['person_name']);
        $person_id = (int)($_POST['person_id'] ?? 0) ?: inv_resolve_person_id($person, $ws_personnel);
        $cdate = to_gregorian($_POST['checkout_date']);

        // اگر کالا با تایپ آزاد انتخاب شده (بدون آیدی)، با نام نرمال‌شده پیدا کن
        if (!$item_id && $item_name_typed !== '') {
            $stl = $pdo->prepare("SELECT * FROM inventory_items WHERE workshop_id = ? AND status = 'active'");
            $stl->execute([$ws_id]);
            foreach ($stl->fetchAll() as $cand) {
                if (normalize_item_key($cand['name']) === normalize_item_key($item_name_typed)) { $item_id = (int)$cand['id']; break; }
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$item_id, $ws_id]);
        $item = $stmt->fetch();
        if (!$item) { showToast(t('کالا یافت نشد — از لیست پیشنهادی انتخاب کنید'), 'error'); redirect("index.php?page=inventory"); }
        $item_id = (int)$item['id'];

        // ── قانون تحویل مجدد مصرفی‌ها (§8) ──
        $is_early = 0; $days_since = null; $interval = (int)($item['reissue_days'] ?? 0);
        $early_approved_by = null; $early_reason = null;
        if ($kind === 'consumable' && $interval > 0) {
            $key = normalize_item_key($person);
            // آخرین تحویل قبلی این کالا به همین شخص (تطبیق نام نرمال‌شده)
            $st2 = $pdo->prepare("SELECT checkout_date, person_name FROM inventory_transactions WHERE workshop_id = ? AND item_id = ? AND type = 'consumable' AND deleted_at IS NULL ORDER BY checkout_date DESC, id DESC LIMIT 100");
            $st2->execute([$ws_id, $item_id]);
            foreach ($st2->fetchAll() as $lx) {
                if (normalize_item_key($lx['person_name']) === $key) {
                    $days_since = (int)floor((strtotime($cdate) - strtotime($lx['checkout_date'])) / 86400);
                    break;
                }
            }
            if ($days_since !== null && $days_since < $interval) {
                $is_early = 1;
                $want_approve = !empty($_POST['early_approve']);
                $early_reason = sanitize($_POST['early_reason'] ?? '');
                if (!$want_approve || $early_reason === '') {
                    showToast(strtr(t('⚠️ تحویل زودهنگام! آخرین تحویل به «{person}» فقط {days} روز پیش بوده (بازه مجاز: {interval} روز). برای ثبت، تیک «تأیید تحویل زودهنگام» + نوشتن دلیل اجباری است.'), ['{person}' => $person, '{days}' => $days_since, '{interval}' => $interval]), 'error');
                    redirect("index.php?page=inventory");
                }
                $early_approved_by = $_SESSION['user_id'];
            }
        }

        if ($kind === 'repair') {
            // ارسال برای تعمیر از سبد «نیازمند تعمیر»
            $repair_stock = (float)($item['repair_quantity'] ?? 0);
            if ($qty > $repair_stock) { showToast(strtr(t('در سبد نیازمند تعمیر فقط {qty} {unit} هست'), ['{qty}' => $repair_stock, '{unit}' => $item['unit']]), 'error'); redirect("index.php?page=inventory"); }
            $repair_sub = (int)($_POST['repair_subcontractor_id'] ?? 0);
            if (!$repair_sub) { showToast(t('پیمانکار تعمیرکار را انتخاب کنید'), 'error'); redirect("index.php?page=inventory"); }
            $repair_cost = (float)($_POST['repair_cost'] ?? 0);

            $stmt = $pdo->prepare("INSERT INTO inventory_transactions (workshop_id, item_id, type, quantity, person_name, person_id, delivered_by, checkout_date, checkout_time, is_consumable, notes, repair_subcontractor_id, repair_cost, created_by, out_type) VALUES (?, ?, 'checkout', ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'repair')");
            $stmt->execute([$ws_id, $item_id, $qty, $person, $person_id, sanitize($_POST['delivered_by'] ?? ''), $cdate, $_POST['checkout_time'] ?: null, sanitize($_POST['notes'] ?? 'ارسال به تعمیر'), $repair_sub, $repair_cost, $_SESSION['user_id']]);
            $inventory_tx_id = (int)$pdo->lastInsertId();
            arash_coin_award_inventory_activity($pdo, (int)$_SESSION['user_id'], (int)$ws_id, 'inventory:tx:' . $inventory_tx_id, strtr(t('ارسال کالا برای تعمیر: {item}'), ['{item}' => $item['name']]), $cdate);
            $pdo->prepare("UPDATE inventory_items SET repair_quantity = GREATEST(0, repair_quantity - ?) WHERE id = ?")->execute([$qty, $item_id]);
            if ($repair_cost > 0) {
                $pdo->prepare("UPDATE subcontractors SET total_paid = total_paid + ? WHERE id = ? AND workshop_id = ?")->execute([$repair_cost, $repair_sub, $ws_id]);
            }
            showToast(t('ارسال برای تعمیر ثبت شد'));
            redirect("index.php?page=inventory");
        }

        // خروج عادی/مصرفی/امانی از سبد «آماده تحویل»
        $ready = (float)$item['quantity'];
        if ($qty > $ready) { showToast(strtr(t('موجودی آماده تحویل کافی نیست (موجود: {ready})'), ['{ready}' => $ready]), 'error'); redirect("index.php?page=inventory"); }

        $tx_type = $kind === 'consumable' ? 'consumable' : 'checkout';
        $stmt = $pdo->prepare("INSERT INTO inventory_transactions (workshop_id, item_id, type, quantity, person_name, person_id, delivered_by, checkout_date, checkout_time, is_consumable, notes, created_by, is_early_reissue, days_since_last, required_interval_days, early_approved_by, early_reason, out_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$ws_id, $item_id, $tx_type, $qty, $person, $person_id, sanitize($_POST['delivered_by'] ?? ''), $cdate, $_POST['checkout_time'] ?: null, $kind === 'consumable' ? 1 : 0, sanitize($_POST['notes'] ?? ($kind === 'custody' ? 'امانی' : '')), $_SESSION['user_id'], $is_early, $days_since, $is_early ? $interval : null, $early_approved_by, $early_reason, $kind]);
        $inventory_tx_id = (int)$pdo->lastInsertId();
        $kind_label = $kind === 'consumable' ? t('مصرفی') : ($kind === 'custody' ? t('امانی') : ($kind === 'repair' ? t('تعمیر') : t('عادی')));
        arash_coin_award_inventory_activity($pdo, (int)$_SESSION['user_id'], (int)$ws_id, 'inventory:tx:' . $inventory_tx_id, strtr(t('خروج کالا ({kind}): {item}'), ['{kind}' => $kind_label, '{item}' => $item['name']]), $cdate);
        $pdo->prepare("UPDATE inventory_items SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")->execute([$qty, $item_id]);

        showToast($is_early ? t('تحویل زودهنگام ثبت شد ⚠️') : ($kind === 'custody' ? t('امانی ثبت شد — تا زمان برگشت/تسویه نزد این شخص می‌ماند 📦') : t('خروج ثبت شد')));
        redirect("index.php?page=inventory");
    }

    if ($action === 'return_item') {
        $tx_id = (int)$_POST['tx_id'];
        $return_qty = (float)($_POST['return_quantity'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM inventory_transactions WHERE id = ? AND workshop_id = ? AND deleted_at IS NULL");
        $stmt->execute([$tx_id, $ws_id]);
        $tx = $stmt->fetch();

        if (!$tx) { showToast(t('یافت نشد'), 'error'); redirect("index.php?page=inventory"); exit; }
        if ($return_qty <= 0) $return_qty = $tx['quantity'];

        $return_date = to_gregorian($_POST['return_date'] ?? jalali_now());
        $return_time = $_POST['return_time'] ?: date('H:i');
        $condition = ($_POST['return_condition'] ?? 'ok') === 'repair' ? 'repair' : 'ok';
        if ($tx['repair_subcontractor_id']) $condition = 'repaired'; // بازگشت از تعمیرکار = سالم

        // §fix: برگشت کالا توسط هر کاربرِ انبار بلافاصله نهایی می‌شود (نیاز به تأیید مدیر نیست)
        $approved = 1;

        $stmt = $pdo->prepare("UPDATE inventory_transactions SET type = 'return', return_date = ?, return_time = ?, return_approved = ?, approved_by = ?, return_condition = ? WHERE id = ?");
        $stmt->execute([$return_date, $return_time, $approved, $_SESSION['user_id'], $condition, $tx_id]);
        arash_coin_award_inventory_activity($pdo, (int)$_SESSION['user_id'], (int)$ws_id, 'inventory:return:' . $tx_id, strtr(t('ثبت برگشت کالا: {item_id}'), ['{item_id}' => $tx['item_id'] ?? '']), $return_date);

        if ($approved) {
            if ($condition === 'repair') {
                $pdo->prepare("UPDATE inventory_items SET repair_quantity = repair_quantity + ? WHERE id = ?")->execute([$return_qty, $tx['item_id']]);
            } else {
                // سالم (یا برگشته از تعمیر) → آماده تحویل
                $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?")->execute([$return_qty, $tx['item_id']]);
            }
        }

        showToast($approved ? t('تحویل ثبت شد و موجودی بروز شد') : t('درخواست تحویل ثبت شد - منتظر تایید'));
        redirect("index.php?page=inventory&tab=active");
    }

    if ($action === 'approve_return') {   // برای برگشت‌های قدیمیِ تأییدنشده؛ حالا هر کاربر انبار می‌تواند نهایی کند
        $tx_id = (int)$_POST['tx_id'];
        $stmt = $pdo->prepare("SELECT * FROM inventory_transactions WHERE id = ? AND workshop_id = ? AND deleted_at IS NULL");
        $stmt->execute([$tx_id, $ws_id]);
        $tx = $stmt->fetch();

        if ($tx && $tx['type'] === 'return' && !$tx['return_approved']) {
            $stmt = $pdo->prepare("UPDATE inventory_transactions SET return_approved = 1, approved_by = ? WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $tx_id]);
            if (($tx['return_condition'] ?? 'ok') === 'repair') {
                $pdo->prepare("UPDATE inventory_items SET repair_quantity = repair_quantity + ? WHERE id = ?")->execute([$tx['quantity'], $tx['item_id']]);
            } else {
                $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?")->execute([$tx['quantity'], $tx['item_id']]);
            }
            arash_coin_award_inventory_activity($pdo, (int)$_SESSION['user_id'], (int)$ws_id, 'inventory:return_approve:' . $tx_id, t('تأیید برگشت کالا'), date('Y-m-d'));
            showToast(t('تحویل تایید شد'));
        }
        redirect("index.php?page=inventory&tab=active");
    }

    if ($action === 'edit_checkout') {
        $tx_id = (int)$_POST['tx_id'];
        $new_qty = (float)$_POST['quantity'];
        $item_id = (int)$_POST['item_id'];

        $st0 = $pdo->prepare("SELECT * FROM inventory_transactions WHERE id = ? AND workshop_id = ? AND deleted_at IS NULL");
        $st0->execute([$tx_id, $ws_id]);
        $old_tx = $st0->fetch();
        if (!$old_tx) { showToast(t('رکورد یافت نشد'), 'error'); redirect("index.php?page=inventory&tab=active"); exit; }
        $old_qty = (float)$old_tx['quantity'];
        $qty_diff = $new_qty - $old_qty;
        $was_repair = !empty($old_tx['repair_subcontractor_id']);

        // تغییر نوع خروج (فقط بین عادی/مصرفی/امانی — تبدیل به/از «ارسال تعمیر» مجاز نیست چون سبد و پیمانکار دارد)
        $new_kind = $_POST['edit_kind'] ?? '';
        if ($was_repair) $new_kind = 'repair';
        if (!in_array($new_kind, ['normal', 'consumable', 'custody', 'repair'], true)) {
            $new_kind = !empty($old_tx['out_type']) ? $old_tx['out_type'] : (!empty($old_tx['is_consumable']) ? 'consumable' : 'normal');
        }
        $new_type = $new_kind === 'consumable' ? 'consumable' : 'checkout';
        $new_consumable = $new_kind === 'consumable' ? 1 : 0;

        $person = sanitize($_POST['person_name']);
        $person_id = (int)($_POST['person_id'] ?? 0) ?: inv_resolve_person_id($person, $ws_personnel);

        $stmt = $pdo->prepare("UPDATE inventory_transactions SET quantity = ?, person_name = ?, person_id = ?, delivered_by = ?, checkout_date = ?, checkout_time = ?, notes = ?, type = ?, is_consumable = ?, out_type = ? WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$new_qty, $person, $person_id, sanitize($_POST['delivered_by'] ?? ''), to_gregorian($_POST['checkout_date']), $_POST['checkout_time'] ?: null, sanitize($_POST['notes'] ?? ''), $new_type, $new_consumable, $new_kind, $tx_id, $ws_id]);

        if ($qty_diff != 0) {
            if ($was_repair) {
                $pdo->prepare("UPDATE inventory_items SET repair_quantity = GREATEST(0, repair_quantity - ?) WHERE id = ?")->execute([$qty_diff, $item_id]);
            } else {
                // همهٔ انواع خروج (عادی/مصرفی/امانی) از سبد آماده تحویل کم شده‌اند
                $pdo->prepare("UPDATE inventory_items SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")->execute([$qty_diff, $item_id]);
            }
        }

        showToast(t('خروج کالا ویرایش شد') . ($new_kind !== (inv_out_type($old_tx)) ? t(' و نوع آن تغییر کرد ✅') : ''));
        redirect("index.php?page=inventory&tab=active");
    }

    // ═══ حذف نرم رکورد انبار (ورود/خروج/برگشت) + بازمحاسبهٔ موجودی + ثبت در تاریخچه ═══
    if ($action === 'delete_tx') {
        $tx_id = (int)$_POST['tx_id'];
        $st = $pdo->prepare("SELECT t.*, i.name AS item_name, i.unit, i.quantity AS ready_now FROM inventory_transactions t JOIN inventory_items i ON i.id = t.item_id WHERE t.id = ? AND t.workshop_id = ? AND t.deleted_at IS NULL");
        $st->execute([$tx_id, $ws_id]);
        $tx = $st->fetch();
        if (!$tx) { showToast(t('رکورد یافت نشد (شاید قبلاً حذف شده)'), 'error'); redirect("index.php?page=inventory&tab=history"); exit; }

        $eff = inv_out_type($tx);
        $qty = (float)$tx['quantity'];
        $unreturned = ($tx['type'] !== 'return') && empty($tx['return_date']);
        $pending_return = ($tx['type'] === 'return') && !$tx['return_approved'];
        $approved_return = ($tx['type'] === 'return') && $tx['return_approved'];
        $stock_note = '';

        if ($eff === 'in') {
            // حذف ورود: موجودی آماده تحویل کم می‌شود — اگر کفایت نکند، فقط ادمین با تأیید می‌تواند
            if ((float)$tx['ready_now'] < $qty) {
                if (false) {
                    showToast(strtr(t('⚠️ حذف این ورود، موجودی «{item}» را منفی می‌کند (موجودی: {ready}، مقدار ورود: {qty}). ابتدا موجودی را اصلاح کنید یا با مدیر هماهنگ شوید.'), ['{item}' => $tx['item_name'], '{ready}' => $tx['ready_now'], '{qty}' => $qty]), 'error');
                    redirect("index.php?page=inventory&tab=history");
                }
                $pdo->prepare("UPDATE inventory_items SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")->execute([$qty, $tx['item_id']]);
                $stock_note = t('موجودی صفر شد (با تأیید مدیر، چون به اندازهٔ کافی موجود نبود)');
            } else {
                $pdo->prepare("UPDATE inventory_items SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")->execute([$qty, $tx['item_id']]);
                $stock_note = strtr(t('{qty} {unit} از آماده تحویل کم شد'), ['{qty}' => $qty, '{unit}' => $tx['unit']]);
            }
        } elseif ($unreturned || $pending_return) {
            // حذف خروجِ برنگشته (عادی/مصرفی/امانی/ارسال تعمیر) یا برگشتِ تاییدنشده → برگشت به آماده تحویل
            $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?")->execute([$qty, $tx['item_id']]);
            $stock_note = strtr(t('{qty} «{unit}» به آماده تحویل برمی‌گردد'), ['{qty}' => $qty, '{unit}' => $tx['unit']]);
        } elseif ($approved_return) {
            // حذف خروجی که برگشتش تایید شده: اثر خروج و برگشت همدیگر را خنثی می‌کند → موجودی بدون تغییر
            $stock_note = t('بدون تغییر موجودی (اثر خروج و برگشت خنثی می‌شود)');
        }

        $pdo->prepare("UPDATE inventory_transactions SET deleted_at = NOW(), deleted_by = ? WHERE id = ?")->execute([$_SESSION['user_id'], $tx_id]);
        $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, new_value) VALUES (?, 'delete', 'inventory_transaction', ?, ?)")
            ->execute([$_SESSION['user_id'], $tx_id, "حذف رکورد انبار — کالا: {$tx['item_name']} | مقدار: {$qty} {$tx['unit']} | نوع: {$eff} | شخص: {$tx['person_name']} | تاریخ: " . to_jalali($tx['checkout_date']) . " | {$stock_note}"]);

        showToast(strtr(t('رکورد حذف شد 🗑 — {note}'), ['{note}' => $stock_note]));
        redirect("index.php?page=inventory&tab=" . (in_array($_POST['back_tab'] ?? '', ['active', 'history'], true) ? $_POST['back_tab'] : 'history'));
    }

    if ($action === 'transfer') {
        $item_id = (int)$_POST['item_id'];
        $to_ws = (int)$_POST['to_workshop'];
        $qty = (float)$_POST['quantity'];

        $stmt = $pdo->prepare("SELECT quantity FROM inventory_items WHERE id = ? AND workshop_id = ?");
        $stmt->execute([$item_id, $ws_id]);
        $stock = (float)$stmt->fetchColumn();
        if ($qty > $stock) { showToast(t('موجودی آماده تحویل کافی نیست'), 'error'); redirect("index.php?page=inventory"); exit; }

        // اگر کاربر مدیر کارگاه است و ادمین یا مدیر پروژه نیست → ثبت درخواست برای تأیید
        if (!is_strict_admin() && !is_project_manager()) {
            $stmt = $pdo->prepare("INSERT INTO pending_transfers (transfer_type, source_workshop_id, target_workshop_id, entity_id, quantity, transfer_date, notes, requested_by, status) VALUES ('inventory', ?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$ws_id, $to_ws, $item_id, $qty, date('Y-m-d'), sanitize($_POST['notes'] ?? ''), $_SESSION['user_id']]);
            showToast(t('درخواست انتقال کالا به کارگاه مقصد ثبت شد و پس از تأیید مدیر پروژه اعمال خواهد شد'));
            redirect("index.php?page=inventory");
            exit;
        }

        $stmt = $pdo->prepare("UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?");
        $stmt->execute([$qty, $item_id]);

        $item_name_q = $pdo->prepare("SELECT name, unit, sku FROM inventory_items WHERE id = ?");
        $item_name_q->execute([$item_id]);
        $item_info = $item_name_q->fetch();

        // مقصد: تطبیق با نام نرمال‌شده (§8)
        $ex = null;
        $stx = $pdo->prepare("SELECT id, name, quantity FROM inventory_items WHERE workshop_id = ? AND status = 'active'");
        $stx->execute([$to_ws]);
        foreach ($stx->fetchAll() as $cand) {
            if (normalize_item_key($cand['name']) === normalize_item_key($item_info['name'])) { $ex = $cand; break; }
        }

        if ($ex) {
            $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?")->execute([$qty, $ex['id']]);
        } else {
            $pdo->prepare("INSERT INTO inventory_items (workshop_id, name, sku, unit, quantity) VALUES (?, ?, ?, ?, ?)")->execute([$to_ws, $item_info['name'], $item_info['sku'] ?? null, $item_info['unit'], $qty]);
        }

        arash_coin_award_inventory_activity($pdo, (int)$_SESSION['user_id'], (int)$ws_id, 'inventory:transfer:' . $item_id . ':' . uniqid(), strtr(t('انتقال کالا به کارگاه دیگر: {item}'), ['{item}' => $item_info['name']]), date('Y-m-d'));
        showToast(strtr(t('انتقال {qty} {unit} انجام شد'), ['{qty}' => $qty, '{unit}' => $item_info['unit']]));
        redirect("index.php?page=inventory");
    }

    if ($action === 'approve_inventory_transfer' && (is_strict_admin() || is_project_manager())) {
        $trans_id = (int)$_POST['transfer_id'];
        $st = $pdo->prepare("SELECT * FROM pending_transfers WHERE id = ? AND transfer_type = 'inventory' AND status = 'pending'");
        $st->execute([$trans_id]);
        $tr = $st->fetch();
        if ($tr) {
            $item_id = $tr['entity_id'];
            $from_ws = $tr['source_workshop_id'];
            $to_ws = $tr['target_workshop_id'];
            $qty = (float)$tr['quantity'];

            $stmt = $pdo->prepare("SELECT quantity, name, unit, sku FROM inventory_items WHERE id = ? AND workshop_id = ?");
            $stmt->execute([$item_id, $from_ws]);
            $item_info = $stmt->fetch();
            if (!$item_info || $qty > (float)$item_info['quantity']) {
                showToast(t('موجودی آماده تحویل در کارگاه مبدأ کافی نیست'), 'error');
            } else {
                $pdo->prepare("UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?")->execute([$qty, $item_id]);

                $ex = null;
                $stx = $pdo->prepare("SELECT id, name, quantity FROM inventory_items WHERE workshop_id = ? AND status = 'active'");
                $stx->execute([$to_ws]);
                foreach ($stx->fetchAll() as $cand) {
                    if (normalize_item_key($cand['name']) === normalize_item_key($item_info['name'])) { $ex = $cand; break; }
                }

                if ($ex) {
                    $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?")->execute([$qty, $ex['id']]);
                } else {
                    $pdo->prepare("INSERT INTO inventory_items (workshop_id, name, sku, unit, quantity) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$to_ws, $item_info['name'], $item_info['sku'] ?? null, $item_info['unit'], $qty]);
                }

                $pdo->prepare("UPDATE pending_transfers SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
                    ->execute([$_SESSION['user_id'], $trans_id]);

                arash_coin_award_inventory_activity($pdo, (int)$tr['requested_by'], (int)$from_ws, 'inventory:transfer:' . $item_id . ':' . uniqid(), strtr(t('انتقال کالا به کارگاه دیگر: {item}'), ['{item}' => $item_info['name']]), date('Y-m-d'));
                showToast(strtr(t('انتقال {qty} {unit} با موفقیت تأیید و انجام شد'), ['{qty}' => $qty, '{unit}' => $item_info['unit']]));
            }
        }
        redirect("index.php?page=inventory");
        exit;
    }

    if ($action === 'reject_inventory_transfer' && (is_strict_admin() || is_project_manager())) {
        $trans_id = (int)$_POST['transfer_id'];
        $pdo->prepare("UPDATE pending_transfers SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?")
            ->execute([$_SESSION['user_id'], $trans_id]);
        showToast(t('درخواست انتقال کالا رد شد'));
        redirect("index.php?page=inventory");
        exit;
    }
}

$filter_month = $_GET['fmonth'] ?? '';
$filter_day = $_GET['fday'] ?? '';
$filter_tab = $_GET['tab'] ?? 'stock';
$filter_type = $_GET['ftype'] ?? ''; // normal | consumable | custody | repair | return | in | pending
$filter_cat = $_GET['fcat'] ?? '';   // فیلتر دسته در تب موجودی
$highlight_tx = (int)($_GET['highlight_tx'] ?? 0); // رکوردی که از هشدار کم‌برگشتی آمده

$stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE workshop_id = ? AND status = 'active' ORDER BY name");
$stmt->execute([$ws_id]);
$items = $stmt->fetchAll();

// دسته‌های موجود (برای فیلتر تب موجودی)
$categories = [];
foreach ($items as $i) { if (trim((string)$i['category']) !== '') $categories[$i['category']] = true; }
$categories = array_keys($categories);
sort($categories);

// سبد «تحویل‌شده و برنگشته» به تفکیک کالا (§8)
$out_map = [];
$stmt = $pdo->prepare("SELECT item_id, COALESCE(SUM(quantity),0) q FROM inventory_transactions WHERE workshop_id = ? AND type IN ('checkout','consumable') AND (return_date IS NULL OR return_approved = 0) AND is_consumable = 0 AND deleted_at IS NULL GROUP BY item_id");
$stmt->execute([$ws_id]);
foreach ($stmt->fetchAll() as $o) { $out_map[$o['item_id']] = (float)$o['q']; }

$stmt = $pdo->prepare("SELECT t.*, i.name as item_name, i.unit FROM inventory_transactions t JOIN inventory_items i ON t.item_id = i.id WHERE t.workshop_id = ? AND t.deleted_at IS NULL AND ((t.type IN ('checkout','consumable') AND t.return_date IS NULL) OR (t.type = 'return' AND t.return_approved = 0)) ORDER BY t.checkout_date DESC");
$stmt->execute([$ws_id]);
$active_checkouts = $stmt->fetchAll();
foreach ($active_checkouts as &$__t) {
    $__t['checkout_date_jalali'] = $__t['checkout_date'] ? to_jalali($__t['checkout_date']) : '';
    $__t['eff_type'] = inv_out_type($__t);
}
unset($__t);
// فیلتر نوع در تب خارج‌شده
if ($filter_tab === 'active' && $filter_type) {
    $active_checkouts = array_values(array_filter($active_checkouts, function($t) use ($filter_type) {
        if ($filter_type === 'pending') return !empty($t['return_date']) && !$t['return_approved'];
        return $t['eff_type'] === $filter_type;
    }));
}

// فیلتر سابقه با ماه/روز جلالی (§7/§8)
$tx_where = "t.workshop_id = ? AND t.deleted_at IS NULL";
$tx_params = [$ws_id];
if ($filter_month && preg_match('/^(\d{4})-(\d{1,2})$/', $filter_month, $fmm)) {
    [$fms, $fme] = jalali_month_bounds((int)$fmm[1], (int)$fmm[2]);
    // روز جلالی هم اگر وارد شده
    if ($filter_day) {
        $gd = jalali_to_gregorian((int)$fmm[1], (int)$fmm[2], (int)$filter_day);
        $exact = sprintf('%04d-%02d-%02d', $gd[0], $gd[1], $gd[2]);
        $tx_where .= " AND t.checkout_date = ?";
        $tx_params[] = $exact;
    } else {
        $tx_where .= " AND t.checkout_date BETWEEN ? AND ?";
        $tx_params[] = $fms; $tx_params[] = $fme;
    }
}
$stmt = $pdo->prepare("SELECT t.*, i.name as item_name, i.unit, u.full_name as creator_name FROM inventory_transactions t JOIN inventory_items i ON t.item_id = i.id JOIN users u ON t.created_by = u.id WHERE {$tx_where} ORDER BY t.created_at DESC LIMIT 100");
$stmt->execute($tx_params);
$transactions = $stmt->fetchAll();
foreach ($transactions as &$__t) { $__t['eff_type'] = inv_out_type($__t); }
unset($__t);
// فیلتر نوع در تب سابقه
if ($filter_tab === 'history' && $filter_type) {
    $transactions = array_values(array_filter($transactions, fn($t) => $t['eff_type'] === $filter_type));
}

$other_workshops = [];
$stmt = $pdo->prepare("SELECT id, name FROM workshops WHERE id != ? AND status = 'active'");
$stmt->execute([$ws_id]);
$other_workshops = $stmt->fetchAll();

$subcontractors = [];
$stmt = $pdo->prepare("SELECT id, name FROM subcontractors WHERE workshop_id = ? AND status = 'active'");
$stmt->execute([$ws_id]);
$subcontractors = $stmt->fetchAll();

// موارد مصرفی دارای بازه تحویل (برای نمایش/هشدار در مودال خروج)
$reissue_map = [];
foreach ($items as $i) { if ((int)($i['reissue_days'] ?? 0) > 0) $reissue_map[$i['id']] = (int)$i['reissue_days']; }

// Unreturned items for dashboard
$overdue = [];
foreach ($active_checkouts as $t) {
    // اقلام مصرفی و تحویلی/امانی ذاتاً برای بازگشت روزانه اخطار نمی‌گیرند.
    // فقط خروج عادی و ابزارِ برگشت‌پذیرِ خارج از امانت بعد از ۷ روز هشدار می‌گیرند.
    if (!$t['is_consumable'] && ($t['eff_type'] ?? '') !== 'custody' && $t['checkout_date'] && strtotime($t['checkout_date']) < strtotime('-7 days')) {
        $overdue[] = $t;
    }
}

// ═══ گزارش انبار به تفکیک شخص (امانی/عادی/مصرفی/برگشتی) ═══
$report_person_name = trim((string)($_GET['person_name'] ?? ''));
$report_person_id = (int)($_GET['person_id'] ?? 0);
$person_lists = ['custody' => [], 'normal' => [], 'consumable' => [], 'returns' => [], 'repair_out' => []];
$person_report_ready = false;
if ($filter_tab === 'person' && ($report_person_name !== '' || $report_person_id > 0)) {
    if ($report_person_id > 0 && $report_person_name === '') {
        $stu = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $stu->execute([$report_person_id]);
        $report_person_name = (string)$stu->fetchColumn();
    }
    $person_key = normalize_item_key($report_person_name);
    $strp = $pdo->prepare("SELECT t.*, i.name AS item_name, i.unit FROM inventory_transactions t JOIN inventory_items i ON i.id = t.item_id WHERE t.workshop_id = ? AND t.deleted_at IS NULL ORDER BY t.checkout_date DESC, t.id DESC LIMIT 500");
    $strp->execute([$ws_id]);
    foreach ($strp->fetchAll() as $rt) {
        $match = ($report_person_id > 0 && (int)($rt['person_id'] ?? 0) === $report_person_id)
              || ($person_key !== '' && normalize_item_key($rt['person_name'] ?? '') === $person_key);
        if (!$match) continue;
        $eff = inv_out_type($rt);
        if ($rt['type'] === 'return') { $person_lists['returns'][] = $rt; continue; }
        if ($eff === 'custody') $person_lists['custody'][] = $rt;
        elseif ($eff === 'consumable') $person_lists['consumable'][] = $rt;
        elseif ($eff === 'repair') $person_lists['repair_out'][] = $rt;
        else $person_lists['normal'][] = $rt;
    }
    $person_report_ready = true;
}

// فیلتر دسته روی تب موجودی
$items_display = $items;
if ($filter_tab === 'stock' && $filter_cat !== '') {
    $items_display = array_values(array_filter($items, fn($i) => (string)$i['category'] === $filter_cat));
}

// چیپ‌های فیلتر نوع
function inv_ftype_chip($label, $val, $cur, $tab, $extra = '') {
    $href = "index.php?page=inventory&tab={$tab}" . ($val !== '' ? "&ftype={$val}" : '') . $extra;
    $on = $cur === $val;
    return '<a href="' . $href . '" class="btn btn-sm ' . ($on ? 'btn-primary' : 'btn-ghost') . '" style="border-radius:999px">' . $label . '</a>';
}

// درخواست‌های انتقال کالا
$pending_inventory_transfers = [];
try {
    if (is_strict_admin() || is_project_manager()) {
        $st = $pdo->query("SELECT pt.*, ii.name AS item_name, ii.unit, ws_from.name AS from_ws_name, ws_to.name AS to_ws_name, req_u.full_name AS requester_name
            FROM pending_transfers pt
            JOIN inventory_items ii ON ii.id = pt.entity_id
            JOIN workshops ws_from ON ws_from.id = pt.source_workshop_id
            JOIN workshops ws_to ON ws_to.id = pt.target_workshop_id
            LEFT JOIN users req_u ON req_u.id = pt.requested_by
            WHERE pt.transfer_type = 'inventory' AND pt.status = 'pending'
            ORDER BY pt.id DESC");
        $pending_inventory_transfers = $st->fetchAll();
    } else {
        $st = $pdo->prepare("SELECT pt.*, ii.name AS item_name, ii.unit, ws_to.name AS to_ws_name
            FROM pending_transfers pt
            JOIN inventory_items ii ON ii.id = pt.entity_id
            JOIN workshops ws_to ON ws_to.id = pt.target_workshop_id
            WHERE pt.transfer_type = 'inventory' AND pt.source_workshop_id = ? AND pt.status = 'pending'
            ORDER BY pt.id DESC");
        $st->execute([$ws_id]);
        $pending_inventory_transfers = $st->fetchAll();
    }
} catch (Throwable $e) {}

include 'includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4 animate-in">
    <h2><?= t('nav_inventory') ?></h2>
    <div class="btn-group">
        <button type="button" onclick="openModal('addItemModal')" class="btn btn-primary"><i data-lucide="plus"></i> <?= t('btn_add') ?> <?= t('کالا') ?></button>
        <button type="button" onclick="openModal('checkoutModal')" class="btn btn-success"><i data-lucide="log-out"></i> <?= t('nav_logout') ?> <?= t('کالا') ?></button>
        <?php if ($is_admin): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('<?= t('موارد') ?> <?= t('تکراری') ?> (<?= t('wh_name') ?> <?= t('یکسان') ?> <?= t('پس') ?> <?= t('lea_from') ?> <?= t('نرمال‌سازی') ?>) <?= t('ادغام') ?> <?= t('شوند') ?>؟')"><?= get_csrf_field() ?>
            <input type="hidden" name="action" value="merge_duplicates">
            <button class="btn btn-ghost" title="<?= t('ادغام') ?> <?= t('خودکار') ?> <?= t('wh_name') ?>‌<?= t('های') ?> <?= t('یکسان') ?>"><i data-lucide="copy"></i> <?= t('ادغام تکراری‌ها') ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ((is_strict_admin() || is_project_manager()) && !empty($pending_inventory_transfers)): ?>
<!-- کارت درخواست‌های تأیید انتقال کالا -->
<div class="card mb-4 animate-in" style="border-right:4px solid #3b82f6">
    <div class="card-header"><h3 class="card-title">🔔 <?= t('درخواست‌های انتقال کالا در انتظار تأیید مدیر پروژه') ?> (<?= count($pending_inventory_transfers) ?>)</h3></div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th><?= t('نام کالا') ?></th>
                    <th><?= t('تعداد') ?></th>
                    <th><?= t('کارگاه مبدأ') ?></th>
                    <th><?= t('کارگاه مقصد') ?></th>
                    <th><?= t('درخواست‌دهنده') ?></th>
                    <th><?= t('wh_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_inventory_transfers as $it): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($it['item_name']) ?></strong></td>
                    <td><?= $it['quantity'] ?> <?= htmlspecialchars($it['unit'] ?? '') ?></td>
                    <td><?= htmlspecialchars($it['from_ws_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($it['to_ws_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($it['requester_name'] ?? 'مدیر کارگاه') ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <form method="POST" style="display:inline"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="approve_inventory_transfer">
                                <input type="hidden" name="transfer_id" value="<?= $it['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm"><i data-lucide="check"></i> <?= t('تأیید انتقال') ?></button>
                            </form>
                            <form method="POST" style="display:inline"><?= get_csrf_field() ?>
                                <input type="hidden" name="action" value="reject_inventory_transfer">
                                <input type="hidden" name="transfer_id" value="<?= $it['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i data-lucide="x"></i> <?= t('رد') ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif (!is_strict_admin() && !is_project_manager() && !empty($pending_inventory_transfers)): ?>
<div class="card mb-4 animate-in" style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:12px 16px;border-radius:10px">
    ⏳ <strong><?= t('توجه:') ?></strong> <?= count($pending_inventory_transfers) ?> <?= t('درخواست انتقال کالا به کارگاه دیگر در انتظار تأیید مدیر پروژه دارید.') ?>
</div>
<?php endif; ?>

<!-- Overdue Warning -->
<?php if (!empty($overdue)): ?>
<div class="card mb-4 animate-in" style="border-right:4px solid var(--danger)">
    <div class="card-header"><h3 class="card-title">⚠️ <?= t('کالاهای') ?> <?= t('برگشت') ?> <?= t('نخورده') ?> (<?= t('بیش') ?> <?= t('lea_from') ?> <?= t('۷') ?> <?= t('wh_day') ?>)</h3></div>
    <?php foreach ($overdue as $t): ?>
    <a href="index.php?page=inventory&tab=active&highlight_tx=<?= (int)$t['id'] ?>#tx-<?= (int)$t['id'] ?>" style="display:block;padding:8px 14px;border-bottom:1px solid var(--border);font-size:13px;text-decoration:none;color:inherit;cursor:pointer" title="<?= t('btn_show') ?> <?= t('همین') ?> <?= t('رکورد') ?> <?= t('در') ?> <?= t('تب') ?> <?= t('خارج‌شده') ?>">
        <strong><?= $t['item_name'] ?></strong> - <?= $t['quantity'] ?> <?= $t['unit'] ?> - <?= t('دست') ?> <?= $t['person_name'] ?> - <?= t('از') ?> <?= to_jalali($t['checkout_date']) ?> <span style="color:var(--primary);font-size:11px"><?= t('مشاهده') ?> <?= t('در') ?> <?= t('خارج‌شده') ?> ←</span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Stats -->
<?php
$needs_repair_total = array_sum(array_column($items, 'repair_quantity'));
$custody_total = 0;
foreach ($active_checkouts as $t) { if ($t['eff_type'] === 'custody') $custody_total++; }
?>
<div class="stats-grid animate-in">
    <div class="stat-card"><div class="stat-icon primary"><i data-lucide="package"></i></div><div class="stat-info"><h3><?= count($items) ?></h3><p><?= t('اقلام') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon warning"><i data-lucide="log-out"></i></div><div class="stat-info"><h3><?= count($active_checkouts) ?></h3><p><?= t('خارج شده') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:rgba(34,197,94,0.12);color:#16a34a"><i data-lucide="hand-helping"></i></div><div class="stat-info"><h3><?= $custody_total ?></h3><p><?= t('امانی') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:rgba(168,85,247,0.12);color:#9333ea"><i data-lucide="wrench"></i></div><div class="stat-info"><h3><?= (float)$needs_repair_total ?></h3><p><?= t('inv_repair') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon danger"><i data-lucide="alert-triangle"></i></div><div class="stat-info"><h3><?= count(array_filter($items, fn($i) => (float)$i['quantity'] <= (float)$i['min_quantity'] && $i['min_quantity'] > 0)) ?></h3><p><?= t('warning') ?></p></div></div>
</div>

<!-- Tabs -->
<div class="tabs mb-4 animate-in">
    <a href="index.php?page=inventory&tab=stock" class="tab <?= $filter_tab == 'stock' ? 'active' : '' ?>"><?= t('inv_stock') ?></a>
    <a href="index.php?page=inventory&tab=active" class="tab <?= $filter_tab == 'active' ? 'active' : '' ?>"><?= t('خارج شده') ?> (<?= count($active_checkouts) ?>)</a>
    <a href="index.php?page=inventory&tab=history" class="tab <?= $filter_tab == 'history' ? 'active' : '' ?>"><?= t('سابقه') ?></a>
    <a href="index.php?page=inventory&tab=person" class="tab <?= $filter_tab == 'person' ? 'active' : '' ?>">👤 <?= t('گزارش شخص') ?></a>
</div>
<?php if ($is_inventory): ?>
<div class="tabs mb-4 animate-in inventory-tools-tabs">
    <span class="tab" style="cursor:default;color:var(--text-secondary);font-weight:700"><i data-lucide="wrench" style="width:16px;height:16px"></i> <?= t('ابزارهای') ?> <?= t('nav_inventory') ?></span>
    <a href="index.php?page=inventory&tab=serials" class="tab">🔖 <?= t('سریال ابزار') ?></a>
    <a href="index.php?page=inventory&tab=purchases" class="tab">🛒 <?= t('page_purchases') ?></a>
    <a href="index.php?page=inventory&tab=depreciation" class="tab">📉 <?= t('page_depreciation') ?></a>
</div>
<?php endif; ?>

<?php if ($filter_tab == 'stock'): ?>
<!-- فیلتر دسته -->
<div class="card mb-4 animate-in">
    <form method="GET" class="d-flex gap-2 align-center flex-wrap" style="padding:10px 14px">
        <input type="hidden" name="page" value="inventory">
        <input type="hidden" name="tab" value="stock">
        <label class="form-label" style="margin:0"><?= t('دسته') ?><?= t('‌بندی:') ?></label>
        <select name="fcat" class="form-control" style="width:170px" onchange="this.form.submit()">
            <option value=""><?= t('همه دسته‌ها') ?></option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>" <?= $filter_cat === $c ? 'selected' : '' ?>><?= $c ?></option>
            <?php endforeach; ?>
        </select>
        <div style="position:relative;min-width:220px;flex:1;max-width:380px">
            <input id="inventoryLiveSearch" type="search" class="form-control" placeholder="🔎 <?= t('حداقل') ?> <?= t('دو حرف') ?> <?= t('lea_from') ?> <?= t('wh_name') ?> <?= t('یا') ?> <?= t('inv_sku') ?> <?= t('را') ?> <?= t('وارد کنید') ?>..." autocomplete="off">
            <div class="text-muted" style="font-size:10px;margin-top:3px"><?= t('جست‌وجوی زنده') ?> <?= t('در') ?> <?= t('اقلامِ همین') ?> <?= t('دسته') ?> <?= t('انجام') ?> <?= t('می‌شود') ?>.</div>
        </div>
        <?php if ($filter_cat !== ''): ?><a href="index.php?page=inventory&tab=stock" class="btn btn-ghost btn-sm"><?= t('btn_delete') ?> <?= t('btn_filter') ?></a><?php endif; ?>
    </form>
</div>
<!-- فرمول §8: موجودی کلی = آماده تحویل + نیازمند تعمیر + تحویل‌شده و برنگشته -->
<div class="card animate-in">
    <div class="table-container" id="reportTable">
        <table>
            <thead><tr><th><?= t('wh_name') ?> <?= t('کالا') ?></th><th>SKU</th><th><?= t('دسته') ?></th><th><?= t('واحد') ?></th><th><?= t('inv_ready') ?></th><th><?= t('inv_repair') ?></th><th><?= t('inv_checkout') ?><?= t('‌شده') ?> <?= t('برنگشته') ?></th><th><?= t('inv_total') ?></th><th><?= t('حداقل') ?></th><th><?= t('wh_status') ?></th><th><?= t('wh_actions') ?></th></tr></thead>
            <tbody>
                <?php foreach ($items_display as $i):
                    $ready = (float)$i['quantity'];
                    $repair = (float)($i['repair_quantity'] ?? 0);
                    $out = (float)($out_map[$i['id']] ?? 0);
                    $total = $ready + $repair + $out;
                ?>
                <tr class="inventory-stock-row" data-inventory-search="<?= htmlspecialchars(($i['name'] ?? '') . ' ' . ($i['sku'] ?? '') . ' ' . ($i['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <td><strong><?= $i['name'] ?></strong><?php if ($i['description']): ?><div class="text-muted" style="font-size:11px"><?= $i['description'] ?></div><?php endif; ?>
                        <?php if ((int)($i['reissue_days'] ?? 0) > 0): ?><div style="font-size:10px;color:#9333ea">⏱ <?= t('بازه') ?> <?= t('inv_checkout') ?> <?= t('مجدد') ?>: <?= (int)$i['reissue_days'] ?> <?= t('wh_day') ?></div><?php endif; ?></td>
                    <td class="text-muted" style="font-size:11px"><?= $i['sku'] ?: '-' ?></td>
                    <td><?= $i['category'] ?: '-' ?></td>
                    <td><?= $i['unit'] ?></td>
                    <td><strong class="<?= $ready <= 0 ? 'text-danger' : '' ?>"><?= $ready ?></strong></td>
                    <td><?= $repair > 0 ? '<span class="tag" style="background:#f3e8ff;color:#7e22ce">' . $repair . '</span>' : '-' ?></td>
                    <td><?= $out > 0 ? '<span class="tag tag-warning">' . $out . '</span>' : '-' ?></td>
                    <td><strong><?= $total ?></strong></td>
                    <td><?= $i['min_quantity'] > 0 ? $i['min_quantity'] : '-' ?></td>
                    <td>
                        <?php if ($i['min_quantity'] > 0 && $ready <= $i['min_quantity']): ?>
                        <span class="tag tag-rejected"><?= t('warning') ?></span>
                        <?php else: ?>
                        <span class="tag tag-active"><?= t('موجود') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="table-actions">
                            <button type="button"  onclick='editItem(<?= js_arg($i) ?>)' class="btn btn-ghost btn-sm" title="<?= t('btn_edit') ?>"><i data-lucide="edit"></i></button>
                            <a href="index.php?page=inventory&tab=serials&item_id=<?= (int)$i['id'] ?>" class="btn btn-ghost btn-sm" title="<?= t('سریال') ?> <?= t('و') ?> <?= t('بارکد') ?>">🔖</a>
                            <a href="index.php?page=inventory&tab=depreciation" class="btn btn-ghost btn-sm" title="<?= t('استهلاک') ?>">📉</a>
                            <?php if (!empty($other_workshops)): ?>
                            <button type="button"  onclick='transferItem(<?= $i["id"] ?>, <?= js_arg($i["name"]) ?>, <?= $ready ?>)' class="btn btn-info btn-sm" title="<?= t('btn_transfer') ?>">🚚</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="inventorySearchEmpty" style="display:none"><td colspan="11" class="text-center text-muted" style="padding:40px"><?= t('کالایی') ?> <?= t('با') ?> <?= t('این') ?> <?= t('عبارت') ?> <?= t('پیدا نشد') ?></td></tr>
                <?php if (empty($items_display)): ?>
                <tr><td colspan="11" class="text-center text-muted" style="padding:40px"><?= $filter_cat !== '' ? t('در این دسته کالایی نیست') : t('کالایی ثبت نشده') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($filter_tab == 'active'): ?>
<!-- چیپ‌های فیلتر نوع -->
<div class="d-flex gap-2 flex-wrap mb-4 animate-in">
    <?= inv_ftype_chip(t('همه'), '', $filter_type, 'active') ?>
    <?= inv_ftype_chip(t('sal_regular'), 'normal', $filter_type, 'active') ?>
    <?= inv_ftype_chip(t('مصرفی'), 'consumable', $filter_type, 'active') ?>
    <?= inv_ftype_chip(t('تحویلی / امانی'), 'custody', $filter_type, 'active') ?>
    <?= inv_ftype_chip(t('ارسال تعمیر'), 'repair', $filter_type, 'active') ?>
    <?= inv_ftype_chip(t('در انتظار تایید برگشت'), 'pending', $filter_type, 'active') ?>
</div>
<div class="card animate-in">
    <div class="table-container">
        <table>
            <thead><tr><th><?= t('کالا') ?></th><th><?= t('تعداد') ?></th><th><?= t('دست چه کسی') ?></th><th><?= t('inv_checkout') ?><?= t('‌دهنده') ?></th><th><?= t('wh_date') ?> <?= t('nav_logout') ?></th><th><?= t('wh_date') ?> <?= t('برگشت') ?></th><th><?= t('wh_status') ?></th><th><?= t('wh_actions') ?></th></tr></thead>
            <tbody>
                <?php foreach ($active_checkouts as $t): ?>
                <tr id="tx-<?= (int)$t['id'] ?>" class="<?= $highlight_tx === (int)$t['id'] ? 'inventory-highlight-row' : '' ?>">
                    <td><strong><?= $t['item_name'] ?></strong></td>
                    <td><?= $t['quantity'] ?> <?= $t['unit'] ?></td>
                    <td><strong><?= $t['person_name'] ?></strong>
                        <?php if (!empty($t['person_id'])): ?><a href="index.php?page=inventory&tab=person&person_id=<?= (int)$t['person_id'] ?>&person_name=<?= urlencode($t['person_name']) ?>" class="text-muted" style="font-size:10px;display:block">👤 <?= t('گزارش شخص') ?></a><?php endif; ?>
                    </td>
                    <td><?= $t['delivered_by'] ?: '-' ?></td>
                    <td><?= to_jalali($t['checkout_date']) ?> <?= $t['checkout_time'] ? substr($t['checkout_time'], 0, 5) : '' ?></td>
                    <td>
                        <?php if ($t['return_date']): ?>
                        <?= to_jalali($t['return_date']) ?> <?= $t['return_time'] ? substr($t['return_time'], 0, 5) : '' ?>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($t['eff_type'] === 'consumable'): ?>
                        <span class="tag tag-rejected"><?= t('مصرفی') ?></span>
                        <?php if (!empty($t['is_early_reissue'])): ?><span class="tag" style="background:#fef3c7;color:#92400e" title="<?= (int)$t['days_since_last'] ?> <?= t('روز') ?> <?= t('بعد') ?> <?= t('از') ?> <?= t('تحویل') ?> <?= t('قبلی') ?> (<?= t('بازه') ?> <?= t('مجاز') ?>: <?= (int)$t['required_interval_days'] ?>) — <?= $t['early_reason'] ?>"><?= t('⚠️ زودهنگام') ?></span><?php endif; ?>
                        <?php elseif ($t['return_date'] && !$t['return_approved']): ?>
                        <span class="tag tag-pending"><?= t('در انتظار') ?> <?= t('btn_confirm') ?> <?= t('inv_checkout') ?></span>
                        <?php elseif ($t['eff_type'] === 'repair'): ?>
                        <span class="tag" style="background:#f3e8ff;color:#7e22ce"><?= t('نزد تعمیرکار') ?></span>
                        <?php elseif ($t['eff_type'] === 'custody'): ?>
                        <span class="tag" style="background:#dcfce7;color:#15803d">📦 <?= t('امانی') ?></span>
                        <?php else: ?>
                        <span class="tag tag-pending"><?= t('خارج شده') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="table-actions">
                            <?php if (!$t['return_date']): ?>
                            <button type="button"  onclick='editCheckout(<?= js_arg($t) ?>)' class="btn btn-ghost btn-sm" title="<?= t('btn_edit') ?>"><i data-lucide="edit"></i></button>
                            <?php endif; ?>
                            <?php if (!$t['is_consumable'] && !$t['return_date']): ?>
                            <button type="button"  onclick="showReturnModal(<?= $t['id'] ?>, <?= js_arg($t['item_name']) ?>, <?= $t['quantity'] ?>, <?= js_arg($t['person_name']) ?>, <?= $t['repair_subcontractor_id'] ? 1 : 0 ?>)" class="btn btn-success btn-sm"><?= t('برگشت') ?></button>
                            <?php endif; ?>
                            <?php if ($t['return_date'] && !$t['return_approved']): ?>
                            <form method="POST" style="display:inline"><?= get_csrf_field() ?><input type="hidden" name="action" value="approve_return"><input type="hidden" name="tx_id" value="<?= $t['id'] ?>">
                                <button class="btn btn-success btn-sm"><?= t('btn_confirm') ?> <?= t('inv_checkout') ?></button>
                            </form>
                            <?php endif; ?>
                            <button type="button"  onclick='delTx(<?= js_arg(["id"=>$t["id"],"label"=>$t["item_name"]." — ".$t["quantity"]." ".$t["unit"]." (".$t["person_name"].")","effect"=>($t["return_date"] ? ($t["return_approved"] ? "بدون تغییر موجودی (اثر خروج و برگشت خنثی می‌شود)" : $t["quantity"]." «{$t["unit"]}» به آماده تحویل برمی‌گردد") : $t["quantity"]." «{$t["unit"]}» به آماده تحویل برمی‌گردد")]) ?>, "active")' class="btn btn-ghost btn-sm text-danger" title="<?= t('btn_delete') ?> <?= t('رکورد') ?>"><i data-lucide="trash-2"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($active_checkouts)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:40px"><?= t('کالای') ?> <?= t('خارج شده') ?><?= t('‌ای') ?> <?= t('نیست') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($filter_tab == 'person'): ?>
<!-- 👤 گزارش انبار به تفکیک شخص -->
<div class="card mb-4 animate-in">
    <form method="GET" class="d-flex gap-2 align-center flex-wrap" style="padding:12px 14px">
        <input type="hidden" name="page" value="inventory">
        <input type="hidden" name="tab" value="person">
        <label class="form-label" style="margin:0"><?= t('شخص:') ?></label>
        <input type="text" name="person_name" list="personList" class="form-control person-pick" data-target="person_id_hidden" value="<?= htmlspecialchars($report_person_name, ENT_QUOTES) ?>" style="width:220px" placeholder="<?= t('دو حرف') ?> <?= t('اول') ?> <?= t('wh_name') ?> <?= t('را') ?> <?= t('تایپ') ?> <?= t('کنید') ?>..." autocomplete="off">
        <input type="hidden" name="person_id" id="person_id_hidden" value="<?= $report_person_id ?: '' ?>">
        <button type="submit" class="btn btn-primary btn-sm"><?= t('btn_show') ?> <?= t('گزارش') ?></button>
    </form>
</div>

<?php if ($person_report_ready): ?>
<div class="stats-grid animate-in">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(34,197,94,0.12);color:#16a34a"><i data-lucide="hand-helping"></i></div><div class="stat-info"><h3><?= count($person_lists['custody']) ?></h3><p><?= t('امانی برنگشته') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon warning"><i data-lucide="log-out"></i></div><div class="stat-info"><h3><?= count($person_lists['normal']) ?></h3><p><?= t('sal_regular') ?> <?= t('برنگشته') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon danger"><i data-lucide="package-open"></i></div><div class="stat-info"><h3><?= count($person_lists['consumable']) ?></h3><p><?= t('مصرفی') ?> <?= t('pet_income') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon primary"><i data-lucide="corner-up-left"></i></div><div class="stat-info"><h3><?= count($person_lists['returns']) ?></h3><p><?= t('برگشتی') ?></p></div></div>
</div>

<?php
$render_person_rows = function($rows, $show_status) {
    if (empty($rows)) { echo '<tr><td colspan="5" class="text-center text-muted" style="padding:20px">' . t('موردی نیست') . '</td></tr>'; return; }
    foreach ($rows as $r) {
        echo '<tr><td><strong>' . htmlspecialchars($r['item_name']) . '</strong></td>';
        echo '<td>' . $r['quantity'] . ' ' . $r['unit'] . '</td>';
        echo '<td>' . to_jalali($r['checkout_date']) . '</td>';
        echo '<td>' . ($r['checkout_date'] ? strtr(t('{days} روز پیش'), ['{days}' => (int)floor((time() - strtotime($r['checkout_date'])) / 86400)]) : '-') . '</td>';
        if ($show_status) {
            $cond = $r['return_condition'] ?? '';
            $cond_fa = ['ok' => t('✅ سالم'), 'repair' => t('🔧 نیازمند تعمیر'), 'repaired' => t('🛠 تعمیرشده')][$cond] ?? '-';
            echo '<td>' . $cond_fa . ' — ' . t('برگشت:') . ' ' . to_jalali($r['return_date']) . ($r['return_approved'] ? ' <span class="tag tag-active">' . t('تایید شده') . '</span>' : ' <span class="tag tag-pending">' . t('در انتظار تایید') . '</span>') . '</td>';
        } else {
            echo '<td>' . htmlspecialchars($r['notes'] ?? '') . '</td>';
        }
        echo '</tr>';
    }
};
?>
<div class="card mb-4 animate-in" style="border-right:4px solid #16a34a">
    <div class="card-header"><h3 class="card-title">📦 <?= t('امانت‌') ?><?= t('های') ?> <?= t('نزد') ?> «<?= htmlspecialchars($report_person_name) ?>» (<?= t('lea_to') ?> <?= t('برگشت') ?>/<?= t('تسویه') ?> <?= t('نزد ایشان') ?> <?= t('می‌ماند') ?>)</h3></div>
    <div class="table-container"><table>
        <thead><tr><th><?= t('کالا') ?></th><th><?= t('تعداد') ?></th><th><?= t('wh_date') ?> <?= t('inv_checkout') ?></th><th><?= t('wh_duration') ?> <?= t('نزد ایشان') ?></th><th><?= t('wh_note') ?></th></tr></thead>
        <tbody><?php $render_person_rows($person_lists['custody'], false); ?></tbody>
    </table></div>
</div>

<div class="card mb-4 animate-in">
    <div class="card-header"><h3 class="card-title">📤 <?= t('nav_logout') ?> <?= t('sal_regular') ?> <?= t('برنگشته') ?></h3></div>
    <div class="table-container"><table>
        <thead><tr><th><?= t('کالا') ?></th><th><?= t('تعداد') ?></th><th><?= t('wh_date') ?> <?= t('nav_logout') ?></th><th><?= t('wh_duration') ?></th><th><?= t('wh_note') ?></th></tr></thead>
        <tbody><?php $render_person_rows($person_lists['normal'], false); ?></tbody>
    </table></div>
</div>

<div class="card mb-4 animate-in">
    <div class="card-header"><h3 class="card-title">🧴 <?= t('مصرفی') ?>‌<?= t('های') ?> <?= t('pet_income') ?></h3></div>
    <div class="table-container"><table>
        <thead><tr><th><?= t('کالا') ?></th><th><?= t('تعداد') ?></th><th><?= t('wh_date') ?> <?= t('inv_checkout') ?></th><th><?= t('wh_duration') ?></th><th><?= t('wh_note') ?></th></tr></thead>
        <tbody><?php $render_person_rows($person_lists['consumable'], false); ?></tbody>
    </table></div>
</div>

<?php if (!empty($person_lists['repair_out'])): ?>
<div class="card mb-4 animate-in">
    <div class="card-header"><h3 class="card-title">🔧 <?= t('btn_submit') ?> <?= t('به') ?> <?= t('تعمیر') ?> (<?= t('ثبت') ?><?= t('‌شده') ?> <?= t('با') ?> <?= t('wh_name') ?> <?= t('ایشان') ?>)</h3></div>
    <div class="table-container"><table>
        <thead><tr><th><?= t('کالا') ?></th><th><?= t('تعداد') ?></th><th><?= t('wh_date') ?> <?= t('btn_submit') ?></th><th><?= t('wh_duration') ?></th><th><?= t('wh_note') ?></th></tr></thead>
        <tbody><?php $render_person_rows($person_lists['repair_out'], false); ?></tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card animate-in">
    <div class="card-header"><h3 class="card-title">↩️ <?= t('برگشتی') ?>‌<?= t('ها') ?></h3></div>
    <div class="table-container"><table>
        <thead><tr><th><?= t('کالا') ?></th><th><?= t('تعداد') ?></th><th><?= t('wh_date') ?> <?= t('nav_logout') ?></th><th><?= t('wh_duration') ?></th><th><?= t('wh_status') ?> <?= t('برگشت') ?></th></tr></thead>
        <tbody><?php $render_person_rows($person_lists['returns'], true); ?></tbody>
    </table></div>
</div>
<?php else: ?>
<div class="card animate-in"><div class="text-center text-muted" style="padding:50px">👆 <?= t('wh_name') ?> <?= t('شخص') ?> <?= t('را') ?> <?= t('lea_from') ?> <?= t('لیست') ?> <?= t('nav_personnel') ?> <?= t('انتخاب کنید') ?> (<?= t('یا') ?> <?= t('تایپ') ?> <?= t('کنید') ?>) <?= t('lea_to') ?> <?= t('گزارش') ?> <?= t('کامل') ?> <?= t('امانت') ?>‌<?= t('ها') ?>، <?= t('مصرفی') ?>‌<?= t('ها') ?> <?= t('و') ?> <?= t('برگشتی') ?>‌<?= t('هایش') ?> <?= t('btn_show') ?> <?= t('داده') ?> <?= t('شود') ?>.</div></div>
<?php endif; ?>

<?php else: ?>
<div class="card mb-4 animate-in">
    <form method="GET" class="d-flex gap-2 align-center flex-wrap">
        <input type="hidden" name="page" value="inventory">
        <input type="hidden" name="tab" value="history">
        <label class="form-label" style="margin:0"><?= t('rep_month') ?> <?= t('(شمسی):') ?></label>
        <input type="text" name="fmonth" class="form-control" value="<?= $filter_month ?>" style="width:120px" placeholder="1405-04">
        <label class="form-label" style="margin:0"><?= t('wh_day') ?>:</label>
        <input type="text" name="fday" class="form-control" value="<?= $filter_day ?>" style="width:80px" placeholder="15">
        <button type="submit" class="btn btn-primary btn-sm"><?= t('btn_filter') ?></button>
    </form>
</div>
<!-- چیپ‌های فیلتر نوع -->
<div class="d-flex gap-2 flex-wrap mb-4 animate-in">
    <?= inv_ftype_chip(t('همه'), '', $filter_type, 'history', ($filter_month ? "&fmonth={$filter_month}" : '') . ($filter_day ? "&fday={$filter_day}" : '')) ?>
    <?= inv_ftype_chip(t('btn_checkin'), 'in', $filter_type, 'history', ($filter_month ? "&fmonth={$filter_month}" : '') . ($filter_day ? "&fday={$filter_day}" : '')) ?>
    <?= inv_ftype_chip(t('sal_regular'), 'normal', $filter_type, 'history', ($filter_month ? "&fmonth={$filter_month}" : '') . ($filter_day ? "&fday={$filter_day}" : '')) ?>
    <?= inv_ftype_chip(t('مصرفی'), 'consumable', $filter_type, 'history', ($filter_month ? "&fmonth={$filter_month}" : '') . ($filter_day ? "&fday={$filter_day}" : '')) ?>
    <?= inv_ftype_chip(t('تحویلی / امانی'), 'custody', $filter_type, 'history', ($filter_month ? "&fmonth={$filter_month}" : '') . ($filter_day ? "&fday={$filter_day}" : '')) ?>
    <?= inv_ftype_chip(t('ارسال تعمیر'), 'repair', $filter_type, 'history', ($filter_month ? "&fmonth={$filter_month}" : '') . ($filter_day ? "&fday={$filter_day}" : '')) ?>
    <?= inv_ftype_chip(t('برگشتی'), 'return', $filter_type, 'history', ($filter_month ? "&fmonth={$filter_month}" : '') . ($filter_day ? "&fday={$filter_day}" : '')) ?>
</div>
<div class="card animate-in">
    <div class="table-container" id="reportTable">
        <table>
            <thead><tr><th><?= t('wh_date') ?></th><th><?= t('کالا') ?></th><th><?= t('تعداد') ?></th><th><?= t('sal_type') ?></th><th><?= t('inv_checkout') ?> <?= t('گیرنده') ?></th><th><?= t('inv_checkout') ?><?= t('‌دهنده') ?></th><th><?= t('wh_date') ?> <?= t('دریافت') ?></th><th><?= t('ثبت کننده') ?></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td style="white-space:nowrap"><?= to_jalali($t['checkout_date']) ?> <?= $t['checkout_time'] ? substr($t['checkout_time'], 0, 5) : '' ?></td>
                    <td><strong><?= $t['item_name'] ?></strong></td>
                    <td><?= $t['quantity'] ?> <?= $t['unit'] ?></td>
                    <td>
                        <?php if ($t['eff_type'] === 'in'): ?><span class="tag tag-active"><?= t('btn_checkin') ?></span>
                        <?php elseif ($t['eff_type'] === 'consumable'): ?><span class="tag tag-rejected"><?= t('مصرفی') ?></span>
                        <?php elseif ($t['eff_type'] === 'return'): ?><span class="tag tag-info"><?= t('برگشت') ?><?= ($t['return_condition'] ?? '') === 'repair' ? ' (' . t('نیازمند تعمیر') . ')' : '' ?></span>
                        <?php elseif ($t['eff_type'] === 'repair'): ?><span class="tag" style="background:#f3e8ff;color:#7e22ce"><?= t('btn_submit') ?> <?= t('تعمیر') ?></span>
                        <?php elseif ($t['eff_type'] === 'custody'): ?><span class="tag" style="background:#dcfce7;color:#15803d">📦 <?= t('امانی') ?></span>
                        <?php else: ?><span class="tag tag-warning"><?= t('nav_logout') ?></span><?php endif; ?>
                        <?php if (!empty($t['is_early_reissue'])): ?><span class="tag" style="background:#fef3c7;color:#92400e" title="<?= t('زودهنگام:') ?> <?= (int)$t['days_since_last'] ?>/<?= (int)$t['required_interval_days'] ?> <?= t('روز') ?> — <?= $t['early_reason'] ?>">⚠️</span><?php endif; ?>
                    </td>
                    <td><?= $t['person_name'] ?></td>
                    <td><?= $t['delivered_by'] ?: '-' ?></td>
                    <td><?= $t['type'] == 'return' && $t['return_date'] ? to_jalali($t['return_date']) : '-' ?></td>
                    <td><?= $t['creator_name'] ?></td>
                    <td>
                        <?php
                        $hx_eff = $t['eff_type'];
                        if ($hx_eff === 'in') $hx_effect = strtr(t('⚠️ {qty} «{unit}» از آماده تحویل کم می‌شود'), ['{qty}' => $t['quantity'], '{unit}' => $t['unit']]);
                        elseif ($hx_eff === 'return' && $t['return_approved']) $hx_effect = t('بدون تغییر موجودی (اثر خروج و برگشت خنثی می‌شود)');
                        else $hx_effect = strtr(t('{qty} «{unit}» به آماده تحویل برمی‌گردد'), ['{qty}' => $t['quantity'], '{unit}' => $t['unit']]);
                        ?>
                        <button type="button"  onclick='delTx(<?= js_arg(["id"=>$t["id"],"label"=>$t["item_name"]." — ".$t["quantity"]." ".$t["unit"]." (".$t["person_name"].")","effect"=>$hx_effect]) ?>, "history")' class="btn btn-ghost btn-sm text-danger" title="<?= t('btn_delete') ?> <?= t('رکورد') ?>"><i data-lucide="trash-2"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                <tr><td colspan="9" class="text-center text-muted" style="padding:40px"><?= t('سابقه') ?><?= t('‌ای') ?> <?= t('نیست') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Datalist پرسنل (پیشنهاد خودکار نام) -->
<datalist id="personList">
    <?php foreach ($ws_personnel as $p): ?>
    <option value="<?= htmlspecialchars($p['full_name'], ENT_QUOTES) ?>" data-id="<?= (int)$p['id'] ?>"></option>
    <?php endforeach; ?>
</datalist>
<!-- Datalist همه کالاها (برای فرم افزودن — جلوگیری از ثبت تکراری) -->
<datalist id="itemAllList">
    <?php foreach ($items as $i): ?>
    <option value="<?= htmlspecialchars($i['name'], ENT_QUOTES) ?>"></option>
    <?php endforeach; ?>
</datalist>
<!-- Datalist کالاهای قابل خروج (با موجودی) — با JS بر اساس نوع خروج پر می‌شود -->
<datalist id="checkoutItemsList"></datalist>

<!-- Add Item Modal -->
<div id="addItemModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_add') ?> <?= t('کالا') ?></h3><button onclick="closeModal('addItemModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="add_item">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_name') ?> <?= t('کالا *') ?></label><input type="text" name="name" class="form-control" required list="itemAllList" id="addItemName" autocomplete="off">
                        <div class="text-muted" style="font-size:10px;margin-top:3px"><?= t('با') ?> <?= t('تایپ') ?> <?= t('دو حرف') ?>، <?= t('کالاهای مشابه') ?> <?= t('پیشنهاد') ?> <?= t('می‌شوند') ?>؛ <?= t('اگر') ?> <?= t('همین') ?> <?= t('wh_name') ?> <?= t('lea_from') ?> <?= t('قبل') ?> <?= t('باشد') ?>، <?= t('inv_stock') ?> <?= t('آن') ?> <?= t('افزایش') ?> <?= t('می‌یابد') ?> (<?= t('بدون') ?> <?= t('ایجاد') ?> <?= t('مورد') ?> <?= t('تکراری') ?>)</div>
                        <div id="addItemMergeHint" style="display:none;font-size:11px;margin-top:6px;padding:8px 10px;background:#dcfce7;border-radius:8px;color:#15803d"></div></div>
                    <div class="form-group"><label class="form-label">SKU (<?= t('شناسه') ?> <?= t('یکتا') ?>)</label><input type="text" name="sku" class="form-control" placeholder="<?= t('مثلاً') ?> GLV-001" style="text-align:left"></div>
                    <div class="form-group"><label class="form-label"><?= t('بارکد') ?> / <?= t('کد') ?> <?= t('برچسب') ?></label><input type="text" name="barcode" class="form-control" placeholder="<?= t('اختیاری') ?>" style="text-align:left"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('واحد') ?></label><select name="unit" class="form-control"><option><?= t('عدد') ?></option><option><?= t('کیلو') ?></option><option><?= t('لیتر') ?></option><option><?= t('بسته') ?></option><option><?= t('متر') ?></option><option><?= t('جعبه') ?></option><option><?= t('شیت') ?></option><option><?= t('جفت') ?></option></select></div>
                    <div class="form-group"><label class="form-label"><?= t('دسته') ?><?= t('‌بندی') ?></label><input type="text" name="category" class="form-control" placeholder="<?= t('ابزار') ?>، <?= t('مصالح...') ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('inv_stock') ?> <?= t('اولیه') ?> (<?= t('inv_ready') ?>) *</label><input type="number" name="quantity" class="form-control" required min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label"><?= t('حداقل') ?> (<?= t('warning') ?>)</label><input type="number" name="min_quantity" class="form-control" min="0" step="0.01" value="0"></div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('بازه') ?> <?= t('مجاز') ?> <?= t('inv_checkout') ?> <?= t('مجدد') ?> <?= t('مصرفی') ?> (<?= t('wh_day') ?>)</label><input type="number" name="reissue_days" class="form-control" min="0" value="0">
                    <div class="text-muted" style="font-size:10px;margin-top:3px"><?= t('مثلاً ابزار مصرفی: دستکش = ۱۰ روز؛ لباس کار = ۱۵۰ روز') ?>. <?= t('صفر') ?> <?= t('یعنی') ?> <?= t('بدون') ?> <?= t('محدودیت') ?>.</div></div>
                <div class="form-row"><div class="form-group"><label class="form-label"><?= t('قیمت خرید') ?> <?= t('تجهیزات') ?> (<?= t('sal_toman') ?>)</label><input type="number" name="asset_purchase_price" class="form-control" min="0"></div><div class="form-group"><label class="form-label"><?= t('wh_date') ?> <?= t('خرید') ?></label><input type="text" name="asset_purchase_date" class="form-control jalali-date"></div><div class="form-group"><label class="form-label"><?= t('عمر مفید') ?> (<?= t('rep_month') ?>)</label><input type="number" name="useful_life_months" class="form-control" min="0"></div></div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?></label><input type="text" name="description" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_add') ?></button></div>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div id="editItemModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_edit') ?> <?= t('کالا') ?></h3><button onclick="closeModal('editItemModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="item_id" id="edit_item_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_name') ?> *</label><input type="text" name="name" id="edit_item_name" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">SKU</label><input type="text" name="sku" id="edit_item_sku" class="form-control" style="text-align:left"></div><div class="form-group"><label class="form-label"><?= t('بارکد') ?></label><input type="text" name="barcode" id="edit_item_barcode" class="form-control" style="text-align:left"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('واحد') ?></label><select name="unit" id="edit_item_unit" class="form-control"><option><?= t('عدد') ?></option><option><?= t('کیلو') ?></option><option><?= t('لیتر') ?></option><option><?= t('بسته') ?></option><option><?= t('متر') ?></option><option><?= t('جعبه') ?></option><option><?= t('شیت') ?></option><option><?= t('جفت') ?></option></select></div>
                    <div class="form-group"><label class="form-label"><?= t('دسته') ?></label><input type="text" name="category" id="edit_item_cat" class="form-control"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('inv_ready') ?> *</label><input type="number" name="quantity" id="edit_item_qty" class="form-control" required min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label"><?= t('inv_repair') ?></label><input type="number" name="repair_quantity" id="edit_item_repair" class="form-control" min="0" step="0.01"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('حداقل') ?></label><input type="number" name="min_quantity" id="edit_item_min" class="form-control" min="0" step="0.01"></div>
                    <div class="form-group"><label class="form-label"><?= t('بازه') ?> <?= t('inv_checkout') ?> <?= t('مجدد') ?> (<?= t('wh_day') ?>)</label><input type="number" name="reissue_days" id="edit_item_reissue" class="form-control" min="0"></div>
                </div>
                <div class="form-row"><div class="form-group"><label class="form-label"><?= t('قیمت خرید') ?> <?= t('تجهیزات') ?></label><input type="number" name="asset_purchase_price" id="edit_asset_price" class="form-control" min="0"></div><div class="form-group"><label class="form-label"><?= t('wh_date') ?> <?= t('خرید') ?></label><input type="text" name="asset_purchase_date" id="edit_asset_date" class="form-control jalali-date"></div><div class="form-group"><label class="form-label"><?= t('عمر مفید') ?> (<?= t('rep_month') ?>)</label><input type="number" name="useful_life_months" id="edit_asset_life" class="form-control" min="0"></div></div>
                <div class="form-group"><label class="form-label"><?= t('wor_description') ?></label><input type="text" name="description" id="edit_item_desc" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button></div>
        </form>
    </div>
</div>

<!-- Checkout Modal -->
<div id="checkoutModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:550px">
        <div class="modal-header"><h3 class="modal-title"><?= t('nav_logout') ?> <?= t('کالا') ?></h3><button onclick="closeModal('checkoutModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="checkout">
            <div class="modal-body">
                <div class="form-group"><label class="form-label"><?= t('sal_type') ?> <?= t('nav_logout') ?> *</label>
                    <div class="d-flex gap-3" style="flex-wrap:wrap">
                        <label class="form-check"><input type="radio" name="checkout_kind" value="normal" checked onchange="rebuildCheckoutItems()"><span><?= t('sal_regular') ?> (<?= t('lea_from') ?> <?= t('inv_ready') ?>)</span></label>
                        <label class="form-check"><input type="radio" name="checkout_kind" value="consumable" onchange="rebuildCheckoutItems()"><span><?= t('مصرفی') ?></span></label>
                        <label class="form-check"><input type="radio" name="checkout_kind" value="custody" onchange="rebuildCheckoutItems()"><span><?= t('تحویلی / امانی 📦') ?></span></label>
                        <label class="form-check"><input type="radio" name="checkout_kind" value="repair" onchange="rebuildCheckoutItems()"><span><?= t('btn_submit') ?> <?= t('برای') ?> <?= t('تعمیر') ?> (<?= t('lea_from') ?> <?= t('سبد') ?> <?= t('تعمیر') ?>)</span></label>
                    </div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('کالا *') ?></label>
                    <input type="text" name="item_name" id="checkoutItemName" class="form-control" list="checkoutItemsList" placeholder="<?= t('دو حرف') ?> <?= t('اول') ?> <?= t('wh_name') ?> <?= t('کالا') ?> <?= t('را') ?> <?= t('تایپ') ?> <?= t('کنید') ?>..." autocomplete="off" required>
                    <input type="hidden" name="item_id" id="checkoutItemId">
                    <div class="text-muted" style="font-size:10px;margin-top:3px" id="checkoutItemHint"><?= t('فقط') ?> <?= t('کالاهای') ?> <?= t('دارای') ?> <?= t('inv_stock') ?> <?= t('btn_show') ?> <?= t('داده') ?> <?= t('می‌') ?><?= t('شوند') ?></div>
                </div>
                <div id="qtyWarn" style="display:none;font-size:11px;margin-bottom:8px;padding:8px 10px;background:#fee2e2;border-radius:8px;color:#b91c1c;font-weight:bold"></div>
                <div id="reissueHint" class="text-muted" style="display:none;font-size:11px;margin-bottom:8px;padding:8px 10px;background:#f3e8ff;border-radius:8px"></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('تعداد') ?> *</label><input type="number" name="quantity" class="form-control" required min="0.01" step="0.01" id="checkoutQty" oninput="qtyCheck()"></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?> *</label><input type="text" name="checkout_date" class="form-control jalali-date" value="<?= jalali_now() ?>" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_hours') ?></label><input type="time" name="checkout_time" class="form-control" value="<?= date('H:i') ?>"></div>
                    <div class="form-group"><label class="form-label"><?= t('inv_checkout') ?><?= t('‌دهنده') ?></label><input type="text" name="delivered_by" class="form-control" placeholder="<?= t('wh_name') ?> <?= t('anbar_keeper') ?>"></div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('inv_checkout') ?> <?= t('گیرنده *') ?></label>
                    <input type="text" name="person_name" class="form-control person-pick" data-target="checkoutPersonId" required list="personList" placeholder="<?= t('دو حرف') ?> <?= t('اول') ?> <?= t('wh_name') ?> <?= t('نیرو') ?> <?= t('را') ?> <?= t('تایپ') ?> <?= t('کنید') ?>..." autocomplete="off">
                    <input type="hidden" name="person_id" id="checkoutPersonId">
                    <div class="text-muted" style="font-size:10px;margin-top:3px"><?= t('lea_from') ?> <?= t('لیست') ?> <?= t('nav_personnel') ?> <?= t('کارگاه') ?> <?= t('پیشنهاد') ?> <?= t('داده') ?> <?= t('می‌شود') ?> (<?= t('تایپ آزاد') ?> <?= t('هم') ?> <?= t('مجاز است') ?>)</div>
                </div>
                <div id="earlyApproveBox" style="display:none;padding:12px;background:rgba(234,179,8,0.12);border:1px solid rgba(234,179,8,0.4);border-radius:8px;margin-bottom:10px">
                    <label class="form-check"><input type="checkbox" name="early_approve" value="1"><span>⚠️ <?= t('تأیید') ?> <?= t('مدیر') ?> <?= t('برای') ?> <?= t('inv_early_reissue') ?></span></label>
                    <div class="form-group" style="margin-top:8px;margin-bottom:0"><label class="form-label"><?= t('adv_reason') ?> <?= t('inv_early_reissue') ?> *</label><input type="text" name="early_reason" class="form-control" placeholder="<?= t('مثلاً') ?>: <?= t('دستکش') ?> <?= t('قبلی') ?> <?= t('پاره') ?> <?= t('شد') ?>"></div>
                </div>
                <div id="repairFields" style="display:none;padding:12px;background:var(--bg);border-radius:8px;margin-bottom:10px">
                    <div class="form-group"><label class="form-label"><?= t('پیمانکار') ?> <?= t('تعمیرکار *') ?></label>
                        <select name="repair_subcontractor_id" class="form-control" id="repairSub">
                            <option value=""><?= t('انتخاب کنید') ?></option>
                            <?php foreach ($subcontractors as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= $s['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0"><label class="form-label"><?= t('مبلغ') ?> <?= t('تعمیر') ?> (<?= t('sal_toman') ?>)</label><input type="number" name="repair_cost" class="form-control" min="0"></div>
                </div>
                <div class="form-group mt-2"><label class="form-label"><?= t('wh_note') ?></label><input type="text" name="notes" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-success"><?= t('wh_checkout_title') ?></button></div>
        </form>
    </div>
</div>

<!-- Return Modal -->
<div id="returnModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('برگشت') ?> <?= t('کالا') ?></h3><button onclick="closeModal('returnModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="return_item">
            <input type="hidden" name="tx_id" id="return_tx_id">
            <div class="modal-body">
                <p><?= t('کالا:') ?> <strong id="return_item_name"></strong></p>
                <p><?= t('inv_checkout') ?> <?= t('گیرنده') ?>: <strong id="return_person"></strong></p>
                <p><?= t('تعداد') ?> <?= t('nav_logout') ?>: <strong id="return_qty"></strong></p>
                <div class="form-group" id="returnConditionBox"><label class="form-label"><?= t('wh_status') ?> <?= t('کالای') ?> <?= t('برگشتی') ?> *</label>
                    <div class="d-flex gap-3">
                        <label class="form-check"><input type="radio" name="return_condition" value="ok" checked><span>✅ <?= t('سالم') ?> (<?= t('به') ?> <?= t('inv_ready') ?>)</span></label>
                        <label class="form-check"><input type="radio" name="return_condition" value="repair"><span>🔧 <?= t('inv_repair') ?></span></label>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?> <?= t('دریافت') ?></label><input type="text" name="return_date" class="form-control jalali-date" value="<?= jalali_now() ?>"></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_hours') ?> <?= t('دریافت') ?></label><input type="time" name="return_time" class="form-control" value="<?= date('H:i') ?>"></div>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-success"><?= t('ثبت') ?> <?= t('برگشت') ?></button></div>
        </form>
    </div>
</div>

<!-- Edit Checkout Modal -->
<div id="editCheckoutModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:550px">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_edit') ?> <?= t('nav_logout') ?> <?= t('کالا') ?></h3><button onclick="closeModal('editCheckoutModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="edit_checkout">
            <input type="hidden" name="tx_id" id="ec_tx_id">
            <input type="hidden" name="item_id" id="ec_item_id">
            <div class="modal-body">
                <p><?= t('کالا:') ?> <strong id="ec_item_name"></strong></p>
                <div class="form-group" id="ecKindBox"><label class="form-label"><?= t('sal_type') ?> <?= t('nav_logout') ?></label>
                    <div class="d-flex gap-3" style="flex-wrap:wrap">
                        <label class="form-check"><input type="radio" name="edit_kind" value="normal" id="ec_kind_normal"><span><?= t('sal_regular') ?></span></label>
                        <label class="form-check"><input type="radio" name="edit_kind" value="consumable" id="ec_kind_consumable"><span><?= t('مصرفی') ?></span></label>
                        <label class="form-check"><input type="radio" name="edit_kind" value="custody" id="ec_kind_custody"><span><?= t('تحویلی / امانی 📦') ?></span></label>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('تعداد') ?> *</label><input type="number" name="quantity" id="ec_qty" class="form-control" required min="0.01" step="0.01"></div>
                    <div class="form-group"><label class="form-label"><?= t('wh_date') ?> <?= t('nav_logout') ?> *</label><input type="text" name="checkout_date" id="ec_date" class="form-control jalali-date" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('wh_hours') ?></label><input type="time" name="checkout_time" id="ec_time" class="form-control"></div>
                    <div class="form-group"><label class="form-label"><?= t('inv_checkout') ?><?= t('‌دهنده') ?></label><input type="text" name="delivered_by" id="ec_delivered" class="form-control"></div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('inv_checkout') ?> <?= t('گیرنده *') ?></label>
                    <input type="text" name="person_name" id="ec_person" class="form-control person-pick" data-target="ecPersonId" required list="personList" autocomplete="off">
                    <input type="hidden" name="person_id" id="ecPersonId">
                </div>
                <div class="form-group"><label class="form-label"><?= t('wh_note') ?></label><input type="text" name="notes" id="ec_notes" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_save') ?></button></div>
        </form>
    </div>
</div>

<!-- Delete Tx Modal -->
<div id="delTxModal" class="modal-overlay hidden">
    <div class="modal glass-modal" style="max-width:440px">
        <div class="modal-header"><h3 class="modal-title">🗑 <?= t('btn_delete') ?> <?= t('رکورد') ?> <?= t('nav_inventory') ?></h3><button onclick="closeModal('delTxModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="delete_tx">
            <input type="hidden" name="tx_id" id="dtx_id">
            <input type="hidden" name="back_tab" id="dtx_back" value="history">
            <div class="modal-body">
                <p><?= t('رکورد') ?>: <strong id="dtx_label"></strong></p>
                <p style="font-size:13px"><?= t('اثر روی') ?> <?= t('inv_stock') ?>: <strong id="dtx_effect" style="color:var(--primary)"></strong></p>
                <p class="text-muted" style="font-size:11px"><?= t('رکورد') ?> <?= t('به‌صورت نرم') ?> <?= t('btn_delete') ?> <?= t('می‌شود') ?> (<?= t('در') ?> <?= t('تاریخچهٔ') ?> <?= t('تغییرات') ?> <?= t('ثبت') ?> <?= t('می‌گردد') ?>) <?= t('و') ?> <?= t('inv_stock') ?> <?= t('nav_inventory') ?> <?= t('wh_open') ?><?= t('btn_calculate') ?> <?= t('می‌شود') ?>.</p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('delTxModal')" class="btn btn-ghost"><?= t('btn_cancel') ?></button>
                <button type="submit" class="btn btn-danger"><?= t('تأیید') ?> <?= t('btn_delete') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer Modal -->
<div id="transferModal" class="modal-overlay hidden">
    <div class="modal glass-modal">
        <div class="modal-header"><h3 class="modal-title"><?= t('btn_transfer') ?> <?= t('به') ?> <?= t('کارگاه') ?> <?= t('دیگر') ?></h3><button onclick="closeModal('transferModal')" class="modal-close">✕</button></div>
        <form method="POST"><?= get_csrf_field() ?><input type="hidden" name="action" value="transfer">
            <input type="hidden" name="item_id" id="transfer_item_id">
            <div class="modal-body">
                <p><?= t('کالا:') ?> <strong id="transfer_item_name"></strong> (<?= t('قابل') ?> <?= t('btn_transfer') ?>: <span id="transfer_max"></span>)</p>
                <div class="form-group"><label class="form-label"><?= t('تعداد') ?> *</label><input type="number" name="quantity" id="transfer_qty" class="form-control" required min="0.01" step="0.01"></div>
                <div class="form-group"><label class="form-label"><?= t('per_to_workshop') ?> *</label>
                    <select name="to_workshop" class="form-control" required>
                        <option value=""><?= t('انتخاب کنید') ?></option>
                        <?php foreach ($other_workshops as $w): ?>
                        <option value="<?= $w['id'] ?>"><?= $w['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= t('btn_transfer') ?></button></div>
        </form>
    </div>
</div>

<style>
@keyframes inventoryHighlight { 0%,100%{background:transparent} 50%{background:#fef3c7} }
.inventory-highlight-row td { background:#fef3c7 !important; animation:inventoryHighlight 1.15s ease-in-out 4; }
</style>
<script>
// ═══ <?= t('داده') ?>‌<?= t('های') ?> کالاها <?= t('و') ?> <?= t('پرسنل') ?> <?= t('برای') ?> <?= t('پیشنهاد') ?> <?= t('خودکار') ?> ═══
var INV_ITEMS = <?= json_encode(array_map(fn($i) => [
    'id' => (int)$i['id'], 'name' => $i['name'], 'unit' => $i['unit'],
    'ready' => (float)$i['quantity'], 'repair' => (float)($i['repair_quantity'] ?? 0),
    'reissue' => (int)($i['reissue_days'] ?? 0)
], $items), JSON_UNESCAPED_UNICODE) ?>;
var INV_PERSONS = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['full_name']], $ws_personnel), JSON_UNESCAPED_UNICODE) ?>;

function invNorm(s) {
    return (s || '').toString().trim().toLowerCase()
        .replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/‌/g, ' ').replace(/\s+/g, ' ');
}
function invFindPerson(name) {
    var k = invNorm(name);
    for (var i = 0; i < INV_PERSONS.length; i++) if (invNorm(INV_PERSONS[i].name) === k) return INV_PERSONS[i];
    return null;
}
function invFindItem(name) {
    var k = invNorm(name);
    for (var i = 0; i < INV_ITEMS.length; i++) if (invNorm(INV_ITEMS[i].name) === k) return INV_ITEMS[i];
    return null;
}

// فیلدهای شخص: تطبیق خودکار نام → آیدی مخفی
document.querySelectorAll('.person-pick').forEach(function(inp) {
    inp.addEventListener('input', function() {
        var t = document.getElementById(inp.dataset.target);
        if (t) { var p = invFindPerson(inp.value); t.value = p ? p.id : ''; }
    });
});

// ── <?= t('افزودن') ?> <?= t('کالا:') ?> <?= t('هشدار') ?> <?= t('ادغام') ?> <?= t('در') ?> <?= t('صورت') ?> <?= t('وجود') ?> <?= t('نام') ?> ──
var addItemName = document.getElementById('addItemName');
if (addItemName) addItemName.addEventListener('input', function() {
    var hint = document.getElementById('addItemMergeHint');
    var it = invFindItem(addItemName.value);
    if (it) { hint.style.display = 'block'; hint.textContent = '🔄 <?= t('کالای') ?> «' + it.name + '» (<?= t('آماده:') ?> ' + it.ready + ' ' + it.unit + ') <?= t('lea_from') ?> <?= t('قبل') ?> <?= t('موجود') ?> <?= t('است') ?> — <?= t('مقدار') ?> <?= t('جدید') ?> <?= t('به') ?> <?= t('inv_stock') ?> <?= t('همان') ?> <?= t('اضافه') ?> <?= t('می‌شود') ?>.'; }
    else { hint.style.display = 'none'; }
});

// ── <?= t('خروج') ?> <?= t('کالا:') ?> <?= t('پر کردن') ?> datalist <?= t('بر اساس') ?> <?= t('نوع') ?> <?= t('خروج') ?> ──
function curKind() {
    var r = document.querySelector('#checkoutModal input[name="checkout_kind"]:checked');
    return r ? r.value : 'normal';
}
function rebuildCheckoutItems() {
    var kind = curKind();
    var dl = document.getElementById('checkoutItemsList');
    dl.innerHTML = '';
    var n = 0;
    INV_ITEMS.forEach(function(it) {
        var avail = (kind === 'repair') ? it.repair : it.ready;
        if (avail <= 0) return;
        var opt = document.createElement('option');
        opt.value = it.name;
        opt.label = (kind === 'repair' ? '<?= t('inv_repair') ?>: ' : '<?= t('آماده:') ?> ') + avail + ' ' + it.unit;
        dl.appendChild(opt);
        n++;
    });
    document.getElementById('checkoutItemHint').textContent = n ? ('✅ ' + n + ' <?= t('کالای') ?> <?= t('دارای') ?> <?= t('inv_stock') ?> — <?= t('با') ?> <?= t('تایپ') ?> <?= t('دو حرف') ?> <?= t('پیشنهاد') ?> <?= t('داده') ?> <?= t('می‌شود') ?>') : '⚠️ <?= t('کالایی') ?> <?= t('با') ?> <?= t('inv_stock') ?> <?= t('برای') ?> <?= t('این') ?> <?= t('sal_type') ?> <?= t('nav_logout') ?> <?= t('نیست') ?>';
    itemChanged();
}
function itemAvail() {
    var it = invFindItem(document.getElementById('checkoutItemName').value);
    if (!it) return null;
    return (curKind() === 'repair') ? it.repair : it.ready;
}
function qtyCheck() {
    var q = parseFloat(document.getElementById('checkoutQty').value) || 0;
    var a = itemAvail();
    var w = document.getElementById('qtyWarn');
    if (a !== null && q > a) {
        w.style.display = 'block';
        w.textContent = '⚠️ <?= t('تعداد') ?> (' + q + ') <?= t('بیشتر') ?> <?= t('lea_from') ?> <?= t('inv_stock') ?> <?= t('قابل') ?> <?= t('nav_logout') ?> (' + a + ') <?= t('است') ?>!';
        return false;
    }
    w.style.display = 'none';
    return true;
}
function itemChanged() {
    var nameInp = document.getElementById('checkoutItemName');
    var it = invFindItem(nameInp.value);
    var kind = curKind();
    var hint = document.getElementById('reissueHint');
    var early = document.getElementById('earlyApproveBox');
    var rep = document.getElementById('repairFields');
    document.getElementById('checkoutItemId').value = it ? it.id : '';
    hint.style.display = 'none';
    early.style.display = 'none';
    rep.style.display = 'none';
    if (kind === 'repair') {
        rep.style.display = 'block';
        hint.style.display = 'block';
        hint.textContent = '🔧 <?= t('این') ?> <?= t('nav_logout') ?> <?= t('lea_from') ?> <?= t('سبد') ?> «<?= t('inv_repair') ?>» <?= t('کم') ?> <?= t('می‌شود') ?>' + (it ? ' (<?= t('موجود') ?>: ' + it.repair + ')' : '');
    } else if (kind === 'custody') {
        hint.style.display = 'block';
        hint.textContent = '📦 <?= t('امانی') ?> <?= t('lea_from') ?> <?= t('سبد') ?> «<?= t('inv_ready') ?>» <?= t('کم') ?> <?= t('می‌شود') ?> <?= t('و') ?> <?= t('lea_to') ?> <?= t('زمان') ?> <?= t('برگشت') ?>/<?= t('تسویه') ?>، <?= t('نزد شخص') ?> <?= t('ثبت') ?> <?= t('می‌ماند') ?>' + (it ? ' (<?= t('آماده:') ?> ' + it.ready + ' ' + it.unit + ')' : '');
    } else if (kind === 'consumable' && it && it.reissue > 0) {
        early.style.display = 'block';
        hint.style.display = 'block';
        hint.textContent = '⏱ <?= t('این') ?> <?= t('کالا') ?> <?= t('بازه') ?> <?= t('inv_checkout') ?> <?= t('مجدد') ?> ' + it.reissue + ' <?= t('wh_day') ?> <?= t('دارد') ?> — <?= t('اگر') ?> <?= t('برای') ?> <?= t('همین') ?> <?= t('شخص') ?> <?= t('زودتر') ?> <?= t('lea_from') ?> <?= t('بازه') ?> <?= t('ثبت') ?> <?= t('شود') ?>، <?= t('فقط') ?> <?= t('با') ?> <?= t('تأیید') ?> <?= t('مدیر') ?> + <?= t('adv_reason') ?> <?= t('ثبت') ?> <?= t('می‌شود') ?>.';
    }
    document.getElementById('checkoutQty').max = it ? itemAvail() : '';
    qtyCheck();
}
document.getElementById('checkoutItemName').addEventListener('input', itemChanged);
document.querySelector('#checkoutModal form').addEventListener('submit', function(e) {
    var it = invFindItem(document.getElementById('checkoutItemName').value);
    if (!it) { e.preventDefault(); alert('<?= t('کالا') ?> <?= t('را') ?> <?= t('lea_from') ?> <?= t('لیست') ?> <?= t('پیشنهادی') ?> <?= t('انتخاب کنید') ?>'); return false; }
    if (!qtyCheck()) { e.preventDefault(); alert('<?= t('تعداد') ?> <?= t('بیشتر') ?> <?= t('lea_from') ?> <?= t('inv_stock') ?> <?= t('است') ?>'); return false; }
});
// پر کردن datalist هنگام لود صفحه + هر بار که فیلد کالا فوکوس می‌شود (نوع خروج ممکن است عوض شده باشد)
document.getElementById('checkoutItemName').addEventListener('focus', rebuildCheckoutItems);
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', rebuildCheckoutItems); else rebuildCheckoutItems();
</script>

<script>
function editItem(item) {
    try {

    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_item_name').value = item.name;
    document.getElementById('edit_item_sku').value = item.sku || '';
    document.getElementById('edit_item_barcode').value = item.barcode || '';
    document.getElementById('edit_item_unit').value = item.unit || '<?= t('عدد') ?>';
    document.getElementById('edit_item_qty').value = item.quantity;
    document.getElementById('edit_item_repair').value = item.repair_quantity || 0;
    document.getElementById('edit_item_min').value = item.min_quantity || 0;
    document.getElementById('edit_item_reissue').value = item.reissue_days || 0;
    document.getElementById('edit_item_cat').value = item.category || '';
    document.getElementById('edit_item_desc').value = item.description || '';
    document.getElementById('edit_asset_price').value = item.asset_purchase_price || 0;
    document.getElementById('edit_asset_date').value = item.asset_purchase_date ? toJalali(item.asset_purchase_date) : '';
    document.getElementById('edit_asset_life').value = item.useful_life_months || 0;
        openModal('editItemModal');
    } catch(e){ console.error('editItem error', e); alert('خطا در باز کردن ویرایش کالا: '+e.message); }
}
function transferItem(id, name, qty) {
    try {
    document.getElementById('transfer_item_id').value = id;
    document.getElementById('transfer_item_name').textContent = name;
        document.getElementById('transfer_max').textContent = qty;
        document.getElementById('transfer_qty').max = qty;
        openModal('transferModal');
    } catch(e){ console.error('transferItem error', e); }
}
function showReturnModal(txId, name, qty, person, isRepairSend) {
    try {
    document.getElementById('return_tx_id').value = txId;
    document.getElementById('return_item_name').textContent = name;
    document.getElementById('return_qty').textContent = qty;
    document.getElementById('return_person').textContent = person;
    // <?= t('برگشت') ?> <?= t('از') ?> <?= t('تعمیرکار') ?>: <?= t('همیشه') ?> <?= t('سالم') ?> (تعمیرشده) <?= t('به') ?> <?= t('آماده تحویل') ?> برمی‌گردد
    document.getElementById('returnConditionBox').style.display = isRepairSend ? 'none' : 'block';
    openModal('returnModal');
    } catch(e){ console.error('showReturnModal error', e); alert('خطا در باز کردن فرم برگشت: '+e.message); }
}
function editCheckout(t) {
    try {
    document.getElementById('ec_tx_id').value = t.id;
    document.getElementById('ec_item_id').value = t.item_id;
    document.getElementById('ec_item_name').textContent = t.item_name;
    document.getElementById('ec_qty').value = t.quantity;
    document.getElementById('ec_date').value = t.checkout_date_jalali || t.checkout_date || '';
    document.getElementById('ec_time').value = t.checkout_time || '';
    document.getElementById('ec_delivered').value = t.delivered_by || '';
    document.getElementById('ec_person').value = t.person_name || '';
    document.getElementById('ec_notes').value = t.notes || '';
    // <?= t('نوع') ?> <?= t('خروج') ?>: <?= t('برای') ?> «<?= t('ارسال') ?> <?= t('تعمیر') ?>» <?= t('امکان') ?> <?= t('تغییر') ?> <?= t('نوع') ?> <?= t('نیست') ?> (<?= t('سبد') ?>/<?= t('پیمانکار') ?> <?= t('جدا') ?> <?= t('دارد') ?>)
    var kind = t.eff_type || (t.is_consumable == 1 ? 'consumable' : 'normal');
    document.getElementById('ecKindBox').style.display = (kind === 'repair') ? 'none' : 'block';
    ['normal','consumable','custody'].forEach(function(k) {
        document.getElementById('ec_kind_' + k).checked = (k === kind);
    });
        var pid = document.getElementById('ecPersonId');
        var p = invFindPerson(t.person_name || '');
        pid.value = p ? p.id : '';
        openModal('editCheckoutModal');
    } catch(e){ console.error('editCheckout error', e); alert('خطا در ویرایش خروج: '+e.message); }
}
function delTx(info, backTab) {
    try {
    document.getElementById('dtx_id').value = info.id;
    document.getElementById('dtx_label').textContent = info.label;
    document.getElementById('dtx_effect').textContent = info.effect;
        document.getElementById('dtx_back').value = backTab || 'history';
        openModal('delTxModal');
    } catch(e){ console.error('delTx error', e); }
}
</script>

<script>
// جست‌وجوی زندهٔ <?= t('موجودی') ?>: <?= t('تا') ?> <?= t('قبل') ?> <?= t('از') ?> دو حرف، <?= t('همهٔ') ?> <?= t('اقلام') ?> <?= t('دیده') ?> <?= t('می‌') ?><?= t('شوند') ?>.
(function () {
    var input = document.getElementById('inventoryLiveSearch');
    if (!input) return;
    function norm(v) { return (v || '').toString().trim().toLowerCase().replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/‌/g,' ').replace(/\s+/g,' '); }
    function filter() {
        var query = norm(input.value);
        var enabled = query.length >= 2;
        var visible = 0;
        document.querySelectorAll('.inventory-stock-row').forEach(function (row) {
            var match = !enabled || norm(row.getAttribute('data-inventory-search')).indexOf(query) !== -1;
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        var empty = document.getElementById('inventorySearchEmpty');
        if (empty) empty.style.display = enabled && visible === 0 ? '' : 'none';
    }
    input.addEventListener('input', filter);
})();
</script>

<script>
(function(){
    var row=document.querySelector('.inventory-highlight-row');
    if(row) setTimeout(function(){row.scrollIntoView({behavior:'smooth',block:'center'});},180);
})();
</script>

<?php include 'includes/footer.php'; ?>
