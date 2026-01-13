<?php
/**
 * Reports by Item
 * Analyze stock movements and history by item
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Reporty dle položky';
$db = Database::getInstance();

// Get filters
$itemId = (int)($_GET['id'] ?? 0);
$categoryFilter = (int)($_GET['category'] ?? 0);
$dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-01')); // First day of current month
$dateTo = sanitize($_GET['date_to'] ?? date('Y-m-d')); // Today

// Get categories
$stmt = $db->prepare("SELECT id, name FROM categories WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$categories = $stmt->fetchAll();

// Get items
$stmt = $db->prepare("
    SELECT i.id, i.name, i.code, c.name as category_name
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.id
    WHERE i.company_id = ? AND i.is_active = 1
    ORDER BY i.name
");
$stmt->execute([getCurrentCompanyId()]);
$items = $stmt->fetchAll();

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

// Get item statistics
$stmt = $db->prepare("
    SELECT
        i.id,
        i.name,
        i.code,
        i.unit,
        i.minimum_stock,
        c.name as category_name,
        COUNT(DISTINCT CASE WHEN sm.movement_type = 'prijem' THEN sm.id END) as total_receipts,
        COUNT(DISTINCT CASE WHEN sm.movement_type = 'vydej' THEN sm.id END) as total_issues,
        SUM(CASE WHEN sm.movement_type = 'prijem' THEN sm.quantity ELSE 0 END) as total_received,
        SUM(CASE WHEN sm.movement_type = 'vydej' THEN sm.quantity ELSE 0 END) as total_issued,
        (SELECT SUM(quantity) FROM stock WHERE item_id = i.id) as current_stock
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.id
    LEFT JOIN stock_movements sm ON i.id = sm.item_id AND $whereSQL
    WHERE i.company_id = ? AND i.is_active = 1
    " . ($itemId > 0 ? "AND i.id = ?" : "") . "
    " . ($categoryFilter > 0 ? "AND i.category_id = ?" : "") . "
    GROUP BY i.id
    HAVING total_receipts > 0 OR total_issues > 0
    ORDER BY (total_receipts + total_issues) DESC, i.name
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
$itemDetail = null;
$stockByLocation = [];
$movements = [];

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
                l.name as location_name,
                e.first_name as employee_first_name,
                e.last_name as employee_last_name,
                d.name as department_name,
                u.full_name as user_name
            FROM stock_movements sm
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

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>📦 <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('reports/by-department') ?>" class="btn btn-secondary">📈 Dle oddělení</a>
        <a href="<?= url('reports/by-employee') ?>" class="btn btn-secondary">👤 Dle zaměstnance</a>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="<?= url('reports/by-item') ?>" class="filter-form">
            <input type="hidden" name="route" value="reports/by-item">

            <div class="form-row">
                <div class="form-group">
                    <label>Položka</label>
                    <select name="id" class="form-control">
                        <option value="">Všechny položky</option>
                        <?php foreach ($items as $item): ?>
                            <option value="<?= $item['id'] ?>" <?= $itemId === $item['id'] ? 'selected' : '' ?>>
                                <?= e($item['code']) ?> - <?= e($item['name']) ?>
                                <?php if ($item['category_name']): ?>
                                    (<?= e($item['category_name']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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

                <div class="form-group">
                    <label>&nbsp;</label>
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">🔍 Filtrovat</button>
                        <a href="<?= url('reports/by-item') ?>" class="btn btn-secondary">✕ Zrušit</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

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
                                    <strong><?= formatNumber($movement['quantity']) ?></strong> <?= e($itemDetail['unit']) ?>
                                </td>
                                <td><?= e($movement['location_name'] ?? '-') ?></td>
                                <td>
                                    <?php if ($movement['employee_first_name']): ?>
                                        <?= e($movement['employee_first_name']) ?> <?= e($movement['employee_last_name']) ?>
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

<style>
.filter-form .form-row {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1fr 1fr auto;
    gap: 1rem;
    align-items: end;
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
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
