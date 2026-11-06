<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  نصب‌کننده دیتابیس — CRM درخت سپید تجارت پارس
 * ═══════════════════════════════════════════════════════════════
 * این فایل همهٔ جدول‌های موردنیاز برنامه را می‌سازد (idempotent:
 * اجرای چندباره هیچ داده‌ای را پاک یا بازنویسی نمی‌کند).
 *
 * نحوه اجرا روی هاست:
 *   ۱) فایل‌ها را آپلود کنید.
 *   ۲) مطمئن شوید config.local.php کنار این فایل است (اطلاعات دیتابیس)
 *      یا فرم این صفحه را پر کنید.
 *   ۳) در مرورگر باز کنید:  https://YOUR-DOMAIN/install_db.php
 *   ۴) پس از موفقیت، این فایل را از هاست حذف کنید.
 *
 * روی CLI هم اجرا می‌شود:  php install_db.php
 *
 * ⚠️ این فایل هیچ جدولی را DROP نمی‌کند؛ فقط CREATE TABLE IF NOT EXISTS.
 */

declare(strict_types=1);
session_start();

const INSTALL_TITLE = 'نصب دیتابیس CRM درخت سپید تجارت پارس';

// ─────────────────────────────────────────────────────────────
//  حل اطلاعات اتصال: (۱) config.local.php  (۲) متغیرهای محیطی  (۳) فرم وب
// ─────────────────────────────────────────────────────────────
function install_creds(): ?array {
    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        require_once $local;
        if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS') && DB_HOST !== 'CHANGE_ME') {
            return [DB_HOST, DB_NAME, DB_USER, DB_PASS];
        }
    }
    $eh = getenv('DB_HOST'); $en = getenv('DB_NAME'); $eu = getenv('DB_USER'); $ep = getenv('DB_PASS');
    if ($eh && $en && $eu && $ep) return [$eh, $en, $eu, $ep];
    return null;
}

