<?php
/**
 * Migration: Add company_id to stock_movements table
 * Stock movements should be scoped to companies for multi-tenant support
 */

return function($db) {
    // Check if stock_movements table has company_id column
    $stmt = $db->query("SHOW COLUMNS FROM `stock_movements` LIKE 'company_id'");
    $hasCompanyId = $stmt->rowCount() > 0;

    if (!$hasCompanyId) {
        // Add company_id column
        $db->exec("
            ALTER TABLE `stock_movements`
            ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`
        ");

        // Update existing records to have company_id based on their item's company
        $db->exec("
            UPDATE `stock_movements` sm
            JOIN `items` i ON sm.item_id = i.id
            SET sm.company_id = i.company_id
        ");

        // Add foreign key constraint
        $db->exec("
            ALTER TABLE `stock_movements`
            ADD CONSTRAINT `fk_stock_movements_company`
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
        ");

        // Add index for better query performance
        $db->exec("
            ALTER TABLE `stock_movements`
            ADD INDEX `idx_stock_movements_company` (`company_id`)
        ");
    }

    // Check if stock_movements has user_id column (might be missing)
    $stmt = $db->query("SHOW COLUMNS FROM `stock_movements` LIKE 'user_id'");
    $hasUserId = $stmt->rowCount() > 0;

    if (!$hasUserId) {
        // Add user_id column and copy data from created_by if it exists
        $db->exec("
            ALTER TABLE `stock_movements`
            ADD COLUMN `user_id` INT UNSIGNED NULL AFTER `company_id`
        ");

        // Copy data from created_by to user_id
        $db->exec("UPDATE `stock_movements` SET `user_id` = `created_by`");

        // Add foreign key constraint
        $db->exec("
            ALTER TABLE `stock_movements`
            ADD CONSTRAINT `fk_stock_movements_user`
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ");
    }
};
