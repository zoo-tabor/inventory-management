<?php
/**
 * Reports by Department
 * Analyze stock movements by department
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Reporty dle oddělení';
$db = Database::getInstance();

// Get filters
$departmentFilter = (int)($_GET['department'] ?? 0);
$dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-01')); // First day of current month
$dateTo = sanitize($_GET['date_to'] ?? date('Y-m-d')); // Today

// Get departments
$stmt = $db->prepare("SELECT id, name, code FROM departments WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$departments = $stmt->fetchAll();

// Build WHERE clause
$whereClauses = ['sm.company_id = ?', "sm.movement_type = 'vydej'", 'sm.department_id IS NOT NULL'];
$params = [getCurrentCompanyId()];

if ($departmentFilter > 0) {
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

// Get department statistics
$stmt = $db->prepare("
    SELECT
        d.id,
        d.name,
        d.code,
        COUNT(DISTINCT sm.id) as total_movements,
        COUNT(DISTINCT sm.item_id) as unique_items,
        SUM(sm.quantity) as total_quantity
    FROM departments d
    LEFT JOIN stock_movements sm ON d.id = sm.department_id AND $whereSQL
    WHERE d.company_id = ? AND d.is_active = 1
    " . ($departmentFilter > 0 ? "AND d.id = ?" : "") . "
    GROUP BY d.id
    ORDER BY total_movements DESC, d.name
");

$statsParams = [...$params, getCurrentCompanyId()];
if ($departmentFilter > 0) {
    $statsParams[] = $departmentFilter;
}
$stmt->execute($statsParams);
$departmentStats = $stmt->fetchAll();

// Get top items by department
$topItemsByDept = [];
if ($departmentFilter > 0) {
    $stmt = $db->prepare("
        SELECT
            i.id,
            i.name,
            i.code,
            i.unit,
            SUM(sm.quantity) as total_quantity,
            COUNT(sm.id) as movement_count
        FROM stock_movements sm
        INNER JOIN items i ON sm.item_id = i.id
        WHERE $whereSQL
        GROUP BY i.id
        ORDER BY total_quantity DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    $topItemsByDept = $stmt->fetchAll();
}

// Get movements detail for selected department
$movements = [];
if ($departmentFilter > 0) {
    $stmt = $db->prepare("
        SELECT
            sm.*,
            i.name as item_name,
            i.code as item_code,
            i.unit as item_unit,
            l.name as location_name,
            e.first_name as employee_first_name,
            e.last_name as employee_last_name,
            u.full_name as user_name
        FROM stock_movements sm
        INNER JOIN items i ON sm.item_id = i.id
        LEFT JOIN locations l ON sm.location_id = l.id
        LEFT JOIN employees e ON sm.employee_id = e.id
        LEFT JOIN users u ON sm.user_id = u.id
        WHERE $whereSQL
        ORDER BY sm.movement_date DESC, sm.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $movements = $stmt->fetchAll();
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>📈 <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('reports/by-employee') ?>" class="btn btn-secondary">👤 Dle zaměstnance</a>
        <a href="<?= url('reports/by-item') ?>" class="btn btn-secondary">📦 Dle položky</a>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="<?= url('reports/by-department') ?>" class="filter-form">
            <input type="hidden" name="route" value="reports/by-department">

            <div class="form-row">
                <div class="form-group">
                    <label>Oddělení</label>
                    <select name="department" class="form-control">
                        <option value="">Všechna oddělení</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= $departmentFilter === $dept['id'] ? 'selected' : '' ?>>
                                <?= e($dept['name']) ?> (<?= e($dept['code']) ?>)
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
                        <a href="<?= url('reports/by-department') ?>" class="btn btn-secondary">✕ Zrušit</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Department Statistics -->
<div class="card">
    <div class="card-header">
        <h2>Přehled oddělení</h2>
        <small class="text-muted">
            Období: <?= formatDate($dateFrom) ?> - <?= formatDate($dateTo) ?>
        </small>
    </div>
    <div class="card-body">
        <?php if (empty($departmentStats)): ?>
            <div class="empty-state">
                <div class="empty-icon">📈</div>
                <h3>Žádná data</h3>
                <p>V tomto období nebyly zaznamenány žádné výdeje pro oddělení.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Oddělení</th>
                            <th>Kód</th>
                            <th>Počet výdejů</th>
                            <th>Různých položek</th>
                            <th>Celkové množství</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departmentStats as $stat): ?>
                            <tr class="<?= $departmentFilter === $stat['id'] ? 'row-selected' : '' ?>">
                                <td><strong><?= e($stat['name']) ?></strong></td>
                                <td><?= e($stat['code']) ?></td>
                                <td><?= formatNumber($stat['total_movements'] ?? 0) ?></td>
                                <td><?= formatNumber($stat['unique_items'] ?? 0) ?></td>
                                <td><?= formatNumber($stat['total_quantity'] ?? 0) ?> ks</td>
                                <td>
                                    <?php if ($departmentFilter === $stat['id']): ?>
                                        <a href="<?= url('reports/by-department', ['date_from' => $dateFrom, 'date_to' => $dateTo]) ?>"
                                           class="btn btn-sm btn-secondary">
                                            Zobrazit vše
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= url('reports/by-department', ['department' => $stat['id'], 'date_from' => $dateFrom, 'date_to' => $dateTo]) ?>"
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

<!-- Top Items (if department selected) -->
<?php if ($departmentFilter > 0 && !empty($topItemsByDept)): ?>
    <div class="card">
        <div class="card-header">
            <h2>Top 10 nejčastěji vydávaných položek</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Kód</th>
                            <th>Název položky</th>
                            <th>Celkové množství</th>
                            <th>Počet výdejů</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topItemsByDept as $index => $item): ?>
                            <tr>
                                <td><strong><?= $index + 1 ?></strong></td>
                                <td><?= e($item['code']) ?></td>
                                <td><?= e($item['name']) ?></td>
                                <td><?= formatNumber($item['total_quantity']) ?> <?= e($item['unit']) ?></td>
                                <td><?= formatNumber($item['movement_count']) ?></td>
                                <td>
                                    <a href="<?= url('reports/by-item', ['id' => $item['id'], 'date_from' => $dateFrom, 'date_to' => $dateTo]) ?>"
                                       class="btn btn-sm btn-secondary">
                                        Detail položky
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Movement Details (if department selected) -->
<?php if ($departmentFilter > 0 && !empty($movements)): ?>
    <div class="card">
        <div class="card-header">
            <h2>Poslední výdeje (max. 100)</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Položka</th>
                            <th>Množství</th>
                            <th>Sklad</th>
                            <th>Zaměstnanec</th>
                            <th>Poznámka</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $movement): ?>
                            <tr>
                                <td>
                                    <?= formatDate($movement['movement_date']) ?>
                                    <br>
                                    <small class="text-muted"><?= formatDateTime($movement['created_at'], 'd.m.Y H:i') ?></small>
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
                                    <?php if ($movement['employee_first_name']): ?>
                                        <?= e($movement['employee_first_name']) ?> <?= e($movement['employee_last_name']) ?>
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
    grid-template-columns: 2fr 1fr 1fr auto;
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

.row-selected {
    background-color: #dbeafe;
}
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
