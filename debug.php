<?php
/**
 * Debug & Error Log Viewer
 * View PHP errors and server diagnostics
 */

// Security check - only allow in development or with secret key
$debugKey = 'debug_2026_sk';
if (!isset($_GET['key']) || $_GET['key'] !== $debugKey) {
    die('Access denied. Add ?key=debug_2026_sk to URL');
}

?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug & Error Logs</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1, h2 { color: #569cd6; }
        .section { background: #252526; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .error { color: #f48771; }
        .success { color: #4ec9b0; }
        .warning { color: #dcdcaa; }
        pre { background: #1e1e1e; padding: 10px; overflow-x: auto; border: 1px solid #3c3c3c; }
        .btn { display: inline-block; padding: 10px 20px; background: #0e639c; color: white; text-decoration: none; border-radius: 3px; margin: 5px; }
        .btn:hover { background: #1177bb; }
    </style>
</head>
<body>
    <h1>🔍 Debug & Error Viewer</h1>

    <div class="section">
        <h2>Quick Actions</h2>
        <a href="?key=<?= $debugKey ?>&action=phpinfo" class="btn">📋 PHP Info</a>
        <a href="?key=<?= $debugKey ?>&action=clear_logs" class="btn">🗑️ Clear Error Logs</a>
        <a href="?key=<?= $debugKey ?>&action=test_db" class="btn">💾 Test Database</a>
        <a href="/" class="btn">🏠 Go to Site</a>
    </div>

    <?php
    $action = $_GET['action'] ?? 'default';

    switch ($action) {
        case 'phpinfo':
            echo '<div class="section">';
            echo '<h2>PHP Info</h2>';
            phpinfo();
            echo '</div>';
            exit;

        case 'clear_logs':
            $logFile = __DIR__ . '/php_errors.log';
            if (file_exists($logFile)) {
                file_put_contents($logFile, '');
                echo '<div class="section success">✅ Error log cleared</div>';
            } else {
                echo '<div class="section warning">⚠️ No error log file found</div>';
            }
            break;

        case 'test_db':
            echo '<div class="section">';
            echo '<h2>Database Connection Test</h2>';
            try {
                require_once __DIR__ . '/config/config.php';
                require_once __DIR__ . '/config/database.php';
                require_once __DIR__ . '/classes/Database.php';

                $db = Database::getInstance();
                echo '<p class="success">✅ Database connection successful!</p>';

                // Test query
                $stmt = $db->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

                echo '<p class="success">✅ Found ' . count($tables) . ' tables</p>';
                echo '<pre>';
                print_r($tables);
                echo '</pre>';

            } catch (Exception $e) {
                echo '<p class="error">❌ Database error: ' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            }
            echo '</div>';
            break;
    }
    ?>

    <!-- PHP Error Log -->
    <div class="section">
        <h2>📄 PHP Error Log (php_errors.log)</h2>
        <?php
        $logFile = __DIR__ . '/php_errors.log';
        if (file_exists($logFile)) {
            $content = file_get_contents($logFile);
            if (empty($content)) {
                echo '<p class="success">✅ No errors logged</p>';
            } else {
                echo '<pre class="error">' . htmlspecialchars($content) . '</pre>';
            }
        } else {
            echo '<p class="warning">⚠️ Log file does not exist yet. Creating it now...</p>';
            file_put_contents($logFile, '');
            chmod($logFile, 0666);
            echo '<p class="success">✅ Log file created at: ' . $logFile . '</p>';
        }
        ?>
    </div>

    <!-- Server Error Log -->
    <div class="section">
        <h2>📄 Apache/Server Error Log (Last 50 lines)</h2>
        <?php
        // Try common locations for error logs
        $possibleLogs = [
            '/var/log/apache2/error.log',
            '/var/log/httpd/error_log',
            ini_get('error_log'),
            '/tmp/error_log'
        ];

        $foundLog = false;
        foreach ($possibleLogs as $logPath) {
            if ($logPath && file_exists($logPath) && is_readable($logPath)) {
                $foundLog = true;
                echo '<p class="success">✅ Reading from: ' . htmlspecialchars($logPath) . '</p>';
                $lines = file($logPath);
                $last50 = array_slice($lines, -50);
                echo '<pre class="error">' . htmlspecialchars(implode('', $last50)) . '</pre>';
                break;
            }
        }

        if (!$foundLog) {
            echo '<p class="warning">⚠️ Server error log not accessible</p>';
            echo '<p>Tried locations:</p><pre>';
            print_r($possibleLogs);
            echo '</pre>';
        }
        ?>
    </div>

    <!-- Environment Check -->
    <div class="section">
        <h2>🌍 Environment Check</h2>
        <table style="width: 100%; color: #d4d4d4;">
            <tr><td><strong>PHP Version:</strong></td><td><?= phpversion() ?></td></tr>
            <tr><td><strong>Server Software:</strong></td><td><?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?></td></tr>
            <tr><td><strong>Document Root:</strong></td><td><?= $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown' ?></td></tr>
            <tr><td><strong>Current Directory:</strong></td><td><?= getcwd() ?></td></tr>
            <tr><td><strong>Script Filename:</strong></td><td><?= __FILE__ ?></td></tr>
            <tr><td><strong>.env exists:</strong></td><td class="<?= file_exists(__DIR__ . '/.env') ? 'success' : 'error' ?>"><?= file_exists(__DIR__ . '/.env') ? '✅ Yes' : '❌ No' ?></td></tr>
            <tr><td><strong>.htaccess exists:</strong></td><td class="<?= file_exists(__DIR__ . '/.htaccess') ? 'success' : 'error' ?>"><?= file_exists(__DIR__ . '/.htaccess') ? '✅ Yes' : '❌ No' ?></td></tr>
            <tr><td><strong>config/ exists:</strong></td><td class="<?= file_exists(__DIR__ . '/config') ? 'success' : 'error' ?>"><?= file_exists(__DIR__ . '/config') ? '✅ Yes' : '❌ No' ?></td></tr>
            <tr><td><strong>index.php exists:</strong></td><td class="<?= file_exists(__DIR__ . '/index.php') ? 'success' : 'error' ?>"><?= file_exists(__DIR__ . '/index.php') ? '✅ Yes' : '❌ No' ?></td></tr>
            <tr><td><strong>Display Errors:</strong></td><td><?= ini_get('display_errors') ? 'On' : 'Off' ?></td></tr>
            <tr><td><strong>Error Reporting:</strong></td><td><?= error_reporting() ?></td></tr>
            <tr><td><strong>Log Errors:</strong></td><td><?= ini_get('log_errors') ? 'On' : 'Off' ?></td></tr>
            <tr><td><strong>Error Log File:</strong></td><td><?= ini_get('error_log') ?: 'Not set' ?></td></tr>
        </table>
    </div>

    <!-- Loaded Extensions -->
    <div class="section">
        <h2>📦 Loaded PHP Extensions</h2>
        <pre><?php
        $extensions = get_loaded_extensions();
        sort($extensions);
        foreach ($extensions as $ext) {
            echo $ext . "\n";
        }
        ?></pre>
    </div>

    <!-- Files in Root -->
    <div class="section">
        <h2>📁 Files in Root Directory</h2>
        <pre><?php
        $files = scandir(__DIR__);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = __DIR__ . '/' . $file;
            $type = is_dir($path) ? '[DIR]' : '[FILE]';
            $perms = substr(sprintf('%o', fileperms($path)), -4);
            echo sprintf("%-10s %s  %s\n", $type, $perms, $file);
        }
        ?></pre>
    </div>

    <!-- Recent Access Attempts -->
    <div class="section">
        <h2>🔍 Test Index.php Directly</h2>
        <?php
        echo '<p>Try accessing index.php directly to see the actual error:</p>';
        echo '<p><a href="/index.php" target="_blank" class="btn">Open /index.php</a></p>';
        ?>
    </div>

</body>
</html>
