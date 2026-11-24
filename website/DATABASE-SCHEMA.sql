-- WARNING: DESTRUCTIVE REFERENCE ONLY. DROP TABLE statements erase existing site data. NEVER run on a live database.
-- ============================================================================
-- درخت سپید تجارت پارس — Database Schema
-- Version: 1.0
-- ============================================================================
-- این فایل رو در phpMyAdmin (cPanel > Databases > phpMyAdmin) اجرا کنید
-- بعد از انتخاب دیتابیس، به تب SQL بروید و این محتوا را paste کنید
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Admins & Staff (کاربران پنل)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(200) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` ENUM('superadmin', 'manager', 'sales', 'operations', 'support') NOT NULL DEFAULT 'support',
    `avatar` VARCHAR(500) DEFAULT NULL,
    `phone` VARCHAR(30) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME DEFAULT NULL,
    `last_login_ip` VARCHAR(45) DEFAULT NULL,
    `failed_login_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_email` (`email`),
    INDEX `idx_role` (`role`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admin_sessions`;
CREATE TABLE `admin_sessions` (
    `id` VARCHAR(128) PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `payload` TEXT,
    `last_activity` DATETIME NOT NULL,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_activity` (`last_activity`),
    FOREIGN KEY (`user_id`) REFERENCES `admin_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admin_login_attempts`;
CREATE TABLE `admin_login_attempts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `success` TINYINT(1) NOT NULL DEFAULT 0,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip_time` (`ip_address`, `attempted_at`),
    INDEX `idx_username_time` (`username`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `admin_activity_log`;
CREATE TABLE `admin_activity_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) DEFAULT NULL,
    `entity_id` INT UNSIGNED DEFAULT NULL,
    `description` TEXT,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Contact Messages (پیام‌های تماس)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `contact_messages`;
CREATE TABLE `contact_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(200) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `subject` VARCHAR(200) DEFAULT NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('new', 'in_progress', 'replied', 'closed', 'spam') NOT NULL DEFAULT 'new',
    `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    `assigned_to` INT UNSIGNED DEFAULT NULL,
    `replied_at` DATETIME DEFAULT NULL,
    `replied_by` INT UNSIGNED DEFAULT NULL,
    `reply_message` TEXT,
    `internal_notes` TEXT,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_assigned` (`assigned_to`),
    INDEX `idx_email` (`email`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`assigned_to`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`replied_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Team Members (اعضای تیم)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `team_members`;
