<?php
/**
 * ══ استعلام قیمت (Quote) ══
 * مسیر: کارشناس استعلام را زیر یک معامله ثبت و ارسال می‌کند → طبق «گردش‌کار استعلام»
 * (گراف Drawflow در «تنظیمات → گردش استعلام»، ذخیره در settings.quote_workflow_json)
 * مراحل طی می‌شود: تأیید مدیر فروش → اعلام قیمت نت (مدیرعامل/مسئول) →
 * تأیید نهایی + حاشیهٔ سود (مدیر فروش) → اعلام به مشتری و «فیکس» (کارشناس).
 *
 * هر استعلام snapshot گردش را در لحظهٔ ساخت نگه می‌دارد تا ویرایش بعدی گردش
 * استعلام‌های در جریان را نشکند. نرخ ارز در لحظهٔ ثبت قیمت نت snapshot می‌شود
 * (fx_snapshot_json) تا جمع‌ها همیشه پایدار باشند؛ جایگزینی بعدی با API ارز زنده
 * فقط همین فایل را لمس می‌کند (quote_fx_rates).
 */

/* ── اجزای قیمت خام (واردات کامل) ── */
function quote_components(): array {
    return [
        'factory_gate'           => 'قیمت درب کارخانه',
        'origin_transport'       => 'حمل داخل کشور مبدأ (تا مرز/بندر)',
        'international_transport' => 'حمل بین‌الملل (دریایی/هوایی/زمینی)',
        'insurance'              => 'بیمهٔ کالا',
        'customs_clearance'      => 'ترخیص و عوارض گمرکی',
        'remittance'             => 'حواله / کارمزد انتقال ارزی',
        'other'                  => 'سایر (متفرقه)',
    ];
}

function quote_currency_labels(): array {
    return ['USD' => 'دلار (USD)', 'CNY' => 'یوان (CNY)', 'TOM' => 'تومان', 'IRR' => 'ریال (IRR)'];
}

