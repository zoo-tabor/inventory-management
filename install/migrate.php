<?php
/**
 * Database Migration Runner
 * Run pending migrations
 */

// Load configuration
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

// Security check - require migration key
if (!isset($_GET['key']) || $_GET['key'] !== (defined('MIGRATE_KEY') ? MIGRATE_KEY : '')) {
    die('Neplatný klíč pro migraci. Přidejte parametr ?key=your_migrate_key');
}

// Get database connection
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    die("Chyba připojení k databázi: " . $e->getMessage());
}

// Create migrations table if it doesn't exist
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL UNIQUE,
            `batch` INT UNSIGNED NOT NULL,
            `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci
    ");
} catch (PDOException $e) {
    die("Chyba vytváření tabulky migrations: " . $e->getMessage());
}

// Get executed migrations
$executedMigrations = [];
try {
    $stmt = $db->query("SELECT migration FROM migrations");
    while ($row = $stmt->fetch()) {
        $executedMigrations[] = $row['migration'];
    }
} catch (PDOException $e) {
    die("Chyba načítání provedených migrací: " . $e->getMessage());
}

// Get migration files
$migrationFiles = glob(__DIR__ . '/migrations/*.php');
sort($migrationFiles);

// Get next batch number
$stmt = $db->query("SELECT COALESCE(MAX(batch), 0) + 1 as next_batch FROM migrations");
$nextBatch = $stmt->fetch()['next_batch'];

// Run pending migrations
$pendingCount = 0;
$executedCount = 0;

echo "<!DOCTYPE html>\n";
echo "<html lang=\"cs\">\n";
echo "<head>\n";
echo "    <meta charset=\"UTF-8\">\n";
echo "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
echo "    <title>Migrace databáze - Skladový systém</title>\n";
echo "    <style>\n";
echo "        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }\n";
echo "        h1 { color: #1a3d2e; }\n";
echo "        .success { color: #16a34a; background: #f0fdf4; padding: 10px; margin: 10px 0; border-left: 4px solid #16a34a; }\n";
echo "        .error { color: #dc2626; background: #fef2f2; padding: 10px; margin: 10px 0; border-left: 4px solid #dc2626; }\n";
echo "        .info { color: #2563eb; background: #eff6ff; padding: 10px; margin: 10px 0; border-left: 4px solid #2563eb; }\n";
echo "        .migration { padding: 5px; margin: 5px 0; }\n";
echo "        pre { background: #f3f4f6; padding: 10px; overflow-x: auto; }\n";
echo "    </style>\n";
echo "</head>\n";
echo "<body>\n";
echo "    <h1>Migrace databáze</h1>\n";

foreach ($migrationFiles as $file) {
    $migrationName = basename($file, '.php');

    // Skip if already executed
    if (in_array($migrationName, $executedMigrations)) {
        echo "    <div class=\"migration\" style=\"color: #6b7280;\">⏭️ Přeskočeno: {$migrationName} (již provedeno)</div>\n";
        continue;
    }

    $pendingCount++;
    echo "    <div class=\"migration\">🔄 Spouštím: {$migrationName}</div>\n";
    flush();

    try {
        // Execute migration in isolated scope
        $migrationExecutor = function($migrationFile, $database) {
            // Include migration file and execute it
            $migration = include $migrationFile;

            // If migration returns a callable, execute it
            if (is_callable($migration)) {
                $migration($database);
            }
        };

        $migrationExecutor($file, $db);

        // Record migration
        $stmt = $db->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migrationName, $nextBatch]);

        echo "    <div class=\"success\">✅ Úspěch: {$migrationName}</div>\n";
        $executedCount++;

    } catch (Exception $e) {
        echo "    <div class=\"error\">❌ Chyba při provádění {$migrationName}: " . htmlspecialchars($e->getMessage()) . "</div>\n";
        echo "    <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";

        // Stop on error
        break;
    }

    flush();
}

echo "\n";
echo "    <div class=\"info\">\n";
echo "        <strong>Shrnutí:</strong><br>\n";
echo "        Celkem nalezeno migrací: " . count($migrationFiles) . "<br>\n";
echo "        Již provedeno: " . count($executedMigrations) . "<br>\n";
echo "        K provedení: {$pendingCount}<br>\n";
echo "        Úspěšně provedeno: {$executedCount}\n";
echo "    </div>\n";

if ($pendingCount === 0) {
    echo "    <div class=\"success\">✅ Všechny migrace jsou aktuální.</div>\n";
} elseif ($executedCount === $pendingCount) {
    echo "    <div class=\"success\">✅ Všechny pending migrace byly úspěšně provedeny.</div>\n";
    echo "    <p><a href=\"/\">→ Pokračovat na aplikaci</a></p>\n";
}

echo "</body>\n";
echo "</html>\n";
