<?php
/**
 * Migration: 001_initial_schema
 * Creates all initial database tables
 */

return function($db) {
    // Disable foreign key checks temporarily
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Companies table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `companies` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `code` VARCHAR(20) NOT NULL UNIQUE,
            `theme` VARCHAR(20) DEFAULT 'default' COMMENT 'CSS theme class',
            `logo` VARCHAR(100) NULL COMMENT 'Logo filename',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Departments table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `departments` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `unique_dept_company` (`company_id`, `name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Employees table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `employees` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT UNSIGNED NOT NULL,
            `department_id` INT UNSIGNED NULL,
            `name` VARCHAR(150) NOT NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
            INDEX `idx_employee_company` (`company_id`),
            INDEX `idx_employee_department` (`department_id`),
            INDEX `idx_employee_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Users table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `full_name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NULL,
            `role` ENUM('admin', 'user') DEFAULT 'user',
            `is_active` TINYINT(1) DEFAULT 1,
            `last_login` TIMESTAMP NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_user_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Locations table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `locations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `unique_location_company` (`company_id`, `name`),
            INDEX `idx_location_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Categories table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `categories` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL UNIQUE,
            `has_expiration` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Items table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT UNSIGNED NOT NULL,
            `category_id` INT UNSIGNED NULL,
            `code` VARCHAR(50) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `pieces_per_package` INT UNSIGNED DEFAULT 1,
            `price` DECIMAL(10,2) NULL,
            `minimum_stock` INT UNSIGNED DEFAULT 0,
            `order_months` TINYINT UNSIGNED DEFAULT 3,
            `is_active` TINYINT(1) DEFAULT 1,
            `is_hidden` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
            UNIQUE KEY `unique_item_code_company` (`company_id`, `code`),
            INDEX `idx_item_category` (`category_id`),
            INDEX `idx_item_company` (`company_id`),
            INDEX `idx_item_active` (`is_active`),
            INDEX `idx_item_hidden` (`is_hidden`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Stock table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `stock` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `item_id` INT UNSIGNED NOT NULL,
            `location_id` INT UNSIGNED NOT NULL,
            `quantity` INT NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `unique_stock_item_location` (`item_id`, `location_id`),
            INDEX `idx_stock_location` (`location_id`),
            INDEX `idx_stock_quantity` (`quantity`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Stock batches table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `stock_batches` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `item_id` INT UNSIGNED NOT NULL,
            `location_id` INT UNSIGNED NOT NULL,
            `quantity` INT NOT NULL DEFAULT 0,
            `expiration_date` DATE NULL,
            `received_at` DATE NOT NULL,
            `movement_id` INT UNSIGNED NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE,
            INDEX `idx_batch_expiration` (`expiration_date`),
            INDEX `idx_batch_item_location` (`item_id`, `location_id`),
            INDEX `idx_batch_quantity` (`quantity`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Stock movements table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `stock_movements` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `item_id` INT UNSIGNED NOT NULL,
            `location_id` INT UNSIGNED NOT NULL,
            `movement_type` ENUM('in', 'out', 'adjustment') NOT NULL,
            `quantity` INT NOT NULL,
            `quantity_packages` DECIMAL(10,2) NULL,
            `employee_id` INT UNSIGNED NULL,
            `batch_id` INT UNSIGNED NULL,
            `movement_date` DATE NOT NULL,
            `note` TEXT NULL,
            `stocktaking_id` INT UNSIGNED NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`batch_id`) REFERENCES `stock_batches`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            INDEX `idx_movement_date` (`movement_date`),
            INDEX `idx_movement_type` (`movement_type`),
            INDEX `idx_movement_item` (`item_id`),
            INDEX `idx_movement_employee` (`employee_id`),
            INDEX `idx_movement_location` (`location_id`),
            INDEX `idx_movement_stocktaking` (`stocktaking_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Stocktakings table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `stocktakings` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `location_id` INT UNSIGNED NOT NULL,
            `status` ENUM('in_progress', 'review', 'completed', 'cancelled') DEFAULT 'in_progress',
            `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `completed_at` TIMESTAMP NULL,
            `started_by` INT UNSIGNED NOT NULL,
            `completed_by` INT UNSIGNED NULL,
            `notes` TEXT NULL,
            FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`started_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX `idx_stocktaking_status` (`status`),
            INDEX `idx_stocktaking_location` (`location_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Stocktaking items table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `stocktaking_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `stocktaking_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NOT NULL,
            `expected_quantity` INT NOT NULL,
            `counted_quantity` INT NULL,
            `difference` INT NULL,
            `note` TEXT NULL,
            `counted_at` TIMESTAMP NULL,
            `counted_by` INT UNSIGNED NULL,
            FOREIGN KEY (`stocktaking_id`) REFERENCES `stocktakings`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`counted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            UNIQUE KEY `unique_stocktaking_item` (`stocktaking_id`, `item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Stocktaking schedules table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `stocktaking_schedules` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `location_id` INT UNSIGNED NOT NULL,
            `schedule_type` ENUM('once', 'monthly', 'quarterly', 'yearly') NOT NULL DEFAULT 'quarterly',
            `scheduled_date` DATE NULL,
            `day_of_month` TINYINT UNSIGNED DEFAULT 1,
            `month_of_year` TINYINT UNSIGNED NULL,
            `reminder_days_before` TINYINT UNSIGNED DEFAULT 7,
            `is_active` TINYINT(1) DEFAULT 1,
            `last_reminder_sent` DATE NULL,
            `last_completed` DATE NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE,
            INDEX `idx_schedule_date` (`scheduled_date`),
            INDEX `idx_schedule_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Order proposals table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `order_proposals` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT UNSIGNED NOT NULL,
            `status` ENUM('draft', 'confirmed', 'ordered', 'cancelled') DEFAULT 'draft',
            `total_items` INT UNSIGNED DEFAULT 0,
            `total_value` DECIMAL(12,2) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `created_by` INT UNSIGNED NOT NULL,
            `confirmed_at` TIMESTAMP NULL,
            `confirmed_by` INT UNSIGNED NULL,
            `notes` TEXT NULL,
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`confirmed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX `idx_proposal_company` (`company_id`),
            INDEX `idx_proposal_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Order proposal items table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `order_proposal_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `proposal_id` INT UNSIGNED NOT NULL,
            `item_id` INT UNSIGNED NOT NULL,
            `current_stock` INT NOT NULL,
            `avg_monthly_consumption` DECIMAL(10,2) NOT NULL,
            `suggested_quantity` INT NOT NULL,
            `adjusted_quantity` INT NULL,
            `adjustment_reason` TEXT NULL,
            `unit_price` DECIMAL(10,2) NULL,
            `total_price` DECIMAL(12,2) NULL,
            FOREIGN KEY (`proposal_id`) REFERENCES `order_proposals`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `unique_proposal_item` (`proposal_id`, `item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Notifications table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `notifications` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `company_id` INT UNSIGNED NOT NULL,
            `type` ENUM('low_stock', 'expiring', 'stocktaking', 'system') NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `reference_type` VARCHAR(50) NULL,
            `reference_id` INT UNSIGNED NULL,
            `link` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expires_at` TIMESTAMP NULL,
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
            INDEX `idx_notification_company` (`company_id`),
            INDEX `idx_notification_type` (`type`),
            INDEX `idx_notification_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Notification reads table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `notification_reads` (
            `notification_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`notification_id`, `user_id`),
            FOREIGN KEY (`notification_id`) REFERENCES `notifications`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Settings table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `settings` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(100) NOT NULL UNIQUE,
            `value` TEXT NULL,
            `description` VARCHAR(255) NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Audit log table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `audit_log` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NULL,
            `company_id` INT UNSIGNED NULL,
            `action` VARCHAR(100) NOT NULL,
            `entity_type` VARCHAR(50) NULL,
            `entity_id` INT UNSIGNED NULL,
            `description` VARCHAR(255) NULL,
            `old_values` JSON NULL,
            `new_values` JSON NULL,
            `ip_address` VARCHAR(45) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_audit_user` (`user_id`),
            INDEX `idx_audit_company` (`company_id`),
            INDEX `idx_audit_action` (`action`),
            INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
            INDEX `idx_audit_date` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Email log table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `email_log` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `recipient_email` VARCHAR(150) NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `status` ENUM('sent', 'failed') NOT NULL,
            `error_message` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_email_status` (`status`),
            INDEX `idx_email_date` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");

    // Re-enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "    <div class=\"info\">✓ Vytvořeny všechny tabulky</div>\n";
};