function ensure_quote_schema(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_quotes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            deal_id INT NULL,
            customer_id INT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            product_name VARCHAR(255) NULL,
            specs TEXT NULL,
            quantity DECIMAL(18,3) NULL,
            unit VARCHAR(30) NULL,
            hs_code VARCHAR(32) NULL,
            incoterm VARCHAR(16) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'in_flow',
            current_step INT NOT NULL DEFAULT 0,
            workflow_json MEDIUMTEXT NULL,
            fx_snapshot_json MEDIUMTEXT NULL,
            margin_pct DECIMAL(6,2) NULL,
            total_cost_toman DECIMAL(20,2) NULL,
            fixed_sell_toman DECIMAL(20,2) NULL,
            fixed_at DATETIME NULL,
            fixed_by INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_quote_deal (deal_id),
            KEY idx_quote_customer (customer_id),
            KEY idx_quote_creator (created_by),
            KEY idx_quote_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS quote_cost_lines (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quote_id INT UNSIGNED NOT NULL,
            component_code VARCHAR(40) NOT NULL,
            label VARCHAR(120) NOT NULL,
            amount DECIMAL(20,4) NULL,
            currency VARCHAR(6) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            KEY idx_qcl_quote (quote_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS quote_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quote_id INT UNSIGNED NOT NULL,
            actor_user_id INT NULL,
            step_index INT NULL,
            step_type VARCHAR(24) NULL,
            action VARCHAR(24) NOT NULL,
            note TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_qh_quote (quote_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ins = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $ins->execute(['quote_default_margin_pct', '25']);
        $ins->execute(['fx_to_toman_TOM', '1']);
        $done = true;
    } catch (Throwable $e) {
        $done = false;
    }
    return $done;
}

/* ── نرخ ارز به تومان (نقطه‌ای که بعدها به API زنده تبدیل می‌شود) ── */
function quote_fx_rates(PDO $pdo): array {
    // نرخ مؤثر = زنده (اگر «نرخ لحظه‌ای» فعال و کش معتبر باشد) یا دستی — یک منبع برای همهٔ موتورها
    if (!function_exists('fx_effective_rates')) require_once __DIR__ . '/fx.php';
    $eff = function_exists('fx_effective_rates') ? fx_effective_rates($pdo) : [];
    $get = function (string $k, string $def) use ($pdo, $eff) {
        if (isset($eff[$k]) && $eff[$k] > 0) return (float)$eff[$k];
        try {
            $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
            $st->execute(['fx_to_toman_' . $k]);
            $v = $st->fetchColumn();
            return ($v === false || $v === '') ? (float)$def : (float)$v;
        } catch (Throwable $e) { return (float)$def; }
    };
    return [
        'USD'  => $get('USD', '0'),
        'CNY'  => $get('CNY', '0'),
        'TOM'  => 1.0,
        'IRR'  => isset($eff['IRR']) ? (float)$eff['IRR'] : $get('IRR', '0.1'),
        'EUR'  => $get('EUR', '0'),
        'AED'  => $get('AED', '0'),
        'GBP'  => $get('GBP', '0'),
        'USDT' => $get('USDT', '0'),
    ];
}

function quote_rates_for(PDO $pdo, ?array $quote): array {
    if ($quote && !empty($quote['fx_snapshot_json'])) {
        $d = json_decode((string)$quote['fx_snapshot_json'], true);
        if (is_array($d) && $d) {
            foreach (['USD','CNY','TOM','IRR'] as $k) { if (!isset($d[$k])) $d[$k] = ($k === 'TOM' ? 1.0 : 0.0); }
            return $d;
        }
    }
    return quote_fx_rates($pdo);
}

function quote_toman(float $amount, string $ccy, array $rates): ?float {
    $rate = (float)($rates[strtoupper($ccy)] ?? 0);
    if ($rate <= 0) return null; // نرخ نامشخص
    return $amount * $rate;
}

function quote_from_toman(float $toman, string $ccy, array $rates): ?float {
    $rate = (float)($rates[strtoupper($ccy)] ?? 0);
    if ($rate <= 0) return null;
    return $toman / $rate;
}

/* ── گردش‌کار: پیش‌فرض / بارگذاری / اعتبارسنجی ── */
function quote_workflow_default(): array {
    return ['steps' => [
        ['id' => 'step_submit',   'type' => 'submit',   'title' => 'ثبت استعلام و ارسال',               'assignee' => ['kind' => 'owner']],
        ['id' => 'step_review',   'type' => 'approval', 'title' => 'تأیید مدیر فروش',                  'assignee' => ['kind' => 'role', 'role' => 'sales_manager']],
        ['id' => 'step_costing',  'type' => 'costing',  'title' => 'اعلام قیمت نت (مدیرعامل/مسئول)',   'assignee' => ['kind' => 'role', 'role' => 'ceo']],
        ['id' => 'step_pricing',  'type' => 'pricing',  'title' => 'تأیید نهایی + حاشیهٔ سود (مدیر فروش)', 'assignee' => ['kind' => 'role', 'role' => 'sales_manager']],
        ['id' => 'step_finalize', 'type' => 'finalize', 'title' => 'اعلام به مشتری و فیکس (کارشناس)', 'assignee' => ['kind' => 'owner']],
    ]];
}

function quote_workflow_validate($wf): ?array {
    if (!is_array($wf) || !isset($wf['steps']) || !is_array($wf['steps'])) return null;
    $allowed = ['submit', 'approval', 'costing', 'pricing', 'finalize', 'notify'];
    $steps = []; $seen = [];
    foreach ($wf['steps'] as $i => $s) {
        if (!is_array($s)) return null;
        $type = (string)($s['type'] ?? '');
        if (!in_array($type, $allowed, true)) return null;
        $id = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($s['id'] ?? ('step_' . $i))), 0, 40);
        if ($id === '' || isset($seen[$id])) $id = 'step_' . $i . '_' . substr(md5((string)$i . $type), 0, 6);
        $seen[$id] = true;
        $title = mb_substr(trim((string)($s['title'] ?? $type)), 0, 120);
        if ($title === '') $title = $type;
        $a = is_array($s['assignee'] ?? null) ? $s['assignee'] : ['kind' => 'owner'];
        $kind = (string)($a['kind'] ?? 'owner');
        if (!in_array($kind, ['owner', 'role', 'user'], true)) $kind = 'owner';
        $assignee = ['kind' => $kind];
        if ($kind === 'role') $assignee['role'] = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($a['role'] ?? 'sales_manager')), 0, 40) ?: 'sales_manager';
        if ($kind === 'user') $assignee['user_id'] = (int)($a['user_id'] ?? 0);
        $step = ['id' => $id, 'type' => $type, 'title' => $title, 'assignee' => $assignee];
        if ($type === 'notify') {
            $step['notify_text'] = mb_substr(trim((string)($s['notify_text'] ?? '')), 0, 300);
        }
        $steps[] = $step;
    }
    if (count($steps) < 2) return null;
    if ($steps[0]['type'] !== 'submit') return null; // باید با ثبت استعلام شروع شود
    return ['steps' => $steps];
}

