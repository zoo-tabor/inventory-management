<?php
/**
 * Migration: Fix stocktakings table structure
 * Add company_id and rename user columns to match code expectations
 */

return function($db) {
    // Check if company_id already exists
    $stmt = $db->query("SHOW COLUMNS FROM `stocktakings` LIKE 'company_id'");
    $hasCompanyId = $stmt->rowCount() > 0;

    if (!$hasCompanyId) {
        // Add company_id column
        $db->exec("
            ALTER TABLE `stocktakings`
            ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`
        ");

        // Update existing stocktakings to belong to company 1 (EKOSPOL)
        // Get company_id from the location
        $db->exec("
            UPDATE `stocktakings` st
            JOIN `locations` l ON st.location_id = l.id
            SET st.company_id = l.company_id
        ");

        // Add foreign key constraint
        $db->exec("
            ALTER TABLE `stocktakings`
            ADD CONSTRAINT `fk_stocktakings_company`
            FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
        ");
    }

    // Check if user_id column exists
    $stmt = $db->query("SHOW COLUMNS FROM `stocktakings` LIKE 'user_id'");
    $hasUserId = $stmt->rowCount() > 0;

    if (!$hasUserId) {
        // First, drop old foreign key on started_by if it exists
        try {
            $db->exec("ALTER TABLE `stocktakings` DROP FOREIGN KEY `stocktakings_ibfk_2`");
        } catch (PDOException $e) {
            // Foreign key might not exist, that's OK
        }

        // Add user_id column and copy data from started_by
        $db->exec("
            ALTER TABLE `stocktakings`
            ADD COLUMN `user_id` INT UNSIGNED NOT NULL AFTER `company_id`
        ");

        // Copy data from started_by to user_id
        $db->exec("UPDATE `stocktakings` SET `user_id` = `started_by`");

        // Add foreign key constraint
        $db->exec("
            ALTER TABLE `stocktakings`
            ADD CONSTRAINT `fk_stocktakings_user`
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ");
    }

    // Check if created_at column exists
    $stmt = $db->query("SHOW COLUMNS FROM `stocktakings` LIKE 'created_at'");
    $hasCreatedAt = $stmt->rowCount() > 0;

    if (!$hasCreatedAt) {
        // Add created_at column and copy data from started_at
        $db->exec("
            ALTER TABLE `stocktakings`
            ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `notes`
        ");

        // Copy data from started_at to created_at
        $db->exec("UPDATE `stocktakings` SET `created_at` = `started_at`");
    }
};
