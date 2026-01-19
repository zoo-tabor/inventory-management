<?php
/**
 * Reports by Item
 * Analyze stock movements and history by item
 * Includes consumption analysis by department
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Reporty dle položky';
$db = Database::getInstance();

// Get view mode (prehled or spotreba)
$viewMode = sanitize($_GET['view'] ?? 'prehled');

// Get filters
$itemId = (int)($_GET['id'] ?? 0);
$categoryFilter = (int)($_GET['category'] ?? 0);
$dateFrom = sanitize($_GET['date_from'] ?? date('Y-01-01')); // First day of current year for consumption
$dateTo = sanitize($_GET['date_to'] ?? date('Y-m-d')); // Today

// Consumption-specific filters
$granularity = sanitize($_GET['granularity'] ?? 'monthly'); // monthly, quarterly, yearly
$orderMonths = (int)($_GET['order_months'] ?? 3); // Default 3 months for order suggestion

// Get categories
$stmt = $db->prepare("SELECT id, name FROM categories WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$categories = $stmt->fetchAll();

// Get items
$stmt = $db->prepare("
    SELECT i.id, i.name, i.code, i.unit, i.pieces_per_package, i.category_id, c.name as category_name
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.id
    WHERE i.company_id = ? AND i.is_active = 1
    ORDER BY i.name
");
$stmt->execute([getCurrentCompanyId()]);
$items = $stmt->fetchAll();

// Get departments for consumption view
$stmt = $db->prepare("SELECT id, name, code FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$departments = $stmt->fetchAll();

// Build WHERE clause for movements
$whereClauses = ['sm.company_id = ?'];
$params = [getCurrentCompanyId()];

if ($itemId > 0) {
    $whereClauses[] = 'sm.item_id = ?';
    $params[] = $itemId;
}

if ($categoryFilter > 0) {
    $whereClauses[] = 'i.category_id = ?';
    $params[] = $categoryFilter;
}

if ($dateFrom) {
    $whereClauses[] = 'sm.movement_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo) {
    $whereClauses[] = 'sm.movement_date <= ?';
    $params[] = $dateTo;
}

$whereSQL = implode(' AND ', $whereClauses);

// ==========================================
// CONSUMPTION VIEW DATA
// ==========================================
$consumptionData = [];
$timePeriods = [];
$consumptionStats = null;

if ($viewMode === 'spotreba' && $itemId > 0) {
    // Get the selected item details
    $stmt = $db->prepare("
        SELECT i.*, c.name as category_name,
               (SELECT COALESCE(SUM(quantity), 0) FROM stock WHERE item_id = i.id) as current_stock
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        WHERE i.id = ? AND i.company_id = ?
    ");
    $stmt->execute([$itemId, getCurrentCompanyId()]);
    $consumptionStats = $stmt->fetch();

    // Generate time periods based on granularity
    $startDate = new DateTime($dateFrom);
    $endDate = new DateTime($dateTo);

    switch ($granularity) {
        case 'yearly':
            $dateFormat = '%Y';
            $displayFormat = 'Y';
            $interval = new DateInterval('P1Y');
            $startDate->modify('first day of January');
            break;
        case 'quarterly':
            $dateFormat = "CONCAT(YEAR(sm.movement_date), '-Q', QUARTER(sm.movement_date))";
            $displayFormat = 'Q';
            $interval = new DateInterval('P3M');
            // Align to quarter start
            $month = (int)$startDate->format('n');
            $quarterStartMonth = (int)(ceil($month / 3) - 1) * 3 + 1;
            $startDate->setDate((int)$startDate->format('Y'), $quarterStartMonth, 1);
            break;
        default: // monthly
            $dateFormat = "DATE_FORMAT(sm.movement_date, '%Y-%m')";
            $displayFormat = 'M';
            $interval = new DateInterval('P1M');
            $startDate->modify('first day of this month');
            break;
    }

    // Generate period labels
    $currentDate = clone $startDate;
    while ($currentDate <= $endDate) {
        if ($granularity === 'yearly') {
            $periodKey = $currentDate->format('Y');
            $periodLabel = $currentDate->format('Y');
        } elseif ($granularity === 'quarterly') {
            $quarter = (int)ceil((int)$currentDate->format('n') / 3);
            $periodKey = $currentDate->format('Y') . '-Q' . $quarter;
            $periodLabel = $quarter . 'Q ' . $currentDate->format('Y');
        } else {
            $periodKey = $currentDate->format('Y-m');
            // Czech month names
            $czechMonths = [
                1 => 'led', 2 => 'úno', 3 => 'bře', 4 => 'dub', 5 => 'kvě', 6 => 'čvn',
                7 => 'čvc', 8 => 'srp', 9 => 'zář', 10 => 'říj', 11 => 'lis', 12 => 'pro'
            ];
            $month = (int)$currentDate->format('n');
            $periodLabel = $czechMonths[$month] . ' ' . $currentDate->format('Y');
        }
        $timePeriods[$periodKey] = $periodLabel;
        $currentDate->add($interval);
    }

    // Build the period SQL based on granularity
    if ($granularity === 'yearly') {
        $periodSQL = "YEAR(sm.movement_date)";
    } elseif ($granularity === 'quarterly') {
        $periodSQL = "CONCAT(YEAR(sm.movement_date), '-Q', QUARTER(sm.movement_date))";
    } else {
        $periodSQL = "DATE_FORMAT(sm.movement_date, '%Y-%m')";
    }

    // Get consumption data grouped by department and period
    // Calculate total quantity: (quantity_packages * pieces_per_package) + quantity
    $stmt = $db->prepare("
        SELECT
            d.id as department_id,
            d.name as department_name,
            $periodSQL as period,
            SUM(COALESCE(sm.quantity_packages, 0) * COALESCE(i.pieces_per_package, 1) + COALESCE(sm.quantity, 0)) as total_consumed
        FROM stock_movements sm
        INNER JOIN departments d ON sm.department_id = d.id
        INNER JOIN items i ON sm.item_id = i.id
        WHERE $whereSQL
          AND sm.movement_type = 'vydej'
          AND sm.department_id IS NOT NULL
        GROUP BY d.id, d.name, $periodSQL
        ORDER BY d.name, period
    ");
    $stmt->execute($params);
    $rawData = $stmt->fetchAll();

    // Organize data by department
    foreach ($rawData as $row) {
        $deptId = $row['department_id'];
        if (!isset($consumptionData[$deptId])) {
            $consumptionData[$deptId] = [
                'name' => $row['department_name'],
                'periods' => [],
                'total' => 0
            ];
        }
        $consumptionData[$deptId]['periods'][$row['period']] = (float)$row['total_consumed'];
        $consumptionData[$deptId]['total'] += (float)$row['total_consumed'];
    }

    // Calculate totals per period
    $periodTotals = [];
    foreach ($timePeriods as $periodKey => $label) {
        $periodTotals[$periodKey] = 0;
        foreach ($consumptionData as $dept) {
            $periodTotals[$periodKey] += $dept['periods'][$periodKey] ?? 0;
        }
    }

    // Calculate average monthly consumption
    if ($consumptionStats) {
        $totalConsumed = array_sum($periodTotals);
        $monthCount = max(1, count($timePeriods));

        // Adjust for granularity
        if ($granularity === 'yearly') {
            $monthCount = $monthCount * 12;
        } elseif ($granularity === 'quarterly') {
            $monthCount = $monthCount * 3;
        }

        $consumptionStats['avg_monthly'] = $totalConsumed / $monthCount;
        $consumptionStats['total_consumed'] = $totalConsumed;
        $consumptionStats['suggested_order'] = max(0, ($consumptionStats['avg_monthly'] * $orderMonths) - $consumptionStats['current_stock']);

        // Convert to packages if applicable
        if ($consumptionStats['pieces_per_package'] > 0) {
            $consumptionStats['suggested_order_packages'] = ceil($consumptionStats['suggested_order'] / $consumptionStats['pieces_per_package']);
        }
    }
}

// ==========================================
// OVERVIEW VIEW DATA (existing functionality)
// ==========================================
$itemStats = [];
$itemDetail = null;
$stockByLocation = [];
$movements = [];

if ($viewMode === 'prehled') {
    // Get item statistics
    // Calculate total quantity: (quantity_packages * pieces_per_package) + quantity
    $stmt = $db->prepare("
        SELECT
            i.id,
            i.name,
            i.code,
            i.unit,
            i.minimum_stock,
            i.pieces_per_package,
            c.name as category_name,
            COUNT(DISTINCT CASE WHEN sm.movement_type = 'prijem' THEN sm.id END) as total_receipts,
            COUNT(DISTINCT CASE WHEN sm.movement_type = 'vydej' THEN sm.id END) as total_issues,
            SUM(CASE WHEN sm.movement_type = 'prijem' THEN COALESCE(sm.quantity_packages, 0) * COALESCE(i.pieces_per_package, 1) + COALESCE(sm.quantity, 0) ELSE 0 END) as total_received,
            SUM(CASE WHEN sm.movement_type = 'vydej' THEN COALESCE(sm.quantity_packages, 0) * COALESCE(i.pieces_per_package, 1) + COALESCE(sm.quantity, 0) ELSE 0 END) as total_issued,
            (SELECT SUM(quantity) FROM stock WHERE item_id = i.id) as current_stock
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        LEFT JOIN stock_movements sm ON i.id = sm.item_id AND $whereSQL
        WHERE i.company_id = ? AND i.is_active = 1
        " . ($itemId > 0 ? "AND i.id = ?" : "") . "
        " . ($categoryFilter > 0 ? "AND i.category_id = ?" : "") . "
        GROUP BY i.id, i.name, i.code, i.unit, i.minimum_stock, i.pieces_per_package, c.name
        HAVING COUNT(DISTINCT CASE WHEN sm.movement_type = 'prijem' THEN sm.id END) > 0
            OR COUNT(DISTINCT CASE WHEN sm.movement_type = 'vydej' THEN sm.id END) > 0
        ORDER BY (COUNT(DISTINCT CASE WHEN sm.movement_type = 'prijem' THEN sm.id END) +
                  COUNT(DISTINCT CASE WHEN sm.movement_type = 'vydej' THEN sm.id END)) DESC, i.name
    ");

    $statsParams = [...$params, getCurrentCompanyId()];
    if ($itemId > 0) {
        $statsParams[] = $itemId;
    }
    if ($categoryFilter > 0) {
        $statsParams[] = $categoryFilter;
    }
    $stmt->execute($statsParams);
    $itemStats = $stmt->fetchAll();

    // Get detailed info for selected item
    if ($itemId > 0) {
        // Get item detail
        $stmt = $db->prepare("
            SELECT i.*, c.name as category_name
            FROM items i
            LEFT JOIN categories c ON i.category_id = c.id
            WHERE i.id = ? AND i.company_id = ?
        ");
        $stmt->execute([$itemId, getCurrentCompanyId()]);
        $itemDetail = $stmt->fetch();

        if ($itemDetail) {
            // Get stock by location
            $stmt = $db->prepare("
                SELECT l.name as location_name, s.quantity
                FROM stock s
                INNER JOIN locations l ON s.location_id = l.id
                WHERE s.item_id = ?
                ORDER BY l.name
            ");
            $stmt->execute([$itemId]);
            $stockByLocation = $stmt->fetchAll();

            // Get movements
            $stmt = $db->prepare("
                SELECT
                    sm.*,
                    i.pieces_per_package,
                    l.name as location_name,
                    e.full_name as employee_name,
                    d.name as department_name,
                    u.full_name as user_name
                FROM stock_movements sm
                INNER JOIN items i ON sm.item_id = i.id
                LEFT JOIN locations l ON sm.location_id = l.id
                LEFT JOIN employees e ON sm.employee_id = e.id
                LEFT JOIN departments d ON sm.department_id = d.id
                LEFT JOIN users u ON sm.user_id = u.id
                WHERE $whereSQL
                ORDER BY sm.movement_date DESC, sm.created_at DESC
                LIMIT 100
            ");
            $stmt->execute($params);
            $movements = $stmt->fetchAll();
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>📦 <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('reports/by-department') ?>" class="btn btn-secondary">📈 Dle oddělení</a>
        <a href="<?= url('reports/by-employee') ?>" class="btn btn-secondary">👤 Dle zaměstnance</a>
    </div>
</div>

<!-- View Toggle Tabs -->
<div class="view-tabs">
    <a href="<?= url('reports/by-item', array_merge($_GET, ['view' => 'prehled'])) ?>"
       class="view-tab <?= $viewMode === 'prehled' ? 'active' : '' ?>">
        📊 Přehled
    </a>
    <a href="<?= url('reports/by-item', array_merge($_GET, ['view' => 'spotreba'])) ?>"
       class="view-tab <?= $viewMode === 'spotreba' ? 'active' : '' ?>">
        📉 Spotřeba dle oddělení
    </a>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="<?= url('reports/by-item') ?>" class="filter-form">
            <input type="hidden" name="route" value="reports/by-item">
            <input type="hidden" name="view" value="<?= e($viewMode) ?>">

            <div class="form-row <?= $viewMode === 'spotreba' ? 'form-row-spotreba' : '' ?>">
                <div class="form-group">
                    <label>Kategorie</label>
                    <div class="searchable-select" id="categorySelectWrapper">
                        <input type="text" class="form-control searchable-input" id="categorySearch" placeholder="Hledat kategorii..." autocomplete="off">
                        <select name="category" class="form-control" id="categorySelect">
                            <option value="">Všechny kategorie</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryFilter === $cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="searchable-dropdown" id="categoryDropdown"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Položka</label>
                    <div class="searchable-select" id="itemSelectWrapper">
                        <input type="text" class="form-control searchable-input" id="itemSearch" placeholder="Hledat položku..." autocomplete="off">
                        <select name="id" class="form-control" id="itemSelect" <?= $viewMode === 'spotreba' ? 'required' : '' ?>>
                            <option value=""><?= $viewMode === 'spotreba' ? '-- Vyberte položku --' : 'Všechny položky' ?></option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= $item['id'] ?>"
                                        data-category="<?= $item['category_id'] ?? '' ?>"
                                        <?= $itemId === $item['id'] ? 'selected' : '' ?>>
                                    <?= e($item['code']) ?> - <?= e($item['name']) ?>
                                    <?php if ($item['category_name']): ?>
                                        (<?= e($item['category_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="searchable-dropdown" id="itemDropdown"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Datum od</label>
                    <input
                        type="date"
                        name="date_from"
                        value="<?= e($dateFrom) ?>"
                        class="form-control"
                    >
                </div>

                <div class="form-group">
                    <label>Datum do</label>
                    <input
                        type="date"
                        name="date_to"
                        value="<?= e($dateTo) ?>"
                        class="form-control"
                    >
                </div>

                <?php if ($viewMode === 'spotreba'): ?>
                    <div class="form-group">
                        <label>Zobrazení</label>
                        <select name="granularity" class="form-control">
                            <option value="monthly" <?= $granularity === 'monthly' ? 'selected' : '' ?>>Měsíčně</option>
                            <option value="quarterly" <?= $granularity === 'quarterly' ? 'selected' : '' ?>>Čtvrtletně</option>
                            <option value="yearly" <?= $granularity === 'yearly' ? 'selected' : '' ?>>Ročně</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Obj. na měsíce</label>
                        <input
                            type="number"
                            name="order_months"
                            value="<?= $orderMonths ?>"
                            min="1"
                            max="24"
                            class="form-control"
                            style="width: 80px;"
                        >
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">🔍 Filtrovat</button>
                        <a href="<?= url('reports/by-item', ['view' => $viewMode]) ?>" class="btn btn-secondary">✕ Zrušit</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($viewMode === 'spotreba'): ?>
    <!-- ==========================================
         CONSUMPTION VIEW
         ========================================== -->

    <?php if (!$itemId): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-icon">📉</div>
                    <h3>Vyberte položku</h3>
                    <p>Pro zobrazení spotřeby dle oddělení vyberte konkrétní položku z filtru výše.</p>
                </div>
            </div>
        </div>
    <?php elseif ($consumptionStats): ?>
        <!-- Item Summary Card -->
        <div class="card">
            <div class="card-header">
                <h2><?= e($consumptionStats['code']) ?> - <?= e($consumptionStats['name']) ?></h2>
                <small class="text-muted">
                    Období: <?= formatDate($dateFrom) ?> - <?= formatDate($dateTo) ?>
                </small>
            </div>
            <div class="card-body">
                <div class="consumption-summary">
                    <div class="summary-item">
                        <span class="summary-label">Aktuální stav</span>
                        <span class="summary-value"><?= formatNumber($consumptionStats['current_stock']) ?> <?= e($consumptionStats['unit']) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Celková spotřeba</span>
                        <span class="summary-value"><?= formatNumber($consumptionStats['total_consumed']) ?> <?= e($consumptionStats['unit']) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">ø / měsíc</span>
                        <span class="summary-value"><?= formatNumber($consumptionStats['avg_monthly'], 2) ?> <?= e($consumptionStats['unit']) ?></span>
                    </div>
                    <div class="summary-item highlight">
                        <span class="summary-label">Návrh obj. (<?= $orderMonths ?> měs.)</span>
                        <span class="summary-value">
                            <?= formatNumber($consumptionStats['suggested_order'], 0) ?> <?= e($consumptionStats['unit']) ?>
                            <?php if (isset($consumptionStats['suggested_order_packages'])): ?>
                                <br><small>(<?= $consumptionStats['suggested_order_packages'] ?> bal.)</small>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Consumption Matrix -->
        <div class="card">
            <div class="card-header">
                <h2>Spotřeba dle oddělení</h2>
            </div>
            <div class="card-body">
                <?php if (empty($consumptionData)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <h3>Žádná data</h3>
                        <p>V tomto období nebyly zaznamenány žádné výdeje pro tuto položku.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table consumption-table">
                            <thead>
                                <tr>
                                    <th class="sticky-col">Oddělení</th>
                                    <?php foreach ($timePeriods as $periodKey => $label): ?>
                                        <th class="period-col"><?= e($label) ?></th>
                                    <?php endforeach; ?>
                                    <th class="total-col">ø / měsíc</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($consumptionData as $deptId => $dept): ?>
                                    <tr>
                                        <td class="sticky-col"><strong><?= e($dept['name']) ?></strong></td>
                                        <?php
                                        $deptPeriodCount = 0;
                                        foreach ($timePeriods as $periodKey => $label):
                                            $value = $dept['periods'][$periodKey] ?? 0;
                                            if ($value > 0) $deptPeriodCount++;
                                        ?>
                                            <td class="period-col <?= $value > 0 ? 'has-value' : '' ?>">
                                                <?= $value > 0 ? formatNumber($value, 0) : '' ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="total-col">
                                            <?php
                                            $periodMultiplier = $granularity === 'yearly' ? 12 : ($granularity === 'quarterly' ? 3 : 1);
                                            $deptMonthCount = max(1, count($timePeriods)) * $periodMultiplier;
                                            $deptAvg = $dept['total'] / $deptMonthCount;
                                            ?>
                                            <?= formatNumber($deptAvg, 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="totals-row">
                                    <td class="sticky-col"><strong>Celkem</strong></td>
                                    <?php foreach ($timePeriods as $periodKey => $label): ?>
                                        <td class="period-col">
                                            <strong><?= $periodTotals[$periodKey] > 0 ? formatNumber($periodTotals[$periodKey], 0) : '' ?></strong>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="total-col">
                                        <strong><?= formatNumber($consumptionStats['avg_monthly'], 2) ?></strong>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ==========================================
         OVERVIEW VIEW (existing)
         ========================================== -->

    <!-- Item Detail (if selected) -->
    <?php if ($itemDetail): ?>
        <div class="card">
            <div class="card-header">
                <h2><?= e($itemDetail['code']) ?> - <?= e($itemDetail['name']) ?></h2>
            </div>
            <div class="card-body">
                <div class="item-detail-grid">
                    <div class="detail-section">
                        <h3>Základní informace</h3>
                        <div class="detail-row">
                            <span class="label">Kód:</span>
                            <span class="value"><?= e($itemDetail['code']) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Název:</span>
                            <span class="value"><?= e($itemDetail['name']) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Kategorie:</span>
                            <span class="value"><?= e($itemDetail['category_name'] ?? '-') ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Jednotka:</span>
                            <span class="value"><?= e($itemDetail['unit']) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Kusů v balení:</span>
                            <span class="value"><?= formatNumber($itemDetail['pieces_per_package']) ?></span>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h3>Aktuální stav</h3>
                        <?php
                        $currentStock = array_sum(array_column($stockByLocation, 'quantity'));
                        $stockStatus = getStockStatus($currentStock, $itemDetail['minimum_stock']);
                        ?>
                        <div class="stock-status-large">
                            <div class="stock-number"><?= formatNumber($currentStock) ?> <?= e($itemDetail['unit']) ?></div>
                            <div class="stock-badge"><?= getStockStatusBadge($stockStatus) ?></div>
                        </div>
                        <div class="detail-row">
                            <span class="label">Minimální stav:</span>
                            <span class="value"><?= formatNumber($itemDetail['minimum_stock']) ?> <?= e($itemDetail['unit']) ?></span>
                        </div>
                        <?php if ($itemDetail['optimal_stock']): ?>
                            <div class="detail-row">
                                <span class="label">Optimální stav:</span>
                                <span class="value"><?= formatNumber($itemDetail['optimal_stock']) ?> <?= e($itemDetail['unit']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="detail-section">
                        <h3>Sklady</h3>
                        <?php if (!empty($stockByLocation)): ?>
                            <?php foreach ($stockByLocation as $loc): ?>
                                <div class="detail-row">
                                    <span class="label"><?= e($loc['location_name']) ?>:</span>
                                    <span class="value"><strong><?= formatNumber($loc['quantity']) ?></strong> <?= e($itemDetail['unit']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted">Žádný stav na skladech</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Item Statistics -->
    <div class="card">
        <div class="card-header">
            <h2>Přehled položek</h2>
            <small class="text-muted">
                Období: <?= formatDate($dateFrom) ?> - <?= formatDate($dateTo) ?>
            </small>
        </div>
        <div class="card-body">
            <?php if (empty($itemStats)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📦</div>
                    <h3>Žádná data</h3>
                    <p>V tomto období nebyly zaznamenány žádné pohyby pro položky.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Kód</th>
                                <th>Název položky</th>
                                <th>Kategorie</th>
                                <th>Aktuální stav</th>
                                <th>Příjmy</th>
                                <th>Výdeje</th>
                                <th>Obrat</th>
                                <th>Akce</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itemStats as $stat): ?>
                                <tr class="<?= $itemId === $stat['id'] ? 'row-selected' : '' ?>">
                                    <td><strong><?= e($stat['code']) ?></strong></td>
                                    <td><?= e($stat['name']) ?></td>
                                    <td><?= e($stat['category_name'] ?? '-') ?></td>
                                    <td>
                                        <?php
                                        $stockStatus = getStockStatus($stat['current_stock'] ?? 0, $stat['minimum_stock']);
                                        ?>
                                        <strong><?= formatNumber($stat['current_stock'] ?? 0) ?></strong> <?= e($stat['unit']) ?>
                                        <br>
                                        <?= getStockStatusBadge($stockStatus) ?>
                                    </td>
                                    <td>
                                        <?= formatNumber($stat['total_receipts']) ?> pohybů
                                        <br>
                                        <small class="text-success">+<?= formatNumber($stat['total_received']) ?> <?= e($stat['unit']) ?></small>
                                    </td>
                                    <td>
                                        <?= formatNumber($stat['total_issues']) ?> pohybů
                                        <br>
                                        <small class="text-primary">-<?= formatNumber($stat['total_issued']) ?> <?= e($stat['unit']) ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $turnover = $stat['total_received'] + $stat['total_issued'];
                                        ?>
                                        <strong><?= formatNumber($turnover) ?></strong> <?= e($stat['unit']) ?>
                                    </td>
                                    <td>
                                        <?php if ($itemId === $stat['id']): ?>
                                            <a href="<?= url('reports/by-item', ['date_from' => $dateFrom, 'date_to' => $dateTo, 'category' => $categoryFilter]) ?>"
                                               class="btn btn-sm btn-secondary">
                                                Zobrazit vše
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= url('reports/by-item', ['id' => $stat['id'], 'date_from' => $dateFrom, 'date_to' => $dateTo]) ?>"
                                               class="btn btn-sm btn-primary">
                                                Detail
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Movement History (if item selected) -->
    <?php if ($itemId > 0 && !empty($movements)): ?>
        <div class="card">
            <div class="card-header">
                <h2>Historie pohybů (max. 100)</h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Typ</th>
                                <th>Množství</th>
                                <th>Sklad</th>
                                <th>Zaměstnanec</th>
                                <th>Oddělení</th>
                                <th>Poznámka</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movements as $movement): ?>
                                <?php
                                $piecesPerPkg = $movement['pieces_per_package'] ?: 1;
                                $pkgs = (float)($movement['quantity_packages'] ?? 0);
                                $pcs = (int)($movement['quantity'] ?? 0);
                                $totalQty = ($pkgs * $piecesPerPkg) + $pcs;
                                ?>
                                <tr class="movement-<?= $movement['movement_type'] ?>">
                                    <td>
                                        <?= formatDate($movement['movement_date']) ?>
                                        <br>
                                        <small class="text-muted"><?= formatDateTime($movement['created_at'], 'd.m.Y H:i') ?></small>
                                    </td>
                                    <td>
                                        <?php if ($movement['movement_type'] === 'prijem'): ?>
                                            <span class="badge badge-success">➕ Příjem</span>
                                        <?php else: ?>
                                            <span class="badge badge-primary">➖ Výdej</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= formatNumber($totalQty) ?></strong> <?= e($itemDetail['unit']) ?>
                                        <?php if ($piecesPerPkg > 1 && ($pkgs > 0 || $pcs > 0)): ?>
                                            <br>
                                            <small class="text-muted">(<?= formatNumber($pkgs) ?> bal + <?= $pcs ?> ks)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($movement['location_name'] ?? '-') ?></td>
                                    <td>
                                        <?php if ($movement['employee_name']): ?>
                                            <?= e($movement['employee_name']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($movement['department_name'] ?? '-') ?></td>
                                    <td>
                                        <?php if ($movement['note']): ?>
                                            <small><?= e($movement['note']) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<style>
/* View Tabs */
.view-tabs {
    display: flex;
    gap: 0;
    margin-bottom: 1rem;
    border-bottom: 2px solid #e5e7eb;
}

