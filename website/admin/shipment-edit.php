<?php
declare(strict_types=1);

// --- Bootstrap (لود کردن تنظیمات و کلاس‌های پایه) ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
// ----------------------------------------------------
Auth::requirePermission('shipments.view');
$pageTitle = 'جزئیات محموله';
$activeMenu = 'shipments';

$id = (int) ($_GET['id'] ?? 0);
$shipment = $id ? DB::fetch('SELECT s.*, u.full_name AS creator_name FROM shipments s LEFT JOIN admin_users u ON u.id = s.created_by WHERE s.id = ?', [$id]) : null;
$events = $id ? DB::fetchAll('SELECT e.*, u.full_name AS author_name FROM shipment_events e LEFT JOIN admin_users u ON u.id = e.created_by WHERE shipment_id = ? ORDER BY event_time DESC', [$id]) : [];
?>
<?php ob_start(); ?>
<form id="shipForm">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">

    <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 20px;">
        <div>
            <div class="card">
                <h3 class="card-title" style="margin-bottom: 16px;">اطلاعات محموله</h3>
                <div class="form-row">
                    <div class="form-field">
                        <label>کد رهگیری (خالی = خودکار)</label>
                        <input type="text" name="tracking_code" dir="ltr" value="<?= htmlspecialchars($shipment['tracking_code'] ?? '') ?>" <?= $id ? 'readonly' : '' ?>>
                    </div>
                    <div class="form-field">
                        <label>سرویس</label>
                        <select name="service_type">
                            <?php foreach (['air' => 'هوایی', 'sea' => 'دریایی', 'land' => 'زمینی', 'multimodal' => 'چندوجهی'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= ($shipment['service_type'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label>نام مشتری <span class="required">*</span></label>
                        <input type="text" name="customer_name" required value="<?= htmlspecialchars($shipment['customer_name'] ?? '') ?>">
                    </div>
                    <div class="form-field">
                        <label>تلفن</label>
                        <input type="tel" name="customer_phone" dir="ltr" value="<?= htmlspecialchars($shipment['customer_phone'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-field">
                    <label>ایمیل</label>
                    <input type="email" name="customer_email" dir="ltr" value="<?= htmlspecialchars($shipment['customer_email'] ?? '') ?>">
                </div>

                <h4 style="margin: 20px 0 12px; color: var(--secondary);">مبدا</h4>
                <div class="form-row">
                    <div class="form-field">
                        <label>کشور <span class="required">*</span></label>
                        <input type="text" name="origin_country" required value="<?= htmlspecialchars($shipment['origin_country'] ?? '') ?>">
                    </div>
                    <div class="form-field">
                        <label>شهر</label>
                        <input type="text" name="origin_city" value="<?= htmlspecialchars($shipment['origin_city'] ?? '') ?>">
                    </div>
                </div>

                <h4 style="margin: 20px 0 12px; color: var(--secondary);">مقصد</h4>
                <div class="form-row">
                    <div class="form-field">
                        <label>کشور <span class="required">*</span></label>
                        <input type="text" name="destination_country" required value="<?= htmlspecialchars($shipment['destination_country'] ?? '') ?>">
                    </div>
                    <div class="form-field">
                        <label>شهر</label>
                        <input type="text" name="destination_city" value="<?= htmlspecialchars($shipment['destination_city'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label>وزن (kg)</label>
                        <input type="number" step="0.01" name="weight_kg" dir="ltr" value="<?= $shipment['weight_kg'] ?? '' ?>">
                    </div>
                    <div class="form-field">
                        <label>حجم (m³)</label>
                        <input type="number" step="0.001" name="volume_m3" dir="ltr" value="<?= $shipment['volume_m3'] ?? '' ?>">
                    </div>
                    <div class="form-field">
                        <label>تعداد بسته</label>
                        <input type="number" name="package_count" min="1" value="<?= $shipment['package_count'] ?? 1 ?>">
                    </div>
                </div>

                <div class="form-field">
                    <label>یادداشت</label>
                    <textarea name="notes"><?= htmlspecialchars($shipment['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <?php if ($id && Auth::can('shipments.edit')): ?>
            <div class="card">
                <h3 class="card-title" style="margin-bottom: 16px;">تاریخچه رویدادها</h3>
                <form id="eventForm">
                    <input type="hidden" name="shipment_id" value="<?= $id ?>">
                    <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
                    <div class="form-row">
                        <div class="form-field">
                            <label>نوع رویداد</label>
                            <select name="event_type">
                                <option value="registered">ثبت</option>
                                <option value="picked_up">جمع‌آوری</option>
                                <option value="in_transit">حرکت</option>
                                <option value="arrived">رسیدن</option>
                                <option value="customs_clearance">ترخیص</option>
                                <option value="out_for_delivery">تحویل</option>
                                <option value="delivered">تحویل نهایی</option>
                                <option value="on_hold">توقف</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>مکان</label>
                            <input type="text" name="location" placeholder="مثلاً: گمرک امام خمینی">
                        </div>
                    </div>
                    <div class="form-field">
                        <label>توضیحات</label>
                        <textarea name="description" required minlength="5"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">➕ ثبت رویداد</button>
                </form>

                <div style="margin-top: 20px;">
                    <?php foreach ($events as $ev): ?>
                        <div style="padding: 12px; border-right: 3px solid var(--primary-light); background: var(--bg); margin-bottom: 8px; border-radius: 6px;">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <strong style="color: var(--text-strong);"><?= htmlspecialchars($ev['description_fa']) ?></strong>
                                <small style="color: var(--text-2);"><?= date('Y/m/d H:i', strtotime($ev['event_time'])) ?></small>
                            </div>
                            <small style="color: var(--text-2);">
                                <?= htmlspecialchars($ev['location'] ?? '') ?>
                                · <?= htmlspecialchars($ev['author_name'] ?? '-') ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="card">
                <h3 class="card-title" style="margin-bottom: 16px;">وضعیت</h3>
                <div class="form-field">
                    <label>وضعیت فعلی</label>
                    <select name="status">
                        <?php foreach ([
                            'registered' => 'ثبت شده',
                            'picked_up' => 'جمع‌آوری شده',
                            'in_transit' => 'در حال حمل',
                            'customs_clearance' => 'ترخیص گمرک',
                            'out_for_delivery' => 'آماده تحویل',
                            'delivered' => 'تحویل شده',
                            'cancelled' => 'لغو شده',
                            'on_hold' => 'در انتظار',
                        ] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= ($shipment['status'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label>زمان تحویل تخمینی</label>
                    <input type="date" name="estimated_delivery" value="<?= $shipment['estimated_delivery'] ?? '' ?>">
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="is_public" value="1" <?= ($shipment['is_public'] ?? 1) ? 'checked' : '' ?>>
                    <span>قابل ردیابی عمومی</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-block">💾 ذخیره</button>
            <?php if ($id && $shipment): ?>
                <div class="card" style="margin-top: 16px;">
                    <p style="color: var(--text-2); font-size: 0.85rem; margin-bottom: 8px;">
                        ساخته شده توسط: <strong style="color:var(--text);"><?= htmlspecialchars($shipment['creator_name'] ?? '-') ?></strong><br>
                        تاریخ: <?= date('Y/m/d H:i', strtotime($shipment['created_at'])) ?>
                    </p>
                    <a href="/track/<?= urlencode($shipment['tracking_code']) ?>" target="_blank" class="btn btn-block">
                        🔗 لینک عمومی ردیابی
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

document.getElementById('shipForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'save');
    try {
        const r = await stsFetch('api/shipments.php', { method: 'POST', body: fd });
        if (r.success && !document.querySelector('[name="id"]').value) {
            location.href = '/admin/shipment-edit.php?id=' + r.id;
        } else {
            alert('ذخیره شد.');
        }
    } catch (err) { alert(err.message); }
});

document.getElementById('eventForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'add_event');
    try {
        await stsFetch('api/shipments.php', { method: 'POST', body: fd });
        location.reload();
    } catch (err) { alert(err.message); }
});
</script>

<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
