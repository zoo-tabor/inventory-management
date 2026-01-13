<?php
/**
 * Migration: Update employees and departments tables
 * Add missing columns for full employee and department management
 */

return function($db) {
    // Update employees table - add missing columns
    $db->exec("
        ALTER TABLE `employees`
        ADD COLUMN `first_name` VARCHAR(100) NOT NULL AFTER `department_id`,
        ADD COLUMN `last_name` VARCHAR(100) NOT NULL AFTER `first_name`,
        ADD COLUMN `employee_number` VARCHAR(50) NULL AFTER `last_name`,
        ADD COLUMN `position` VARCHAR(100) NULL AFTER `employee_number`,
        ADD COLUMN `email` VARCHAR(150) NULL AFTER `position`,
        ADD COLUMN `phone` VARCHAR(50) NULL AFTER `email`,
        ADD UNIQUE KEY `unique_employee_number` (`company_id`, `employee_number`)
    ");

    // Update name column to be nullable since we now have first_name and last_name
    $db->exec("ALTER TABLE `employees` MODIFY COLUMN `name` VARCHAR(150) NULL");

    // Migrate existing name data to first_name
    $db->exec("UPDATE `employees` SET `first_name` = `name`, `last_name` = '' WHERE `first_name` = ''");

    // Update departments table - add missing columns
    $db->exec("
        ALTER TABLE `departments`
        ADD COLUMN `code` VARCHAR(50) NOT NULL AFTER `name`,
        ADD COLUMN `description` TEXT NULL AFTER `code`,
        ADD UNIQUE KEY `unique_department_code` (`company_id`, `code`)
    ");

    // Update items table - add missing columns that pages expect
    $db->exec("
        ALTER TABLE `items`
        ADD COLUMN `description` TEXT NULL AFTER `name`,
        ADD COLUMN `unit` VARCHAR(20) NOT NULL DEFAULT 'ks' AFTER `description`,
        ADD COLUMN `optimal_stock` INT NULL AFTER `minimum_stock`
    ");
};
