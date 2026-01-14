<?php
/**
 * Migration: Fix categories table structure (retry)
 * Migration 006 failed, this is a corrected version
 */

return function($db) {
    // Check if company_id already exists (in case migration 006 partially ran)
    $stmt = $db->query("SHOW COLUMNS FROM `categories` LIKE 'company_id'");
    $hasCompanyId = $stmt->rowCount() > 0;

    if (!$hasCompanyId) {
        // Add company_id column with default value
        $db->exec("
            ALTER TABLE `categories`
            ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`
        ");

        // Update existing categories to belong to company 1 (EKOSPOL)
        $db->exec("UPDATE `categories` SET `company_id` = 1");

        // Add the foreign key constraint
        $db->exec("
            ALTER TABLE `categories`
            ADD CONSTRAINT `fk_categories_company`
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
        ");
    }

    // Check if parent_id already exists
    $stmt = $db->query("SHOW COLUMNS FROM `categories` LIKE 'parent_id'");
    $hasParentId = $stmt->rowCount() > 0;

    if (!$hasParentId) {
        // Add parent_id column for hierarchical categories
        $db->exec("
            ALTER TABLE `categories`
            ADD COLUMN `parent_id` INT UNSIGNED NULL AFTER `company_id`
        ");

        // Add foreign key for parent_id
        $db->exec("
            ALTER TABLE `categories`
            ADD CONSTRAINT `fk_categories_parent`
            FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
        ");
    }

    // Check if old unique constraint on name exists
    $stmt = $db->query("SHOW INDEXES FROM `categories` WHERE Key_name = 'name'");
    $hasOldIndex = $stmt->rowCount() > 0;

    if ($hasOldIndex) {
        // Remove the old UNIQUE constraint on name
        $db->exec("ALTER TABLE `categories` DROP INDEX `name`");
    }

    // Check if new unique constraint exists
    $stmt = $db->query("SHOW INDEXES FROM `categories` WHERE Key_name = 'unique_category_name'");
    $hasNewIndex = $stmt->rowCount() > 0;

    if (!$hasNewIndex) {
        // Add new unique constraint for company_id + name
        $db->exec("
            ALTER TABLE `categories`
            ADD UNIQUE KEY `unique_category_name` (`company_id`, `name`)
        ");
    }

    // Check if description column exists
    $stmt = $db->query("SHOW COLUMNS FROM `categories` LIKE 'description'");
    $hasDescription = $stmt->rowCount() > 0;

    if (!$hasDescription) {
        // Add description column
        $db->exec("
            ALTER TABLE `categories`
            ADD COLUMN `description` TEXT NULL AFTER `name`
        ");
    }
};
