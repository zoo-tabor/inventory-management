<?php
/**
 * Migration: Add department_id to stock_movements table
 * Track which department received items in stock issues
 */

return function($db) {
    // Check if stock_movements has department_id column
    $stmt = $db->query("SHOW COLUMNS FROM `stock_movements` LIKE 'department_id'");
    $hasDepartmentId = $stmt->rowCount() > 0;

    if (!$hasDepartmentId) {
        // Add department_id column after employee_id
        $db->exec("
            ALTER TABLE `stock_movements`
            ADD COLUMN `department_id` INT UNSIGNED NULL AFTER `employee_id`
        ");

        // Add foreign key constraint
        $db->exec("
            ALTER TABLE `stock_movements`
            ADD CONSTRAINT `fk_stock_movements_department`
            FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
        ");

        // Add index for better query performance
        $db->exec("
            ALTER TABLE `stock_movements`
            ADD INDEX `idx_stock_movements_department` (`department_id`)
        ");

        // Populate department_id from employee's department for existing records
        $db->exec("
            UPDATE `stock_movements` sm
            JOIN `employees` e ON sm.employee_id = e.id
            SET sm.department_id = e.department_id
            WHERE sm.employee_id IS NOT NULL
            AND e.department_id IS NOT NULL
        ");
    }
};