function pdo_from(string $host, string $name, string $user, string $pass): PDO {
    $dsn = "mysql:host=" . $host . ";dbname=" . $name . ";charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

// ─────────────────────────────────────────────────────────────
//  تعریف جدول‌ها (بیش از ۵۵ جدول — مطابق استفادهٔ کل کد)
// ─────────────────────────────────────────────────────────────
function schema_statements(): array {
    $T = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $S = [];

    // ── هستهٔ کاربران و دسترسی ───────────────────────────────
    $S['users'] = "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(100) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `full_name` VARCHAR(150) NOT NULL DEFAULT '',
        `phone` VARCHAR(30) NULL,
        `role` VARCHAR(50) NOT NULL DEFAULT 'user',
        `position` VARCHAR(100) NULL,
        `specialty` VARCHAR(100) NULL,
        `work_role` VARCHAR(100) NULL,
        `national_code` VARCHAR(20) NULL,
        `card_number` VARCHAR(30) NULL,
        `iban` VARCHAR(34) NULL,
        `account_number` VARCHAR(50) NULL,
        `account_name` VARCHAR(100) NULL,
        `address` TEXT NULL,
        `avatar` VARCHAR(255) NULL,
        `description` TEXT NULL,
        `is_karigar` TINYINT(2) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_users_username` (`username`)
    ) $T";

    $S['user_permissions'] = "CREATE TABLE IF NOT EXISTS `user_permissions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `permission` VARCHAR(100) NOT NULL,
        KEY `idx_up_user` (`user_id`),
        UNIQUE KEY `uq_user_perm` (`user_id`, `workshop_id`, `permission`)
    ) $T";

    $S['user_page_access'] = "CREATE TABLE IF NOT EXISTS `user_page_access` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `page_key` VARCHAR(100) NOT NULL,
        `mode` VARCHAR(20) NOT NULL DEFAULT 'viewer',
        `expires_at` DATETIME NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_upa` (`user_id`, `page_key`)
    ) $T";

    // ── کارگاه / تیم / سازمان ────────────────────────────────
    $S['workshops'] = "CREATE TABLE IF NOT EXISTS `workshops` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(150) NOT NULL,
        `address` TEXT NULL,
        `description` TEXT NULL,
        `employer_name` VARCHAR(150) NULL,
        `employer_logo` VARCHAR(255) NULL,
        `logo` VARCHAR(255) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'active',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['workshop_personnel'] = "CREATE TABLE IF NOT EXISTS `workshop_personnel` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NOT NULL,
        `contract_type` VARCHAR(50) NULL,
        `contract_start` DATE NULL,
        `contract_end` DATE NULL,
        `hire_date` DATE NULL,
        `ended_at` DATE NULL,
        `daily_salary` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `hourly_salary` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `monthly_salary` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `insurance_number` VARCHAR(30) NULL,
        `insurance_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `status` VARCHAR(20) NOT NULL DEFAULT 'active',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_wp_user` (`user_id`, `status`),
        KEY `idx_wp_ws` (`workshop_id`, `status`)
    ) $T";

    $S['workshop_settings'] = "CREATE TABLE IF NOT EXISTS `workshop_settings` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NOT NULL,
        `setting_key` VARCHAR(150) NOT NULL,
        `setting_value` MEDIUMTEXT NULL,
        UNIQUE KEY `uq_ws_setting` (`workshop_id`, `setting_key`)
    ) $T";

    $S['settings'] = "CREATE TABLE IF NOT EXISTS `settings` (
        `setting_key` VARCHAR(150) NOT NULL PRIMARY KEY,
        `setting_value` MEDIUMTEXT NULL
    ) $T";

    $S['settings_requests'] = "CREATE TABLE IF NOT EXISTS `settings_requests` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `requested_by` INT(11) NULL,
        `settings_data` MEDIUMTEXT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    // ── ساعت کاری، مرخصی، استراحت ───────────────────────────
    $S['work_hours'] = "CREATE TABLE IF NOT EXISTS `work_hours` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NOT NULL,
        `work_date` DATE NOT NULL,
        `start_time` VARCHAR(19) NULL,
        `end_time` VARCHAR(19) NULL,
        `is_night` TINYINT(1) NOT NULL DEFAULT 0,
        `note` VARCHAR(500) NULL,
        `mission_id` INT(11) NULL,
        `mission_source_ws` INT(11) NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_wh_user_date` (`user_id`, `work_date`),
        KEY `idx_wh_ws_date` (`workshop_id`, `work_date`)
    ) $T";

    $S['missions'] = "CREATE TABLE IF NOT EXISTS `missions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `source_workshop_id` INT(11) NULL,
        `target_workshop_id` INT(11) NULL,
        `start_date` DATE NULL,
        `start_time` VARCHAR(19) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'active',
        `description` TEXT NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['leave_requests'] = "CREATE TABLE IF NOT EXISTS `leave_requests` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `leave_type` VARCHAR(30) NOT NULL DEFAULT 'daily',
        `start_date` DATE NULL,
        `end_date` DATE NULL,
        `start_time` VARCHAR(10) NULL,
        `end_time` VARCHAR(10) NULL,
        `hours` DECIMAL(6,2) NULL,
        `reason` VARCHAR(500) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `approved_by` INT(11) NULL,
        `approved_at` DATETIME NULL,
        `coin_redemption_id` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_leave_user` (`user_id`, `status`)
    ) $T";

    $S['employee_breaks'] = "CREATE TABLE IF NOT EXISTS `employee_breaks` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NOT NULL,
        `break_date` DATE NOT NULL,
        `break_no` TINYINT(3) NOT NULL DEFAULT 1,
        `start_time` DATETIME NULL,
        `end_time` DATETIME NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_rt` (`user_id`, `workshop_id`, `break_date`)
    ) $T";

    $S['temp_entry_grants'] = "CREATE TABLE IF NOT EXISTS `temp_entry_grants` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NOT NULL,
        `grant_date` DATE NOT NULL,
        `shift_type` ENUM('day','night') NOT NULL DEFAULT 'day',
        `created_by` INT(11) NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `revoked_at` DATETIME NULL,
        KEY `idx_tg_user` (`user_id`, `revoked_at`),
        KEY `idx_tg_scope` (`workshop_id`, `grant_date`, `shift_type`),
        UNIQUE KEY `uq_grant_shift` (`user_id`, `workshop_id`, `grant_date`, `shift_type`)
    ) $T";

    $S['holidays'] = "CREATE TABLE IF NOT EXISTS `holidays` (
        `holiday_date` DATE NOT NULL PRIMARY KEY,
        `title` VARCHAR(200) NULL
    ) $T";

    // ── حقوق، مساعده، پاداش/جریمه، تنخواه ─────────────────────
    $S['salary_history'] = "CREATE TABLE IF NOT EXISTS `salary_history` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `old_salary` DECIMAL(15,2) NULL,
        `new_salary` DECIMAL(15,2) NULL,
        `reason` VARCHAR(500) NULL,
        `start_date` DATE NULL,
        `changed_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_sh_user` (`user_id`, `start_date`)
    ) $T";

    $S['salary_calculations'] = "CREATE TABLE IF NOT EXISTS `salary_calculations` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `month_key` VARCHAR(10) NULL,
        `amount` DECIMAL(15,2) NULL,
        `note` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['advances'] = "CREATE TABLE IF NOT EXISTS `advances` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `remaining` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `reason` VARCHAR(500) NULL,
        `payment_type` VARCHAR(30) NULL,
        `payment_method` VARCHAR(30) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `approved_by` INT(11) NULL,
        `approved_at` DATETIME NULL,
        `paid_at` DATETIME NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_adv_user` (`user_id`, `status`)
    ) $T";

    $S['advance_expenses'] = "CREATE TABLE IF NOT EXISTS `advance_expenses` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `advance_id` INT(11) NOT NULL,
        `user_id` INT(11) NULL,
        `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `expense_date` DATE NULL,
        `expense_time` VARCHAR(10) NULL,
        `description` VARCHAR(500) NULL,
        `receipt_image` VARCHAR(255) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_ae_advance` (`advance_id`)
    ) $T";

    $S['bonuses_penalties'] = "CREATE TABLE IF NOT EXISTS `bonuses_penalties` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `type` VARCHAR(20) NOT NULL DEFAULT 'bonus',
        `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `date` DATE NULL,
        `reason` VARCHAR(500) NULL,
        `calculation_mode` VARCHAR(30) NULL,
        `unit_amount` DECIMAL(15,2) NULL,
        `quantity` DECIMAL(15,3) NULL,
        `hours` DECIMAL(6,2) NOT NULL DEFAULT 0,
        `hourly_rate` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `evidence_image` VARCHAR(255) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_bp_user` (`user_id`, `type`)
    ) $T";

    $S['petty_cash'] = "CREATE TABLE IF NOT EXISTS `petty_cash` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `advance_id` INT(11) NULL,
        `type` VARCHAR(30) NOT NULL DEFAULT 'expense',
        `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `date` DATE NULL,
        `description` VARCHAR(500) NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_pc_ws` (`workshop_id`, `date`)
    ) $T";

    // ── دستور کار و گزارش‌ها ─────────────────────────────────
    $S['work_orders'] = "CREATE TABLE IF NOT EXISTS `work_orders` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `created_by` INT(11) NULL,
        `target_team` VARCHAR(50) NULL,
        `shift_type` VARCHAR(10) NULL,
        `order_date` DATE NULL,
        `items` MEDIUMTEXT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `completed_by` INT(11) NULL,
        `completed_at` DATETIME NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_wo_ws` (`workshop_id`, `order_date`)
    ) $T";

    $S['work_order_items'] = "CREATE TABLE IF NOT EXISTS `work_order_items` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `work_order_id` INT(11) NOT NULL,
        `row_num` INT(11) NOT NULL DEFAULT 0,
        `description` TEXT NULL,
        `progress` VARCHAR(255) NULL,
        `next_day` VARCHAR(255) NULL,
        `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
        `completed_by` INT(11) NULL,
        `completed_at` DATETIME NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_woi_wo` (`work_order_id`, `row_num`)
    ) $T";

    $S['daily_reports'] = "CREATE TABLE IF NOT EXISTS `daily_reports` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `report_date` DATE NOT NULL,
        `report_text` TEXT NULL,
        `category` VARCHAR(50) NULL,
        `shift` VARCHAR(10) NULL,
        `wo_description` TEXT NULL,
        `wo_next_day` TEXT NULL,
        `wo_progress` VARCHAR(255) NULL,
        `source_item_id` INT(11) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `approved_by` INT(11) NULL,
        `approved_at` DATETIME NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_dr_user_date` (`user_id`, `report_date`),
        KEY `idx_dr_ws_status` (`workshop_id`, `status`)
    ) $T";

    $S['hse_reports'] = "CREATE TABLE IF NOT EXISTS `hse_reports` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `workshop_name` VARCHAR(150) NULL,
        `created_by` INT(11) NULL,
        `report_date` DATE NULL,
        `report_number` VARCHAR(50) NULL,
        `shift` VARCHAR(10) NULL,
        `weather` VARCHAR(100) NULL,
        `work_fronts` TEXT NULL,
        `unsafe_items` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['anbar_reports'] = "CREATE TABLE IF NOT EXISTS `anbar_reports` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `project_name` VARCHAR(150) NULL,
        `keeper_name` VARCHAR(150) NULL,
        `report_date` DATE NULL,
        `report_number` VARCHAR(50) NULL,
        `description` TEXT NULL,
        `notes` TEXT NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    // ── انبار و کالا ─────────────────────────────────────────
    $S['inventory_items'] = "CREATE TABLE IF NOT EXISTS `inventory_items` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `name` VARCHAR(200) NOT NULL,
        `category` VARCHAR(100) NULL,
        `sku` VARCHAR(100) NULL,
        `barcode` VARCHAR(100) NULL,
        `description` TEXT NULL,
        `unit` VARCHAR(30) NOT NULL DEFAULT 'عدد',
        `quantity` DECIMAL(15,3) NOT NULL DEFAULT 0,
        `min_quantity` DECIMAL(15,3) NOT NULL DEFAULT 0,
        `asset_purchase_price` DECIMAL(15,2) NULL,
        `asset_purchase_date` DATE NULL,
        `useful_life_months` INT(11) NULL,
        `reissue_days` INT(11) NULL,
        `deleted_at` TIMESTAMP NULL DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_inv_ws` (`workshop_id`, `name`),
        KEY `idx_inv_barcode` (`barcode`)
    ) $T";

    $S['inventory_transactions'] = "CREATE TABLE IF NOT EXISTS `inventory_transactions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `item_id` INT(11) NOT NULL,
        `type` VARCHAR(30) NOT NULL,
        `out_type` VARCHAR(30) NULL,
        `quantity` DECIMAL(15,3) NOT NULL DEFAULT 0,
        `person_id` INT(11) NULL,
        `person_name` VARCHAR(150) NULL,
        `checkout_date` DATE NULL,
        `checkout_time` VARCHAR(10) NULL,
        `return_date` DATE NULL,
        `return_approved` TINYINT(1) NOT NULL DEFAULT 1,
        `days_since_last` INT(11) NULL,
        `required_interval_days` INT(11) NULL,
        `early_approved_by` INT(11) NULL,
        `early_reason` VARCHAR(255) NULL,
        `is_early_reissue` TINYINT(1) NOT NULL DEFAULT 0,
        `is_consumable` TINYINT(1) NOT NULL DEFAULT 0,
        `repair_cost` DECIMAL(15,2) NULL,
        `repair_subcontractor_id` INT(11) NULL,
        `notes` TEXT NULL,
        `created_by` INT(11) NULL,
        `deleted_at` TIMESTAMP NULL DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_it_item` (`item_id`),
        KEY `idx_it_ws_date` (`workshop_id`, `checkout_date`)
    ) $T";

    $S['inventory_serials'] = "CREATE TABLE IF NOT EXISTS `inventory_serials` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `item_id` INT(11) NOT NULL,
        `serial_no` VARCHAR(120) NOT NULL,
        `notes` VARCHAR(255) NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_serial` (`item_id`, `serial_no`)
    ) $T";

    $S['purchase_requests'] = "CREATE TABLE IF NOT EXISTS `purchase_requests` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `item_id` INT(11) NULL,
        `item_name` VARCHAR(200) NOT NULL,
        `quantity` DECIMAL(15,3) NOT NULL DEFAULT 1,
        `unit` VARCHAR(30) NULL,
        `expected_unit_price` DECIMAL(15,2) NULL,
        `vendor_name` VARCHAR(150) NULL,
        `description` TEXT NULL,
        `attachment` VARCHAR(255) NULL,
        `requested_by` INT(11) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['pending_transfers'] = "CREATE TABLE IF NOT EXISTS `pending_transfers` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `transfer_type` VARCHAR(30) NULL,
        `entity_id` INT(11) NULL,
        `source_workshop_id` INT(11) NULL,
        `target_workshop_id` INT(11) NULL,
        `quantity` DECIMAL(15,3) NULL,
        `transfer_date` DATE NULL,
        `notes` VARCHAR(500) NULL,
        `requested_by` INT(11) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    // ── پیمانکاران (زیرمجموعه‌های پروژه) ─────────────────────
    $S['subcontractors'] = "CREATE TABLE IF NOT EXISTS `subcontractors` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `name` VARCHAR(150) NOT NULL,
        `company` VARCHAR(150) NULL,
        `phone` VARCHAR(30) NULL,
        `description` TEXT NULL,
        `total_contract` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `linked_user_id` INT(11) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'active',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['subcontractor_services'] = "CREATE TABLE IF NOT EXISTS `subcontractor_services` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `subcontractor_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `service_name` VARCHAR(200) NOT NULL,
        `unit_name` VARCHAR(50) NULL,
        `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_ss_sub` (`subcontractor_id`)
    ) $T";

    $S['subcontractor_face_items'] = "CREATE TABLE IF NOT EXISTS `subcontractor_face_items` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `subcontractor_id` INT(11) NOT NULL,
        `date` DATE NULL,
        `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `description` VARCHAR(500) NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_sfi_sub` (`subcontractor_id`, `date`)
    ) $T";

    $S['subcontractor_daily_entries'] = "CREATE TABLE IF NOT EXISTS `subcontractor_daily_entries` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `subcontractor_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `entry_date` DATE NULL,
        `shift` VARCHAR(10) NULL,
        `description` TEXT NULL,
        `attachment` VARCHAR(255) NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_sde_sub` (`subcontractor_id`, `entry_date`)
    ) $T";

    $S['subcontractor_daily_entry_items'] = "CREATE TABLE IF NOT EXISTS `subcontractor_daily_entry_items` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `entry_id` INT(11) NOT NULL,
        `service_id` INT(11) NULL,
        `quantity` DECIMAL(15,3) NOT NULL DEFAULT 0,
        `unit_price_snapshot` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `total_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_sdei_entry` (`entry_id`)
    ) $T";

    $S['subcontractor_payments'] = "CREATE TABLE IF NOT EXISTS `subcontractor_payments` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `subcontractor_id` INT(11) NOT NULL,
        `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `payment_date` DATE NULL,
        `method` VARCHAR(30) NULL,
        `source` VARCHAR(50) NULL,
        `tracking_no` VARCHAR(100) NULL,
        `description` VARCHAR(500) NULL,
        `attachment` VARCHAR(255) NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_sp_sub` (`subcontractor_id`, `payment_date`)
    ) $T";

    // ── سیستم سکه/گیمیفیکیشن (WTTP COIN) ────────────────────
    $S['arash_coin_transactions'] = "CREATE TABLE IF NOT EXISTS `arash_coin_transactions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `amount` INT(11) NOT NULL DEFAULT 0,
        `transaction_type` VARCHAR(50) NOT NULL,
        `description` VARCHAR(500) NULL,
        `source_key` VARCHAR(120) NULL,
        `rule_id` INT(11) NULL,
        `event_date` DATE NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_act_user` (`user_id`, `transaction_type`),
        UNIQUE KEY `uq_source_key` (`source_key`)
    ) $T";

    $S['arash_coin_rewards'] = "CREATE TABLE IF NOT EXISTS `arash_coin_rewards` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `title` VARCHAR(200) NOT NULL,
        `description` VARCHAR(500) NULL,
        `icon` VARCHAR(50) NULL,
        `reward_type` VARCHAR(30) NULL,
        `cost` INT(11) NOT NULL DEFAULT 0,
        `stock` INT(11) NULL,
        `leave_days` DECIMAL(4,1) NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['arash_coin_redemptions'] = "CREATE TABLE IF NOT EXISTS `arash_coin_redemptions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `reward_id` INT(11) NULL,
        `cost` INT(11) NOT NULL DEFAULT 0,
        `leave_date` DATE NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['arash_coin_rules'] = "CREATE TABLE IF NOT EXISTS `arash_coin_rules` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `title` VARCHAR(200) NOT NULL,
        `event_type` VARCHAR(50) NULL,
        `task_keyword` VARCHAR(200) NULL,
        `target_team` VARCHAR(50) NOT NULL DEFAULT 'all',
        `multiplier` DECIMAL(6,2) NOT NULL DEFAULT 1,
        `base_coins` INT(11) NOT NULL DEFAULT 0,
        `priority` INT(11) NOT NULL DEFAULT 0,
        `start_date` DATE NULL,
        `end_date` DATE NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['arash_coin_challenges'] = "CREATE TABLE IF NOT EXISTS `arash_coin_challenges` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `title` VARCHAR(200) NOT NULL,
        `description` VARCHAR(500) NULL,
        `event_type` VARCHAR(50) NULL,
        `task_keyword` VARCHAR(200) NULL,
        `target_team` VARCHAR(50) NOT NULL DEFAULT 'all',
        `multiplier` DECIMAL(6,2) NOT NULL DEFAULT 1,
        `start_date` DATE NULL,
        `end_date` DATE NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['arash_coin_seasons'] = "CREATE TABLE IF NOT EXISTS `arash_coin_seasons` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `title` VARCHAR(200) NOT NULL,
        `description` VARCHAR(500) NULL,
        `start_date` DATE NULL,
        `end_date` DATE NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['arash_coin_flags'] = "CREATE TABLE IF NOT EXISTS `arash_coin_flags` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `flag_date` DATE NULL,
        `severity` VARCHAR(20) NULL,
        `reason` VARCHAR(500) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['arash_coin_wallet_controls'] = "CREATE TABLE IF NOT EXISTS `arash_coin_wallet_controls` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
        `locked_by` INT(11) NULL,
        `reason` VARCHAR(500) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_wallet` (`user_id`, `workshop_id`)
    ) $T";

    // ── ارتباطات، اعلان، چت ──────────────────────────────────
    $S['notification_recipients'] = "CREATE TABLE IF NOT EXISTS `notification_recipients` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `notification_id` INT(11) NOT NULL,
        `user_id` INT(11) NOT NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `read_at` DATETIME NULL,
        `attachment` VARCHAR(255) NULL,
        `message_text` TEXT NULL,
        `reply_to_task` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_nr_user` (`user_id`, `is_read`),
        KEY `idx_nr_notif` (`notification_id`)
    ) $T";

    $S['push_subscriptions'] = "CREATE TABLE IF NOT EXISTS `push_subscriptions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NULL,
        `endpoint` TEXT NOT NULL,
        `p256dh` VARCHAR(255) NOT NULL,
        `auth` VARCHAR(255) NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['chat_channels'] = "CREATE TABLE IF NOT EXISTS `chat_channels` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `name` VARCHAR(150) NOT NULL,
        `description` VARCHAR(255) NULL,
        `icon` VARCHAR(50) NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['chat_channel_members'] = "CREATE TABLE IF NOT EXISTS `chat_channel_members` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `channel_id` INT(11) NOT NULL,
        `user_id` INT(11) NOT NULL,
        `role` VARCHAR(30) NOT NULL DEFAULT 'member',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_ccm` (`channel_id`, `user_id`)
    ) $T";

    $S['chat_messages'] = "CREATE TABLE IF NOT EXISTS `chat_messages` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `channel_id` INT(11) NULL,
        `sender_id` INT(11) NULL,
        `receiver_id` INT(11) NULL,
        `message` TEXT NULL,
        `attachment` VARCHAR(255) NULL,
        `attachment_type` VARCHAR(30) NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `is_system` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_cm_channel` (`channel_id`, `id`),
        KEY `idx_cm_receiver` (`receiver_id`, `is_read`)
    ) $T";

    // ── مستندات، آرشیو ───────────────────────────────────────
    $S['archive_folders'] = "CREATE TABLE IF NOT EXISTS `archive_folders` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `parent_id` INT(11) NULL,
        `name` VARCHAR(200) NOT NULL,
        `created_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    $S['archive_files'] = "CREATE TABLE IF NOT EXISTS `archive_files` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `workshop_id` INT(11) NULL,
        `folder_id` INT(11) NULL,
        `filename` VARCHAR(255) NOT NULL,
        `original_name` VARCHAR(255) NULL,
        `file_type` VARCHAR(100) NULL,
        `file_size` BIGINT(20) NULL,
        `uploaded_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_af_folder` (`folder_id`)
    ) $T";

    $S['personnel_documents'] = "CREATE TABLE IF NOT EXISTS `personnel_documents` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NOT NULL,
        `workshop_id` INT(11) NULL,
        `document_type` VARCHAR(50) NULL,
        `title` VARCHAR(200) NULL,
        `file_path` VARCHAR(255) NULL,
        `expiry_date` DATE NULL,
        `notes` VARCHAR(500) NULL,
        `uploaded_by` INT(11) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $T";

    // ── لاگ ───────────────────────────────────────────────────
    $S['audit_log'] = "CREATE TABLE IF NOT EXISTS `audit_log` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT(11) NULL,
        `action` VARCHAR(150) NOT NULL,
        `entity_type` VARCHAR(100) NULL,
        `entity_id` INT(11) NULL,
        `old_value` TEXT NULL,
        `new_value` TEXT NULL,
        `ip_address` VARCHAR(64) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_audit_entity` (`entity_type`, `entity_id`),
        KEY `idx_audit_user` (`user_id`, `created_at`)
    ) $T";

    $S['notifications'] = "CREATE TABLE IF NOT EXISTS `notifications` (
        `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `created_by` INT(11) NULL,
        `title` VARCHAR(255) NOT NULL,
        `message` TEXT NULL,
        `type` VARCHAR(50) NOT NULL DEFAULT 'info',
        `is_group` TINYINT(1) NOT NULL DEFAULT 0,
        `is_task` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_notif_created` (`created_at`)
    ) $T";

    return $S;
}

