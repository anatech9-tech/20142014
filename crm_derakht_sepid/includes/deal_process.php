<?php
/** پروندهٔ اجرایی مستقل از دسته‌بندی قدیمی کانبان؛ رویدادها مبنای اتصال آتی پیامک هستند. */
function deal_process_stages(): array {
    return [
        'contact' => ['تماس و بررسی اولیه', false],
        'requirements' => ['دریافت اطلاعات کالا و پکینگ‌لیست', false],
        'quotation' => ['استعلام، تأیید و قیمت‌گذاری', false],
        'customer_decision' => ['اعلام قیمت و تأیید مشتری', false],
        'proforma' => ['صدور پیش‌فاکتور', true],
        'contract' => ['عقد قرارداد', true],
        'sourcing' => ['تأمین کالا در مبدأ', true],
        'china_warehouse' => ['حمل به انبار چین', true],
        'china_export' => ['صدور برگه صادرات چین', true],
        'shipping' => ['ارسال بین‌المللی', true],
        'iran_customs' => ['ورود به گمرک ایران', true],
        'clearance' => ['ترخیص کالا', true],
        'settlement' => ['تسویه مالی', true],
        'delivery' => ['ارسال و تحویل به مشتری', true],
        'satisfaction' => ['رضایت‌سنجی', true],
        'completed' => ['پایان پرونده', true],
    ];
}
function deal_process_schema(): array {
    $tail='ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'crm_deal_process' => "CREATE TABLE IF NOT EXISTS crm_deal_process (
            deal_id INT NOT NULL PRIMARY KEY, stage_key VARCHAR(40) NOT NULL,
            quote_id INT NULL, created_by INT NULL, updated_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_process_stage (stage_key)
        ) $tail",
        'crm_deal_process_events' => "CREATE TABLE IF NOT EXISTS crm_deal_process_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, deal_id INT NOT NULL,
            from_stage VARCHAR(40) NULL, to_stage VARCHAR(40) NOT NULL, action VARCHAR(20) NOT NULL,
            actor_id INT NOT NULL, quote_id INT NULL, note TEXT NULL, satisfaction_score TINYINT UNSIGNED NULL,
            customer_visible TINYINT(1) NOT NULL DEFAULT 0,
            notification_status VARCHAR(24) NOT NULL DEFAULT 'not_configured',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_process_event_deal (deal_id, id)
        ) $tail",
        'crm_deal_documents' => "CREATE TABLE IF NOT EXISTS crm_deal_documents (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, deal_id INT NOT NULL,
            stage_key VARCHAR(40) NOT NULL, original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL, file_size INT UNSIGNED NOT NULL, file_data MEDIUMBLOB NOT NULL,
            uploaded_by INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_process_doc_deal (deal_id, id)
        ) $tail",
    ];
}
function ensure_deal_process_schema(PDO $pdo): bool {
    static $done=null;
    if ($done!==null) return $done;
    try { foreach (deal_process_schema() as $sql) $pdo->exec($sql); return $done=true; }
    catch (Throwable $e) { error_log('Deal process schema: '.$e->getMessage()); return $done=false; }
}
/** رویداد داخل همان تراکنش تغییر مرحله ثبت می‌شود؛ بدون فرستادن پیامک. */
function deal_process_add_event(PDO $pdo, int $dealId, ?string $from, string $to, string $action, int $actor, string $note='', ?int $score=null, ?int $quoteId=null): void {
    $stages=deal_process_stages();
    $visible=($action==='advance' && !empty($stages[$to][1])) ? 1 : 0;
    $st=$pdo->prepare("INSERT INTO crm_deal_process_events (deal_id,from_stage,to_stage,action,actor_id,quote_id,note,satisfaction_score,customer_visible,notification_status) VALUES (?,?,?,?,?,?,?,?,?,'not_configured')");
    $st->execute([$dealId,$from,$to,$action,$actor,$quoteId ?: null,$note ?: null,$score,$visible]);
}
