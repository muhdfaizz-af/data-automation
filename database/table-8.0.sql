-- ============================================================
-- Daily Sales Report - MySQL Schema
-- ============================================================
-- Engine: InnoDB (support FK + transaction)
-- Charset: utf8mb4 (support nama & remark dalam pelbagai bahasa)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- Table: admin_users
-- ============================================================
CREATE TABLE `admin_users` (
  `id`         int            NOT NULL AUTO_INCREMENT,
  `username`   varchar(100)   NOT NULL,
  `password`   varchar(255)   NOT NULL COMMENT 'bcrypt hashed',
  `created_at` timestamp      NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Table: companies
-- ============================================================
DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_code` VARCHAR(10) NOT NULL,
  `company_name` VARCHAR(100) NOT NULL,
  `country_code` CHAR(2) NOT NULL,
  `currency_code` CHAR(3) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_companies_company_code` (`company_code`),
  KEY `idx_companies_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores Malaysia and Singapore company information';

INSERT IGNORE INTO `companies` (`company_code`, `company_name`, `country_code`, `currency_code`)
VALUES
  ('MYHQ', 'Malaysia Headquarters', 'MY', 'MYR'),
  ('SGHQ', 'Singapore Headquarters', 'SG', 'SGD');


-- ============================================================
-- Table: import_batches
-- ============================================================
DROP TABLE IF EXISTS `import_batches`;
CREATE TABLE `import_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `file_type` VARCHAR(30) NOT NULL DEFAULT 'ORDER_HISTORY',
  `original_filename` VARCHAR(255) NOT NULL,
  `file_hash` VARCHAR(64) DEFAULT NULL,
  `period_from` DATE DEFAULT NULL,
  `period_to` DATE DEFAULT NULL,
  `total_rows` INT NOT NULL DEFAULT 0,
  `successful_rows` INT NOT NULL DEFAULT 0,
  `failed_rows` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'enum: pending, processing, completed, completed_with_errors, failed',
  `error_message` TEXT DEFAULT NULL,
  `imported_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_import_batches_file_hash` (`file_hash`),
  KEY `idx_import_batches_company_id` (`company_id`),
  KEY `idx_import_batches_status` (`status`),
  KEY `idx_import_batches_period` (`period_from`, `period_to`),
  CONSTRAINT `fk_import_batches_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Records every Order History file uploaded';


-- ============================================================
-- Table: orders
-- ============================================================
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `import_batch_id` BIGINT UNSIGNED DEFAULT NULL,
  `order_id` VARCHAR(80) NOT NULL,
  `order_datetime` DATETIME NOT NULL,
  `member_code` VARCHAR(50) DEFAULT NULL,
  `member_type` VARCHAR(50) DEFAULT NULL,
  `member_name` VARCHAR(150) DEFAULT NULL,
  `remark` TEXT DEFAULT NULL,
  `delivery_method` VARCHAR(100) DEFAULT NULL,
  `mobile_no` VARCHAR(30) DEFAULT NULL,
  `order_type` VARCHAR(100) DEFAULT NULL,
  `sub_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Used to calculate Daily Sales',
  `shipping_fee` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `voucher_discount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `convenience_fee` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `order_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `gst` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_bv` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_pv` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_tp` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `payment_mode` VARCHAR(100) DEFAULT NULL,
  `order_status` VARCHAR(50) DEFAULT NULL,
  `delivery_status` VARCHAR(50) DEFAULT NULL,
  `payment_gateway` VARCHAR(100) DEFAULT NULL,
  `payment_gateway_id` VARCHAR(150) DEFAULT NULL,
  `currency_code` CHAR(3) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_company_order` (`company_id`, `order_id`),
  KEY `idx_orders_order_datetime` (`order_datetime`),
  KEY `idx_orders_company_datetime` (`company_id`, `order_datetime`),
  KEY `idx_orders_order_status` (`order_status`),
  KEY `idx_orders_order_type` (`order_type`),
  KEY `idx_orders_member_code` (`member_code`),
  KEY `idx_orders_import_batch_id` (`import_batch_id`),
  CONSTRAINT `fk_orders_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_import_batch`
    FOREIGN KEY (`import_batch_id`) REFERENCES `import_batches` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores Order History MY and Order History SG - satu row = satu order (header level)';


-- ============================================================
-- Table: order_items
-- ============================================================
DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to orders.id',
  `commission_month` VARCHAR(10) DEFAULT NULL,
  `cdo` VARCHAR(50) DEFAULT NULL COMMENT 'CDO code, boleh null',
  `cdo_created_date` DATETIME DEFAULT NULL,
  `product_type` VARCHAR(20) DEFAULT NULL COMMENT 'Normal atau Composite - Composite = bundle/carton yang ada component row bawah dia',
  `item_code` VARCHAR(50) NOT NULL,
  `item_description` VARCHAR(255) DEFAULT NULL,
  `brand` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL COMMENT 'Dari Tax Invoice Listing',
  `total_weight` DECIMAL(15,2) DEFAULT NULL,
  `qty` INT NOT NULL DEFAULT 0,
  `bv` DECIMAL(15,2) DEFAULT NULL,
  `pv` DECIMAL(15,2) DEFAULT NULL,
  `total_bv` DECIMAL(15,2) DEFAULT NULL,
  `total_pv` DECIMAL(15,2) DEFAULT NULL,
  `total_retail_price` DECIMAL(15,2) DEFAULT NULL,
  `discount` DECIMAL(15,2) DEFAULT NULL,
  `invoice_amount` DECIMAL(15,2) DEFAULT NULL,
  `total_invoice_amount_paid` DECIMAL(15,2) DEFAULT NULL,
  `order_processed_location` VARCHAR(20) DEFAULT NULL COMMENT 'MYHQWH, MYBTWH, dll',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order_id` (`order_id`),
  KEY `idx_order_items_item_code` (`item_code`),
  KEY `idx_order_items_order_item` (`order_id`, `item_code`),
  KEY `idx_order_items_product_type` (`product_type`),
  KEY `idx_order_items_email` (`email`),
  CONSTRAINT `fk_order_items_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Line items dari Tax Invoice Listing - satu order ada banyak row (banyak SKU)';


-- ============================================================
-- Table: sales_channels
-- ============================================================
DROP TABLE IF EXISTS `sales_channels`;
CREATE TABLE `sales_channels` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_code` VARCHAR(50) NOT NULL,
  `channel_name` VARCHAR(100) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_channels_channel_code` (`channel_code`),
  KEY `idx_sales_channels_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores manual/external sales channel names';


-- ============================================================
-- Table: manual_sales
-- ============================================================
DROP TABLE IF EXISTS `manual_sales`;
CREATE TABLE `manual_sales` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sales_channel_id` BIGINT UNSIGNED NOT NULL,
  `sales_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `currency_code` CHAR(3) NOT NULL DEFAULT 'MYR',
  `remarks` TEXT DEFAULT NULL,
  `entered_by` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Can be connected to users.id later',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_manual_sales_channel_date` (`sales_channel_id`, `sales_date`),
  KEY `idx_manual_sales_sales_date` (`sales_date`),
  CONSTRAINT `fk_manual_sales_channel`
    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channels` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores sales that are not available in Solucis';


-- ============================================================
-- Table: exchange_rates
-- ============================================================
DROP TABLE IF EXISTS `exchange_rates`;
CREATE TABLE `exchange_rates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_currency` CHAR(3) NOT NULL,
  `to_currency` CHAR(3) NOT NULL,
  `rate` DECIMAL(12,6) NOT NULL COMMENT 'must be > 0',
  `effective_from` DATE NOT NULL,
  `effective_to` DATE DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exchange_rates_pair_from` (`from_currency`, `to_currency`, `effective_from`),
  KEY `idx_exchange_rates_pair_range` (`from_currency`, `to_currency`, `effective_from`, `effective_to`),
  CONSTRAINT `chk_exchange_rates_rate_positive` CHECK (`rate` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores exchange rates such as SGD to MYR (OPTIONAL)';


-- ============================================================
-- Table: daily_report_runs
-- ============================================================
DROP TABLE IF EXISTS `daily_report_runs`;
CREATE TABLE `daily_report_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `working_date` DATE NOT NULL,
  `period_start` DATETIME NOT NULL,
  `period_end` DATETIME NOT NULL,
  `my_subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `sg_subtotal_sgd` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `exchange_rate_used` DECIMAL(12,6) NOT NULL DEFAULT 1.000000,
  `sg_subtotal_myr` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `solucis_sales` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `manual_sales_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `daily_total_sales` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(20) NOT NULL DEFAULT 'processing' COMMENT 'enum: processing, completed, failed',
  `generated_at` DATETIME DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_daily_report_runs_working_date` (`working_date`),
  KEY `idx_daily_report_runs_period` (`period_start`, `period_end`),
  KEY `idx_daily_report_runs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores generated Daily Sales Report results (OPTIONAL)';

SET FOREIGN_KEY_CHECKS = 1;