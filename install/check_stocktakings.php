<?php
/**
 * Check stocktakings table structure
 * Diagnostic tool to verify columns exist
 */

// Load configuration
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

// Security check - require migration key
if (!isset($_GET['key']) || $_GET['key'] !== (defined('MIGRATE_KEY') ? MIGRATE_KEY : '')) {
    die('Neplatný klíč. Přidejte parametr ?key=your_migrate_key');
}

// Get database connection
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    die("Chyba připojení k databázi: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kontrola struktury tabulky stocktakings</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 50px auto; padding: 20px; }
        h1 { color: #1a3d2e; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f3f4f6; font-weight: 600; }
        .success { color: #16a34a; }
        .error { color: #dc2626; }
        .info { background: #eff6ff; padding: 15px; margin: 20px 0; border-left: 4px solid #2563eb; }
    </style>
</head>
<body>
    <h1>Kontrola struktury tabulky stocktakings</h1>

    <h2>Sloupce v tabulce stocktakings:</h2>
    <table>
        <thead>
            <tr>
                <th>Field</th>
                <th>Type</th>
                <th>Null</th>
                <th>Key</th>
                <th>Default</th>
                <th>Extra</th>
            </tr>
        </thead>
        <tbody>
            <?php
            try {
                $stmt = $db->query("SHOW COLUMNS FROM stocktakings");
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($columns as $column) {
                    echo "<tr>";
                    echo "<td><strong>" . htmlspecialchars($column['Field']) . "</strong></td>";
                    echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
                    echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
                    echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
                    echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
                    echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
                    echo "</tr>";
                }
            } catch (PDOException $e) {
                echo "<tr><td colspan='6' class='error'>Chyba: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
            }
            ?>
        </tbody>
    </table>

    <h2>Kontrola požadovaných sloupců:</h2>
    <div class="info">
        <?php
        $requiredColumns = ['id', 'company_id', 'location_id', 'user_id', 'status', 'created_at', 'completed_at'];
        $existingColumns = array_column($columns ?? [], 'Field');

        echo "<ul>";
        foreach ($requiredColumns as $col) {
            if (in_array($col, $existingColumns)) {
                echo "<li class='success'>✓ <strong>{$col}</strong> - existuje</li>";
            } else {
                echo "<li class='error'>✗ <strong>{$col}</strong> - CHYBÍ!</li>";
            }
        }
        echo "</ul>";

        // Check for old columns
        $oldColumns = ['started_by', 'started_at'];
        echo "<h3>Staré sloupce (měly by stále existovat):</h3>";
        echo "<ul>";
        foreach ($oldColumns as $col) {
            if (in_array($col, $existingColumns)) {
                echo "<li class='success'>✓ <strong>{$col}</strong> - existuje</li>";
            } else {
                echo "<li>ℹ️ <strong>{$col}</strong> - neexistuje</li>";
            }
        }
        echo "</ul>";
        ?>
    </div>

    <h2>Test INSERT dotazu:</h2>
    <div class="info">
        <?php
        try {
            // Try to prepare the INSERT statement that start.php uses
            $stmt = $db->prepare("
                INSERT INTO stocktakings (company_id, location_id, user_id, status)
                VALUES (?, ?, ?, 'in_progress')
            ");
            echo "<p class='success'>✓ INSERT dotaz lze připravit (sloupce existují)</p>";

            // Don't actually execute it, just prepare
            echo "<p><strong>Dotaz:</strong></p>";
            echo "<pre>INSERT INTO stocktakings (company_id, location_id, user_id, status)\nVALUES (?, ?, ?, 'in_progress')</pre>";

        } catch (PDOException $e) {
            echo "<p class='error'>✗ Chyba při přípravě INSERT dotazu:</p>";
            echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
        }
        ?>
    </div>

    <h2>Spuštěné migrace:</h2>
    <table>
        <thead>
            <tr>
                <th>Migration</th>
                <th>Batch</th>
                <th>Executed At</th>
            </tr>
        </thead>
        <tbody>
            <?php
            try {
                $stmt = $db->query("SELECT * FROM migrations ORDER BY id");
                $migrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($migrations)) {
                    echo "<tr><td colspan='3'>Žádné migrace nebyly nalezeny</td></tr>";
                } else {
                    foreach ($migrations as $migration) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($migration['migration']) . "</td>";
                        echo "<td>" . htmlspecialchars($migration['batch']) . "</td>";
                        echo "<td>" . htmlspecialchars($migration['executed_at']) . "</td>";
                        echo "</tr>";
                    }
                }
            } catch (PDOException $e) {
                echo "<tr><td colspan='3' class='error'>Chyba: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
            }
            ?>
        </tbody>
    </table>

    <p><a href="/">← Zpět na aplikaci</a></p>
</body>
</html>