// ─────────────────────────────────────────────────────────────
//  اجرای نصب
// ─────────────────────────────────────────────────────────────
function run_install(PDO $pdo): array {
    $log = [];
    $log[] = '✓ اتصال به دیتابیس برقرار شد.';
    $tables = schema_statements();
    foreach ($tables as $name => $sql) {
        $pdo->exec($sql);
        $log[] = '✓ جدول `' . $name . '` بررسی/ساخته شد.';
    }

    // ── دادهٔ اولیه: یک کارگاه/دفتر پیش‌فرض
    $wsCount = (int)$pdo->query("SELECT COUNT(*) FROM workshops")->fetchColumn();
    if ($wsCount === 0) {
        $pdo->prepare("INSERT INTO workshops (name, employer_name, status, description) VALUES (?,?,?,?)")
            ->execute(['ستاد مرکزی', 'درخت سپید تجارت پارس', 'active', 'واحد سازمانی پیش‌فرض — می‌توانید در تنظیمات تغییر دهید']);
        $log[] = '✓ واحد «ستاد مرکزی» به‌صورت پیش‌فرض ساخته شد.';
    }

    // ── دادهٔ اولیه: ادمین اصلی (فقط اگر هیچ ادمینی وجود ندارد)
    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $generatedPass = null;
    if ($adminCount === 0) {
        $generatedPass = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'xy'), '='); // ۱۲ کاراکتر
        $pdo->prepare("INSERT INTO users (username, password, full_name, role, is_karigar) VALUES (?,?,?,?,0)")
            ->execute(['admin', password_hash($generatedPass, PASSWORD_DEFAULT), 'مدیر کل', 'admin']);
        $log[] = '✓ کاربر «admin» (مدیر کل) ساخته شد.';
    }

    // ── تنظیمات پیش‌فرض
    $defaults = [
        'app_name'     => 'درخت سپید تجارت پارس',
        'app_timezone' => 'Asia/Tehran',
        'app_lang'     => 'fa',
    ];
    $st = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaults as $k => $v) { $st->execute([$k, $v]); }
    $log[] = '✓ تنظیمات پیش‌فرض ثبت شد.';

    return [$log, $generatedPass, count($tables)];
}

