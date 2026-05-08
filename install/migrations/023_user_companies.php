<?php
/**
 * Migration 023: Add user_companies table for per-user company access control
 */

$migrations[] = [
    'version' => '023',
    'description' => 'Add user_companies table for per-user company access control',
    'up' => function($db) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `user_companies` (
                `user_id` INT UNSIGNED NOT NULL,
                `company_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`user_id`, `company_id`),
                CONSTRAINT `fk_user_companies_user` FOREIGN KEY (`user_id`)
                    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
];
