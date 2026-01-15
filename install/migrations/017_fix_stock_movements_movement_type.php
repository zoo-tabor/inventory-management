<?php
/**
 * Migration: Fix stock_movements movement_type enum values
 * Change from English ('in','out','adjustment') to Czech ('prijem','vydej','korekce')
 */

return function($db) {
    // Update existing data to Czech values
    $db->exec("UPDATE `stock_movements` SET `movement_type` = 'prijem' WHERE `movement_type` = 'in'");
    $db->exec("UPDATE `stock_movements` SET `movement_type` = 'vydej' WHERE `movement_type` = 'out'");
    $db->exec("UPDATE `stock_movements` SET `movement_type` = 'korekce' WHERE `movement_type` = 'adjustment'");

    // Modify the enum to use Czech values
    $db->exec("
        ALTER TABLE `stock_movements`
        MODIFY COLUMN `movement_type` ENUM('prijem', 'vydej', 'korekce', 'inventura') NOT NULL
    ");

    // Also make user_id NOT NULL since it should always be set
    $db->exec("
        UPDATE `stock_movements` SET `user_id` = `created_by` WHERE `user_id` IS NULL
    ");

    $db->exec("
        ALTER TABLE `stock_movements`
        MODIFY COLUMN `user_id` INT UNSIGNED NOT NULL
    ");
};
