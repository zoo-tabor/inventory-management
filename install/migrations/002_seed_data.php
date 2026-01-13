<?php
/**
 * Migration: 002_seed_data
 * Seeds initial data for companies, settings, and default admin user
 */

function up($db) {
    // Insert companies
    $db->exec("
        INSERT INTO `companies` (`name`, `code`, `theme`, `logo`) VALUES
        ('EKOSPOL', 'EKO', 'ekospol', 'logo-ekospol.png'),
        ('ZOO Tábor', 'ZOO', 'zoo', 'logo-zoo.png')
        ON DUPLICATE KEY UPDATE name=name
    ");
    echo "    <div class=\"info\">✓ Vloženy společnosti (EKOSPOL, ZOO Tábor)</div>\n";

    // Insert default settings
    $settings = [
        ['smtp_host', '', 'SMTP server'],
        ['smtp_port', '587', 'SMTP port'],
        ['smtp_user', '', 'SMTP username'],
        ['smtp_password', '', 'SMTP password'],
        ['smtp_from_email', '', 'From email'],
        ['smtp_from_name', 'Skladový systém', 'From name'],
        ['expiration_warning_days', '30', 'Days before expiration to warn'],
        ['low_stock_warning_months', '1', 'Warn when stock < X months'],
        ['default_order_months', '3', 'Default order calculation months'],
        ['csv_export_delimiter', ';', 'CSV delimiter'],
        ['csv_export_encoding', 'UTF-8', 'CSV encoding'],
        ['csv_export_columns', 'code,quantity', 'CSV export columns']
    ];

    $stmt = $db->prepare("INSERT INTO `settings` (`key`, `value`, `description`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value=value");

    foreach ($settings as $setting) {
        $stmt->execute($setting);
    }
    echo "    <div class=\"info\">✓ Vložena výchozí nastavení</div>\n";

    // Create default admin user (password: admin123)
    // In production, this should be changed immediately!
    $defaultPasswordHash = password_hash('admin123', PASSWORD_DEFAULT);

    $stmt = $db->prepare("
        INSERT INTO `users` (`username`, `password_hash`, `full_name`, `email`, `role`, `is_active`)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE username=username
    ");

    $stmt->execute([
        'admin',
        $defaultPasswordHash,
        'Administrátor',
        'admin@example.com',
        'admin',
        1
    ]);

    echo "    <div class=\"info\">✓ Vytvořen výchozí administrátorský účet</div>\n";
    echo "    <div class=\"success\"><strong>Přihlašovací údaje:</strong><br>Username: <strong>admin</strong><br>Password: <strong>admin123</strong><br><span style=\"color: #dc2626;\">⚠️ Změňte heslo co nejdříve!</span></div>\n";

    // Insert sample categories
    $categories = [
        ['Kancelářské potřeby', 0],
        ['Čisticí prostředky', 0],
        ['Potraviny', 1],
        ['Léky a zdravotnický materiál', 1],
        ['Krmivo', 1],
        ['Nástroje a nářadí', 0],
        ['Ochranné pracovní pomůcky', 0]
    ];

    $stmt = $db->prepare("INSERT INTO `categories` (`name`, `has_expiration`) VALUES (?, ?) ON DUPLICATE KEY UPDATE name=name");

    foreach ($categories as $category) {
        $stmt->execute($category);
    }
    echo "    <div class=\"info\">✓ Vloženy ukázkové kategorie</div>\n";
}
