<?php
/**
 * Migration: Import EKOSPOL items
 * One-time import of existing inventory items
 */

return function($db) {
    // Items data: [code, name]
    $items = [
        ['123.100', 'Kancelářský papír OFFICEO Economy A4 - 80 g/m2, CIE 146, 500 listů'],
        ['510.2108', 'Plotterová role Q-Connect - 914 mm x 50 m, 80 g/m2, barevný tisk, 1 ks'],
        ['510.3233', 'Kancelářský papír Q-Connect A3 - 80 g/m2, CIE 146, 500 listů'],
        ['250.490', 'Kancelářský papír Color Copy A4 - 120 g/m2, CIE 161, 250 listů'],
        ['115.032', 'Pákový pořadač Officeo - A4, kartonový, šíře hřbetu 7,5 cm, mramor, černý hřbet'],
        ['510.2185', 'Euroobaly U Q-Connect - A4, krupičkové, 50 mic, 100 ks'],
        ['640.330', 'Euroobaly U s rozšířenou kapacitou - A4+, krupičkové, 100 mic, 50 ks'],
        ['356.855', 'Obálky DL - s vnitřním tiskem, samolepicí, 1 000 ks'],
        ['635.355', 'Obchodní tašky C4 - samolepicí s krycí páskou, 25 ks'],
        ['580.157', 'Obálka C5 - samolepící, s vnitřním tiskem, samolepicí, 50 ks'],
        ['510.2135', 'Univerzální etikety Q-Connect - bílé, 38,1 x 21,2 mm, 6 500 ks zaoblené rohy'],
        ['510.2125', 'Univerzální etikety Q-Connect - bílé, 105 x 74 mm, 800 ks'],
        ['510.2127', 'Univerzální etikety Q-Connect - bílé, 105 x 42,3 mm, 1 400 ks'],
        ['510.2717', 'Lepicí tyčinka Qstick Q-Connect - 20 g'],
        ['510.2672', 'Korekční strojek Q-Connect - 5 mm x 8 m'],
        ['510.2326', 'Plastové rychlovazače Q-Connect - A4, žluté, 50 ks'],
        ['430.402', 'Plastové rychlovazače Donau - A4, zelené, 10 ks'],
        ['304.404', 'Plastové rychlovazače Donau - A4, modré, 10 ks'],
        ['520.789', 'Papírové desky s chlopněmi HIT Office - A4, žluté, 50 ks'],
        ['520.697', 'Papírové desky s chlopněmi HIT Office - A4, modré , 50 ks'],
        ['520.741', 'Papírové desky s chlopněmi HIT Office - A4, zelené, 50 ks'],
        ['520.846', 'Papírové desky s chlopněmi HIT Office - A4, růžové , 50 ks'],
        ['145.488', 'Papírové rozlišovače Donau - 1/3 A4, 235x105 mm, 100 ks, mix barev'],
        ['510.8513', 'Samolepicí bločky Q-Connect QUICK - 76 x 76 mm, žluté'],
        ['510.8514', 'Samolepicí bločky Q-Connect - 38 x 51 mm, žluté, 3 ks'],
        ['559.218', 'Kancelářské sponky Sakota - délka 28 mm, 100 ks'],
        ['559.220', 'Kancelářské sponky Sakota - délka 50 mm, 100 ks'],
        ['559.221', 'Kancelářské sponky Sakota - délka 78 mm, 50 ks'],
        ['510.2771', 'Drátky do sešívačky Q-Connect - 24/6, pozinkované, 1000 ks'],
        ['784.045', 'Drátky do sešívačky SAX - 26/6, pozinkované, 1000 ks'],
        ['948.864', 'Drátky do sešívaček Rapid Standard 23/12, 1000 ks'],
        ['140.4968', 'Drátky K pro sešívačku Leitz 5551 K8, 5 x 210 ks'],
        ['350.200', 'Balicí páska Tartan - čirá, 50 mm x 66 m, 1 ks'],
        ['718.956', 'Lepicí páska Kores 19 mm x 10 m, transparentní'],
        ['AV021181', 'Náhradní náplň do rolleru Parker F, modrá'],
        ['835.855', 'Zvýrazňovač Centropen 2822 - neonové barvy, zkosený hrot, sada 4 ks'],
        ['788.001', 'Zvýrazňovač Q-Connect - pastelově žlutý'],
        ['788.002', 'Zvýrazňovač Q-Connect - pastelově růžový'],
        ['788.003', 'Zvýrazňovač Q-Connect - pastelově zelený'],
        ['788.005', 'Zvýrazňovač Q-Connect - pastelově oranžový'],
        ['510.8069', 'Permanentní popisovač na CD Q-Connect - sada 4 barev'],
        ['510.8078', 'Permanentní popisovač Q-Connect - zkosený hrot, černý'],
        ['548.501', 'Permanentní popisovač Centropen 2846 - kulatý hrot, černý'],
        ['548.111', 'Permanentní popisovač Centropen 2846 - kulatý hrot, červený'],
        ['548.122', 'Permanentní popisovač Centropen 2846 - kulatý hrot, modrý'],
        ['548.101', 'Permanentní popisovač Centropen 2846 - kulatý hrot, zelený'],
        ['623.050', 'Popisovač na bílé tabule Centropen 8559 - kulatý hrot, černý'],
        ['623.051', 'Popisovač na bílé tabule Centropen 8559 - kulatý hrot, červený'],
        ['623.052', 'Popisovač na bílé tabule Centropen 8559 - kulatý hrot, zelený'],
        ['623.053', 'Popisovač na bílé tabule Centropen 8559 - kulatý hrot, modrý'],
        ['615.800', 'Liner Centropen 4611 - 0,3 mm, černý'],
        ['615.801', 'Liner Centropen 4611 - 0,3 mm, červený'],
        ['615.802', 'Liner Centropen 4611 - 0,3 mm, modrý'],
        ['615.803', 'Liner Centropen 4611 - 0,3 mm, zelený'],
        ['510.2740', 'Mikrotužka Q-Connect - 0,5 mm, černá'],
        ['510.8229', 'Náhradní tuhy Q-Connect - HB, 0,5 mm, 12 ks'],
        ['510.8231', 'Grafitová tužka Q-Connect - s pryží, HB, 12 ks'],
        ['510.8110', 'Pryž Q-Connect - bílá'],
        ['247.510', 'Zrnková káva Segafredo - Selezione Espresso, 1 kg'],
        ['495.522', 'Smetana do kávy Meggle - 10x 10g'],
        ['875.100', 'Porcovaný cukr v roličkách Office Depot, 1000 x 4 g'],
        ['393.749', 'Minerální voda Magnesia - jemně perlivá, 6x 1,5 l'],
        ['446.159', 'Minerální voda Mattoni - jemně perlivá, 6x 1,5 l'],
        ['185.790', 'Zelený čaj Pickwick - 20x 2 g'],
        ['185.791', 'Zelený čaj Pickwick - s citronem, 20x 2 g'],
        ['221.598', 'Černý čaj Pickwick - ranní Earl Grey, 20x 1,75 g'],
        ['572.511', 'Ovocný čaj Pickwick - sladká jahoda, 20x 2 g'],
        ['171.784', 'Ovocný čaj Pickwick - švestky s vanilkou a skořicí, 20x 2 g'],
        ['572.513', 'Ovocný čaj Pickwick - variace pomeranč, 20x 2g'],
        ['572.451', 'Ovocný čaj Pickwick - lesní ovoce, 20x 1,75 g'],
        ['156.420', 'Toaletní papír Perfex - 2vrstvý, bílý, 18 m, 10 rolí'],
        ['698.124', 'Skládané papírové ručníky - 1vrstvé, šedý recykl, 250 ks'],
        ['989.989', 'Pytle na odpadky viGO - zatahovací, 120 l, 35 mic, 10 ks'],
        ['917.000', 'Pytle na odpad - černé, 120 l, 55 mic, 25 ks'],
        ['401.678', 'Osvěžovač vzduchu Glade - Clean linen, sprej, 300 ml'],
        ['535.649', 'Osvěžovač vzduchu Glade - Vanilla blossom, 300 ml'],
        ['545.458', 'Osvěžovač vzduchu Glade - Fresh lemon, 300 ml'],
        ['561.285', 'Čisticí sprej proti prachu Pronto - 250 ml'],
        ['155.550', 'Prostředek na mytí nádobí Jar - citron, 450 ml'],
        ['241.572', 'Tekuté mýdlo Medilona - all energy, 5 l'],
        ['716.245', 'Čisticí WC gel Savo - citrón, 750 ml'],
        ['501.332', 'Pytle na odpadky - 30 l, 9 mic, 50 ks'],
        ['285.835', 'Rychloutěrky Spontex, 38x38 cm - 10 ks'],
        ['404.985', 'Houbičky na nádobí malé - 8x5cm, 10 ks'],
        ['786.730', 'Závěsné papírové rychlovazače HIT Office - A4, půlená přední strana, zelené, 50 ks'],
        ['784.040', 'Drátky do sešívačky Sax - 24/8, 1000 ks'],
        ['250.640', 'Spisové desky s tkanicí EMBA - přírodní, 25 ks'],
        ['148.141', 'Obchodní tašky B4 - s křížovým dnem, samolepicí s krycí páskou, 10 ks'],
        ['510.5414', 'Laminovací kapsy Q-Connect - A4, 2x 80 mic, 100 ks'],
        ['510.5412', 'Laminovací kapsy Q-Connect - A3, 2x 80 mic, 100 ks'],
        ['510.5417', 'Laminovací kapsy Q-Connect - A5, 2x 80 mic, 100 ks'],
        ['176.651', 'Papírové ručníky v roli Maxi - 2 vrstvé, celulóza, 6 rolí'],
    ];

    // Prepare insert statement
    $stmt = $db->prepare("
        INSERT INTO items (company_id, code, name, unit)
        VALUES (1, ?, ?, 'ks')
    ");

    // Insert all items
    foreach ($items as $item) {
        try {
            $stmt->execute([$item[0], $item[1]]);
        } catch (PDOException $e) {
            // Skip if item with this code already exists
            if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                throw $e;
            }
        }
    }
};
