<?php
/**
 * Migration: Fix items table - ensure company_id exists
 * Some installations may be missing the company_id column in items table
 */

return function($db) {
    // Check if items table has company_id column
    $stmt = $db->query("SHOW COLUMNS FROM `items` LIKE 'company_id'");
    $hasCompanyId = $stmt->rowCount() > 0;

    if (!$hasCompanyId) {
        // Add company_id column
        $db->exec("
            ALTER TABLE `items`
            ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`
        ");

        // Add foreign key constraint
        $db->exec("
            ALTER TABLE `items`
            ADD CONSTRAINT `fk_items_company`
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
        ");

        // Add index
        $db->exec("
            ALTER TABLE `items`
            ADD INDEX `idx_items_company` (`company_id`)
        ");
    }
};
