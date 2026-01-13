<?php
/**
 * Reports by Employee
 * Analyze stock movements by employee
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Reporty dle zaměstnance';
$db = Database::getInstance();

// Get filters
$employeeFilter = (int)($_GET['employee'] ?? 0);
$departmentFilter = (int)($_GET['department'] ?? 0);
$dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-01')); // First day of current month
$dateTo = sanitize($_GET['date_to'] ?? date('Y-m-d')); // Today

// Get employees
$stmt = $db->prepare("
    SELECT e.id, e.first_name, e.last_name, d.name as department_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.company_id = ?
    ORDER BY e.first_name, e.last_name
");
$stmt->execute([getCurrentCompanyId()]);
$employees = $stmt->fetchAll();

// Get departments
$stmt = $db->prepare("SELECT id, name FROM departments WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$departments = $stmt->fetchAll();

// Build WHERE clause for movements
$whereClauses = ['sm.company_id = ?', 'sm.employee_id IS NOT NULL'];
$params = [getCurrentCompanyId()];

if ($employeeFilter > 0) {
    $whereClauses[] = 'sm.employee_id = ?';
    $params[] = $employeeFilter;
}

if ($departmentFilter > 0) {
    $whereClauses[] = 'e.department_id = ?';
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

// Get employee statistics
$stmt = $db->prepare("
    SELECT
        e.id,
        e.first_name,
        e.last_name,
        d.name as department_name,
        COUNT(DISTINCT CASE WHEN sm.movement_type = 'prijem' THEN sm.id END) as total_receipts,
        COUNT(DISTINCT CASE WHEN sm.movement_type = 'vydej' THEN sm.id END) as total_issues,
        COUNT(DISTINCT sm.id) as total_movements,
        COUNT(DISTINCT sm.item_id) as unique_items,
        SUM(CASE WHEN sm.movement_type = 'prijem' THEN sm.quantity ELSE 0 END) as total_received,
        SUM(CASE WHEN sm.movement_type = 'vydej' THEN sm.quantity ELSE 0 END) as total_issued
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN stock_movements sm ON e.id = sm.employee_id AND $whereSQL
    WHERE e.company_id = ? AND e.is_active = 1
    " . ($employeeFilter > 0 ? "AND e.id = ?" : "") . "
    " . ($departmentFilter > 0 ? "AND e.department_id = ?" : "") . "
    GROUP BY e.id
    HAVING total_movements > 0
    ORDER BY total_movements DESC, e.first_name, e.last_name
");

$statsParams = [...$params, getCurrentCompanyId()];
if ($employeeFilter > 0) {
    $statsParams[] = $employeeFilter;
}
if ($departmentFilter > 0) {
    $statsParams[] = $departmentFilter;
}
$stmt->execute($statsParams);
$employeeStats = $stmt->fetchAll();

// Get top items by employee
$topItemsByEmp = [];
if ($employeeFilter > 0) {
    $stmt = $db->prepare("
        SELECT
            i.id,
            i.name,
            i.code,
            i.unit,
            sm.movement_type,
            SUM(sm.quantity) as total_quantity,
            COUNT(sm.id) as movement_count
        FROM stock_movements sm
        INNER JOIN items i ON sm.item_id = i.id
        INNER JOIN employees e ON sm.employee_id = e.id
        WHERE $whereSQL
        GROUP BY i.id, sm.movement_type
        ORDER BY total_quantity DESC
        LIMIT 20
    ");
    $stmt->execute($params);
    $topItemsByEmp = $stmt->fetchAll();
}

// Get movements detail for selected employee
$movements = [];
if ($employeeFilter > 0) {
    $stmt = $db->prepare("
        SELECT
            sm.*,
            i.name as item_name,
            i.code as item_code,
            i.unit as item_unit,
            l.name as location_name,
            d.name as department_name,
            u.full_name as user_name
        FROM stock_movements sm
        INNER JOIN items i ON sm.item_id = i.id
        INNER JOIN employees e ON sm.employee_id = e.id
        LEFT JOIN locations l ON sm.location_id = l.id
        LEFT JOIN departments d ON sm.department_id = d.id
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
    <h1>👤 <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('reports/by-department') ?>" class="btn btn-secondary">📈 Dle oddělení</a>
        <a href="<?= url('reports/by-item') ?>" class="btn btn-secondary">📦 Dle položky</a>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="<?= url('reports/by-employee') ?>" class="filter-form">
            <input type="hidden" name="route" value="reports/by-employee">

            <div class="form-row">
                <div class="form-group">
                    <label>Zaměstnanec</label>
                    <select name="employee" class="form-control">
                        <option value="">Všichni zaměstnanci</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $employeeFilter === $emp['id'] ? 'selected' : '' ?>>
                                <?= e($emp['first_name']) ?> <?= e($emp['last_name']) ?>
                                <?php if ($emp['department_name']): ?>
                                    (<?= e($emp['department_name']) ?>)
                                <?php endif; ?>
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
                        <a href="<?= url('reports/by-employee') ?>" class="btn btn-secondary">✕ Zrušit</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Employee Statistics -->
<div class="card">
    <div class="card-header">
        <h2>Přehled zaměstnanců</h2>
        <small class="text-muted">
            Období: <?= formatDate($dateFrom) ?> - <?= formatDate($dateTo) ?>
        </small>
    </div>
    <div class="card-body">
        <?php if (empty($employeeStats)): ?>
            <div class="empty-state">
                <div class="empty-icon">👤</div>
                <h3>Žádná data</h3>
                <p>V tomto období nebyly zaznamenány žádné pohyby pro zaměstnance.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Zaměstnanec</th>
                            <th>Oddělení</th>
                            <th>Příjmy</th>
                            <th>Výdeje</th>
                            <th>Celkem pohybů</th>
                            <th>Různých položek</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employeeStats as $stat): ?>
                            <tr class="<?= $employeeFilter === $stat['id'] ? 'row-selected' : '' ?>">
                                <td>
                                    <strong>
                                        <?= e($stat['first_name']) ?> <?= e($stat['last_name']) ?>
                                    </strong>
                                </td>
                                <td><?= e($stat['department_name'] ?? '-') ?></td>
                                <td>
                                    <?= formatNumber($stat['total_receipts']) ?>
                                    <?php if ($stat['total_received'] > 0): ?>
                                        <br>
                                        <small class="text-muted"><?= formatNumber($stat['total_received']) ?> ks</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= formatNumber($stat['total_issues']) ?>
                                    <?php if ($stat['total_issued'] > 0): ?>
                                        <br>
                                        <small class="text-muted"><?= formatNumber($stat['total_issued']) ?> ks</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= formatNumber($stat['total_movements']) ?></td>
                                <td><?= formatNumber($stat['unique_items']) ?></td>
                                <td>
                                    <?php if ($employeeFilter === $stat['id']): ?>
                                        <a href="<?= url('reports/by-employee', ['date_from' => $dateFrom, 'date_to' => $dateTo, 'department' => $departmentFilter]) ?>"
                                           class="btn btn-sm btn-secondary">
                                            Zobrazit vše
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= url('reports/by-employee', ['employee' => $stat['id'], 'date_from' => $dateFrom, 'date_to' => $dateTo, 'department' => $departmentFilter]) ?>"
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

<!-- Top Items (if employee selected) -->
<?php if ($employeeFilter > 0 && !empty($topItemsByEmp)): ?>
    <div class="card">
        <div class="card-header">
            <h2>Nejčastější položky</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kód</th>
                            <th>Název položky</th>
                            <th>Typ pohybu</th>
                            <th>Celkové množství</th>
                            <th>Počet pohybů</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topItemsByEmp as $item): ?>
                            <tr>
                                <td><?= e($item['code']) ?></td>
                                <td><?= e($item['name']) ?></td>
                                <td>
                                    <?php if ($item['movement_type'] === 'prijem'): ?>
                                        <span class="badge badge-success">Příjem</span>
                                    <?php else: ?>
                                        <span class="badge badge-primary">Výdej</span>
                                    <?php endif; ?>
                                </td>
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

<!-- Movement Details (if employee selected) -->
<?php if ($employeeFilter > 0 && !empty($movements)): ?>
    <div class="card">
        <div class="card-header">
            <h2>Poslední pohyby (max. 100)</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Typ</th>
                            <th>Položka</th>
                            <th>Množství</th>
                            <th>Sklad</th>
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
                                    <strong><?= e($movement['item_code']) ?></strong>
                                    <br>
                                    <?= e($movement['item_name']) ?>
                                </td>
                                <td>
                                    <strong><?= formatNumber($movement['quantity']) ?></strong> <?= e($movement['item_unit']) ?>
                                </td>
                                <td><?= e($movement['location_name'] ?? '-') ?></td>
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
