<?php
/**
 * Authentication Functions
 */

/**
 * Check if user is logged in
 *
 * @return bool
 */
function isLoggedIn() {
    $result = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    error_log("DEBUG isLoggedIn(): result=" . ($result ? 'true' : 'false') . ", SESSION data: " . print_r($_SESSION, true));
    return $result;
}

/**
 * Check if user is admin
 *
 * @return bool
 */
function isAdmin() {
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === ROLE_ADMIN;
}

/**
 * Require user to be logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        redirect('/login');
    }
}

/**
 * Require user to be admin
 */
function requireAdmin() {
    if (!isAdmin()) {
        setFlash('error', 'Nemáte oprávnění k této akci.');
        redirect('/dashboard');
    }
}

/**
 * Attempt to log in user
 *
 * @param string $username Username
 * @param string $password Password
 * @return bool Success
 */
function attemptLogin($username, $password) {
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['full_name'];

            // Set default company if not set
            if (!isset($_SESSION['current_company'])) {
                $_SESSION['current_company'] = 1; // Default to EKOSPOL
            }

            // Update last login
            $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$user['id']]);

            // Log the login
            logAudit('login', 'user', $user['id'], 'Uživatel se přihlásil');

            return true;
        }

        return false;
    } catch (Exception $e) {
        error_log("Login failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log out current user
 */
function logout() {
    // Log the logout
    if (isset($_SESSION['user_id'])) {
        logAudit('logout', 'user', $_SESSION['user_id'], 'Uživatel se odhlásil');
    }

    // Destroy session
    session_destroy();
    redirect('/login');
}

/**
 * Get current user data
 *
 * @return array|null User data
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }

    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, username, full_name, email, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get current user ID
 *
 * @return int|null User ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user name
 *
 * @return string|null User name
 */
function getCurrentUserName() {
    return $_SESSION['user_name'] ?? null;
}