CREATE TABLE `team_members` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `role` VARCHAR(200) NOT NULL,
    `handle` VARCHAR(100) DEFAULT NULL,
    `bio` TEXT,
    `image_path` VARCHAR(500) DEFAULT NULL,
    `email` VARCHAR(200) DEFAULT NULL,
    `linkedin_url` VARCHAR(500) DEFAULT NULL,
    `display_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_order` (`display_order`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Services (خدمات)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `services`;
CREATE TABLE `services` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `icon_path` VARCHAR(500) DEFAULT NULL,
    `title_fa` VARCHAR(200) NOT NULL,
    `title_en` VARCHAR(200) DEFAULT NULL,
    `description_fa` TEXT NOT NULL,
    `description_en` TEXT,
    `display_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_order` (`display_order`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Network (شبکه جهانی)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `network_locations`;
CREATE TABLE `network_locations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `country_code` VARCHAR(2) NOT NULL,
    `country_name_fa` VARCHAR(100) NOT NULL,
    `country_name_en` VARCHAR(100) DEFAULT NULL,
    `flag_emoji` VARCHAR(8) DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `lat` DECIMAL(10, 6) DEFAULT NULL,
    `lon` DECIMAL(10, 6) DEFAULT NULL,
    `display_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_order` (`display_order`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_country` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Blog Posts (مقالات بلاگ)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `blog_categories`;
CREATE TABLE `blog_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `name_fa` VARCHAR(100) NOT NULL,
    `name_en` VARCHAR(100) DEFAULT NULL,
    `description` TEXT,
    `display_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `blog_posts`;
CREATE TABLE `blog_posts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `slug` VARCHAR(200) NOT NULL UNIQUE,
    `title_fa` VARCHAR(300) NOT NULL,
    `title_en` VARCHAR(300) DEFAULT NULL,
    `excerpt_fa` TEXT,
    `excerpt_en` TEXT,
    `content_fa` LONGTEXT NOT NULL,
    `content_en` LONGTEXT,
    `cover_image` VARCHAR(500) DEFAULT NULL,
    `author_id` INT UNSIGNED DEFAULT NULL,
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
    `published_at` DATETIME DEFAULT NULL,
    `views` INT UNSIGNED NOT NULL DEFAULT 0,
    `meta_title` VARCHAR(300) DEFAULT NULL,
    `meta_description` TEXT,
    `meta_keywords` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_published` (`is_published`, `published_at`),
    INDEX `idx_category` (`category_id`),
    INDEX `idx_featured` (`is_featured`),
    FULLTEXT KEY `ft_search_fa` (`title_fa`, `excerpt_fa`, `content_fa`),
    FOREIGN KEY (`category_id`) REFERENCES `blog_categories`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`author_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. Content Sections (متن سکشن‌های صفحه اصلی)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `content_sections`;
CREATE TABLE `content_sections` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `section_key` VARCHAR(50) NOT NULL UNIQUE,
    `title_fa` VARCHAR(300) DEFAULT NULL,
    `title_en` VARCHAR(300) DEFAULT NULL,
    `subtitle_fa` TEXT,
    `subtitle_en` TEXT,
    `body_fa` LONGTEXT,
    `body_en` LONGTEXT,
    `extra_data` JSON DEFAULT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`updated_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. Why Us / مزیت‌ها
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `why_us_items`;
CREATE TABLE `why_us_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `number_label` VARCHAR(10) NOT NULL,
    `title_fa` VARCHAR(200) NOT NULL,
    `title_en` VARCHAR(200) DEFAULT NULL,
    `description_fa` TEXT NOT NULL,
    `description_en` TEXT,
    `display_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_order` (`display_order`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. Process Steps (مراحل فرآیند)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `process_steps`;
CREATE TABLE `process_steps` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `step_number` VARCHAR(5) NOT NULL,
    `title_fa` VARCHAR(200) NOT NULL,
    `title_en` VARCHAR(200) DEFAULT NULL,
    `description_fa` TEXT NOT NULL,
    `description_en` TEXT,
    `display_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    INDEX `idx_order` (`display_order`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. Site Settings (تنظیمات سایت)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `site_settings`;
CREATE TABLE `site_settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` LONGTEXT,
    `setting_type` ENUM('text', 'longtext', 'json', 'boolean', 'number', 'email', 'url') NOT NULL DEFAULT 'text',
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `label_fa` VARCHAR(200) DEFAULT NULL,
    `label_en` VARCHAR(200) DEFAULT NULL,
    `description` TEXT,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`updated_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11. Shipment Tracking (ردیابی محموله)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `shipments`;
CREATE TABLE `shipments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tracking_code` VARCHAR(50) NOT NULL UNIQUE,
    `customer_name` VARCHAR(200) NOT NULL,
    `customer_email` VARCHAR(200) DEFAULT NULL,
    `customer_phone` VARCHAR(50) DEFAULT NULL,
    `origin_country` VARCHAR(100) NOT NULL,
    `origin_city` VARCHAR(100) DEFAULT NULL,
    `destination_country` VARCHAR(100) NOT NULL,
    `destination_city` VARCHAR(100) DEFAULT NULL,
    `weight_kg` DECIMAL(10, 2) DEFAULT NULL,
    `volume_m3` DECIMAL(10, 3) DEFAULT NULL,
    `package_count` INT UNSIGNED DEFAULT 1,
    `service_type` ENUM('air', 'sea', 'land', 'multimodal') NOT NULL,
    `status` ENUM(
        'registered', 'picked_up', 'in_transit', 'customs_clearance',
        'out_for_delivery', 'delivered', 'cancelled', 'on_hold'
    ) NOT NULL DEFAULT 'registered',
    `estimated_delivery` DATE DEFAULT NULL,
    `actual_delivery` DATE DEFAULT NULL,
    `notes` TEXT,
    `is_public` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_tracking` (`tracking_code`),
    INDEX `idx_status` (`status`),
    INDEX `idx_customer_email` (`customer_email`),
    FOREIGN KEY (`created_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `shipment_events`;
CREATE TABLE `shipment_events` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `shipment_id` INT UNSIGNED NOT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `location` VARCHAR(200) DEFAULT NULL,
    `description_fa` TEXT NOT NULL,
    `description_en` TEXT,
    `event_time` DATETIME NOT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_shipment` (`shipment_id`, `event_time`),
    FOREIGN KEY (`shipment_id`) REFERENCES `shipments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12. Analytics (آمار بازدید)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `page_views`;
CREATE TABLE `page_views` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `session_id` VARCHAR(64) NOT NULL,
    `page_url` VARCHAR(500) NOT NULL,
    `page_title` VARCHAR(300) DEFAULT NULL,
    `referrer` VARCHAR(500) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `ip_hash` VARCHAR(64) DEFAULT NULL,
    `country_code` VARCHAR(2) DEFAULT NULL,
    `device_type` ENUM('desktop', 'mobile', 'tablet', 'bot') DEFAULT NULL,
    `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_session` (`session_id`),
    INDEX `idx_page` (`page_url`(191)),
    INDEX `idx_viewed` (`viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `conversion_events`;
CREATE TABLE `conversion_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_type` ENUM('contact_form', 'phone_click', 'email_click', 'whatsapp_click', 'cta_click') NOT NULL,
    `page_url` VARCHAR(500) DEFAULT NULL,
    `session_id` VARCHAR(64) DEFAULT NULL,
    `value` VARCHAR(200) DEFAULT NULL,
    `ip_hash` VARCHAR(64) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_type` (`event_type`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 13. Newsletter (خبرنامه)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `newsletter_subscribers`;
CREATE TABLE `newsletter_subscribers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(200) NOT NULL UNIQUE,
    `name` VARCHAR(100) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `subscribed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `unsubscribed_at` DATETIME DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    INDEX `idx_email` (`email`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 14. Translations (ترجمه‌ها)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `translations`;
CREATE TABLE `translations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `translation_key` VARCHAR(200) NOT NULL,
    `lang` ENUM('fa', 'en') NOT NULL,
    `value` TEXT NOT NULL,
    `category` VARCHAR(50) DEFAULT 'general',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_key_lang` (`translation_key`, `lang`),
    INDEX `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- SEED DATA — داده‌های اولیه
-- ----------------------------------------------------------------------------

-- Default Superadmin (password: Admin@123 — MUST CHANGE AFTER FIRST LOGIN!)
-- حساب مدیر پیش‌فرض عمداً حذف شده است؛ ایجاد حساب باید امن و دستی انجام شود.

-- Default site settings
INSERT INTO `site_settings` (`setting_key`, `setting_value`, `setting_type`, `category`, `label_fa`, `label_en`) VALUES
('site_name', 'درخت سپید تجارت پارس', 'text', 'general', 'نام سایت', 'Site Name'),
('site_email', 'info@sepidtrade.com', 'email', 'general', 'ایمیل اصلی', 'Main Email'),
('site_phone', '+98 21 0000 0000', 'text', 'general', 'تلفن', 'Phone'),
('site_address', 'دفتر مرکزی: ایران', 'text', 'general', 'آدرس', 'Address'),
('hero_title', 'درخت سپید تجارت پارس', 'text', 'hero', 'عنوان اصلی', 'Hero Title'),
('hero_subtitle', 'حمل‌ونقل بین‌المللی، ترخیص کالا و بازرگانی', 'text', 'hero', 'زیرعنوان', 'Hero Subtitle'),
('hero_tagline', 'ارتباط مطمئن ایران با جهان با سرعت، تخصص و اعتماد', 'text', 'hero', 'شعار', 'Hero Tagline'),
('default_lang', 'fa', 'text', 'i18n', 'زبان پیش‌فرض', 'Default Language'),
('available_langs', '["fa","en"]', 'json', 'i18n', 'زبان‌های فعال', 'Available Languages'),
('seo_meta_title', 'درخت سپید تجارت پارس | حمل‌ونقل بین‌المللی', 'text', 'seo', 'عنوان سئو', 'SEO Title'),
('seo_meta_description', 'خدمات حمل‌ونقل بین‌المللی، ترخیص کالا و بازرگانی. بیش از ۱۰ سال تجربه در لجستیک بین‌المللی.', 'longtext', 'seo', 'توضیحات سئو', 'SEO Description'),
('seo_og_image', '', 'url', 'seo', 'تصویر OG', 'OG Image'),
('google_analytics_id', '', 'text', 'analytics', 'کد GA4', 'GA4 ID'),
('recaptcha_site_key', '', 'text', 'security', 'کلید سایت reCAPTCHA', 'reCAPTCHA Site Key'),
('recaptcha_secret_key', '', 'text', 'security', 'کلید مخفی reCAPTCHA', 'reCAPTCHA Secret'),
('contact_form_emails', '["info@sepidtrade.com"]', 'json', 'contact', 'ایمیل‌های دریافت پیام', 'Contact Form Recipients'),
('contact_rate_limit', '5', 'number', 'contact', 'سقف پیام در ساعت', 'Rate Limit Per Hour'),
('social_instagram', '', 'url', 'social', 'اینستاگرام', 'Instagram'),
('social_linkedin', '', 'url', 'social', 'لینکدین', 'LinkedIn'),
('social_whatsapp', '', 'text', 'social', 'واتساپ', 'WhatsApp'),
('social_telegram', '', 'text', 'social', 'تلگرام', 'Telegram');

-- Default content sections
INSERT INTO `content_sections` (`section_key`, `title_fa`, `body_fa`) VALUES
('about', 'درباره ما', 'شرکت درخت سپید تجارت پارس با بیش از ۱۰ سال تجربه در حوزه حمل‌ونقل بین‌المللی، ترخیص کالا و بازرگانی خارجی، شهرت قوی در ارائه خدمات سریع و قابل اعتماد کسب کرده است.'),
('hero', 'درخت سپید تجارت پارس', 'حمل‌ونقل بین‌المللی، ترخیص کالا و بازرگانی');

-- Default services
INSERT INTO `services` (`icon_path`, `title_fa`, `description_fa`, `display_order`) VALUES
('assets/icons/icons8-around-the-globe-100.png', 'حمل هوایی', 'تحویل سریع و امن برای کالاهای حساس به زمان', 1),
('assets/icons/icons8-cruise-ship-100.png', 'حمل دریایی', 'راه‌حل‌های اقتصادی برای محموله‌های حجیم', 2),
('assets/icons/icons8-camper-100.png', 'حمل زمینی', 'حمل جاده‌ای و ریلی قابل اعتماد', 3),
('assets/icons/icons8-compare-100.png', 'ترخیص کالا', 'ترخیص سریع در گمرک امام خمینی', 4);

-- Default network locations
INSERT INTO `network_locations` (`country_code`, `country_name_fa`, `flag_emoji`, `city`, `lat`, `lon`, `display_order`) VALUES
('CN', 'چین', '🇨🇳', 'Xi\'an', 34.3416, 108.9398, 1),
('IN', 'هند', '🇮🇳', 'New Delhi', 28.6139, 77.2090, 2),
('HK', 'هنگ‌کنگ', '🇭🇰', 'Hong Kong', 22.3193, 114.1694, 3),
('KR', 'کره جنوبی', '🇰🇷', 'Seoul', 37.5665, 126.9780, 4),
('DE', 'آلمان', '🇩🇪', 'Frankfurt', 50.1109, 8.6821, 5),
('IR', 'ایران', '🇮🇷', 'Tehran', 35.6892, 51.3890, 6);

-- Default why us items
INSERT INTO `why_us_items` (`number_label`, `title_fa`, `description_fa`, `display_order`) VALUES
('۰۱', 'تجربه اثبات‌شده', 'بیش از ۱۰ سال سابقه در حوزه لجستیک', 1),
('۰۲', 'سرعت بی‌نظیر', 'یکی از سریع‌ترین زمان‌های ترخیص', 2),
('۰۳', 'تخصص عمیق', 'دانش تخصصی در حوزه‌های خاص', 3);

-- Default process steps
INSERT INTO `process_steps` (`step_number`, `title_fa`, `description_fa`, `display_order`) VALUES
('۱', 'مشاوره اولیه', 'درک دقیق نیازهای محموله شما', 1),
('۲', 'برنامه‌ریزی', 'انتخاب بهترین روش حمل و مسیر', 2),
('۳', 'تهیه مدارک', 'تهیه تمام اسناد لازم', 3);

-- Default blog categories
INSERT INTO `blog_categories` (`slug`, `name_fa`, `name_en`, `display_order`) VALUES
('logistics', 'لجستیک', 'Logistics', 1),
('customs', 'گمرک', 'Customs', 2),
('trade', 'تجارت', 'Trade', 3),
('news', 'اخبار', 'News', 4);

-- Default translations
INSERT INTO `translations` (`translation_key`, `lang`, `value`, `category`) VALUES
('nav.home', 'fa', 'خانه', 'navigation'),
('nav.home', 'en', 'Home', 'navigation'),
('nav.about', 'fa', 'درباره ما', 'navigation'),
('nav.about', 'en', 'About Us', 'navigation'),
('nav.services', 'fa', 'خدمات', 'navigation'),
('nav.services', 'en', 'Services', 'navigation'),
('nav.network', 'fa', 'شبکه جهانی', 'navigation'),
('nav.network', 'en', 'Global Network', 'navigation'),
('nav.why', 'fa', 'چرا ما', 'navigation'),
('nav.why', 'en', 'Why Us', 'navigation'),
('nav.process', 'fa', 'فرآیند', 'navigation'),
('nav.process', 'en', 'Process', 'navigation'),
('nav.team', 'fa', 'تیم ما', 'navigation'),
('nav.team', 'en', 'Our Team', 'navigation'),
('nav.contact', 'fa', 'تماس', 'navigation'),
('nav.contact', 'en', 'Contact', 'navigation');

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- نصب تمام شد.
-- حالا فایل /admin/install.php را اجرا کنید تا:
--   1) رمز عبور پیش‌فرض admin ساخته شود
--   2) جداول verify شوند
--   3) اولین session ایجاد شود
-- ============================================================================
