<?php
/**
 * Order Proposals
 * Automatically suggest items to order based on stock levels
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Návrhy objednávek';
$db = Database::getInstance();

// Get filters
$categoryFilter = (int)($_GET['category'] ?? 0);
$statusFilter = sanitize($_GET['status'] ?? 'low'); // low, critical, all
$sortBy = sanitize($_GET['sort'] ?? 'priority'); // priority, name, quantity

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

$whereSQL = implode(' AND ', $whereClauses);

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
    HAVING current_stock <= i.minimum_stock
    ORDER BY
        CASE WHEN '$sortBy' = 'priority' THEN
            CASE
                WHEN current_stock <= 0 THEN 1
                WHEN current_stock <= i.minimum_stock THEN 2
                ELSE 3
            END
        END,
        CASE WHEN '$sortBy' = 'name' THEN i.name END,
        CASE WHEN '$sortBy' = 'quantity' THEN needed_quantity END DESC
");
$stmt->execute($params);
$allItems = $stmt->fetchAll();

// Filter by status
$items = [];
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
$totalValue = 0;
$criticalCount = 0;
$lowCount = 0;

foreach ($items as $item) {
    $stockStatus = getStockStatus($item['current_stock'], $item['minimum_stock']);
    if ($stockStatus === STOCK_STATUS_CRITICAL) $criticalCount++;
    if ($stockStatus === STOCK_STATUS_LOW) $lowCount++;

    if ($item['price'] && $item['needed_quantity'] > 0) {
        $totalValue += $item['price'] * $item['needed_quantity'];
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
        <button type="button" class="btn btn-primary" onclick="printOrders()">
            🖨️ Tisk
        </button>
    </div>
</div>

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
                        ?>
                            <tr class="stock-row-<?= $stockStatus ?>">
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

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
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

.stat-card.stat-critical {
    border-left: 4px solid #dc2626;
}

.stat-card.stat-low {
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
    grid-template-columns: 1.5fr 1fr 1fr auto;
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

.stock-row-low {
    background-color: #fef3c7;
}

.stock-row-critical {
    background-color: #fee2e2;
}

.total-row {
    background: #f9fafb;
    font-size: 1.1rem;
}

.total-row td {
    padding: 1rem !important;
    border-top: 2px solid #e5e7eb;
}

@media print {
    .page-header .page-actions,
    .filter-form,
    .stats-grid {
        display: none;
    }
}
</style>

<script>
function exportToCSV() {
    const rows = [];

    // Header
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

    // Data
    const table = document.querySelector('#ordersTable table');
    const dataRows = table.querySelectorAll('tbody tr');

    dataRows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 1) {
            const code = cells[1].textContent.trim();
            const name = cells[2].textContent.trim().split('\n')[0];
            const category = cells[3].textContent.trim();
            const current = cells[4].textContent.trim().split('\n')[0];
            const minimum = cells[5].textContent.trim();
            const optimal = cells[6].textContent.trim();
            const recommended = cells[7].textContent.trim().split('\n')[0];
            const price = cells[8].textContent.trim();
            const total = cells[9].textContent.trim();

            rows.push([code, name, category, current, minimum, optimal, recommended, '', price, total].join(';'));
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

function printOrders() {
    window.print();
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