.view-tab {
    padding: 0.75rem 1.5rem;
    text-decoration: none;
    color: #6b7280;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
}

.view-tab:hover {
    color: #374151;
    background: #f9fafb;
}

.view-tab.active {
    color: #2563eb;
    border-bottom-color: #2563eb;
}

/* Filter form */
.filter-form .form-row {
    display: grid;
    grid-template-columns: 1.5fr 2fr 1fr 1fr auto;
    gap: 1rem;
    align-items: end;
}

.filter-form .form-row-spotreba {
    grid-template-columns: 1fr 2fr 1fr 1fr 1fr 80px auto;
}

/* Searchable Select */
.searchable-select {
    position: relative;
}

.searchable-select select {
    display: none;
}

.searchable-select .searchable-input {
    cursor: pointer;
    background: white;
}

.searchable-select .searchable-input:focus {
    cursor: text;
}

.searchable-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 300px;
    overflow-y: auto;
    background: white;
    border: 1px solid #d1d5db;
    border-top: none;
    border-radius: 0 0 6px 6px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    z-index: 1000;
}

.searchable-dropdown.active {
    display: block;
}

.searchable-dropdown-item {
    padding: 0.5rem 0.75rem;
    cursor: pointer;
    font-size: 0.875rem;
    border-bottom: 1px solid #f3f4f6;
}

