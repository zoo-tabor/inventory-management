<?php
/**
 * Database Configuration
 * Database connection credentials
 */

// Load .env if not already loaded
if (!defined('DB_HOST')) {
    if (file_exists(__DIR__ . '/../.env')) {
        $env = parse_ini_file(__DIR__ . '/../.env');

        define('DB_HOST', $env['DB_HOST'] ?? 'localhost');
        define('DB_NAME', $env['DB_NAME'] ?? 'skladovy_system');
        define('DB_USER', $env['DB_USER'] ?? 'root');
        define('DB_PASS', $env['DB_PASS'] ?? '');
    } else {
        // Fallback defaults for development
        define('DB_HOST', 'localhost');
        define('DB_NAME', 'skladovy_system');
        define('DB_USER', 'root');
        define('DB_PASS', '');
    }
}

// Always define DB_CHARSET if not already defined
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

// PDO options
if (!defined('DB_OPTIONS')) {
    define('DB_OPTIONS', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_czech_ci"
    ]);
}
