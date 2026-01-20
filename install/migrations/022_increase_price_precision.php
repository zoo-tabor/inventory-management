<?php
/**
 * Migration: Increase price column precision
 * Allows storing prices with up to 4 decimal places (e.g., 0.0072 for bulk items)
 */

return function($db) {
    // Change price column from DECIMAL(10,2) to DECIMAL(10,4)
    $db->exec("
        ALTER TABLE `items`
        MODIFY COLUMN `price` DECIMAL(10,4) NULL DEFAULT NULL
    ");

    // Also update price_per_package for consistency
    $db->exec("
        ALTER TABLE `items`
        MODIFY COLUMN `price_per_package` DECIMAL(10,4) NULL DEFAULT NULL
    ");
};
