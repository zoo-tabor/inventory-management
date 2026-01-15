<?php
/**
 * Migration: Fix employees table structure
 * Ensure full_name column exists and is properly configured
 */

return function($db) {
    // Check if employees table has 'name' column (old structure)
    $stmt = $db->query("SHOW COLUMNS FROM `employees` LIKE 'name'");
    $hasNameColumn = $stmt->rowCount() > 0;

    // Check if employees table has 'full_name' column (new structure)
    $stmt = $db->query("SHOW COLUMNS FROM `employees` LIKE 'full_name'");
    $hasFullNameColumn = $stmt->rowCount() > 0;

    // If has 'name' but not 'full_name', rename it
    if ($hasNameColumn && !$hasFullNameColumn) {
        $db->exec("ALTER TABLE `employees` CHANGE `name` `full_name` VARCHAR(150) NOT NULL");
    }

    // If has neither, add full_name column
    if (!$hasNameColumn && !$hasFullNameColumn) {
        $db->exec("ALTER TABLE `employees` ADD COLUMN `full_name` VARCHAR(150) NOT NULL AFTER `department_id`");
    }

    // Check and add other missing columns
    $stmt = $db->query("SHOW COLUMNS FROM `employees` LIKE 'employee_number'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE `employees` ADD COLUMN `employee_number` VARCHAR(50) NULL AFTER `full_name`");
    }

    $stmt = $db->query("SHOW COLUMNS FROM `employees` LIKE 'position'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE `employees` ADD COLUMN `position` VARCHAR(100) NULL AFTER `employee_number`");
    }

    $stmt = $db->query("SHOW COLUMNS FROM `employees` LIKE 'email'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE `employees` ADD COLUMN `email` VARCHAR(150) NULL AFTER `position`");
    }

    $stmt = $db->query("SHOW COLUMNS FROM `employees` LIKE 'phone'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE `employees` ADD COLUMN `phone` VARCHAR(50) NULL AFTER `email`");
    }

    // Add unique constraint if it doesn't exist
    try {
        $db->exec("ALTER TABLE `employees` ADD UNIQUE KEY `unique_employee_number` (`company_id`, `employee_number`)");
    } catch (PDOException $e) {
        // Constraint might already exist, that's OK
    }
};
