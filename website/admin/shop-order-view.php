<?php
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/shop/Orders.php';
require_once BASE_PATH . '/includes/shop/Price.php';
require_once BASE_PATH . '/includes/shop/Jalali.php';
// ------------------
Auth::requirePermission('shop.orders');

$id = (int)($_GET['id'] ?? 0);
$order = Shop\Orders::byId($id);
if (!$order) {
    die('سفارش یافت نشد');
}
$pageTitle = 'سفارش ' . $order['order_no'];
$activeMenu = 'shop-orders';
?>
<?php ob_start(); ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            سفارش <span dir="ltr"><?= htmlspecialchars($order['order_no']) ?></span>
            <span class="badge <?= $order['order_status'] === 'delivered' ? 'success' : ($order['order_status'] === 'cancelled' ? 'error' : 'warning') ?>" style="margin-inline-start:10px;">
                <?= Shop\Orders::STATUS_LABELS[$order['order_status']] ?>
            </span>
        </h2>
        <a href="shop-orders.php" class="btn btn-secondary">← لیست سفارش‌ها</a>
    </div>

    <div class="form-grid">
        <div>
            <h3 style="color:var(--text-strong);">📍 اطلاعات ارسال</h3>
            <div style="line-height:2.2;">
                <div><strong><?= htmlspecialchars($order['customer_name']) ?></strong></div>
                <div>📞 <span dir="ltr"><?= htmlspecialchars($order['customer_phone']) ?></span></div>
                <?php if ($order['customer_email']): ?><div>✉️ <span dir="ltr"><?= htmlspecialchars($order['customer_email']) ?></span></div><?php endif; ?>
                <div>🏠 <?= htmlspecialchars($order['address']) ?></div>
                <?php if ($order['city'] || $order['province']): ?><div>📌 <?= htmlspecialchars(trim($order['province'] . '، ' . $order['city'])) ?> — کد پستی: <?= htmlspecialchars($order['postal_code'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($order['notes']): ?><div style="background:rgba(136,146,176,.1); border-radius:8px; padding:10px 14px; margin-top:8px;">📝 <?= htmlspecialchars($order['notes']) ?></div><?php endif; ?>
            </div>
            <?php if ($order['customer_id']): ?>
                <div style="margin-top:10px;"><small style="color:var(--text-2);">مشتری ثبت‌شده در سیستم (ID: <?= (int)$order['customer_id'] ?>)</small></div>
            <?php endif; ?>
            <div style="margin-top:8px; color:var(--text-2); font-size:.85rem;">
                ثبت: <?= Shop\Jalali::dateTime($order['created_at']) ?>
                <?php if ($order['usd_rate']): ?> — نرخ دلار ثبت: <?= Shop\Price::faNumber((int)$order['usd_rate']) ?> تومان
            <?php endif; ?>
            </div>
        </div>

        <div>
            <h3 style="color:var(--text-strong);">⚙️ مدیریت وضعیت</h3>
            <p>پرداخت و استرداد اینجا دستی ثبت می‌شوند؛ درگاه یا بازپرداخت خودکار وجود ندارد. سفارش ارسال‌شده برای مرجوعی نیاز به بررسی دستی دارد.</p>
            <div class="form-field" style="margin-bottom:12px;">
                <label>وضعیت سفارش</label>
                <select id="orderStatus">
                    <?php foreach (Shop\Orders::STATUS_LABELS as $v => $label): ?>
                        <option value="<?= $v ?>" <?= $order['order_status'] === $v ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field" style="margin-bottom:12px;">
                <label>وضعیت پرداخت</label>
                <select id="payStatus">
                    <?php foreach (Shop\Orders::PAYMENT_LABELS as $v => $label): ?>
                        <option value="<?= $v ?>" <?= $order['payment_status'] === $v ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field" style="margin-bottom:12px;">
                <label>یادداشت برای مشتری (در تاریخچه سفارش نمایش داده می‌شود)</label>
                <input type="text" id="statusNote" placeholder="مثلاً: سفارش شما فردا ارسال می‌شود" maxlength="500">
            </div>
            <button class="btn btn-primary" onclick="updateOrder()">💾 به‌روزرسانی وضعیت</button>
            <?php if ($order['order_status'] !== 'cancelled'): ?>
                <button class="btn btn-danger" style="margin-inline-start:8px;" onclick="cancelOrder()">لغو سفارش و برگشت موجودی</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2 class="card-title">اقلام سفارش</h2></div>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead><tr><th>تصویر</th><th>کالا</th><th>قیمت واحد</th><th>تعداد</th><th>جمع</th></tr></thead>
            <tbody>
            <?php foreach ($order['items'] as $it): ?>
                <tr>
                    <td><?php if ($it['product_image']): ?><img src="<?= htmlspecialchars($it['product_image']) ?>" style="width:52px;height:52px;border-radius:8px;object-fit:cover;background:#f4f5f7;" alt=""><?php else: ?>—<?php endif; ?></td>
                    <td><?= htmlspecialchars($it['product_name']) ?></td>
                    <td><?= Shop\Price::faNumber((int)$it['price_toman']) ?></td>
                    <td><?= Shop\Jalali::toPersianDigits((int)$it['qty']) ?></td>
                    <td style="color:#d4af37; font-weight:700;"><?= Shop\Price::faNumber((int)$it['total_toman']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top:14px; display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
        <span>جمع کالاها: <strong><?= Shop\Price::faNumber((int)$order['subtotal_toman']) ?> تومان</strong></span>
        <?php if ($order['discount_toman']): ?><span>تخفیف (<?= htmlspecialchars((string)$order['coupon_code']) ?>): <strong style="color:var(--success);">− <?= Shop\Price::faNumber((int)$order['discount_toman']) ?></strong></span><?php endif; ?>
        <span>هزینه ارسال: <strong><?= $order['shipping_fee'] ? Shop\Price::faNumber((int)$order['shipping_fee']) . ' تومان' : 'رایگان' ?></strong></span>
        <span style="font-size:1.15rem;">مبلغ کل: <strong style="color:#d4af37;"><?= Shop\Price::faNumber((int)$order['total_toman']) ?> تومان</strong></span>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <div class="card-header"><h2 class="card-title">تاریخچه وضعیت</h2></div>
    <ul style="list-style:none; padding:0; margin:0;">
        <?php foreach ($order['events'] as $i => $ev): ?>
            <li style="position:relative; padding:0 0 18px 26px; border-right:2px solid rgba(136,146,176,.25); margin-right:6px;">
                <span style="position:absolute; right:-7px; top:3px; width:12px; height:12px; border-radius:50%; background:<?= $i === 0 ? '#d4af37' : '#3a4358' ?>;"></span>
                <div style="font-weight:700;"><?= htmlspecialchars(Shop\Orders::STATUS_LABELS[$ev['status']] ?? Shop\Orders::EVENT_LABELS[$ev['status']] ?? $ev['status'], ENT_QUOTES, 'UTF-8') ?></div>
                <div style="color:var(--text-2); font-size:.8rem;"><?= Shop\Jalali::dateTime($ev['created_at']) ?></div>
                <?php if ($ev['note']): ?><div style="font-size:.88rem;"><?= htmlspecialchars($ev['note']) ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<script>
async function updateOrder() {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const body = new URLSearchParams({
        action: 'update', id: <?= $id ?>,
        order_status: document.getElementById('orderStatus').value,
        payment_status: document.getElementById('payStatus').value,
        note: document.getElementById('statusNote').value,
        expected_status: <?= json_encode($order['order_status']) ?>,
        expected_payment: <?= json_encode($order['payment_status']) ?>,
        expected_event_id: <?= (int)$order['event_version'] ?>
    });
    const res = await fetch('api/shop-orders.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        body
    });
    const r = await res.json();
    if (r.success) location.reload();
    else alert(r.error || 'خطا');
}
async function cancelOrder() {
    if (!confirm('با لغو سفارش، موجودی محصولات برگشتانده می‌شود. مطمئن هستید؟')) return;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const res = await fetch('api/shop-orders.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({action:'update', id:<?= $id ?>, order_status:'cancelled', expected_status:<?= json_encode($order['order_status']) ?>, expected_payment:<?= json_encode($order['payment_status']) ?>, expected_event_id:<?= (int)$order['event_version'] ?>, note:'سفارش توسط ادمین لغو شد'})
    });
    const r = await res.json();
    if (r.success) location.reload();
    else alert(r.error || 'خطا');
}
</script>
<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