.searchable-dropdown-item:last-child {
    border-bottom: none;
}

.searchable-dropdown-item:hover,
.searchable-dropdown-item.highlighted {
    background: #dbeafe;
}

.searchable-dropdown-item.selected {
    background: #2563eb;
    color: white;
}

.searchable-dropdown-empty {
    padding: 0.75rem;
    text-align: center;
    color: #6b7280;
    font-size: 0.875rem;
}

/* Consumption Summary */
.consumption-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
}

.summary-item {
    text-align: center;
    padding: 1rem;
    background: #f9fafb;
    border-radius: 8px;
}

.summary-item.highlight {
    background: #dbeafe;
    border: 2px solid #2563eb;
}

.summary-label {
    display: block;
    font-size: 0.875rem;
    color: #6b7280;
    margin-bottom: 0.5rem;
}

.summary-value {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    color: #111827;
}

.summary-item.highlight .summary-value {
    color: #2563eb;
}

/* Consumption Table */
.consumption-table {
    font-size: 0.875rem;
}

.consumption-table th.sticky-col,
.consumption-table td.sticky-col {
    position: sticky;
    left: 0;
    background: white;
    z-index: 1;
    min-width: 150px;
}

.consumption-table thead th.sticky-col {
    background: #f9fafb;
}

.consumption-table .period-col {
    text-align: center;
    min-width: 70px;
}