// ─────────────────────────────────────────────────────────────
//  پاسخ خروجی (CLI یا HTML)
// ─────────────────────────────────────────────────────────────
$creds  = install_creds();
$isCli  = (PHP_SAPI === 'cli');
$action = null;
$error  = null;
$result = null;
$genPass = null;
$tableCount = 0;

// بررسی توکن فرم (فقط وب)
if (!$isCli && empty($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(16));
}

if ($isCli) {
    if (!$creds) {
        fwrite(STDERR, "اطلاعات دیتابیس پیدا نشد. config.local.php بسازید یا متغیرهای DB_HOST/DB_NAME/DB_USER/DB_PASS را ست کنید.\n");
        exit(1);
    }
    try {
        [$log, $genPass, $tableCount] = run_install(pdo_from($creds[0], $creds[1], $creds[2], $creds[3]));
        foreach ($log as $line) echo $line, "\n";
        if ($genPass) {
            echo "\n========================================\n";
            echo "  رمز ادمین (فقط همین یک‌بار نمایش داده می‌شود):\n  $genPass\n";
            echo "  نام کاربری: admin\n";
            echo "========================================\n";
        }
        echo "\nنصب کامل شد. حتماً این فایل را حذف کنید:  rm install_db.php\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "خطا در نصب: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// ── وب ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['install_token'] ?? '', (string)($_POST['install_token'] ?? ''))) {
        http_response_code(419);
        $error = 'توکن امنیتی نامعتبر است. صفحه را رفرش کنید و دوباره تلاش کنید.';
    } else {
        $host = trim((string)($_POST['db_host'] ?? ($creds[0] ?? '')));
        $name = trim((string)($_POST['db_name'] ?? ($creds[1] ?? '')));
        $user = trim((string)($_POST['db_user'] ?? ($creds[2] ?? '')));
        $pass = (string)($_POST['db_pass'] ?? ($creds[3] ?? ''));
        if ($host === '' || $name === '' || $user === '') {
            $error = 'میزبان، نام دیتابیس و نام کاربری الزامی است.';
        } else {
            try {
                [$result, $genPass, $tableCount] = run_install(pdo_from($host, $name, $user, $pass));
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $msg = str_replace($pass, '••••••', $msg);
                $error = 'خطا در نصب: ' . $msg;
            }
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= INSTALL_TITLE ?></title>
<style>
    body { font-family: Tahoma, Vazirmatn, Arial; background: #0f172a; color: #e2e8f0; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; max-width: 620px; width: 100%; padding: 32px; box-shadow: 0 20px 60px rgba(0,0,0,.4); }
    h1 { font-size: 20px; margin: 0 0 4px; color: #fbbf24; }
    .sub { color: #94a3b8; font-size: 13px; margin-bottom: 20px; }
    label { display: block; font-size: 13px; margin: 14px 0 6px; color: #cbd5e1; }
    input { width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid #334155; background: #0f172a; color: #e2e8f0; font-size: 14px; }
    input:focus { outline: 2px solid #f59e0b; }
    button { width: 100%; margin-top: 18px; padding: 12px; font-size: 15px; font-weight: bold; color: #422006; background: linear-gradient(135deg,#fbbf24,#f59e0b); border: none; border-radius: 10px; cursor: pointer; }
    .ok { background: #052e16; border: 1px solid #16a34a; color: #86efac; padding: 10px 14px; border-radius: 10px; font-size: 13px; margin: 8px 0; }
    .err { background: #450a0a; border: 1px solid #dc2626; color: #fca5a5; padding: 12px 14px; border-radius: 10px; font-size: 13px; margin: 12px 0; }
    .warn { background: #451a03; border: 1px solid #f97316; color: #fdba74; padding: 12px 14px; border-radius: 10px; font-size: 13px; margin-top: 18px; line-height: 1.9; }
    .passbox { direction: ltr; text-align: center; font-size: 22px; letter-spacing: 2px; background: #0f172a; border: 1px dashed #fbbf24; border-radius: 10px; padding: 14px; margin: 14px 0; color: #fde68a; }
    code { background:#0f172a; padding:2px 6px; border-radius:6px; }
</style>
</head>
<body>
<div class="card">
    <h1>🌳 نصب دیتابیس — CRM درخت سپید تجارت پارس</h1>
    <div class="sub">این اسکریپت فقط جدول‌ها را می‌سازد و چیزی را حذف نمی‌کند (CREATE TABLE IF NOT EXISTS).</div>

    <?php if ($error): ?>
        <div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (is_array($result)): ?>
        <?php foreach ($result as $line): ?>
            <div class="ok"><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if ($genPass): ?>
            <p style="margin:18px 0 4px">رمز عبور ادمین (فقط همین یک‌بار نمایش داده می‌شود — همین حالا یادداشت کنید):</p>
            <div class="passbox"><?= htmlspecialchars($genPass, ENT_QUOTES, 'UTF-8') ?></div>
            <p style="font-size:13px;color:#cbd5e1">نام کاربری: <code>admin</code> — پس از اولین ورود از صفحهٔ پروفایل، رمز را تغییر دهید.</p>
        <?php else: ?>
            <p style="font-size:13px;color:#cbd5e1">کاربر ادمین از قبل وجود داشت؛ رمز عبور تغییر نکرد.</p>
        <?php endif; ?>

        <div class="warn">
            ✅ نصب با موفقیت انجام شد (<?= (int)$tableCount ?> جدول بررسی/ساخته شد).<br>
            <strong>قدم آخر: همین حالا فایل <code>install_db.php</code> را از هاست حذف کنید.</strong><br>
            سپس وارد سایت شوید: <code>index.php?page=login</code>
        </div>
    <?php else: ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="install_token" value="<?= htmlspecialchars($_SESSION['install_token'], ENT_QUOTES, 'UTF-8') ?>">
            <label>میزبان دیتابیس (DB Host)</label>
            <input type="text" name="db_host" dir="ltr" placeholder="mysql.example.com" value="<?= htmlspecialchars($creds[0] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
            <label>نام دیتابیس (DB Name)</label>
            <input type="text" name="db_name" dir="ltr" placeholder="db_name" value="<?= htmlspecialchars($creds[1] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
            <label>نام کاربری دیتابیس (DB User)</label>
            <input type="text" name="db_user" dir="ltr" value="<?= htmlspecialchars($creds[2] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
            <label>رمز عبور دیتابیس (DB Password)</label>
            <input type="password" name="db_pass" dir="ltr" value="<?= htmlspecialchars($creds[3] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">ساخت جدول‌ها و نصب</button>
        </form>
        <div class="warn">
            قبل از اجرا: مطمئن شوید دیتابیس روی هاست ساخته شده. این عملیات فقط جدول‌های ناقص را می‌سازد.<br>
            بعد از نصب موفق، حتماً این فایل را حذف کنید.
        </div>
    <?php endif; ?>
</div>
</body>
</html>
