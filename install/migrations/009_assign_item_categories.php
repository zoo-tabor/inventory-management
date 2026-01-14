<?php
/**
 * Migration: Assign categories to EKOSPOL items
 * Match items with their categories based on the provided mapping
 */

return function($db) {
    // Get category IDs
    $categories = [];
    $stmt = $db->query("SELECT id, name FROM categories WHERE company_id = 1");
    while ($row = $stmt->fetch()) {
        $categories[$row['name']] = $row['id'];
    }

    // Item code => category name mapping
    $itemCategories = [
        // Papír
        '123.100' => 'Papír',
        '510.3233' => 'Papír',
        '250.490' => 'Papír',

        // Plotterová role
        '510.2108' => 'Plotterová role',

        // Šanony a desky
        '115.032' => 'Šanony a desky',
        '510.2326' => 'Šanony a desky',
        '430.402' => 'Šanony a desky',
        '304.404' => 'Šanony a desky',
        '520.789' => 'Šanony a desky',
        '520.697' => 'Šanony a desky',
        '520.741' => 'Šanony a desky',
        '520.846' => 'Šanony a desky',
        '145.488' => 'Šanony a desky',
        '786.730' => 'Šanony a desky',
        '250.640' => 'Šanony a desky',

        // Euroobaly
        '510.2185' => 'Euroobaly',
        '640.330' => 'Euroobaly',

        // Obálky
        '356.855' => 'Obálky',
        '635.355' => 'Obálky',
        '580.157' => 'Obálky',
        '148.141' => 'Obálky',

        // Etikety
        '510.2135' => 'Etikety',
        '510.2125' => 'Etikety',
        '510.2127' => 'Etikety',

        // Lepidlo
        '510.2717' => 'Lepidlo',

        // Korektor
        '510.2672' => 'Korektor',

        // Poznámkové a samolepící bločky
        '510.8513' => 'Poznámkové a samolepící bločky',
        '510.8514' => 'Poznámkové a samolepící bločky',

        // Sponky
        '559.218' => 'Sponky',
        '559.220' => 'Sponky',
        '559.221' => 'Sponky',

        // Sponky do sešívačky
        '510.2771' => 'Sponky do sešívačky',
        '784.045' => 'Sponky do sešívačky',
        '948.864' => 'Sponky do sešívačky',
        '140.4968' => 'Sponky do sešívačky',
        '784.040' => 'Sponky do sešívačky',

        // Lepicí pásky
        '350.200' => 'Lepicí pásky',
        '718.956' => 'Lepicí pásky',

        // Náplně tužek
        'AV021181' => 'Náplně tužek',

        // Popisovače a zvýrazňovače
        '835.855' => 'Popisovače a zvýrazňovače',
        '788.001' => 'Popisovače a zvýrazňovače',
        '788.002' => 'Popisovače a zvýrazňovače',
        '788.003' => 'Popisovače a zvýrazňovače',
        '788.005' => 'Popisovače a zvýrazňovače',
        '510.8069' => 'Popisovače a zvýrazňovače',
        '510.8078' => 'Popisovače a zvýrazňovače',
        '548.501' => 'Popisovače a zvýrazňovače',
        '548.111' => 'Popisovače a zvýrazňovače',
        '548.122' => 'Popisovače a zvýrazňovače',
        '548.101' => 'Popisovače a zvýrazňovače',
        '623.050' => 'Popisovače a zvýrazňovače',
        '623.051' => 'Popisovače a zvýrazňovače',
        '623.052' => 'Popisovače a zvýrazňovače',
        '623.053' => 'Popisovače a zvýrazňovače',
        '615.800' => 'Popisovače a zvýrazňovače',
        '615.801' => 'Popisovače a zvýrazňovače',
        '615.802' => 'Popisovače a zvýrazňovače',
        '615.803' => 'Popisovače a zvýrazňovače',
        '510.2740' => 'Popisovače a zvýrazňovače',

        // Psací potřeby
        '510.8229' => 'Psací potřeby',
        '510.8231' => 'Psací potřeby',
        '510.8110' => 'Psací potřeby',

        // Nápoje a potraviny
        '247.510' => 'Nápoje a potraviny',
        '495.522' => 'Nápoje a potraviny',
        '875.100' => 'Nápoje a potraviny',
        '393.749' => 'Nápoje a potraviny',
        '446.159' => 'Nápoje a potraviny',
        '185.790' => 'Nápoje a potraviny',
        '185.791' => 'Nápoje a potraviny',
        '221.598' => 'Nápoje a potraviny',
        '572.511' => 'Nápoje a potraviny',
        '171.784' => 'Nápoje a potraviny',
        '572.513' => 'Nápoje a potraviny',
        '572.451' => 'Nápoje a potraviny',

        // Drogerie
        '156.420' => 'Drogerie',
        '698.124' => 'Drogerie',
        '989.989' => 'Drogerie',
        '917.000' => 'Drogerie',
        '401.678' => 'Drogerie',
        '535.649' => 'Drogerie',
        '545.458' => 'Drogerie',
        '561.285' => 'Drogerie',
        '155.550' => 'Drogerie',
        '241.572' => 'Drogerie',
        '716.245' => 'Drogerie',
        '501.332' => 'Drogerie',
        '285.835' => 'Drogerie',
        '404.985' => 'Drogerie',
        '176.651' => 'Drogerie',

        // Laminovací fólie
        '510.5414' => 'Laminovací fólie',
        '510.5412' => 'Laminovací fólie',
        '510.5417' => 'Laminovací fólie',
    ];

    // Update items with their categories
    $updateStmt = $db->prepare("UPDATE items SET category_id = ? WHERE code = ? AND company_id = 1");

    foreach ($itemCategories as $code => $categoryName) {
        if (isset($categories[$categoryName])) {
            $categoryId = $categories[$categoryName];
            $updateStmt->execute([$categoryId, $code]);
        }
    }
};
