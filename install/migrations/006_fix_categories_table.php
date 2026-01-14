<?php
/**
 * Migration: Fix categories table structure
 * Add company_id and parent_id columns that the UI expects
 */

return function($db) {
    // Add company_id column WITHOUT foreign key first
    $db->exec("
        ALTER TABLE `categories`
        ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`
    ");

    // Update existing categories to belong to company 1 (EKOSPOL)
    $db->exec("UPDATE `categories` SET `company_id` = 1");

    // Now add the foreign key constraint
    $db->exec("
        ALTER TABLE `categories`
        ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
    ");

    // Add parent_id column for hierarchical categories
    $db->exec("
        ALTER TABLE `categories`
        ADD COLUMN `parent_id` INT UNSIGNED NULL AFTER `company_id`,
        ADD FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
    ");

    // Remove the old UNIQUE constraint on name (should be unique per company)
    $db->exec("ALTER TABLE `categories` DROP INDEX `name`");

    // Add new unique constraint for company_id + name
    $db->exec("
        ALTER TABLE `categories`
        ADD UNIQUE KEY `unique_category_name` (`company_id`, `name`)
    ");
};