.consumption-table .period-col.has-value {
    background: #dcfce7;
}

.consumption-table .total-col {
    text-align: center;
    background: #f3f4f6;
    font-weight: 600;
    min-width: 90px;
}

.consumption-table tfoot .totals-row {
    background: #e5e7eb;
}

.consumption-table tfoot .totals-row td {
    border-top: 2px solid #9ca3af;
}

/* Existing styles */
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.card-header h2 {
    margin: 0;
    font-size: 1.25rem;
}

.item-detail-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2rem;
}

.detail-section h3 {
    font-size: 1rem;
    margin: 0 0 1rem 0;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    padding-bottom: 0.5rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #f3f4f6;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-row .label {
    color: #6b7280;
    font-weight: 500;
}

.detail-row .value {
    font-weight: 600;
    color: #111827;
}

.stock-status-large {
    text-align: center;
    padding: 1.5rem;
    background: #f9fafb;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.stock-number {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.row-selected {
    background-color: #dbeafe;
}

.movement-prijem {
    background-color: #dcfce7;
}

.movement-vydej {
    background-color: #dbeafe;
}

@media (max-width: 768px) {
    .filter-form .form-row,
    .filter-form .form-row-spotreba {
        grid-template-columns: 1fr;
    }

    .consumption-summary {
        grid-template-columns: repeat(2, 1fr);
    }

    .item-detail-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize searchable selects
    initSearchableSelect('categorySelect', 'categorySearch', 'categoryDropdown', function(selectedValue) {
        // When category changes, filter items and reset item selection
        filterItemsByCategory(selectedValue);
        document.getElementById('itemSelect').value = '';
        document.getElementById('itemSearch').value = '';
        updateItemDropdown();
    });

    initSearchableSelect('itemSelect', 'itemSearch', 'itemDropdown', null, function() {
        // Get current category filter when building item list
        return document.getElementById('categorySelect').value;
    });

    // Set initial values from selected options
    const categorySelect = document.getElementById('categorySelect');
    const itemSelect = document.getElementById('itemSelect');
    const categorySearch = document.getElementById('categorySearch');
    const itemSearch = document.getElementById('itemSearch');

    if (categorySelect.selectedIndex > 0) {
        categorySearch.value = categorySelect.options[categorySelect.selectedIndex].text;
    }
    if (itemSelect.selectedIndex > 0) {
        itemSearch.value = itemSelect.options[itemSelect.selectedIndex].text;
    }

    // Initial filter of items based on category
    filterItemsByCategory(categorySelect.value);
});

