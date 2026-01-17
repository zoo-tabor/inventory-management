<?php
/**
 * Migration: Add price_per_package column to items table
 * Allows storing price per package for items with package quantities
 */

return function($db) {
    // Check if items table has price_per_package column
    $stmt = $db->query("SHOW COLUMNS FROM `items` LIKE 'price_per_package'");
    $hasPricePerPackage = $stmt->rowCount() > 0;

    if (!$hasPricePerPackage) {
        // Add price_per_package column after pieces_per_package
        $db->exec("
            ALTER TABLE `items`
            ADD COLUMN `price_per_package` DECIMAL(10,2) NULL DEFAULT NULL AFTER `pieces_per_package`,
            ADD COLUMN `price_per_package_currency` VARCHAR(3) DEFAULT 'CZK' AFTER `price_per_package`
        ");
    }
};
