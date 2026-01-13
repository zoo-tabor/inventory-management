<?php
/**
 * Email Configuration
 * SMTP settings loaded from database settings table
 */

/**
 * Get email settings from database
 *
 * @return array Email configuration
 */
function getEmailConfig() {
    try {
        $db = Database::getInstance();
        $settings = $db->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smtp_%'")->fetchAll();

        $config = [
            'host' => '',
            'port' => 587,
            'user' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => 'Skladový systém',
            'encryption' => 'tls'
        ];

        foreach ($settings as $setting) {
            $key = str_replace('smtp_', '', $setting['key']);
            if (isset($config[$key])) {
                $config[$key] = $setting['value'];
            }
        }

        return $config;
    } catch (Exception $e) {
        // Return default config if database not available
        return [
            'host' => '',
            'port' => 587,
            'user' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => 'Skladový systém',
            'encryption' => 'tls'
        ];
    }
}

/**
 * Send email using PHP mail() or SMTP
 *
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @return bool Success status
 */
function sendEmail($to, $subject, $body) {
    $config = getEmailConfig();

    // Log email attempt
    $db = Database::getInstance();
    $logId = null;

    try {
        // If SMTP is not configured, use PHP mail()
        if (empty($config['host'])) {
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=utf-8',
                'From: ' . $config['from_name'] . ' <' . $config['from_email'] . '>',
            ];

            $success = mail($to, $subject, $body, implode("\r\n", $headers));

            // Log result
            $stmt = $db->prepare("INSERT INTO email_log (recipient_email, subject, status, error_message) VALUES (?, ?, ?, ?)");
            $stmt->execute([$to, $subject, $success ? 'sent' : 'failed', $success ? null : 'PHP mail() failed']);

            return $success;
        }

        // SMTP sending would be implemented here using a library like PHPMailer
        // For now, we'll use basic mail() function

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . $config['from_name'] . ' <' . $config['from_email'] . '>',
        ];

        $success = mail($to, $subject, $body, implode("\r\n", $headers));

        // Log result
        $stmt = $db->prepare("INSERT INTO email_log (recipient_email, subject, status, error_message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$to, $subject, $success ? 'sent' : 'failed', $success ? null : 'Mail function failed']);

        return $success;

    } catch (Exception $e) {
        // Log error
        if ($db) {
            try {
                $stmt = $db->prepare("INSERT INTO email_log (recipient_email, subject, status, error_message) VALUES (?, ?, 'failed', ?)");
                $stmt->execute([$to, $subject, $e->getMessage()]);
            } catch (Exception $logError) {
                // Silently fail if logging fails
            }
        }

        return false;
    }
}
