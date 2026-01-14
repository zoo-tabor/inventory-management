<?php
/**
 * Migration: Add address column to locations table
 * The UI form collects address but the table doesn't have this column
 */

return function($db) {
    // Add address column to locations table
    $db->exec("
        ALTER TABLE `locations`
        ADD COLUMN `address` TEXT NULL AFTER `code`
    ");
};
