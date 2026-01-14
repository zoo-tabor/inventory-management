<?php
/**
 * Stock Movements History
 * View all stock movements with filtering
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Historie pohybů';
$db = Database::getInstance();

// Get filters
$search = sanitize($_GET['search'] ?? '');
$typeFilter = sanitize($_GET['type'] ?? '');
$itemFilter = (int)($_GET['item'] ?? 0);
$locationFilter = (int)($_GET['location'] ?? 0);
$employeeFilter = (int)($_GET['employee'] ?? 0);
$departmentFilter = (int)($_GET['department'] ?? 0);
$dateFrom = sanitize($_GET['date_from'] ?? '');
$dateTo = sanitize($_GET['date_to'] ?? '');

// Pagination
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get filter options
$stmt = $db->prepare("SELECT id, name, code FROM items WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$items = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, name FROM locations WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$locations = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, full_name FROM employees WHERE company_id = ? ORDER BY full_name");
$stmt->execute([getCurrentCompanyId()]);
$employees = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, name FROM departments WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$departments = $stmt->fetchAll();

// Build WHERE clause
$whereClauses = ['sm.company_id = ?'];
$params = [getCurrentCompanyId()];

if (!empty($search)) {
    $whereClauses[] = '(i.name LIKE ? OR i.code LIKE ? OR sm.note LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($typeFilter) {
    $whereClauses[] = 'sm.movement_type = ?';
    $params[] = $typeFilter;
}

if ($itemFilter) {
    $whereClauses[] = 'sm.item_id = ?';
    $params[] = $itemFilter;
}

if ($locationFilter) {
    $whereClauses[] = 'sm.location_id = ?';
    $params[] = $locationFilter;
}

if ($employeeFilter) {
    $whereClauses[] = 'sm.employee_id = ?';
    $params[] = $employeeFilter;
}

if ($departmentFilter) {
    $whereClauses[] = 'sm.department_id = ?';
    $params[] = $departmentFilter;
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

// Get total count
$stmt = $db->prepare("
    SELECT COUNT(*) as count
    FROM stock_movements sm
    INNER JOIN items i ON sm.item_id = i.id
    WHERE $whereSQL
");
$stmt->execute($params);
$totalMovements = $stmt->fetch()['count'];
$totalPages = ceil($totalMovements / $perPage);

// Get movements
$stmt = $db->prepare("
    SELECT
        sm.*,
        i.name as item_name,
        i.code as item_code,
        i.unit as item_unit,
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
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Calculate statistics
$stmt = $db->prepare("
    SELECT
        movement_type,
        COUNT(*) as count,
        SUM(quantity) as total_quantity
    FROM stock_movements sm
    WHERE $whereSQL
    GROUP BY movement_type
");
$stmt->execute($params);
$stats = ['prijem' => ['count' => 0, 'total' => 0], 'vydej' => ['count' => 0, 'total' => 0]];
foreach ($stmt->fetchAll() as $row) {
    $stats[$row['movement_type']] = [
        'count' => $row['count'],
        'total' => $row['total_quantity']
    ];
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>🔄 <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('movements/prijem') ?>" class="btn btn-success">➕ Nový příjem</a>
        <a href="<?= url('movements/vydej') ?>" class="btn btn-primary">➖ Nový výdej</a>
        <a href="<?= url('stock') ?>" class="btn btn-secondary">📦 Přehled skladu</a>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card stat-success">
        <div class="stat-icon">➕</div>
        <div class="stat-content">
            <div class="stat-label">Příjmy</div>
            <div class="stat-value"><?= formatNumber($stats['prijem']['count']) ?></div>
        </div>
    </div>

    <div class="stat-card stat-primary">
        <div class="stat-icon">➖</div>
        <div class="stat-content">
            <div class="stat-label">Výdeje</div>
            <div class="stat-value"><?= formatNumber($stats['vydej']['count']) ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">🔢</div>
        <div class="stat-content">
            <div class="stat-label">Celkem pohybů</div>
            <div class="stat-value"><?= formatNumber($totalMovements) ?></div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="<?= url('movements') ?>" class="filter-form">
            <input type="hidden" name="route" value="movements">

            <div class="form-row">
                <div class="form-group">
                    <label>Hledat</label>
                    <input
                        type="text"
                        name="search"
                        placeholder="Položka, kód, poznámka..."
                        value="<?= e($search) ?>"
                        class="form-control"
                    >
                </div>

                <div class="form-group">
                    <label>Typ pohybu</label>
                    <select name="type" class="form-control">
                        <option value="">Všechny typy</option>
                        <option value="prijem" <?= $typeFilter === 'prijem' ? 'selected' : '' ?>>➕ Příjem</option>
                        <option value="vydej" <?= $typeFilter === 'vydej' ? 'selected' : '' ?>>➖ Výdej</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Položka</label>
                    <select name="item" class="form-control">
                        <option value="">Všechny položky</option>
                        <?php foreach ($items as $item): ?>
                            <option value="<?= $item['id'] ?>" <?= $itemFilter === $item['id'] ? 'selected' : '' ?>>
                                <?= e($item['code']) ?> - <?= e($item['name']) ?>
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
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Zaměstnanec</label>
                    <select name="employee" class="form-control">
                        <option value="">Všichni zaměstnanci</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $employeeFilter === $emp['id'] ? 'selected' : '' ?>>
                                <?= e($emp['full_name']) ?> <?= e($emp) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Oddělení</label>
                    <select name="department" class="form-control">
                        <option value="">Všechna oddělení</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= $departmentFilter === $dept['id'] ? 'selected' : '' ?>>
                                <?= e($dept['name']) ?>
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
                        <a href="<?= url('movements') ?>" class="btn btn-secondary">✕ Zrušit</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Movements Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($movements)): ?>
            <div class="empty-state">
                <div class="empty-icon">🔄</div>
                <h3>Žádné pohyby</h3>
                <p>Nebyly nalezeny žádné skladové pohyby.</p>
                <?php if (!empty($search) || $typeFilter || $itemFilter || $locationFilter || $employeeFilter || $departmentFilter || $dateFrom || $dateTo): ?>
                    <a href="<?= url('movements') ?>" class="btn btn-secondary">Zrušit filtry</a>
                <?php else: ?>
                    <div class="btn-group">
                        <a href="<?= url('movements/prijem') ?>" class="btn btn-success">➕ Nový příjem</a>
                        <a href="<?= url('movements/vydej') ?>" class="btn btn-primary">➖ Nový výdej</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Typ</th>
                            <th>Položka</th>
                            <th>Množství</th>
                            <th>Sklad</th>
                            <th>Zaměstnanec</th>
                            <th>Oddělení</th>
                            <th>Poznámka</th>
                            <th>Zaznamenal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $movement): ?>
                            <tr class="movement-<?= $movement['movement_type'] ?>">
                                <td>
                                    <strong><?= formatDate($movement['movement_date']) ?></strong>
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
                                    <strong><?= e($movement['item_code']) ?></strong>
                                    <br>
                                    <?= e($movement['item_name']) ?>
                                </td>
                                <td>
                                    <strong><?= formatNumber($movement['quantity']) ?></strong> <?= e($movement['item_unit']) ?>
                                </td>
                                <td><?= e($movement['location_name'] ?? '-') ?></td>
                                <td>
                                    <?php if ($movement['employee_name']): ?>
                                        <?= e($movement['employee_name']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($movement['department_name']): ?>
                                        <?= e($movement['department_name']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($movement['note']): ?>
                                        <small><?= e($movement['note']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted"><?= e($movement['user_name'] ?? '-') ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $queryParams = $_GET;
                    unset($queryParams['p']);
                    $queryString = http_build_query($queryParams);
                    ?>

                    <?php if ($page > 1): ?>
                        <a href="<?= url('movements') ?>&<?= $queryString ?>&p=<?= $page - 1 ?>" class="btn btn-secondary">← Předchozí</a>
                    <?php endif; ?>

                    <span class="pagination-info">
                        Strana <?= $page ?> z <?= $totalPages ?>
                        (celkem <?= formatNumber($totalMovements) ?> pohybů)
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= url('movements') ?>&<?= $queryString ?>&p=<?= $page + 1 ?>" class="btn btn-secondary">Další →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
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

.stat-card.stat-success {
    border-left: 4px solid #16a34a;
}

.stat-card.stat-primary {
    border-left: 4px solid #2563eb;
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
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    align-items: end;
    margin-bottom: 1rem;
}

.filter-form .form-row:last-child {
    margin-bottom: 0;
}

.movement-prijem {
    background-color: #dcfce7;
}

.movement-vydej {
    background-color: #dbeafe;
}

.badge-success {
    background: #16a34a;
    color: white;
}

.badge-primary {
    background: #2563eb;
    color: white;
}
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
