<?php
/**
 * Migration: Update employees and departments tables
 * Add missing columns needed by the UI
 */

return function($db) {
    // Update employees table - rename name to full_name and add other columns
    $db->exec("ALTER TABLE `employees` CHANGE `name` `full_name` VARCHAR(150) NOT NULL");

    $db->exec("
        ALTER TABLE `employees`
        ADD COLUMN `employee_number` VARCHAR(50) NULL AFTER `full_name`,
        ADD COLUMN `position` VARCHAR(100) NULL AFTER `employee_number`,
        ADD COLUMN `email` VARCHAR(150) NULL AFTER `position`,
        ADD COLUMN `phone` VARCHAR(50) NULL AFTER `email`,
        ADD UNIQUE KEY `unique_employee_number` (`company_id`, `employee_number`)
    ");

    // Update departments table - add missing columns
    $db->exec("
        ALTER TABLE `departments`
        ADD COLUMN `code` VARCHAR(50) NOT NULL AFTER `name`,
        ADD COLUMN `description` TEXT NULL AFTER `code`,
        ADD COLUMN `is_active` TINYINT(1) DEFAULT 1 AFTER `description`,
        ADD UNIQUE KEY `unique_department_code` (`company_id`, `code`)
    ");

    // Update items table - add missing columns that pages expect
    $db->exec("
        ALTER TABLE `items`
        ADD COLUMN `description` TEXT NULL AFTER `name`,
        ADD COLUMN `unit` VARCHAR(20) NOT NULL DEFAULT 'ks' AFTER `description`,
        ADD COLUMN `optimal_stock` INT NULL AFTER `minimum_stock`
    ");

    // Update locations table - add missing code column
    $db->exec("
        ALTER TABLE `locations`
        ADD COLUMN `code` VARCHAR(50) NOT NULL AFTER `name`,
        ADD UNIQUE KEY `unique_location_code` (`company_id`, `code`)
    ");
};
