<?php
/**
 * Schema Diagnostic Tool
 * Shows current database table structure
 */

require __DIR__ . '/../config/database.php';

// Security check
$key = $_GET['key'] ?? '';
if ($key !== 'sk_mig_2026_officeo_secure_key_xj8k2p') {
    die('Unauthorized');
}

$db = Database::getInstance();

echo "<h1>Database Schema Diagnostic</h1>";

// Check stock_movements table
echo "<h2>stock_movements Table Structure</h2>";
$stmt = $db->query("SHOW COLUMNS FROM stock_movements");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
foreach ($columns as $column) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check foreign keys
echo "<h2>stock_movements Foreign Keys</h2>";
$stmt = $db->query("
    SELECT
        CONSTRAINT_NAME,
        COLUMN_NAME,
        REFERENCED_TABLE_NAME,
        REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_movements'
    AND REFERENCED_TABLE_NAME IS NOT NULL
");
$foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Constraint</th><th>Column</th><th>References Table</th><th>References Column</th></tr>";
foreach ($foreignKeys as $fk) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($fk['CONSTRAINT_NAME']) . "</td>";
    echo "<td>" . htmlspecialchars($fk['COLUMN_NAME']) . "</td>";
    echo "<td>" . htmlspecialchars($fk['REFERENCED_TABLE_NAME']) . "</td>";
    echo "<td>" . htmlspecialchars($fk['REFERENCED_COLUMN_NAME']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check items table
echo "<h2>items Table Structure</h2>";
$stmt = $db->query("SHOW COLUMNS FROM items");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
foreach ($columns as $column) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
    echo "</tr>";
}
echo "</table>";
