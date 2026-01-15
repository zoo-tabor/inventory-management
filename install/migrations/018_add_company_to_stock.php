<?php
/**
 * Migration: Add company_id to stock table
 * Stock levels should be scoped to companies for multi-tenant support
 */

return function($db) {
    // Check if stock table has company_id column
    $stmt = $db->query("SHOW COLUMNS FROM `stock` LIKE 'company_id'");
    $hasCompanyId = $stmt->rowCount() > 0;

    if (!$hasCompanyId) {
        // Add company_id column
        $db->exec("
            ALTER TABLE `stock`
            ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`
        ");

        // Update existing records to have company_id based on their item's company
        $db->exec("
            UPDATE `stock` s
            JOIN `items` i ON s.item_id = i.id
            SET s.company_id = i.company_id
        ");

        // Add foreign key constraint
        $db->exec("
            ALTER TABLE `stock`
            ADD CONSTRAINT `fk_stock_company`
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
        ");

        // Add index for better query performance
        $db->exec("
            ALTER TABLE `stock`
            ADD INDEX `idx_stock_company` (`company_id`)
        ");

        // Update unique key to include company_id
        // First, get the name of the existing unique key if it exists
        try {
            $db->exec("ALTER TABLE `stock` DROP INDEX `item_location`");
        } catch (PDOException $e) {
            // Index might not exist or have different name
        }

        // Create new unique index including company_id
        $db->exec("
            ALTER TABLE `stock`
            ADD UNIQUE KEY `unique_stock` (`company_id`, `item_id`, `location_id`)
        ");
    }
};
