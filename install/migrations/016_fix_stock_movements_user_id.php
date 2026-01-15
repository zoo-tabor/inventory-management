<?php
/**
 * Migration: Fix stock_movements user_id column
 * This is a corrected version of migration 015 to properly handle foreign keys
 */

return function($db) {
    // Check if stock_movements has user_id column
    $stmt = $db->query("SHOW COLUMNS FROM `stock_movements` LIKE 'user_id'");
    $hasUserId = $stmt->rowCount() > 0;

    if (!$hasUserId) {
        // Drop old foreign key on created_by if it exists
        try {
            $db->exec("ALTER TABLE `stock_movements` DROP FOREIGN KEY `stock_movements_ibfk_5`");
        } catch (PDOException $e) {
            // Foreign key might not exist or have different name, that's OK
        }

        // Add user_id column
        $db->exec("
            ALTER TABLE `stock_movements`
            ADD COLUMN `user_id` INT UNSIGNED NULL AFTER `company_id`
        ");

        // Copy data from created_by to user_id (only if created_by exists and has valid user_id)
        $db->exec("
            UPDATE `stock_movements` sm
            SET sm.user_id = sm.created_by
            WHERE sm.created_by IS NOT NULL
            AND EXISTS (SELECT 1 FROM users u WHERE u.id = sm.created_by)
        ");

        // Set user_id to 1 (admin) for records without valid created_by
        $db->exec("UPDATE `stock_movements` SET `user_id` = 1 WHERE `user_id` IS NULL");

        // Now make user_id NOT NULL after populating it
        $db->exec("ALTER TABLE `stock_movements` MODIFY COLUMN `user_id` INT UNSIGNED NOT NULL");

        // Add foreign key constraint
        $db->exec("
            ALTER TABLE `stock_movements`
            ADD CONSTRAINT `fk_stock_movements_user`
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ");

        // Re-add the created_by foreign key if it was dropped
        try {
            $db->exec("
                ALTER TABLE `stock_movements`
                ADD CONSTRAINT `stock_movements_ibfk_5`
                FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ");
        } catch (PDOException $e) {
            // Constraint might already exist, that's OK
        }
    }
};