function quote_workflow_load(PDO $pdo): array {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'quote_workflow_json' LIMIT 1");
        $st->execute();
        $raw = $st->fetchColumn();
        if ($raw) {
            $wf = quote_workflow_validate(json_decode((string)$raw, true));
            if ($wf) return $wf;
        }
    } catch (Throwable $e) {}
    return quote_workflow_default();
}

function quote_workflow_save(PDO $pdo, array $wf): bool {
    $wf = quote_workflow_validate($wf);
    if (!$wf) return false;
    try {
        $st = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('quote_workflow_json', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st->execute([json_encode($wf, JSON_UNESCAPED_UNICODE)]);
        return true;
    } catch (Throwable $e) { return false; }
}

/* ── snapshot یک استعلام ── */
function quote_snapshot_workflow(PDO $pdo, ?array $quote): array {
    if ($quote && !empty($quote['workflow_json'])) {
        $wf = quote_workflow_validate(json_decode((string)$quote['workflow_json'], true));
        if ($wf) return $wf;
    }
    return quote_workflow_load($pdo);
}

function quote_steps(array $quote): array {
    // snapshot قبلاً validate شده است؛ اینجا فقط خواندن امن
    $wf = json_decode((string)($quote['workflow_json'] ?? ''), true);
    if (!is_array($wf['steps'] ?? null)) return [];
    return $wf['steps'];
}

function quote_current_step(array $quote): ?array {
    $steps = quote_steps($quote);
    $i = (int)($quote['current_step'] ?? 0);
    if ($i < 0) $i = 0;
    if (!isset($steps[$i])) return null;
    return $steps[$i];
}

/* ── resolve مسئول یک قدم (لیست user_id) ── */
function quote_assignee_user_ids(PDO $pdo, array $step, array $quote): array {
    $a = $step['assignee'] ?? ['kind' => 'owner'];
    $kind = $a['kind'] ?? 'owner';
    if ($kind === 'owner') return array_values(array_filter([(int)($quote['created_by'] ?? 0)]));
    if ($kind === 'user') return array_values(array_filter([(int)($a['user_id'] ?? 0)]));
    // role
    $role = (string)($a['role'] ?? '');
    if ($role === '') return [];
    $ids = [];
    // مدیر فروشِ تیم ثبت‌کننده؛ برای استعلام‌های بدون تیم، رفتار قدیمی حفظ می‌شود.
    if ($role === 'sales_manager') {
        try {
            $st = $pdo->prepare("SELECT COALESCE(NULLIF(u.team_id, 0), d.team_id) AS team_id, t.manager_user_id, m.status AS manager_status
                FROM crm_quotes q LEFT JOIN users u ON u.id = q.created_by
                LEFT JOIN crm_deals d ON d.id = q.deal_id
                LEFT JOIN teams t ON t.id = COALESCE(NULLIF(u.team_id, 0), d.team_id)
                LEFT JOIN users m ON m.id = t.manager_user_id WHERE q.id = ?");
            $st->execute([(int)($quote['id'] ?? 0)]);
            $team = $st->fetch(PDO::FETCH_ASSOC);
            if (!empty($team['team_id'])) {
                if (!empty($team['manager_user_id']) && ($team['manager_status'] ?? '') === 'active') return [(int)$team['manager_user_id']];
                // تیم تنظیم‌شده اما بدون مدیر فعال: فقط مدیر کل، نه مدیر تیم دیگر.
                return array_map('intval', $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN));
            }
        } catch (Throwable $e) { return []; } // در خطای پایگاه داده به تیم دیگری ارجاع نده.
    }
    try {
        $st = $pdo->prepare("SELECT DISTINCT u.id FROM users u JOIN user_org_roles uo ON uo.user_id = u.id JOIN org_roles o ON o.id = uo.org_role_id WHERE o.code = ? AND o.is_active = 1 AND u.status = 'active'");
        $st->execute([$role]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT u.id FROM users u JOIN org_roles o ON o.id = u.org_role_id WHERE o.code = ? AND o.is_active = 1 AND u.status = 'active'");
        $st->execute([$role]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { $ids[] = (int)$id; }
    } catch (Throwable $e) {}
    if (!$ids) {
        // هیچ‌کس این سمت را ندارد → مدیر(ان) کل کار می‌توانند اقدام کنند
        try { $ids = array_map('intval', $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) {}
    }
    return array_values(array_unique($ids));
}

function quote_user_can_act(PDO $pdo, array $quote, int $userId): bool {
    if (is_strict_admin()) return true;
    if (in_array((string)($quote['status'] ?? ''), ['fixed', 'cancelled'], true)) return false;
    $step = quote_current_step($quote);
    if (!$step) return false;
    if (!empty($step['assignee']['passthrough'])) return false; // notify خودکار است
    return in_array($userId, quote_assignee_user_ids($pdo, $step, $quote), true);
}

/* ── اعلان داخل CRM (همان الگوی pages/notifications.php) ── */
function quote_notify(PDO $pdo, int $actorId, array $recipientIds, string $title, string $message): void {
    $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds))));
    if (!$recipientIds) return;
    try {
        $st = $pdo->prepare("INSERT INTO notifications (title, message, type, is_group, is_task, created_by) VALUES (?, ?, 'info', 0, 0, ?)");
        $st->execute([$title, $message, $actorId]);
        $nid = (int)$pdo->lastInsertId();
        if ($nid > 0) {
            $st = $pdo->prepare("INSERT INTO notification_recipients (notification_id, user_id) VALUES (?, ?)");
            foreach ($recipientIds as $rid) { $st->execute([$nid, $rid]); }
        }
    } catch (Throwable $e) { /* اعلان نباید فرایند را بشکند */ }
}

