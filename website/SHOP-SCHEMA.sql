-- ============================================================================
-- فروشگاه درخت سپید تجارت پارس — SHOP SCHEMA
-- برای اجرا در همان دیتابیس wttpir_sepid
-- این فایل غیرمخرب و تکرارپذیر است: جداول موجود را نمی‌سازد و فقط جداول جدید می‌سازد
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- دسته‌بندی محصولات
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name_fa` VARCHAR(100) NOT NULL,
    `name_en` VARCHAR(100) NOT NULL DEFAULT '',
    `slug` VARCHAR(120) NOT NULL UNIQUE,
    `icon` VARCHAR(16) NOT NULL DEFAULT '📦',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `display_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- محصولات
-- قیمت: یا تومان (price_toman) یا دلار (price_usd) — ستون currency مشخص‌کننده است
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name_fa` VARCHAR(200) NOT NULL,
    `name_en` VARCHAR(200) NOT NULL DEFAULT '',
    `slug` VARCHAR(220) NOT NULL UNIQUE,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `brand` VARCHAR(100) NOT NULL DEFAULT '',
    `short_desc_fa` VARCHAR(500) NOT NULL DEFAULT '',
    `description_fa` MEDIUMTEXT,
    `images` MEDIUMTEXT COMMENT 'JSON array of /uploads/... paths',
    `price_toman` BIGINT UNSIGNED DEFAULT NULL COMMENT 'قیمت به تومان',
    `price_usd` DECIMAL(12,2) DEFAULT NULL COMMENT 'قیمت به دلار',
    `currency` ENUM('toman','usd') NOT NULL DEFAULT 'toman',
    `old_price_toman` BIGINT UNSIGNED DEFAULT NULL COMMENT 'قیمت قبل از تخفیف (برای بج)',
    `stock` INT UNSIGNED NOT NULL DEFAULT 0,
    `sku` VARCHAR(60) NOT NULL DEFAULT '',
    `weight_kg` DECIMAL(8,2) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_featured` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'محصول ویژه/محبوب',
    `views` INT UNSIGNED NOT NULL DEFAULT 0,
    `sold_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_products_category` (`category_id`),
    INDEX `idx_products_active` (`is_active`),
    INDEX `idx_products_featured` (`is_featured`),
    INDEX `idx_products_created` (`created_at`),
    INDEX `idx_products_sold` (`sold_count`),
    CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- دیدگاه مشتریان
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_reviews` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED DEFAULT NULL,
    `customer_name` VARCHAR(100) NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `body` TEXT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_reviews_product` (`product_id`),
    CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- کاربران (مشتریان) فروشگاه
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(200) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(30) NOT NULL DEFAULT '',
    `province` VARCHAR(100) NOT NULL DEFAULT '',
    `city` VARCHAR(100) NOT NULL DEFAULT '',
    `postal_code` VARCHAR(20) NOT NULL DEFAULT '',
    `address` VARCHAR(500) NOT NULL DEFAULT '',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cust_email` (`email`),
    INDEX `idx_cust_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- سفارش‌ها (اطلاعات مشتری اسنپ‌شات گرفته می‌شود)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_no` VARCHAR(30) NOT NULL UNIQUE,
    `customer_id` INT UNSIGNED DEFAULT NULL,
    `customer_name` VARCHAR(100) NOT NULL,
    `customer_phone` VARCHAR(30) NOT NULL,
    `customer_email` VARCHAR(200) NOT NULL DEFAULT '',
    `province` VARCHAR(100) NOT NULL DEFAULT '',
    `city` VARCHAR(100) NOT NULL DEFAULT '',
    `postal_code` VARCHAR(20) NOT NULL DEFAULT '',
    `address` VARCHAR(500) NOT NULL,
    `notes` TEXT,
    `subtotal_toman` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `shipping_fee` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `discount_toman` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `coupon_code` VARCHAR(40) DEFAULT NULL,
    `total_toman` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `usd_rate` INT UNSIGNED DEFAULT NULL COMMENT 'نرخ دلار در زمان ثبت سفارش',
    `payment_status` ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    `order_status` ENUM('pending','confirmed','packed','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
    `status_note` TEXT,
    `success_token` VARCHAR(64) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_orders_customer` (`customer_id`),
    INDEX `idx_orders_status` (`order_status`),
    INDEX `idx_orders_payment` (`payment_status`),
    INDEX `idx_orders_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- اقلام سفارش (اسنپ‌شات قیمت در زمان خرید)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED DEFAULT NULL,
    `product_name` VARCHAR(200) NOT NULL,
    `product_image` VARCHAR(500) DEFAULT NULL,
    `price_toman` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `qty` INT UNSIGNED NOT NULL DEFAULT 1,
    `total_toman` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX `idx_items_order` (`order_id`),
    CONSTRAINT `fk_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_items_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- تاریخچه وضعیت سفارش
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_events` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `note` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_events_order` (`order_id`),
    CONSTRAINT `fk_events_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- کدهای تخفیف
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `discount_codes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(40) NOT NULL UNIQUE,
    `type` ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    `value` DECIMAL(10,2) NOT NULL COMMENT 'درصد یا مبلغ تومان',
    `min_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'حداقل مبلغ سفارش (تومان)',
    `max_uses` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = نامحدود',
    `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` DATETIME DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- SEED: دسته‌بندی‌های اولیه
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `product_categories` (`name_fa`, `name_en`, `slug`, `icon`, `display_order`) VALUES
('الکترونیک و موبایل', 'Electronics', 'electronics', '📱', 1),
('خانه و آشپزخانه', 'Home & Kitchen', 'home-kitchen', '🏠', 2),
('لوازم جانبی', 'Accessories', 'accessories', '🎧', 3),
('ورزش و سفر', 'Sport & Travel', 'sport-travel', '⚽', 4);

-- ----------------------------------------------------------------------------
-- SEED: تنظیمات فروشگاه
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `site_settings` (`setting_key`, `setting_value`, `setting_type`, `category`, `label_fa`, `label_en`) VALUES
('shop_name', 'فروشگاه درخت سپید', 'text', 'shop', 'نام فروشگاه', 'Shop name'),
('shop_shipping_cost', '500000', 'number', 'shop', 'هزینه ارسال (تومان)', 'Shipping cost (Toman)'),
('shop_free_shipping_min', '30000000', 'number', 'shop', 'حداقل مبلغ ارسال رایگان (تومان، 0 = غیرفعال)', 'Free shipping min (Toman, 0=off)'),
('shop_usd_manual', '', 'text', 'shop', 'نرخ دلار دستی (خالی = خودکار)', 'Manual USD rate (empty=auto)'),
('shop_usd_default', '950000', 'number', 'shop', 'نرخ پیش‌فرض دلار (بازگشتی)', 'Default USD rate (fallback)'),
('shop_rate_ttl', '1800', 'number', 'shop', 'معتبر بودن نرخ (ثانیه)', 'Rate cache TTL (seconds)'),
('shop_per_page', '12', 'number', 'shop', 'تعداد محصولات در صفحه', 'Products per page');
