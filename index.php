<?php
/**
 * Main Router
 * Skladový systém
 */

// Enable error logging to file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
// Error reporting based on environment
if (file_exists(__DIR__ . '/.env')) {
    $env = parse_ini_file(__DIR__ . '/.env');
    error_reporting($env['APP_DEBUG'] === 'true' ? E_ALL : 0);
    ini_set('display_errors', $env['APP_DEBUG'] === 'true' ? '1' : '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Configure session BEFORE starting it
ini_set('session.gc_maxlifetime', 7200);
ini_set('session.cookie_lifetime', 7200);
session_name('skladovy_system');

// Start session
session_start();

// Load configuration
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Auto-load classes
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/classes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Get route from URL
$route = isset($_GET['route']) ? trim($_GET['route'], '/') : '';

// DEBUG: Log route access
if (defined('APP_DEBUG') && APP_DEBUG === 'true') {
    error_log("DEBUG: Route = '$route', Session user_id = " . ($_SESSION['user_id'] ?? 'NOT SET'));
}

// Route handling
if (empty($route)) {
    // Default route - redirect to dashboard or login
    if (isLoggedIn()) {
        error_log("DEBUG: Empty route, user is logged in, showing dashboard directly");
        // Don't redirect - just set route to dashboard and continue
        $route = 'dashboard';
    } else {
        error_log("DEBUG: Empty route, user NOT logged in, showing login page");
        require __DIR__ . '/pages/login.php';
        exit;
    }
}

// Parse route
$routeParts = explode('/', $route);
$page = $routeParts[0];
$action = isset($routeParts[1]) ? $routeParts[1] : 'index';
$id = isset($routeParts[2]) ? $routeParts[2] : null;

// Public routes (no authentication required)
$publicRoutes = ['login', 'logout'];

if (!in_array($page, $publicRoutes)) {
    requireLogin();
}

// Route to appropriate page
switch ($page) {
    case 'login':
        require __DIR__ . '/pages/login.php';
        break;

    case 'logout':
        session_destroy();
        header('Location: /login');
        exit;

    case 'switch-company':
        // Handle company switching
        $companyId = (int)($_GET['id'] ?? 0);
        if (isset(COMPANIES[$companyId])) {
            setCurrentCompany($companyId);
            setFlash('success', 'Společnost přepnuta na ' . COMPANIES[$companyId]['name']);
        } else {
            setFlash('error', 'Neplatná společnost');
        }
        header('Location: /dashboard');
        exit;

    case 'dashboard':
        error_log("DEBUG: Loading dashboard.php for user_id = " . ($_SESSION['user_id'] ?? 'NOT SET'));
        require __DIR__ . '/pages/dashboard.php';
        break;

    case 'items':
        handleRoute(__DIR__ . '/pages/items/', $action, $id);
        break;

    case 'stock':
        handleRoute(__DIR__ . '/pages/stock/', $action, $id);
        break;

    case 'movements':
        handleRoute(__DIR__ . '/pages/movements/', $action, $id);
        break;

    case 'stocktaking':
        handleRoute(__DIR__ . '/pages/stocktaking/', $action, $id);
        break;

    case 'reports':
        handleRoute(__DIR__ . '/pages/reports/', $action, $id);
        break;

    case 'orders':
        handleRoute(__DIR__ . '/pages/orders/', $action, $id);
        break;

    case 'employees':
        requireAdmin();
        handleRoute(__DIR__ . '/pages/employees/', $action, $id);
        break;

    case 'users':
        requireAdmin();
        handleRoute(__DIR__ . '/pages/users/', $action, $id);
        break;

    case 'categories':
        requireAdmin();
        require __DIR__ . '/pages/categories/index.php';
        break;

    case 'departments':
        requireAdmin();
        require __DIR__ . '/pages/departments/index.php';
        break;

    case 'locations':
        requireAdmin();
        require __DIR__ . '/pages/locations/index.php';
        break;

    case 'settings':
        requireAdmin();
        handleRoute(__DIR__ . '/pages/settings/', $action, $id);
        break;

    default:
        http_response_code(404);
        echo '<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Stránka nenalezena</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
        h1 { color: #dc2626; }
    </style>
</head>
<body>
    <h1>404</h1>
    <p>Požadovaná stránka nebyla nalezena.</p>
    <a href="/dashboard">Zpět na dashboard</a>
</body>
</html>';
        break;
}

/**
 * Handle route to specific page file
 */
function handleRoute($basePath, $action, $id = null) {
    $file = $basePath . $action . '.php';

    if (file_exists($file)) {
        // Make $id available to the page
        $_GET['id'] = $id;
        require $file;
    } else {
        // Default to index.php if action file doesn't exist
        $indexFile = $basePath . 'index.php';
        if (file_exists($indexFile)) {
            require $indexFile;
        } else {
            http_response_code(404);
            die('Stránka nenalezena.');
        }
    }
}
