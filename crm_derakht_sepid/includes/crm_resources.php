<?php
/** Shared installer/runtime schema for the sourcing catalogue and cold-call queue. */
function crm_resource_schema(): array {
    $tail = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'crm_products' => "CREATE TABLE IF NOT EXISTS crm_products (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL,
            specs TEXT NULL, supplier VARCHAR(200) NULL, factory_price DECIMAL(20,4) NULL,
            customer_price DECIMAL(20,4) NULL, currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            unit VARCHAR(30) NULL, price_date DATE NULL, image_path VARCHAR(255) NULL,
            notes TEXT NULL, created_by INT NULL, updated_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_product_name (name)
        ) $tail",
        'crm_cold_numbers' => "CREATE TABLE IF NOT EXISTS crm_cold_numbers (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(20) NOT NULL,
            name VARCHAR(150) NULL, company VARCHAR(200) NULL, city VARCHAR(100) NULL, notes TEXT NULL,
            owner_user_id INT NULL, team_id INT NULL, status VARCHAR(20) NOT NULL DEFAULT 'new',
            customer_id INT NULL, created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cold_phone (phone), KEY idx_cold_owner (owner_user_id, id), KEY idx_cold_team (team_id, id), KEY idx_cold_status (status)
        ) $tail",
        'crm_cold_calls' => "CREATE TABLE IF NOT EXISTS crm_cold_calls (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, cold_id INT NOT NULL, user_id INT NOT NULL,
            outcome VARCHAR(30) NOT NULL, note TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cold_call (cold_id, id)
        ) $tail",
    ];
}
function ensure_crm_resources(PDO $pdo): bool {
    try { foreach (crm_resource_schema() as $sql) $pdo->exec($sql); return true; }
    catch (Throwable $e) { error_log('CRM resources schema: ' . $e->getMessage()); return false; }
}
