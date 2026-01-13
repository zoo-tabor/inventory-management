<?php
/**
 * Test Session and Auth
 */

// Start fresh
session_start();

echo "<h1>Session Test</h1>";

echo "<h2>Session Status:</h2>";
echo "<pre>";
echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . " (1=disabled, 2=active)\n";
echo "</pre>";

echo "<h2>Session Data:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>Cookies:</h2>";
echo "<pre>";
print_r($_COOKIE);
echo "</pre>";

echo "<h2>Test Login Detection:</h2>";
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

echo "<pre>";
echo "isLoggedIn(): " . (isLoggedIn() ? 'YES' : 'NO') . "\n";
echo "user_id in session: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET') . "\n";
echo "</pre>";

echo "<h2>Test Manual Login:</h2>";
echo '<form method="POST">';
echo '<button type="submit" name="test_login">Set Session Variables</button>';
echo '</form>';

if (isset($_POST['test_login'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_name'] = 'Test Admin';
    $_SESSION['current_company'] = 1;
    echo '<p style="color: green;">Session variables set! Refresh page to see them.</p>';
}

echo '<hr>';
echo '<p><a href="/dashboard">Try Dashboard</a> | <a href="/login">Go to Login</a> | <a href="/">Go to Root</a></p>';
