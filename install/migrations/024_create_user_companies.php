<?php
/**
 * Migration 024: Actually create user_companies table
 * Migration 023 had wrong format so its SQL never executed.
 */

return function($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `user_companies` (
            `user_id` INT UNSIGNED NOT NULL,
            `company_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`user_id`, `company_id`),
            CONSTRAINT `fk_user_companies_user` FOREIGN KEY (`user_id`)
                REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
