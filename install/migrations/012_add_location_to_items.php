<?php
/**
 * Migration: Add location_id to items table
 * Items should have a default/primary location
 */

return function($db) {
    // Check if location_id already exists
    $stmt = $db->query("SHOW COLUMNS FROM `items` LIKE 'location_id'");
    $hasLocationId = $stmt->rowCount() > 0;

    if (!$hasLocationId) {
        // Add location_id column (nullable - existing items may not have a location set)
        $db->exec("
            ALTER TABLE `items`
            ADD COLUMN `location_id` INT UNSIGNED NULL AFTER `company_id`
        ");

        // Add index for faster queries
        $db->exec("
            ALTER TABLE `items`
            ADD INDEX `idx_items_location` (`location_id`)
        ");

        // Add foreign key constraint
        $db->exec("
            ALTER TABLE `items`
            ADD CONSTRAINT `fk_items_location`
            FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL
        ");
    }
};
