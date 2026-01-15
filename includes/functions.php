<?php
/**
 * Common Helper Functions
 */

/**
 * Escape HTML output
 *
 * @param string $string String to escape
 * @return string Escaped string
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Generate URL for route
 *
 * @param string $route Route path (e.g., 'categories', 'items/edit')
 * @param array $params Additional query parameters
 * @return string Generated URL
 */
function url($route, $params = []) {
    $url = '/index.php?route=' . urlencode($route);

    foreach ($params as $key => $value) {
        $url .= '&' . urlencode($key) . '=' . urlencode($value);
    }

    return $url;
}

/**
 * Redirect to URL
 *
 * @param string $url URL to redirect to
 */
function redirect($url) {
    // If URL doesn't start with http or index.php, convert to query param format
    if (strpos($url, 'http') !== 0 && strpos($url, 'index.php') !== 0) {
        // Remove leading slash if present
        $url = url(ltrim($url, '/'));
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Set flash message
 *
 * @param string $type Message type (success, error, warning, info)
 * @param string $message Message text
 */
function setFlash($type, $message) {
    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash messages
 *
 * @return array Flash messages
 */
function getFlash() {
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * Get current company ID from session
 *
 * @return int|null Company ID
 */
function getCurrentCompanyId() {
    return $_SESSION['current_company'] ?? null;
}

/**
 * Set current company
 *
 * @param int $companyId Company ID
 */
function setCurrentCompany($companyId) {
    $_SESSION['current_company'] = $companyId;
}

/**
 * Get current company data
 *
 * @return array|null Company data
 */
function getCurrentCompany() {
    $companyId = getCurrentCompanyId();
    return $companyId ? COMPANIES[$companyId] : null;
}

/**
 * Get company theme class
 *
 * @return string Theme class name
 */
function getThemeClass() {
    $company = getCurrentCompany();
    return $company ? 'theme-' . $company['theme'] : 'theme-default';
}

/**
 * Format date for display
 *
 * @param string $date Date string
 * @param string $format Format string
 * @return string Formatted date
 */
function formatDate($date, $format = 'd.m.Y') {
    if (empty($date)) return '';
    return date($format, strtotime($date));
}

/**
 * Format datetime for display
 *
 * @param string $datetime Datetime string
 * @param string $format Format string
 * @return string Formatted datetime
 */
function formatDateTime($datetime, $format = 'd.m.Y H:i') {
    if (empty($datetime)) return '';
    return date($format, strtotime($datetime));
}

/**
 * Format number
 *
 * @param float $number Number to format
 * @param int $decimals Number of decimals
 * @return string Formatted number
 */
function formatNumber($number, $decimals = 0) {
    return number_format($number, $decimals, ',', ' ');
}

/**
 * Format price
 *
 * @param float $price Price to format
 * @return string Formatted price with currency
 */
function formatPrice($price) {
    return formatNumber($price, 2) . ' Kč';
}

/**
 * Get stock status
 *
 * @param int $quantity Current quantity
 * @param int $minimumStock Minimum stock level
 * @return string Status (ok, low, critical)
 */
function getStockStatus($quantity, $minimumStock) {
    if ($quantity <= 0) {
        return STOCK_STATUS_CRITICAL;
    } elseif ($quantity <= $minimumStock) {
        return STOCK_STATUS_LOW;
    }
    return STOCK_STATUS_OK;
}

/**
 * Get stock status badge HTML
 *
 * @param string $status Status
 * @return string HTML badge
 */
function getStockStatusBadge($status) {
    $labels = [
        'ok' => 'OK',
        'low' => 'Nízký stav',
        'critical' => 'Kritický'
    ];

    $label = $labels[$status] ?? 'Neznámý';

    return '<span class="badge badge-' . $status . '">' . $label . '</span>';
}

/**
 * Calculate packages from pieces
 *
 * @param int $pieces Number of pieces
 * @param int $piecesPerPackage Pieces per package
 * @return float Number of packages
 */
function piecesToPackages($pieces, $piecesPerPackage) {
    if ($piecesPerPackage <= 0) return 0;
    return round($pieces / $piecesPerPackage, 2);
}

/**
 * Calculate pieces from packages
 *
 * @param float $packages Number of packages
 * @param int $piecesPerPackage Pieces per package
 * @return int Number of pieces
 */
function packagesToPieces($packages, $piecesPerPackage) {
    return round($packages * $piecesPerPackage);
}

/**
 * Sanitize input
 *
 * @param string $input Input to sanitize
 * @return string Sanitized input
 */
function sanitize($input) {
    return trim(strip_tags($input));
}

/**
 * Validate CSRF token
 *
 * @return bool Valid or not
 */
function validateCsrfToken() {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Generate CSRF token
 *
 * @return string CSRF token
 */
function getCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Get CSRF token input field
 *
 * @return string HTML input field
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . getCsrfToken() . '">';
}

/**
 * Log audit entry
 *
 * @param string $action Action performed
 * @param string|null $entityType Entity type
 * @param int|null $entityId Entity ID
 * @param string|null $description Description
 * @param array|null $oldValues Old values
 * @param array|null $newValues New values
 */
function logAudit($action, $entityType = null, $entityId = null, $description = null, $oldValues = null, $newValues = null) {
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO audit_log (user_id, company_id, action, entity_type, entity_id, description, old_values, new_values, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            getCurrentCompanyId(),
            $action,
            $entityType,
            $entityId,
            $description,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to log audit: " . $e->getMessage());
    }
}
