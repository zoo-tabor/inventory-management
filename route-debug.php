<?php
/**
 * Route Debug Page
 * Shows what PHP is receiving from Apache
 */
?>
<!DOCTYPE html>
<html>
<head>
    <title>Route Debug</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        h2 { color: #569cd6; }
        pre { background: #252526; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .info { background: #0e639c; color: white; padding: 10px; border-radius: 3px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>🔍 Route Debug Information</h1>

    <div class="info">
        <strong>Current URL:</strong> <?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'NOT SET') ?>
    </div>

    <h2>$_GET Parameters</h2>
    <pre><?php print_r($_GET); ?></pre>

    <h2>$_SERVER Variables (Relevant)</h2>
    <pre><?php
    $relevant = [
        'REQUEST_URI',
        'QUERY_STRING',
        'SCRIPT_NAME',
        'PHP_SELF',
        'REQUEST_METHOD',
        'HTTP_HOST',
        'HTTPS',
        'DOCUMENT_ROOT',
        'SCRIPT_FILENAME',
        'REDIRECT_STATUS',
        'REDIRECT_URL',
    ];

    $serverInfo = [];
    foreach ($relevant as $key) {
        $serverInfo[$key] = $_SERVER[$key] ?? 'NOT SET';
    }
    print_r($serverInfo);
    ?></pre>

    <h2>Test Links</h2>
    <ul>
        <li><a href="/categories">Pretty URL: /categories</a></li>
        <li><a href="/index.php?route=categories">Query Param: ?route=categories</a></li>
        <li><a href="/locations">Pretty URL: /locations</a></li>
        <li><a href="/index.php?route=locations">Query Param: ?route=locations</a></li>
    </ul>

    <h2>Apache mod_rewrite Test</h2>
    <pre><?php
    echo "mod_rewrite loaded: ";
    if (function_exists('apache_get_modules')) {
        echo in_array('mod_rewrite', apache_get_modules()) ? 'YES ✓' : 'NO ✗';
    } else {
        echo "Cannot detect (apache_get_modules not available)";
    }
    ?></pre>

    <h2>.htaccess File Check</h2>
    <pre><?php
    $htaccessPath = __DIR__ . '/.htaccess';
    echo "File exists: " . (file_exists($htaccessPath) ? 'YES ✓' : 'NO ✗') . "\n";
    echo "Readable: " . (is_readable($htaccessPath) ? 'YES ✓' : 'NO ✗') . "\n";
    echo "Path: " . $htaccessPath . "\n";
    ?></pre>

    <p><a href="/">← Back to Home</a></p>
</body>
</html>
