<?php
/**
 * Stock Overview
 * Shows current stock levels for all items across all locations
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Přehled skladu';
$db = Database::getInstance();

// Get filters
$search = sanitize($_GET['search'] ?? '');
$categoryFilter = (int)($_GET['category'] ?? 0);
$locationFilter = (int)($_GET['location'] ?? 0);
$statusFilter = sanitize($_GET['status'] ?? '');

// Get categories for filter
$stmt = $db->prepare("SELECT id, name FROM categories WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$categories = $stmt->fetchAll();

// Get locations for filter
$stmt = $db->prepare("SELECT id, name FROM locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$locations = $stmt->fetchAll();

// Build WHERE clause
$whereClauses = ['i.company_id = ?'];
$params = [getCurrentCompanyId()];

if (!empty($search)) {
    $whereClauses[] = '(i.name LIKE ? OR i.code LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($categoryFilter > 0) {
    $whereClauses[] = 'i.category_id = ?';
    $params[] = $categoryFilter;
}

if ($locationFilter > 0) {
    $whereClauses[] = 's.location_id = ?';
    $params[] = $locationFilter;
}

$whereSQL = implode(' AND ', $whereClauses);

// Get stock data grouped by item and location
$stmt = $db->prepare("
    SELECT
        i.id,
        i.name,
        i.code,
        i.unit,
        i.pieces_per_package,
        i.minimum_stock,
        i.optimal_stock,
        c.name as category_name,
        l.id as location_id,
        l.name as location_name,
        COALESCE(s.quantity, 0) as quantity
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.id
    LEFT JOIN stock s ON i.id = s.item_id
    LEFT JOIN locations l ON s.location_id = l.id
    WHERE $whereSQL
    ORDER BY i.name, l.name
");
$stmt->execute($params);
$stockData = $stmt->fetchAll();

// Group stock by item
$itemsStock = [];
foreach ($stockData as $row) {
    $itemId = $row['id'];

    if (!isset($itemsStock[$itemId])) {
        $itemsStock[$itemId] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'code' => $row['code'],
            'unit' => $row['unit'],
            'pieces_per_package' => $row['pieces_per_package'],
            'minimum_stock' => $row['minimum_stock'],
            'optimal_stock' => $row['optimal_stock'],
            'category_name' => $row['category_name'],
            'total_quantity' => 0,
            'locations' => []
        ];
    }

    if ($row['location_id']) {
        $itemsStock[$itemId]['locations'][] = [
            'id' => $row['location_id'],
            'name' => $row['location_name'],
            'quantity' => $row['quantity']
        ];
        $itemsStock[$itemId]['total_quantity'] += $row['quantity'];
    }
}

// Apply status filter
if (!empty($statusFilter)) {
    $itemsStock = array_filter($itemsStock, function($item) use ($statusFilter) {
        $status = getStockStatus($item['total_quantity'], $item['minimum_stock']);
        return $status === $statusFilter;
    });
}

// Calculate statistics
$totalItems = count($itemsStock);
$okCount = 0;
$lowCount = 0;
$criticalCount = 0;

foreach ($itemsStock as $item) {
    $status = getStockStatus($item['total_quantity'], $item['minimum_stock']);
    if ($status === STOCK_STATUS_OK) $okCount++;
    elseif ($status === STOCK_STATUS_LOW) $lowCount++;
    elseif ($status === STOCK_STATUS_CRITICAL) $criticalCount++;
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>📦 <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('movements/prijem') ?>" class="btn btn-success">➕ Nový příjem</a>
        <a href="<?= url('movements/vydej') ?>" class="btn btn-primary">➖ Nový výdej</a>
    </div>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">📦</div>
        <div class="stat-content">
            <div class="stat-label">Celkem položek</div>
            <div class="stat-value"><?= formatNumber($totalItems) ?></div>
        </div>
    </div>

    <div class="stat-card stat-ok">
        <div class="stat-icon">✅</div>
        <div class="stat-content">
            <div class="stat-label">Stav OK</div>
            <div class="stat-value"><?= formatNumber($okCount) ?></div>
        </div>
    </div>

    <div class="stat-card stat-low">
        <div class="stat-icon">⚠️</div>
        <div class="stat-content">
            <div class="stat-label">Nízký stav</div>
            <div class="stat-value"><?= formatNumber($lowCount) ?></div>
        </div>
    </div>

    <div class="stat-card stat-critical">
        <div class="stat-icon">🔴</div>
        <div class="stat-content">
            <div class="stat-label">Kritický stav</div>
            <div class="stat-value"><?= formatNumber($criticalCount) ?></div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="<?= url('stock') ?>" class="filter-form">
            <input type="hidden" name="route" value="stock">

            <div class="form-row">
                <div class="form-group">
                    <label>Hledat položku</label>
                    <input
                        type="text"
                        name="search"
                        placeholder="Název nebo kód..."
                        value="<?= e($search) ?>"
                        class="form-control"
                    >
                </div>

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
                    <label>Sklad</label>
                    <select name="location" class="form-control">
                        <option value="">Všechny sklady</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['id'] ?>" <?= $locationFilter === $loc['id'] ? 'selected' : '' ?>>
                                <?= e($loc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Stav skladu</label>
                    <select name="status" class="form-control">
                        <option value="">Všechny stavy</option>
                        <option value="ok" <?= $statusFilter === 'ok' ? 'selected' : '' ?>>OK</option>
                        <option value="low" <?= $statusFilter === 'low' ? 'selected' : '' ?>>Nízký stav</option>
                        <option value="critical" <?= $statusFilter === 'critical' ? 'selected' : '' ?>>Kritický stav</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">🔍 Filtrovat</button>
                        <a href="<?= url('stock') ?>" class="btn btn-secondary">✕ Zrušit</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Stock Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($itemsStock)): ?>
            <div class="empty-state">
                <div class="empty-icon">📦</div>
                <h3>Žádné položky</h3>
                <p>Nebyla nalezena žádná položka odpovídající filtrům.</p>
                <?php if (!empty($search) || $categoryFilter || $locationFilter || $statusFilter): ?>
                    <a href="<?= url('stock') ?>" class="btn btn-secondary">Zrušit filtry</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kód</th>
                            <th>Název položky</th>
                            <th>Kategorie</th>
                            <th>Celkový stav</th>
                            <th>Min. stav</th>
                            <th>Opt. stav</th>
                            <th>Sklady</th>
                            <th>Stav</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itemsStock as $item):
                            $stockStatus = getStockStatus($item['total_quantity'], $item['minimum_stock']);
                        ?>
                            <tr class="stock-row-<?= $stockStatus ?>">
                                <td><strong><?= e($item['code']) ?></strong></td>
                                <td><?= e($item['name']) ?></td>
                                <td><?= e($item['category_name'] ?? '-') ?></td>
                                <td>
                                    <strong><?= formatNumber($item['total_quantity']) ?></strong> <?= e($item['unit']) ?>
                                    <?php if ($item['pieces_per_package'] > 1): ?>
                                        <br>
                                        <small class="text-muted">
                                            (<?= formatNumber(piecesToPackages($item['total_quantity'], $item['pieces_per_package']), 2) ?> bal)
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatNumber($item['minimum_stock']) ?> <?= e($item['unit']) ?></td>
                                <td><?= $item['optimal_stock'] ? formatNumber($item['optimal_stock']) . ' ' . e($item['unit']) : '-' ?></td>
                                <td>
                                    <?php if (!empty($item['locations'])): ?>
                                        <div class="location-breakdown">
                                            <?php foreach ($item['locations'] as $loc): ?>
                                                <div class="location-item">
                                                    <span class="location-name"><?= e($loc['name']) ?>:</span>
                                                    <span class="location-qty"><?= formatNumber($loc['quantity']) ?> <?= e($item['unit']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">Bez skladu</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= getStockStatusBadge($stockStatus) ?></td>
                                <td>
                                    <div class="btn-group">
                                        <a href="<?= url('movements/prijem', ['item' => $item['id']]) ?>"
                                           class="btn btn-sm btn-success"
                                           title="Příjem">
                                            ➕
                                        </a>
                                        <a href="<?= url('movements/vydej', ['item' => $item['id']]) ?>"
                                           class="btn btn-sm btn-primary"
                                           title="Výdej">
                                            ➖
                                        </a>
                                        <a href="<?= url('reports/by-item', ['id' => $item['id']]) ?>"
                                           class="btn btn-sm btn-secondary"
                                           title="Historie">
                                            📊
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
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

.stat-card.stat-ok {
    border-left: 4px solid #16a34a;
}

.stat-card.stat-low {
    border-left: 4px solid #f59e0b;
}

.stat-card.stat-critical {
    border-left: 4px solid #dc2626;
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
    grid-template-columns: 2fr 1.5fr 1.5fr 1.5fr auto;
    gap: 1rem;
    align-items: end;
}

.stock-row-low {
    background-color: #fef3c7;
}

.stock-row-critical {
    background-color: #fee2e2;
}

.location-breakdown {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.location-item {
    display: flex;
    gap: 0.5rem;
    font-size: 0.875rem;
}

.location-name {
    color: #6b7280;
}

.location-qty {
    font-weight: 600;
    color: #111827;
}
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
