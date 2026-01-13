<?php
/**
 * Application Configuration
 * Loads environment variables and defines constants
 */

// Load .env file
if (file_exists(__DIR__ . '/../.env')) {
    $env = parse_ini_file(__DIR__ . '/../.env');

    foreach ($env as $key => $value) {
        if (!defined($key)) {
            define($key, $value);
        }
    }
}

// Fallback defaults if .env is missing
if (!defined('APP_URL')) define('APP_URL', 'http://localhost');
if (!defined('APP_ENV')) define('APP_ENV', 'development');
if (!defined('APP_DEBUG')) define('APP_DEBUG', 'true');
if (!defined('TIMEZONE')) define('TIMEZONE', 'Europe/Prague');

// Set timezone
date_default_timezone_set(TIMEZONE);

// Session configuration
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 7200);
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'skladovy_system');

// Configure session
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
ini_set('session.cookie_lifetime', SESSION_LIFETIME);
session_name(SESSION_NAME);

// Application constants
define('APP_NAME', 'Skladový systém');
define('APP_VERSION', '1.0.0');

// Path constants
define('ROOT_PATH', dirname(__DIR__));
define('ASSETS_PATH', ROOT_PATH . '/assets');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');

// Company configuration
define('COMPANIES', [
    1 => [
        'id' => 1,
        'name' => 'EKOSPOL',
        'code' => 'EKO',
        'theme' => 'ekospol',
        'logo' => 'logo-ekospol.png'
    ],
    2 => [
        'id' => 2,
        'name' => 'ZOO Tábor',
        'code' => 'ZOO',
        'theme' => 'zoo',
        'logo' => 'logo-zoo.png'
    ]
]);

// Stock status constants
define('STOCK_STATUS_OK', 'ok');
define('STOCK_STATUS_LOW', 'low');
define('STOCK_STATUS_CRITICAL', 'critical');

// User roles
define('ROLE_ADMIN', 'admin');
define('ROLE_USER', 'user');

// Pagination
define('ITEMS_PER_PAGE', 25);

// Upload settings
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_UPLOAD_TYPES', ['csv', 'xlsx', 'xls']);
