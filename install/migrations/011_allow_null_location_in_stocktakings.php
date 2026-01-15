<?php
/**
 * Migration: Allow NULL location_id in stocktakings
 * Stocktaking can be for all warehouses (no specific location)
 */

return function($db) {
    // Check if location_id allows NULL
    $stmt = $db->query("SHOW COLUMNS FROM `stocktakings` LIKE 'location_id'");
    $column = $stmt->fetch();

    if ($column && $column['Null'] === 'NO') {
        // Modify location_id to allow NULL
        $db->exec("
            ALTER TABLE `stocktakings`
            MODIFY COLUMN `location_id` INT UNSIGNED NULL
        ");

        // Remove foreign key constraint if it exists
        try {
            $db->exec("
                ALTER TABLE `stocktakings`
                DROP FOREIGN KEY `fk_stocktakings_location`
            ");
        } catch (PDOException $e) {
            // Foreign key might not exist, that's OK
        }

        // Re-add foreign key constraint with ON DELETE SET NULL
        $db->exec("
            ALTER TABLE `stocktakings`
            ADD CONSTRAINT `fk_stocktakings_location`
            FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL
        ");
    }
};