/* ── تاریخچه ── */
function quote_history_add(PDO $pdo, int $quoteId, int $actorId, ?int $stepIndex, ?string $stepType, string $action, string $note = ''): void {
    try {
        $st = $pdo->prepare("INSERT INTO quote_history (quote_id, actor_user_id, step_index, step_type, action, note) VALUES (?,?,?,?,?,?)");
        $st->execute([$quoteId, $actorId, $stepIndex, $stepType, $action, $note !== '' ? $note : null]);
    } catch (Throwable $e) {}
}

function quote_history(PDO $pdo, int $quoteId): array {
    try {
        $st = $pdo->prepare("SELECT h.*, u.full_name AS actor_name FROM quote_history h LEFT JOIN users u ON u.id = h.actor_user_id WHERE h.quote_id = ? ORDER BY h.id");
        $st->execute([$quoteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** نمایش عدد بدون صفرهای انتهایی — بدون لمس بخش صحیح عدد صحیح */
function quote_trim_num($v): string {
    $s = (string)$v;
    if ($s === '' || !is_numeric($s)) return $s;
    if (strpos($s, '.') === false) return $s;
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}

/* ── ردیف‌های هزینهٔ یک استعلام (همیشه ۷ ردیف؛ با ensure ساخته می‌شود) ── */
function quote_cost_lines(PDO $pdo, int $quoteId): array {
    $rows = [];
    try {
        $st = $pdo->prepare("SELECT * FROM quote_cost_lines WHERE quote_id = ? ORDER BY sort_order, id");
        $st->execute([$quoteId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $existing = array_column($rows, 'component_code');
    foreach (quote_components() as $code => $label) {
        if (!in_array($code, $existing, true)) {
            try {
                $st = $pdo->prepare("INSERT INTO quote_cost_lines (quote_id, component_code, label, amount, currency, sort_order) VALUES (?,?,?,NULL,?,?)");
                $st->execute([$quoteId, $code, $label, 'USD', count($existing)]);
            } catch (Throwable $e) {}
        }
    }
    if (count($rows) !== count(quote_components())) {
        try {
            $st = $pdo->prepare("SELECT * FROM quote_cost_lines WHERE quote_id = ? ORDER BY sort_order, id");
            $st->execute([$quoteId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
    return $rows;
}

/** جمع هزینهٔ نت به تومان + فهرست ارزهای بدون نرخ. ['toman'=>float,'missing'=>string[]] */
function quote_cost_total(array $lines, array $rates): array {
    $toman = 0.0; $missing = []; $used = [];
    foreach ($lines as $l) {
        $amt = (float)($l['amount'] ?? 0);
        if ($amt <= 0) continue;
        $cur = strtoupper((string)($l['currency'] ?? 'USD'));
        $t = quote_toman($amt, $cur, $rates);
        if ($t === null) { $missing[] = $cur; continue; }
        $toman += $t;
        $used[$cur] = true;
    }
    return ['toman' => $toman, 'missing' => array_values(array_unique($missing)), 'used' => array_keys($used)];
}

/** ذخیرهٔ ردیف‌ها + snapshot نرخ؛ برمی‌گرداند: ['total_toman'=>float,'missing'=>[],'saved'=>bool] */
function quote_save_costs(PDO $pdo, int $quoteId, array $amounts, array $currencies, bool $snapshotFx = true): array {
    $codes = array_keys(quote_components());
    $rates = quote_fx_rates($pdo);
    $pdo->beginTransaction();
    try {
        foreach ($codes as $i => $code) {
            $raw = (string)($amounts[$code] ?? '');
            $num = str_replace(['،', ',', '٬', ' '], '', $raw);
            $amt = ($num === '' || !is_numeric($num)) ? null : (float)$num;
            $cur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($currencies[$code] ?? 'USD')), 0, 3));
            if (!in_array($cur, ['USD', 'CNY', 'TOM', 'IRR'], true)) $cur = 'USD';
            $st = $pdo->prepare("UPDATE quote_cost_lines SET amount = ?, currency = ? WHERE quote_id = ? AND component_code = ?");
            $st->execute([$amt, $cur, $quoteId, $code]);
        }
        if ($snapshotFx) {
            $json = json_encode($rates, JSON_UNESCAPED_UNICODE);
            $st = $pdo->prepare("UPDATE crm_quotes SET fx_snapshot_json = ? WHERE id = ?");
            $st->execute([$json, $quoteId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['total_toman' => 0.0, 'missing' => ['DB'], 'saved' => false];
    }
    $lines = quote_cost_lines($pdo, $quoteId);
    $tot = quote_cost_total($lines, $rates);
    try {
        $st = $pdo->prepare("UPDATE crm_quotes SET total_cost_toman = ? WHERE id = ?");
        $st->execute([round($tot['toman'], 2), $quoteId]);
    } catch (Throwable $e) {}
    return ['total_toman' => $tot['toman'], 'missing' => $tot['missing'], 'saved' => true];
}

/* ── پیش‌فرض حاشیهٔ سود قابل تنظیم ── */
function quote_default_margin(PDO $pdo): float {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'quote_default_margin_pct' LIMIT 1");
        $st->execute();
        $v = (float)$st->fetchColumn();
        return ($v >= 0 && $v <= 100) ? $v : 25.0;
    } catch (Throwable $e) { return 25.0; }
}

/** قیمت نهایی = نت × (۱ + حاشیه)؛ برمی‌گرداند ['sell_toman'=>float] */
function quote_sell_toman(float $totalToman, float $marginPct): float {
    return $totalToman * (1.0 + ($marginPct / 100.0));
}

/* ── ثبت «فیکس قیمت»: به‌روزرسانی معامله + لاگ در CRM + اعلان ── */
function quote_do_fix(PDO $pdo, array $quote, int $actorId): array {
    $total = (float)($quote['total_cost_toman'] ?? 0);
    if ($total <= 0) return ['ok' => false, 'error' => 'جمع قیمت نت ثبت نشده است.'];
    $margin = (float)($quote['margin_pct'] ?? quote_default_margin($pdo));
    $sell = round(quote_sell_toman($total, $margin), 0);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("UPDATE crm_quotes SET status = 'fixed', fixed_sell_toman = ?, fixed_at = NOW(), fixed_by = ? WHERE id = ?");
        $st->execute([$sell, $actorId, (int)$quote['id']]);
        // مبنای محاسبهٔ پورسانت: مبلغ معامله = قیمت فیکس‌شده (تومان)
        if (!empty($quote['deal_id'])) {
            $st = $pdo->prepare("UPDATE crm_deals SET estimated_value = ?, currency = 'TOM' WHERE id = ?");
            $st->execute([$sell, (int)$quote['deal_id']]);
        }
        // یادداشت در تایم‌لاین CRM
        try {
            $cust = (int)($quote['customer_id'] ?? 0);
            $st = $pdo->prepare("INSERT INTO crm_activities (customer_id, lead_id, deal_id, user_id, type, subject, body, activity_date, outcome)
                                 VALUES (?, NULL, ?, ?, 'note', ?, ?, NOW(), 'fix')");
            $body = 'فیکس قیمت استعلام #' . (int)$quote['id'] . ' — قیمت نت: ' . number_format($total, 0, '.', '،') . ' تومان | حاشیهٔ سود: ' . rtrim(rtrim(number_format($margin, 1), '0'), '.') . '٪ | قیمت نهایی: ' . number_format($sell, 0, '.', '،') . ' تومان';
            $st->execute([$cust ?: null, !empty($quote['deal_id']) ? (int)$quote['deal_id'] : null, $actorId, 'فیکس قیمت پیش‌فاکتور (استعلام #' . (int)$quote['id'] . ')', $body]);
        } catch (Throwable $e) { /* فعالیت ثبت نشد — فرایند ادامه دارد */ }
        $pdo->commit();
        quote_history_add($pdo, (int)$quote['id'], $actorId, null, 'finalize', 'fix', 'قیمت نهایی: ' . number_format($sell, 0, '.', '،') . ' تومان');
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'sell_toman' => $sell];
}

/* ── ماشین حالت گردش‌کار: submit|approve|return|fix|cancel ── */
function quote_transition(PDO $pdo, int $quoteId, int $actorId, string $action, string $note = ''): array {
    try {
        $st = $pdo->prepare("SELECT * FROM crm_quotes WHERE id = ? LIMIT 1 FOR UPDATE");
        $st->execute([$quoteId]);
        $quote = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'خطا در خواندن استعلام']; }
    if (!$quote) return ['ok' => false, 'error' => 'استعلام پیدا نشد'];
    $status = (string)$quote['status'];
    if ($status === 'fixed') return ['ok' => false, 'error' => 'این استعلام قبلاً «فیکس» شده است.'];
    if ($status === 'cancelled') return ['ok' => false, 'error' => 'این استعلام لغو شده است.'];

    $steps = quote_steps($quote);
    $idx = (int)$quote['current_step'];
    $step = $steps[$idx] ?? null;
    if (!$step) return ['ok' => false, 'error' => 'خطای گردش (مرحلهٔ نامعتبر)'];
    $type = (string)$step['type'];

    // لغو: سازنده یا ادمین، از هر مرحله
    if ($action === 'cancel') {
        if (!(is_strict_admin() || (int)$quote['created_by'] === $actorId)) return ['ok' => false, 'error' => 'فقط سازنده یا مدیر کل می‌تواند لغو کند.'];
        try {
            $st = $pdo->prepare("UPDATE crm_quotes SET status = 'cancelled' WHERE id = ?");
            $st->execute([(int)$quote['id']]);
        } catch (Throwable $e) {}
        quote_history_add($pdo, $quoteId, $actorId, $idx, $type, 'cancel', $note);
        return ['ok' => true, 'status' => 'cancelled'];
    }

    // مجوز اقدام روی مرحلهٔ جاری
    if (!quote_user_can_act($pdo, $quote, $actorId)) return ['ok' => false, 'error' => 'اقدام روی این مرحله در اختیار شما نیست.'];

    if ($action === 'submit') {
        if ($type !== 'submit') return ['ok' => false, 'error' => 'این اقدام فقط در مرحلهٔ ثبت است.'];
        $next = $idx + 1;
        _quote_move_to($pdo, $quote, $actorId, $next, 'submit', $note);
        return ['ok' => true];
    }
    if ($action === 'approve') {
        if (!in_array($type, ['approval', 'costing', 'pricing'], true)) return ['ok' => false, 'error' => 'این مرحله تأیید/اعلام ندارد.'];
        $next = $idx + 1;
        if ($type === 'costing' && (float)($quote['total_cost_toman'] ?? 0) <= 0) return ['ok' => false, 'error' => 'ابتدا قیمت نت را ثبت کنید.'];
        $finalIdx = _quote_move_to($pdo, $quote, $actorId, $next, 'approve', $note);
        // اگر پس از تأیید (و رد شدن از notifyها) دیگر مرحله‌ای نماند → فیکس خودکار
        if ($finalIdx >= count($steps)) { return quote_do_fix($pdo, $quote, $actorId); }
        return ['ok' => true];
    }
    if ($action === 'return') {
        if ($idx <= 0) return ['ok' => false, 'error' => 'بازگشت از مرحلهٔ اول ممکن نیست.'];
        $next = $idx - 1;
        // «برگشت از قیمت نت» را به «اعلام قیمت نت» و نه به تأیید برگرداند؟ طبق گراف: یک قدم عقب.
        _quote_move_to($pdo, $quote, $actorId, $next, 'return', $note);
        return ['ok' => true];
    }
    if ($action === 'fix') {
        if ($type !== 'finalize') return ['ok' => false, 'error' => 'فیکس فقط در مرحلهٔ پایانی.'];
        $r = quote_do_fix($pdo, $quote, $actorId);
        if (!empty($r['ok'])) {
            // اعلان به مدیر فروش برای اطلاع
            $mgrIds = quote_assignee_user_ids($pdo, ['assignee' => ['kind' => 'role', 'role' => 'sales_manager']], $quote);
            $title = '🧾 استعلام #' . (int)$quote['id'] . ' فیکس شد';
            quote_notify($pdo, $actorId, $mgrIds, $title, ($quote['title'] ?? '') . "\nقیمت نهایی: " . number_format((float)($r['sell_toman'] ?? 0), 0, '.', '،') . " تومان\nindex.php?page=quote_view&id=" . (int)$quote['id']);
        }
        return $r;
    }
    return ['ok' => false, 'error' => 'اقدام ناشناخته'];
}

/** جابه‌جایی مرحله + تاریخچه + اعلان به مسئول مرحلهٔ جدید؛ notify های بین‌راهی را رد می‌کند (خودکار عبور می‌کند). برمی‌گرداند: اندیس نهایی */
function _quote_move_to(PDO $pdo, array $quote, int $actorId, int $nextIdx, string $action, string $note = ''): int {
    $steps = quote_steps($quote);
    // عبور خودکار از نودهای notify (با ثبت در تاریخچه + اعلان)
    $guard = 0;
    $cursor = $nextIdx;
    $hops = [];
    while (isset($steps[$cursor]) && $steps[$cursor]['type'] === 'notify' && $guard++ < 10) {
        $hops[] = $cursor;
        $cursor++;
    }
    // اگر بعد از notifyها به انتهای گراف رسیدیم، pricing را فیکس کن
    $finalIdx = $cursor;
    try {
        $st = $pdo->prepare("UPDATE crm_quotes SET current_step = ? WHERE id = ?");
        $st->execute([min($finalIdx, count($steps)), (int)$quote['id']]);
    } catch (Throwable $e) {}
    quote_history_add($pdo, (int)$quote['id'], $actorId, (int)$quote['current_step'], (string)($steps[(int)$quote['current_step']]['type'] ?? ''), $action, $note);
    // اعلان‌ها برای notifyهای ردشده
    foreach ($hops as $hi) {
        $s = $steps[$hi];
        $rids = quote_assignee_user_ids($pdo, $s, $quote);
        $msg = (string)($s['notify_text'] ?? '');
        if ($msg === '') $msg = ('استعلام #' . (int)$quote['id'] . ' — مرحلهٔ «' . ($s['title'] ?? '') . '»' . "\n" . 'index.php?page=quote_view&id=' . (int)$quote['id']);
        quote_notify($pdo, $actorId, $rids, '🔔 ' . ($quote['title'] ?? 'استعلام'), $msg);
        quote_history_add($pdo, (int)$quote['id'], $actorId, $hi, 'notify', 'notify', $s['title'] ?? '');
    }
    // اعلان به مسئول مرحلهٔ بعدی (اگر وجود دارد)
    if (isset($steps[$finalIdx])) {
        $s = $steps[$finalIdx];
        $rids = quote_assignee_user_ids($pdo, $s, $quote);
        $msg = ($quote['title'] ?? '') . "\nمرحلهٔ بعدی: " . ($s['title'] ?? '') . "\n" . 'index.php?page=quote_view&id=' . (int)$quote['id'];
        quote_notify($pdo, $actorId, $rids, '🧾 اقدام لازم: استعلام #' . (int)$quote['id'], $msg);
    }
    return $finalIdx;
}

/** تبدیل مبلغ به ارزهای نمایشی + قالب‌بندی */
function quote_multi_view(float $toman, array $rates): array {
    $out = [];
    foreach (['TOM', 'USD', 'CNY'] as $ccy) {
        $v = quote_from_toman($toman, $ccy, $rates);
        $out[$ccy] = $v === null ? null : $v;
    }
    return $out;
}

function quote_fmt_toman(float $v): string { return number_format($v, 0, '.', '،'); }
