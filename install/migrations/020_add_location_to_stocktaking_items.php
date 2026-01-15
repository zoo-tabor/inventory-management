<?php
/**
 * Migration: Add location_id to stocktaking_items table
 * Track which location each item is in during stocktaking
 * Allow changing item location during inventory
 */

return function($db) {
    // Check if stocktaking_items has location_id column
    $stmt = $db->query("SHOW COLUMNS FROM `stocktaking_items` LIKE 'location_id'");
    $hasLocationId = $stmt->rowCount() > 0;

    if (!$hasLocationId) {
        // Add location_id column after item_id
        $db->exec("
            ALTER TABLE `stocktaking_items`
            ADD COLUMN `location_id` INT UNSIGNED NULL AFTER `item_id`
        ");

        // Add foreign key constraint
        $db->exec("
            ALTER TABLE `stocktaking_items`
            ADD CONSTRAINT `fk_stocktaking_items_location`
            FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL
        ");

        // Add index for better query performance
        $db->exec("
            ALTER TABLE `stocktaking_items`
            ADD INDEX `idx_stocktaking_items_location` (`location_id`)
        ");

        // Update the unique key to include location_id
        // This allows same item in different locations
        try {
            $db->exec("ALTER TABLE `stocktaking_items` DROP INDEX `unique_stocktaking_item`");
        } catch (PDOException $e) {
            // Index might not exist or have different name
        }

        // Create new unique index including location_id
        $db->exec("
            ALTER TABLE `stocktaking_items`
            ADD UNIQUE KEY `unique_stocktaking_item_location` (`stocktaking_id`, `item_id`, `location_id`)
        ");

        // Populate location_id from stocktaking's location for existing records
        $db->exec("
            UPDATE `stocktaking_items` si
            JOIN `stocktakings` s ON si.stocktaking_id = s.id
            SET si.location_id = s.location_id
            WHERE s.location_id IS NOT NULL
        ");
    }
};
