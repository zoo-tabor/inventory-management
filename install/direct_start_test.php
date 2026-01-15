<?php
/**
 * Direct test of start.php without routing
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load config first
require_once __DIR__ . '/../config/config.php';

// Security check
if (!isset($_GET['key']) || $_GET['key'] !== MIGRATE_KEY) {
    die('Neplatný klíč. Přidejte parametr ?key=your_migrate_key');
}

echo "<!DOCTYPE html>\n<html><head><meta charset='UTF-8'><title>Direct Start Test</title></head><body>\n";
echo "<h1>Direct Start.php Test</h1>\n";

try {
    // Simulate session
    session_name('skladovy_system');
    session_start();

    // Set up a fake logged-in user
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = 1;
        $_SESSION['company_id'] = 1;
        $_SESSION['is_logged_in'] = true;
        echo "<p>✓ Session initialized with user_id=1, company_id=1</p>\n";
    }

    // Load required files
    echo "<p>Loading required files...</p>\n";
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/auth.php';

    // Auto-load classes
    spl_autoload_register(function ($class) {
        $file = __DIR__ . '/../classes/' . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });

    echo "<p>✓ All includes loaded</p>\n";

    // Now try to load the actual start.php
    echo "<hr><h2>Loading start.php directly:</h2>\n";
    echo "<div style='border: 2px solid #ccc; padding: 10px; margin: 10px 0;'>\n";

    ob_start();
    require __DIR__ . '/../pages/stocktaking/start.php';
    $output = ob_get_clean();

    echo $output;
    echo "</div>\n";
    echo "<p style='color: green; font-weight: bold;'>✓ start.php loaded successfully!</p>\n";

} catch (Throwable $e) {
    echo "<div style='color: red; background: #fee; padding: 10px; margin: 10px 0; border: 2px solid red;'>\n";
    echo "<h2>❌ Error loading start.php:</h2>\n";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>\n";
    echo "<h3>Stack trace:</h3>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
    echo "</div>\n";
}

echo "</body></html>\n";
