<?php
/**
 * Order Proposals
 * Automatically suggest items to order based on stock levels and consumption
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Návrhy objednávek';
$db = Database::getInstance();

// Get view mode (nizky_stav or spotreba)
$viewMode = sanitize($_GET['view'] ?? 'nizky_stav');

// Get filters
$categoryFilter = (int)($_GET['category'] ?? 0);
$excludeCategory = (int)($_GET['exclude_category'] ?? 0);
$statusFilter = sanitize($_GET['status'] ?? 'low'); // low, critical, all
$sortBy = sanitize($_GET['sort'] ?? 'priority'); // priority, name, quantity

// Consumption view specific filters
$consumptionPeriod = (int)($_GET['period'] ?? 12); // months to calculate average from
$defaultOrderMonths = (int)($_GET['order_months'] ?? 0); // 0 = use item's order_months

// Get categories
$stmt = $db->prepare("SELECT id, name FROM categories WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$categories = $stmt->fetchAll();

// Build WHERE clause
$whereClauses = ['i.company_id = ?', 'i.is_active = 1'];
$params = [getCurrentCompanyId()];

if ($categoryFilter > 0) {
    $whereClauses[] = 'i.category_id = ?';
    $params[] = $categoryFilter;
}

if ($excludeCategory > 0) {
    $whereClauses[] = '(i.category_id != ? OR i.category_id IS NULL)';
    $params[] = $excludeCategory;
}

$whereSQL = implode(' AND ', $whereClauses);

// ==========================================
// CONSUMPTION VIEW DATA
// ==========================================
$consumptionItems = [];
$consumptionStats = [
    'total_items' => 0,
    'items_to_order' => 0,
    'total_value' => 0
];

if ($viewMode === 'spotreba') {
    // Calculate the date range for consumption period
    $periodEndDate = date('Y-m-d');
    $periodStartDate = date('Y-m-d', strtotime("-{$consumptionPeriod} months"));

    // Get all items with their consumption data and current stock
    $stmt = $db->prepare("
        SELECT
            i.*,
            c.name as category_name,
            COALESCE(stock_sum.total_stock, 0) as current_stock,
            COALESCE(consumption.total_consumed, 0) as total_consumed,
            COALESCE(consumption.total_consumed, 0) / ? as avg_monthly_consumption
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        LEFT JOIN (
            SELECT item_id, SUM(quantity) as total_stock
            FROM stock
            GROUP BY item_id
        ) stock_sum ON i.id = stock_sum.item_id
        LEFT JOIN (
            SELECT
                sm.item_id,
                SUM(sm.quantity) as total_consumed
            FROM stock_movements sm
            WHERE sm.company_id = ?
              AND sm.movement_type = 'vydej'
              AND sm.movement_date >= ?
              AND sm.movement_date <= ?
            GROUP BY sm.item_id
        ) consumption ON i.id = consumption.item_id
        WHERE $whereSQL
        ORDER BY i.name
    ");

    $consumptionParams = array_merge(
        [$consumptionPeriod, getCurrentCompanyId(), $periodStartDate, $periodEndDate],
        $params
    );
    $stmt->execute($consumptionParams);
    $allConsumptionItems = $stmt->fetchAll();

    // Process items and calculate order suggestions
    foreach ($allConsumptionItems as $item) {
        // Determine order months - use filter value if set, otherwise item's value
        $orderMonths = $defaultOrderMonths > 0 ? $defaultOrderMonths : ($item['order_months'] ?? 3);

        // Calculate needed quantity based on consumption
        $avgMonthly = (float)$item['avg_monthly_consumption'];
        $currentStock = (float)$item['current_stock'];
        $neededForPeriod = $avgMonthly * $orderMonths;
        $suggestedOrder = max(0, $neededForPeriod - $currentStock);

        // Calculate months of stock remaining
        $monthsRemaining = $avgMonthly > 0 ? $currentStock / $avgMonthly : 999;

        // Convert to packages if applicable
        $suggestedOrderPackages = null;
        if ($item['pieces_per_package'] > 0 && $suggestedOrder > 0) {
            $suggestedOrderPackages = ceil($suggestedOrder / $item['pieces_per_package']);
        }

        // Calculate price
        $totalPrice = 0;
        if ($item['price'] && $suggestedOrder > 0) {
            $totalPrice = $suggestedOrder * $item['price'];
        }

        $item['order_months_used'] = $orderMonths;
        $item['avg_monthly'] = $avgMonthly;
        $item['months_remaining'] = $monthsRemaining;
        $item['suggested_order'] = $suggestedOrder;
        $item['suggested_order_packages'] = $suggestedOrderPackages;
        $item['total_price'] = $totalPrice;

        $consumptionItems[] = $item;

        // Update statistics
        $consumptionStats['total_items']++;
        if ($suggestedOrder > 0) {
            $consumptionStats['items_to_order']++;
            $consumptionStats['total_value'] += $totalPrice;
        }
    }

    // Sort consumption items
    if ($sortBy === 'priority') {
        usort($consumptionItems, function($a, $b) {
            // Priority: items running out sooner first
            if ($a['months_remaining'] != $b['months_remaining']) {
                return $a['months_remaining'] <=> $b['months_remaining'];
            }
            return strcmp($a['name'], $b['name']);
        });
    } elseif ($sortBy === 'name') {
        usort($consumptionItems, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
    } elseif ($sortBy === 'quantity') {
        usort($consumptionItems, function($a, $b) {
            return $b['suggested_order'] <=> $a['suggested_order'];
        });
    }
}

// ==========================================
// LOW STOCK VIEW DATA (existing functionality)
// ==========================================
$items = [];
$totalItems = 0;
$totalValue = 0;
$criticalCount = 0;
$lowCount = 0;

if ($viewMode === 'nizky_stav') {
    // Build ORDER BY clause based on sort parameter
    $orderBySQL = '';
    if ($sortBy === 'priority') {
        $orderBySQL = "
            CASE
                WHEN COALESCE(SUM(s.quantity), 0) <= 0 THEN 1
                WHEN COALESCE(SUM(s.quantity), 0) <= i.minimum_stock THEN 2
                ELSE 3
            END ASC,
            i.name ASC
        ";
    } elseif ($sortBy === 'name') {
        $orderBySQL = "i.name ASC";
    } elseif ($sortBy === 'quantity') {
        $orderBySQL = "(i.optimal_stock - COALESCE(SUM(s.quantity), 0)) DESC";
    } else {
        $orderBySQL = "i.name ASC";
    }

    // Get items with stock levels
    $stmt = $db->prepare("
        SELECT
            i.*,
            c.name as category_name,
            COALESCE(SUM(s.quantity), 0) as current_stock,
            i.optimal_stock - COALESCE(SUM(s.quantity), 0) as needed_quantity
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        LEFT JOIN stock s ON i.id = s.item_id
        WHERE $whereSQL
        GROUP BY i.id
        HAVING COALESCE(SUM(s.quantity), 0) <= i.minimum_stock
        ORDER BY $orderBySQL
    ");
    $stmt->execute($params);
    $allItems = $stmt->fetchAll();

    // Filter by status
    foreach ($allItems as $item) {
        $stockStatus = getStockStatus($item['current_stock'], $item['minimum_stock']);

        if ($statusFilter === 'all' ||
            ($statusFilter === 'critical' && $stockStatus === STOCK_STATUS_CRITICAL) ||
            ($statusFilter === 'low' && $stockStatus === STOCK_STATUS_LOW)) {
            $items[] = $item;
        }
    }

    // Calculate statistics
    $totalItems = count($items);

    foreach ($items as $item) {
        $stockStatus = getStockStatus($item['current_stock'], $item['minimum_stock']);
        if ($stockStatus === STOCK_STATUS_CRITICAL) $criticalCount++;
        if ($stockStatus === STOCK_STATUS_LOW) $lowCount++;

        if ($item['price'] && $item['needed_quantity'] > 0) {
            $totalValue += $item['price'] * $item['needed_quantity'];
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>🛒 <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <button type="button" class="btn btn-success" onclick="exportToCSV()">
            📥 Export do CSV
        </button>
        <button type="button" class="btn btn-primary" onclick="showBulkExport()">
            🖨️ Tisk
        </button>
    </div>
</div>

<!-- View Toggle Tabs -->
<div class="view-tabs">
    <a href="<?= url('orders', array_merge($_GET, ['view' => 'nizky_stav'])) ?>"
       class="view-tab <?= $viewMode === 'nizky_stav' ? 'active' : '' ?>">
        ⚠️ Nízký stav
    </a>
    <a href="<?= url('orders', array_merge($_GET, ['view' => 'spotreba'])) ?>"
       class="view-tab <?= $viewMode === 'spotreba' ? 'active' : '' ?>">
        📊 Dle spotřeby
    </a>
</div>

<?php if ($viewMode === 'spotreba'): ?>
    <!-- ==========================================
         CONSUMPTION VIEW
         ========================================== -->

    <!-- Statistics -->
    <div class="stats-grid stats-grid-3">
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-content">
                <div class="stat-label">Celkem položek</div>
                <div class="stat-value"><?= formatNumber($consumptionStats['total_items']) ?></div>
            </div>
        </div>

        <div class="stat-card stat-warning">
            <div class="stat-icon">🛒</div>
            <div class="stat-content">
                <div class="stat-label">K objednání</div>
                <div class="stat-value"><?= formatNumber($consumptionStats['items_to_order']) ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-content">
                <div class="stat-label">Odhadovaná hodnota</div>
                <div class="stat-value"><?= formatPrice($consumptionStats['total_value']) ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card">
        <div class="card-body">
            <form method="GET" action="<?= url('orders') ?>" class="filter-form">
                <input type="hidden" name="route" value="orders">
                <input type="hidden" name="view" value="spotreba">

                <div class="form-row form-row-consumption">
                    <div class="form-group">
                        <label>Kategorie</label>
                        <select name="category" class="form-control">
                            <option value="">Všechny kategorie</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryFilter === $cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Vynechat kategorii</label>
                        <select name="exclude_category" class="form-control">
                            <option value="">-- Žádná --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $excludeCategory === $cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Spotřeba za období</label>
                        <select name="period" class="form-control">
                            <option value="3" <?= $consumptionPeriod === 3 ? 'selected' : '' ?>>Posledních 3 měsíce</option>
                            <option value="6" <?= $consumptionPeriod === 6 ? 'selected' : '' ?>>Posledních 6 měsíců</option>
                            <option value="12" <?= $consumptionPeriod === 12 ? 'selected' : '' ?>>Posledních 12 měsíců</option>
                            <option value="24" <?= $consumptionPeriod === 24 ? 'selected' : '' ?>>Posledních 24 měsíců</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Objednávka na</label>
                        <select name="order_months" class="form-control">
                            <option value="0" <?= $defaultOrderMonths === 0 ? 'selected' : '' ?>>Dle položky</option>
                            <option value="1" <?= $defaultOrderMonths === 1 ? 'selected' : '' ?>>1 měsíc</option>
                            <option value="2" <?= $defaultOrderMonths === 2 ? 'selected' : '' ?>>2 měsíce</option>
                            <option value="3" <?= $defaultOrderMonths === 3 ? 'selected' : '' ?>>3 měsíce</option>
                            <option value="6" <?= $defaultOrderMonths === 6 ? 'selected' : '' ?>>6 měsíců</option>
                            <option value="12" <?= $defaultOrderMonths === 12 ? 'selected' : '' ?>>12 měsíců</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Řazení</label>
                        <select name="sort" class="form-control">
                            <option value="priority" <?= $sortBy === 'priority' ? 'selected' : '' ?>>Dle priority (zbývá nejméně)</option>
                            <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Dle názvu</option>
                            <option value="quantity" <?= $sortBy === 'quantity' ? 'selected' : '' ?>>Dle potřebného množství</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">🔍 Filtrovat</button>
                            <a href="<?= url('orders', ['view' => 'spotreba']) ?>" class="btn btn-secondary">✕ Zrušit</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Consumption Order Proposals Table -->
    <div class="card" id="ordersTable">
        <div class="card-header">
            <h2>Návrhy objednávek dle spotřeby</h2>
            <small class="text-muted">
                Průměrná spotřeba počítána z období: <?= formatDate($periodStartDate) ?> - <?= formatDate($periodEndDate) ?>
            </small>
        </div>
        <div class="card-body">
            <?php if (empty($consumptionItems)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📦</div>
                    <h3>Žádné položky</h3>
                    <p>Nebyly nalezeny žádné položky odpovídající filtru.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Stav</th>
                                <th>Kód</th>
                                <th>Název položky</th>
                                <th>Kategorie</th>
                                <th>Aktuální stav</th>
                                <th>ø Spotřeba/měs</th>
                                <th>Zbývá na</th>
                                <th>Obj. na měs.</th>
                                <th>Návrh obj.</th>
                                <th>Cena</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($consumptionItems as $item):
                                $monthsRemaining = $item['months_remaining'];
                                $orderMonthsUsed = $item['order_months_used'];

                                // Determine row status
                                $rowClass = '';
                                $statusBadge = '';
                                if ($monthsRemaining < 1) {
                                    $rowClass = 'stock-row-critical';
                                    $statusBadge = '<span class="priority-badge priority-high">🔴 Kritický</span>';
                                } elseif ($monthsRemaining < $orderMonthsUsed) {
                                    $rowClass = 'stock-row-low';
                                    $statusBadge = '<span class="priority-badge priority-medium">⚠️ Nízký</span>';
                                } elseif ($item['suggested_order'] > 0) {
                                    $rowClass = 'stock-row-ok';
                                    $statusBadge = '<span class="priority-badge priority-low">📦 Doplnit</span>';
                                } else {
                                    $statusBadge = '<span class="priority-badge priority-ok">✅ OK</span>';
                                }
                            ?>
                                <tr class="<?= $rowClass ?>" data-code="<?= e($item['code']) ?>" data-suggested="<?= $item['suggested_order_packages'] ?? round($item['suggested_order']) ?>">
                                    <td><?= $statusBadge ?></td>
                                    <td><strong><?= e($item['code']) ?></strong></td>
                                    <td>
                                        <?= e($item['name']) ?>
                                        <?php if ($item['description']): ?>
                                            <br>
                                            <small class="text-muted"><?= e(substr($item['description'], 0, 50)) ?><?= strlen($item['description']) > 50 ? '...' : '' ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($item['category_name'] ?? '-') ?></td>
                                    <td>
                                        <strong><?= formatNumber($item['current_stock']) ?></strong> <?= e($item['unit']) ?>
                                        <?php if ($item['pieces_per_package'] > 1): ?>
                                            <br>
                                            <small class="text-muted">
                                                (<?= formatNumber(piecesToPackages($item['current_stock'], $item['pieces_per_package']), 1) ?> bal)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['avg_monthly'] > 0): ?>
                                            <?= formatNumber($item['avg_monthly'], 1) ?> <?= e($item['unit']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['avg_monthly'] > 0): ?>
                                            <?php if ($monthsRemaining >= 99): ?>
                                                <span class="text-success">∞</span>
                                            <?php else: ?>
                                                <strong class="<?= $monthsRemaining < 1 ? 'text-danger' : ($monthsRemaining < $orderMonthsUsed ? 'text-warning' : 'text-success') ?>">
                                                    <?= formatNumber($monthsRemaining, 1) ?>
                                                </strong> měs.
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-info"><?= $orderMonthsUsed ?> měs.</span>
                                    </td>
                                    <td>
                                        <?php if ($item['suggested_order'] > 0): ?>
                                            <strong class="text-success"><?= formatNumber($item['suggested_order'], 0) ?></strong> <?= e($item['unit']) ?>
                                            <?php if ($item['suggested_order_packages']): ?>
                                                <br>
                                                <small class="text-primary">
                                                    (<?= $item['suggested_order_packages'] ?> bal)
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['total_price'] > 0): ?>
                                            <strong><?= formatPrice($item['total_price']) ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="9" class="text-right"><strong>Celková odhadovaná hodnota (položky k objednání):</strong></td>
                                <td><strong><?= formatPrice($consumptionStats['total_value']) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ==========================================
         LOW STOCK VIEW (existing functionality)
         ========================================== -->

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card stat-critical">
            <div class="stat-icon">🔴</div>
            <div class="stat-content">
                <div class="stat-label">Kritický stav</div>
                <div class="stat-value"><?= formatNumber($criticalCount) ?></div>
            </div>
        </div>

        <div class="stat-card stat-low">
            <div class="stat-icon">⚠️</div>
            <div class="stat-content">
                <div class="stat-label">Nízký stav</div>
                <div class="stat-value"><?= formatNumber($lowCount) ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-content">
                <div class="stat-label">Položek k objednání</div>
                <div class="stat-value"><?= formatNumber($totalItems) ?></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-content">
                <div class="stat-label">Odhadovaná hodnota</div>
                <div class="stat-value"><?= formatPrice($totalValue) ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card">
        <div class="card-body">
            <form method="GET" action="<?= url('orders') ?>" class="filter-form">
                <input type="hidden" name="route" value="orders">
                <input type="hidden" name="view" value="nizky_stav">

                <div class="form-row">
                    <div class="form-group">
                        <label>Kategorie</label>
                        <select name="category" class="form-control">
                            <option value="">Všechny kategorie</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryFilter === $cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Vynechat kategorii</label>
                        <select name="exclude_category" class="form-control">
                            <option value="">-- Žádná --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $excludeCategory === $cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Stav skladu</label>
                        <select name="status" class="form-control">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Vše</option>
                            <option value="critical" <?= $statusFilter === 'critical' ? 'selected' : '' ?>>Pouze kritický</option>
                            <option value="low" <?= $statusFilter === 'low' ? 'selected' : '' ?>>Pouze nízký</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Řazení</label>
                        <select name="sort" class="form-control">
                            <option value="priority" <?= $sortBy === 'priority' ? 'selected' : '' ?>>Dle priority</option>
                            <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Dle názvu</option>
                            <option value="quantity" <?= $sortBy === 'quantity' ? 'selected' : '' ?>>Dle potřebného množství</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">🔍 Filtrovat</button>
                            <a href="<?= url('orders') ?>" class="btn btn-secondary">✕ Zrušit</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Order Proposals Table -->
    <div class="card" id="ordersTable">
        <div class="card-header">
            <h2>Doporučené objednávky</h2>
            <small class="text-muted">
                Položky s aktuálním stavem na nebo pod minimální úrovní
            </small>
        </div>
        <div class="card-body">
            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <h3>Vše je na skladě!</h3>
                    <p>Žádné položky nevyžadují doplnění zásob.</p>
                    <?php if ($categoryFilter || $statusFilter !== 'low'): ?>
                        <a href="<?= url('orders') ?>" class="btn btn-secondary">Zobrazit vše</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Priorita</th>
                                <th>Kód</th>
                                <th>Název položky</th>
                                <th>Kategorie</th>
                                <th>Aktuální stav</th>
                                <th>Min. stav</th>
                                <th>Opt. stav</th>
                                <th>Doporučené množství</th>
                                <th>Cena/ks</th>
                                <th>Celková cena</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item):
                                $stockStatus = getStockStatus($item['current_stock'], $item['minimum_stock']);
                                $orderQuantity = max(0, $item['optimal_stock'] ?
                                    ($item['optimal_stock'] - $item['current_stock']) :
                                    ($item['minimum_stock'] * 2 - $item['current_stock']));
                                $totalPrice = $item['price'] ? $orderQuantity * $item['price'] : 0;

                                // Calculate packages for export
                                $orderPackages = ($item['pieces_per_package'] > 1) ?
                                    ceil(piecesToPackages($orderQuantity, $item['pieces_per_package'])) :
                                    round($orderQuantity);
                            ?>
                                <tr class="stock-row-<?= $stockStatus ?>" data-code="<?= e($item['code']) ?>" data-suggested="<?= $orderPackages ?>">
                                    <td>
                                        <?php if ($stockStatus === STOCK_STATUS_CRITICAL): ?>
                                            <span class="priority-badge priority-high">🔴 Urgentní</span>
                                        <?php else: ?>
                                            <span class="priority-badge priority-medium">⚠️ Nízký</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= e($item['code']) ?></strong></td>
                                    <td>
                                        <?= e($item['name']) ?>
                                        <?php if ($item['description']): ?>
                                            <br>
                                            <small class="text-muted"><?= e(substr($item['description'], 0, 50)) ?><?= strlen($item['description']) > 50 ? '...' : '' ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($item['category_name'] ?? '-') ?></td>
                                    <td>
                                        <strong class="text-danger"><?= formatNumber($item['current_stock']) ?></strong> <?= e($item['unit']) ?>
                                        <?php if ($item['pieces_per_package'] > 1): ?>
                                            <br>
                                            <small class="text-muted">
                                                (<?= formatNumber(piecesToPackages($item['current_stock'], $item['pieces_per_package']), 2) ?> bal)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatNumber($item['minimum_stock']) ?> <?= e($item['unit']) ?></td>
                                    <td>
                                        <?= $item['optimal_stock'] ? formatNumber($item['optimal_stock']) . ' ' . e($item['unit']) : '-' ?>
                                    </td>
                                    <td>
                                        <strong class="text-success"><?= formatNumber($orderQuantity) ?></strong> <?= e($item['unit']) ?>
                                        <?php if ($item['pieces_per_package'] > 1): ?>
                                            <br>
                                            <small class="text-muted">
                                                (<?= formatNumber(piecesToPackages($orderQuantity, $item['pieces_per_package']), 2) ?> bal)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $item['price'] ? formatPrice($item['price']) : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td>
                                        <?php if ($totalPrice > 0): ?>
                                            <strong><?= formatPrice($totalPrice) ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="9" class="text-right"><strong>Celková odhadovaná hodnota:</strong></td>
                                <td><strong><?= formatPrice($totalValue) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Bulk Export Modal -->
<div id="bulkExportModal" class="modal">
    <div class="modal-content modal-md">
        <div class="modal-header">
            <h2>Hromadné přidání položek</h2>
            <button type="button" class="modal-close" onclick="closeBulkExportModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="info-box">
                <strong>ℹ️</strong> Sem můžete snadno zkopírovat seznam zboží z excelové nebo jiné tabulky.
                Objednací číslo a množství oddělte mezerou, čárkou nebo středníkem.
                Další položku vložte na nový řádek. Příklad níže:
                <div style="margin-top: 0.5rem; font-family: monospace; background: #f5f5f5; padding: 0.5rem; border-radius: 4px;">
                    123.100 5<br>
                    546.152 3
                </div>
            </div>

            <div class="form-group" style="margin-top: 1rem;">
                <textarea
                    id="bulkExportTextarea"
                    class="form-control"
                    rows="10"
                    placeholder="Vložte seznam položek..."
                    style="font-family: monospace;"
                ></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeBulkExportModal()">Zavřít</button>
            <button type="button" class="btn btn-primary" onclick="copyBulkExport()">📋 Kopírovat</button>
        </div>
    </div>
</div>

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

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 2rem;
}

.stats-grid-3 {
    grid-template-columns: repeat(3, 1fr);
}

.stat-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    border: 1px solid #e5e7eb;
}

.stat-card.stat-critical {
    border-left: 4px solid #dc2626;
}

.stat-card.stat-low,
.stat-card.stat-warning {
    border-left: 4px solid #f59e0b;
}

.stat-icon {
    font-size: 2rem;
}

.stat-content {
    flex: 1;
}

.stat-label {
    font-size: 0.875rem;
    color: #6b7280;
    margin-bottom: 0.25rem;
}

.stat-value {
    font-size: 1.875rem;
    font-weight: 700;
    color: #111827;
}

.filter-form .form-row {
    display: grid;
    grid-template-columns: 1.5fr 1.5fr 1fr 1fr auto;
    gap: 1rem;
    align-items: end;
}

.filter-form .form-row-consumption {
    grid-template-columns: 1.5fr 1.5fr 1fr 1fr 1fr auto;
}

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

.priority-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 600;
}

.priority-high {
    background: #fee2e2;
    color: #991b1b;
}

.priority-medium {
    background: #fef3c7;
    color: #92400e;
}

.priority-low {
    background: #dbeafe;
    color: #1e40af;
}

.priority-ok {
    background: #dcfce7;
    color: #166534;
}

.badge-info {
    background: #e0e7ff;
    color: #3730a3;
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
}

.stock-row-low {
    background-color: #fef3c7;
}

.stock-row-critical {
    background-color: #fee2e2;
}

.stock-row-ok {
    background-color: #dbeafe;
}

.text-warning {
    color: #d97706;
}

.total-row {
    background: #f9fafb;
    font-size: 1.1rem;
}

.total-row td {
    padding: 1rem !important;
    border-top: 2px solid #e5e7eb;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow: auto;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.modal-md {
    max-width: 700px;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
    font-size: 1.5rem;
}

.modal-close {
    background: none;
    border: none;
    font-size: 2rem;
    cursor: pointer;
    color: #6b7280;
    padding: 0;
    width: 2rem;
    height: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close:hover {
    color: #111827;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.info-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    padding: 1rem;
    font-size: 0.875rem;
    color: #1e40af;
}

@media (max-width: 768px) {
    .stats-grid,
    .stats-grid-3 {
        grid-template-columns: repeat(2, 1fr);
    }

    .filter-form .form-row,
    .filter-form .form-row-consumption {
        grid-template-columns: 1fr;
    }
}

@media print {
    .page-header .page-actions,
    .filter-form,
    .stats-grid,
    .view-tabs {
        display: none;
    }
}
</style>

<script>
function exportToCSV() {
    const rows = [];
    const viewMode = '<?= $viewMode ?>';

    if (viewMode === 'spotreba') {
        // Consumption view headers
        rows.push([
            'Kód',
            'Název',
            'Kategorie',
            'Aktuální stav',
            'Průměrná spotřeba/měsíc',
            'Zbývá na měsíců',
            'Objednávka na měsíců',
            'Návrh objednávky',
            'Návrh (balení)',
            'Jednotka',
            'Cena'
        ].join(';'));
    } else {
        // Low stock view headers
        rows.push([
            'Kód',
            'Název',
            'Kategorie',
            'Aktuální stav',
            'Minimální stav',
            'Optimální stav',
            'Doporučené množství',
            'Jednotka',
            'Cena/ks',
            'Celková cena'
        ].join(';'));
    }

    // Data
    const table = document.querySelector('#ordersTable table');
    if (!table) return;

    const dataRows = table.querySelectorAll('tbody tr');

    dataRows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 1) {
            const rowData = [];
            cells.forEach((cell, index) => {
                if (index === 0) return; // Skip status column
                let text = cell.textContent.trim().split('\n')[0];
                // Clean up numbers and special characters
                text = text.replace(/\s+/g, ' ').trim();
                rowData.push(text);
            });
            rows.push(rowData.join(';'));
        }
    });

    // Create and download
    const csv = rows.join('\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'navrhy-objednavek-' + new Date().toISOString().split('T')[0] + '.csv';
    link.click();
}

function showBulkExport() {
    // Generate the export data from the current order proposals
    const table = document.querySelector('#ordersTable table');
    if (!table) {
        alert('Žádné položky k exportu.');
        return;
    }

    const dataRows = table.querySelectorAll('tbody tr');
    const exportLines = [];

    dataRows.forEach(row => {
        const code = row.dataset.code;
        const suggested = row.dataset.suggested;

        // Only include items with suggested order > 0
        if (code && suggested && parseFloat(suggested) > 0) {
            exportLines.push(`${code} ${suggested}`);
        }
    });

    document.getElementById('bulkExportTextarea').value = exportLines.join('\n');
    document.getElementById('bulkExportModal').classList.add('active');
}

function closeBulkExportModal() {
    document.getElementById('bulkExportModal').classList.remove('active');
}

function copyBulkExport() {
    const textarea = document.getElementById('bulkExportTextarea');
    textarea.select();
    document.execCommand('copy');

    // Visual feedback
    const btn = event.target;
    const originalText = btn.textContent;
    btn.textContent = '✓ Zkopírováno!';
    btn.classList.add('btn-success');
    btn.classList.remove('btn-primary');

    setTimeout(() => {
        btn.textContent = originalText;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-primary');
    }, 2000);
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeBulkExportModal();
    }
});

// Close modal on outside click
document.addEventListener('click', function(e) {
    const modal = document.getElementById('bulkExportModal');
    if (e.target === modal) {
        closeBulkExportModal();
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
