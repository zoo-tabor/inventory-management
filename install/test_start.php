<?php
/**
 * Test start.php loading
 * Diagnostic tool to see actual PHP errors
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php_errors.log');

echo "<!DOCTYPE html>\n";
echo "<html lang=\"cs\">\n";
echo "<head>\n";
echo "    <meta charset=\"UTF-8\">\n";
echo "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
echo "    <title>Test stocktaking/start</title>\n";
echo "    <style>\n";
echo "        body { font-family: Arial, sans-serif; max-width: 900px; margin: 50px auto; padding: 20px; }\n";
echo "        h1 { color: #1a3d2e; }\n";
echo "        .success { color: #16a34a; background: #f0fdf4; padding: 10px; margin: 10px 0; border-left: 4px solid #16a34a; }\n";
echo "        .error { color: #dc2626; background: #fef2f2; padding: 10px; margin: 10px 0; border-left: 4px solid #dc2626; }\n";
echo "        .info { color: #2563eb; background: #eff6ff; padding: 10px; margin: 10px 0; border-left: 4px solid #2563eb; }\n";
echo "        pre { background: #f3f4f6; padding: 10px; overflow-x: auto; }\n";
echo "    </style>\n";
echo "</head>\n";
echo "<body>\n";
echo "    <h1>Test načtení stocktaking/start.php</h1>\n";

// Load config first to get MIGRATE_KEY
require_once __DIR__ . '/../config/config.php';

// Security check - require migration key
if (!isset($_GET['key']) || $_GET['key'] !== MIGRATE_KEY) {
    echo "    <div class='error'>Neplatný klíč. Přidejte parametr ?key=your_migrate_key</div>\n";
    echo "</body></html>";
    exit;
}

echo "    <div class='info'>Zkouším načíst všechny required soubory...</div>\n";
flush();

try {
    // config.php already loaded for security check
    echo "    <div class='success'>✓ config.php načten</div>\n";
    flush();

    echo "    <div class='info'>Načítám config/database.php...</div>\n";
    flush();
    require_once __DIR__ . '/../config/database.php';
    echo "    <div class='success'>✓ database.php načten</div>\n";
    flush();

    echo "    <div class='info'>Načítám includes/functions.php...</div>\n";
    flush();
    require_once __DIR__ . '/../includes/functions.php';
    echo "    <div class='success'>✓ functions.php načten</div>\n";
    flush();

    echo "    <div class='info'>Načítám includes/auth.php...</div>\n";
    flush();
    require_once __DIR__ . '/../includes/auth.php';
    echo "    <div class='success'>✓ auth.php načten</div>\n";
    flush();

    // Auto-load classes
    spl_autoload_register(function ($class) {
        $file = __DIR__ . '/../classes/' . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
    echo "    <div class='success'>✓ Autoloader registrován</div>\n";
    flush();

    echo "    <div class='info'>Zkouším získat databázové připojení...</div>\n";
    flush();
    $db = Database::getInstance();
    echo "    <div class='success'>✓ Databázové připojení OK</div>\n";
    flush();

    echo "    <div class='info'>Zkouším připravit INSERT dotaz...</div>\n";
    flush();
    $stmt = $db->prepare("
        INSERT INTO stocktakings (company_id, location_id, user_id, status)
        VALUES (?, ?, ?, 'in_progress')
    ");
    echo "    <div class='success'>✓ INSERT dotaz lze připravit</div>\n";
    flush();

    echo "    <div class='info'>Kontrolujem funkce používané v start.php...</div>\n";
    flush();

    $requiredFunctions = [
        'isLoggedIn',
        'redirect',
        'getCurrentCompanyId',
        'validateCsrfToken',
        'setFlash',
        'logAudit',
        'formatNumber',
        'url',
        'e',
        'csrfField'
    ];

    $missingFunctions = [];
    foreach ($requiredFunctions as $func) {
        if (!function_exists($func)) {
            $missingFunctions[] = $func;
        }
    }

    if (empty($missingFunctions)) {
        echo "    <div class='success'>✓ Všechny požadované funkce existují</div>\n";
    } else {
        echo "    <div class='error'>✗ Chybějící funkce: " . implode(', ', $missingFunctions) . "</div>\n";
    }
    flush();

    echo "    <div class='info'>Zkouším načíst kategorie z databáze...</div>\n";
    flush();
    $stmt = $db->prepare("SELECT id, name FROM categories WHERE company_id = ? ORDER BY name");
    $stmt->execute([1]); // Use company 1 for test
    $categories = $stmt->fetchAll();
    echo "    <div class='success'>✓ Kategorie načteny (" . count($categories) . " záznamů)</div>\n";
    flush();

    echo "    <div class='info'>Zkouším načíst lokace z databáze...</div>\n";
    flush();
    $stmt = $db->prepare("SELECT id, name, code FROM locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([1]); // Use company 1 for test
    $locations = $stmt->fetchAll();
    echo "    <div class='success'>✓ Lokace načteny (" . count($locations) . " záznamů)</div>\n";
    flush();

    echo "    <div class='info'>Zkouším načíst statistiky položek...</div>\n";
    flush();
    $stmt = $db->prepare("
        SELECT
            COUNT(DISTINCT i.id) as total_items,
            COUNT(DISTINCT CASE WHEN COALESCE(SUM(s.quantity), 0) > 0 THEN i.id END) as items_with_stock
        FROM items i
        LEFT JOIN stock s ON i.id = s.item_id
        WHERE i.company_id = ? AND i.is_active = 1
    ");
    $stmt->execute([1]); // Use company 1 for test
    $itemStats = $stmt->fetch();
    echo "    <div class='success'>✓ Statistiky položek načteny</div>\n";
    flush();

    echo "    <div class='success'><strong>✓ Všechny testy prošly!</strong></div>\n";
    echo "    <div class='info'>Pokud tyto testy projdou, problém může být v:<ul>";
    echo "    <li>Session management (není inicializována session)</li>";
    echo "    <li>Routing (start.php není správně načten)</li>";
    echo "    <li>Autentizace (kontrola přihlášení)</li>";
    echo "    <li>Nějaký specifický PHP modul chybí na serveru</li>";
    echo "    </ul></div>\n";

} catch (Exception $e) {
    echo "    <div class='error'><strong>✗ Chyba:</strong><br>" . htmlspecialchars($e->getMessage()) . "</div>\n";
    echo "    <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}

echo "    <p><a href='/'>← Zpět na aplikaci</a></p>\n";
echo "</body>\n";
echo "</html>\n";