function initSearchableSelect(selectId, inputId, dropdownId, onChangeCallback, getCategoryFilter) {
    const select = document.getElementById(selectId);
    const input = document.getElementById(inputId);
    const dropdown = document.getElementById(dropdownId);

    let highlightedIndex = -1;
    let filteredOptions = [];

    function buildDropdown(searchText = '') {
        const categoryFilter = getCategoryFilter ? getCategoryFilter() : null;
        dropdown.innerHTML = '';
        filteredOptions = [];
        highlightedIndex = -1;

        const searchLower = searchText.toLowerCase();

        Array.from(select.options).forEach((option, index) => {
            // Skip hidden options (items filtered by category)
            if (option.style.display === 'none') return;

            const text = option.text;
            const value = option.value;

            // Filter by search text
            if (searchText && !text.toLowerCase().includes(searchLower)) return;

            filteredOptions.push({ value, text, index });

            const div = document.createElement('div');
            div.className = 'searchable-dropdown-item';
            if (option.selected) div.classList.add('selected');
            div.textContent = text;
            div.dataset.value = value;
            div.dataset.index = filteredOptions.length - 1;

            div.addEventListener('click', function(e) {
                e.stopPropagation();
                selectOption(value, text);
            });

            dropdown.appendChild(div);
        });

        if (filteredOptions.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'searchable-dropdown-empty';
            empty.textContent = 'Žádné výsledky';
            dropdown.appendChild(empty);
        }
    }

    function selectOption(value, text) {
        select.value = value;
        input.value = value ? text : '';
        dropdown.classList.remove('active');

        if (onChangeCallback) {
            onChangeCallback(value);
        }
    }

    function updateHighlight() {
        const items = dropdown.querySelectorAll('.searchable-dropdown-item');
        items.forEach((item, i) => {
            item.classList.toggle('highlighted', i === highlightedIndex);
        });

        // Scroll highlighted item into view
        if (highlightedIndex >= 0 && items[highlightedIndex]) {
            items[highlightedIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    input.addEventListener('focus', function() {
        buildDropdown(this.value);
        dropdown.classList.add('active');
    });

    input.addEventListener('input', function() {
        buildDropdown(this.value);
        dropdown.classList.add('active');
    });

    input.addEventListener('keydown', function(e) {
        if (!dropdown.classList.contains('active')) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightedIndex = Math.min(highlightedIndex + 1, filteredOptions.length - 1);
            updateHighlight();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightedIndex = Math.max(highlightedIndex - 1, 0);
            updateHighlight();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (highlightedIndex >= 0 && filteredOptions[highlightedIndex]) {
                const opt = filteredOptions[highlightedIndex];
                selectOption(opt.value, opt.text);
            }
        } else if (e.key === 'Escape') {
            dropdown.classList.remove('active');
            // Restore previous value
            if (select.selectedIndex >= 0) {
                input.value = select.options[select.selectedIndex].text;
            }
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.remove('active');
            // Restore previous value if input doesn't match
            if (select.selectedIndex >= 0) {
                const selectedText = select.options[select.selectedIndex].text;
                if (input.value !== selectedText && select.value) {
                    input.value = selectedText;
                }
            }
        }
    });
}

function filterItemsByCategory(categoryId) {
    const itemSelect = document.getElementById('itemSelect');

    Array.from(itemSelect.options).forEach((option, index) => {
        if (index === 0) {
            // Always show "Všechny položky" / "-- Vyberte položku --"
            option.style.display = '';
            return;
        }

        const itemCategory = option.dataset.category;

        if (!categoryId || categoryId === '') {
            // Show all items
            option.style.display = '';
        } else if (itemCategory === categoryId) {
            // Show items matching category
            option.style.display = '';
        } else {
            // Hide items not matching category
            option.style.display = 'none';
        }
    });
}

function updateItemDropdown() {
    // Trigger rebuild of item dropdown if it's open
    const itemSearch = document.getElementById('itemSearch');
    const itemDropdown = document.getElementById('itemDropdown');

    if (itemDropdown.classList.contains('active')) {
        itemSearch.dispatchEvent(new Event('input'));
    }
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
