<?php
/**
 * Stock Movements History
 * View all stock movements with filtering, sorting, and editing
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Historie pohybů';
$db = Database::getInstance();

// Handle AJAX update request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_movement') {
    header('Content-Type: application/json');

    $movementId = (int)($_POST['movement_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);  // pieces (ks)
    $quantityPackages = (float)($_POST['quantity_packages'] ?? 0);  // packages (bal)
    $note = sanitize($_POST['note'] ?? '');
    $movementDate = sanitize($_POST['movement_date'] ?? '');
    $employeeId = (int)($_POST['employee_id'] ?? 0) ?: null;
    $departmentId = (int)($_POST['department_id'] ?? 0) ?: null;
    $locationId = (int)($_POST['location_id'] ?? 0) ?: null;

    if ($movementId <= 0 || ($quantity <= 0 && $quantityPackages <= 0)) {
        echo json_encode(['success' => false, 'error' => 'Neplatné hodnoty']);
        exit;
    }

    try {
        // Get the original movement and item info to calculate stock difference
        $stmt = $db->prepare("
            SELECT sm.*, i.pieces_per_package
            FROM stock_movements sm
            INNER JOIN items i ON sm.item_id = i.id
            WHERE sm.id = ? AND sm.company_id = ?
        ");
        $stmt->execute([$movementId, getCurrentCompanyId()]);
        $original = $stmt->fetch();

        if (!$original) {
            echo json_encode(['success' => false, 'error' => 'Pohyb nenalezen']);
            exit;
        }

        $piecesPerPackage = $original['pieces_per_package'] ?: 1;

        // Calculate total quantities in pieces for stock update
        $oldTotalPieces = ((float)$original['quantity_packages'] * $piecesPerPackage) + (int)$original['quantity'];
        $newTotalPieces = ($quantityPackages * $piecesPerPackage) + $quantity;
        $quantityDiff = $newTotalPieces - $oldTotalPieces;

        // Update the movement
        $stmt = $db->prepare("
            UPDATE stock_movements SET
                quantity = ?,
                quantity_packages = ?,
                note = ?,
                movement_date = ?,
                employee_id = ?,
                department_id = ?,
                location_id = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([
            $quantity,
            $quantityPackages,
            $note,
            $movementDate,
            $employeeId,
            $departmentId,
            $locationId,
            $movementId,
            getCurrentCompanyId()
        ]);

        // Update stock if total quantity changed
        if ($quantityDiff != 0) {
            // For prijem (receipt), add to stock; for vydej (issue), subtract from stock
            $stockChange = $original['movement_type'] === 'prijem' ? $quantityDiff : -$quantityDiff;

            $stmt = $db->prepare("
                UPDATE stock SET quantity = quantity + ?
                WHERE company_id = ? AND item_id = ? AND location_id = ?
            ");
            $stmt->execute([
                $stockChange,
                getCurrentCompanyId(),
                $original['item_id'],
                $original['location_id']
            ]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Get filters
$search = sanitize($_GET['search'] ?? '');
$typeFilter = sanitize($_GET['type'] ?? '');
$itemFilter = (int)($_GET['item'] ?? 0);
$locationFilter = (int)($_GET['location'] ?? 0);
$employeeFilter = (int)($_GET['employee'] ?? 0);
$departmentFilter = (int)($_GET['department'] ?? 0);
$dateFrom = sanitize($_GET['date_from'] ?? '');
$dateTo = sanitize($_GET['date_to'] ?? '');

// Sorting
$sortColumn = sanitize($_GET['sort'] ?? 'movement_date');
$sortDir = sanitize($_GET['dir'] ?? 'desc');
$allowedSortColumns = ['movement_date', 'movement_type', 'item_name', 'quantity', 'location_name', 'employee_name', 'department_name'];
if (!in_array($sortColumn, $allowedSortColumns)) {
    $sortColumn = 'movement_date';
}
$sortDir = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';

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

// Build ORDER BY clause
$orderByMap = [
    'movement_date' => 'sm.movement_date',
    'movement_type' => 'sm.movement_type',
    'item_name' => 'i.name',
    'quantity' => 'sm.quantity',
    'location_name' => 'l.name',
    'employee_name' => 'e.full_name',
    'department_name' => 'd.name'
];
$orderByColumn = $orderByMap[$sortColumn] ?? 'sm.movement_date';
$orderBySQL = "$orderByColumn $sortDir, sm.created_at DESC";

// Get movements
$stmt = $db->prepare("
    SELECT
        sm.*,
        i.name as item_name,
        i.code as item_code,
        i.unit as item_unit,
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
    ORDER BY $orderBySQL
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
                                <?= e($emp['full_name']) ?>
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
            <?php
            // Helper to build sort URL
            $buildSortUrl = function($column) use ($sortColumn, $sortDir) {
                $params = $_GET;
                $params['sort'] = $column;
                $params['dir'] = ($sortColumn === $column && $sortDir === 'ASC') ? 'desc' : 'asc';
                unset($params['p']); // Reset to first page on sort change
                return '?' . http_build_query($params);
            };
            $getSortIcon = function($column) use ($sortColumn, $sortDir) {
                if ($sortColumn !== $column) return '<span class="sort-icon">⇅</span>';
                return $sortDir === 'ASC' ? '<span class="sort-icon active">↑</span>' : '<span class="sort-icon active">↓</span>';
            };
            ?>
            <div class="table-responsive">
                <table class="table" id="movementsTable">
                    <thead>
                        <tr>
                            <th class="sortable"><a href="<?= $buildSortUrl('movement_date') ?>">Datum <?= $getSortIcon('movement_date') ?></a></th>
                            <th class="sortable"><a href="<?= $buildSortUrl('movement_type') ?>">Typ <?= $getSortIcon('movement_type') ?></a></th>
                            <th class="sortable"><a href="<?= $buildSortUrl('item_name') ?>">Položka <?= $getSortIcon('item_name') ?></a></th>
                            <th class="sortable"><a href="<?= $buildSortUrl('quantity') ?>">Množství <?= $getSortIcon('quantity') ?></a></th>
                            <th class="sortable"><a href="<?= $buildSortUrl('location_name') ?>">Sklad <?= $getSortIcon('location_name') ?></a></th>
                            <th class="sortable"><a href="<?= $buildSortUrl('employee_name') ?>">Zaměstnanec <?= $getSortIcon('employee_name') ?></a></th>
                            <th class="sortable"><a href="<?= $buildSortUrl('department_name') ?>">Oddělení <?= $getSortIcon('department_name') ?></a></th>
                            <th>Poznámka</th>
                            <th>Zaznamenal</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $movement): ?>
                            <?php
                            // Use quantity_packages from movement record, and pieces_per_package from item
                            $piecesPerPackage = $movement['pieces_per_package'] ?: 1;
                            $packages = (float)($movement['quantity_packages'] ?? 0);
                            $pieces = (int)($movement['quantity'] ?? 0);
                            // Calculate total quantity in pieces
                            $totalQuantity = ($packages * $piecesPerPackage) + $pieces;
                            ?>
                            <tr class="movement-<?= $movement['movement_type'] ?>"
                                data-id="<?= $movement['id'] ?>"
                                data-quantity="<?= $pieces ?>"
                                data-quantity-packages="<?= $packages ?>"
                                data-note="<?= e($movement['note'] ?? '') ?>"
                                data-date="<?= $movement['movement_date'] ?>"
                                data-employee-id="<?= $movement['employee_id'] ?? '' ?>"
                                data-department-id="<?= $movement['department_id'] ?? '' ?>"
                                data-location-id="<?= $movement['location_id'] ?? '' ?>"
                                data-item-name="<?= e($movement['item_name']) ?>"
                                data-item-code="<?= e($movement['item_code']) ?>"
                                data-item-unit="<?= e($movement['item_unit']) ?>"
                                data-pieces-per-package="<?= $piecesPerPackage ?>"
                                data-movement-type="<?= $movement['movement_type'] ?>">
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
                                    <strong><?= formatNumber($totalQuantity) ?></strong> <?= e($movement['item_unit']) ?>
                                    <?php if ($piecesPerPackage > 1 && ($packages > 0 || $pieces > 0)): ?>
                                        <br>
                                        <small class="text-muted">(<?= formatNumber($packages) ?> bal + <?= $pieces ?> ks)</small>
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
                                <td>
                                    <button type="button" class="btn btn-sm btn-secondary edit-movement-btn" title="Upravit">✏️</button>
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

<!-- Edit Movement Modal -->
<div id="editMovementModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Upravit pohyb</h3>
            <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form id="editMovementForm">
            <input type="hidden" name="action" value="update_movement">
            <input type="hidden" name="movement_id" id="editMovementId">
            <input type="hidden" name="quantity" id="editQuantityHidden">
            <input type="hidden" name="quantity_packages" id="editQuantityPackagesHidden">

            <div class="modal-body">
                <div class="movement-info">
                    <span id="editMovementType" class="badge"></span>
                    <strong id="editItemCode"></strong> - <span id="editItemName"></span>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Datum pohybu</label>
                        <input type="date" name="movement_date" id="editMovementDate" class="form-control" required>
                    </div>
                </div>

                <div class="form-row" id="packageInputRow">
                    <div class="form-group">
                        <label>Množství v balení (bal)</label>
                        <input type="number" id="editPackages" class="form-control" min="0" step="0.01" value="0">
                        <small class="text-muted">1 bal = <span id="piecesPerPackageInfo">0</span> ks</small>
                    </div>
                    <div class="form-group">
                        <label>Množství kusů (ks)</label>
                        <input type="number" id="editPieces" class="form-control" min="0" step="1" value="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Celkové množství (<span id="editItemUnit">ks</span>)</label>
                        <input type="number" id="editQuantityDisplay" class="form-control" min="0" step="1" readonly>
                        <small class="text-muted">Pouze pro informaci - upravte bal/ks výše</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Sklad</label>
                        <select name="location_id" id="editLocationId" class="form-control">
                            <option value="">-- Vyberte sklad --</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Zaměstnanec</label>
                        <select name="employee_id" id="editEmployeeId" class="form-control">
                            <option value="">-- Vyberte zaměstnance --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= e($emp['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Oddělení</label>
                        <select name="department_id" id="editDepartmentId" class="form-control">
                            <option value="">-- Vyberte oddělení --</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= e($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Poznámka</label>
                    <textarea name="note" id="editNote" class="form-control" rows="2"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Zrušit</button>
                <button type="submit" class="btn btn-primary">Uložit změny</button>
            </div>
        </form>
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

/* Sortable columns */
th.sortable a {
    color: inherit;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

th.sortable a:hover {
    color: #2563eb;
}

.sort-icon {
    opacity: 0.3;
    font-size: 0.75rem;
}

.sort-icon.active {
    opacity: 1;
    color: #2563eb;
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 8px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #6b7280;
}

.modal-close:hover {
    color: #111827;
}

.modal-body {
    padding: 1.5rem;
}

.modal-body .form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.modal-body .form-group {
    margin-bottom: 1rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    padding: 1rem 1.5rem;
    border-top: 1px solid #e5e7eb;
}

.movement-info {
    background: #f3f4f6;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1.5rem;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.edit-movement-btn {
    opacity: 0.7;
}

.edit-movement-btn:hover {
    opacity: 1;
}
</style>

<script>
let currentPiecesPerPackage = 1;

function openEditModal(row) {
    const modal = document.getElementById('editMovementModal');
    const data = row.dataset;

    document.getElementById('editMovementId').value = data.id;
    document.getElementById('editMovementDate').value = data.date;
    document.getElementById('editNote').value = data.note || '';
    document.getElementById('editLocationId').value = data.locationId || '';
    document.getElementById('editEmployeeId').value = data.employeeId || '';
    document.getElementById('editDepartmentId').value = data.departmentId || '';
    document.getElementById('editItemCode').textContent = data.itemCode;
    document.getElementById('editItemName').textContent = data.itemName;
    document.getElementById('editItemUnit').textContent = data.itemUnit;

    // Set movement type badge
    const typeEl = document.getElementById('editMovementType');
    if (data.movementType === 'prijem') {
        typeEl.textContent = '➕ Příjem';
        typeEl.className = 'badge badge-success';
    } else {
        typeEl.textContent = '➖ Výdej';
        typeEl.className = 'badge badge-primary';
    }

    // Get packages and pieces from data attributes (stored separately in DB)
    currentPiecesPerPackage = parseInt(data.piecesPerPackage) || 1;
    const packages = parseFloat(data.quantityPackages) || 0;
    const pieces = parseInt(data.quantity) || 0;

    document.getElementById('piecesPerPackageInfo').textContent = currentPiecesPerPackage;
    document.getElementById('editPackages').value = packages;
    document.getElementById('editPieces').value = pieces;

    // Update the hidden fields and display
    updateQuantityFields();

    modal.classList.add('show');
}

function closeEditModal() {
    document.getElementById('editMovementModal').classList.remove('show');
}

function updateQuantityFields() {
    const packages = parseFloat(document.getElementById('editPackages').value) || 0;
    const pieces = parseInt(document.getElementById('editPieces').value) || 0;
    const total = (packages * currentPiecesPerPackage) + pieces;

    // Update hidden fields that will be submitted
    document.getElementById('editQuantityHidden').value = pieces;
    document.getElementById('editQuantityPackagesHidden').value = packages;

    // Update display field
    document.getElementById('editQuantityDisplay').value = total;
}

// Event listeners for package/pieces inputs
document.getElementById('editPackages').addEventListener('input', updateQuantityFields);
document.getElementById('editPieces').addEventListener('input', updateQuantityFields);

// Edit button click handlers
document.querySelectorAll('.edit-movement-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const row = this.closest('tr');
        openEditModal(row);
    });
});

// Close modal when clicking outside
document.getElementById('editMovementModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});

// Form submission
document.getElementById('editMovementForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    // Ensure hidden fields are up to date
    updateQuantityFields();

    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Ukládám...';

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            // Reload the page to show updated data
            window.location.reload();
        } else {
            alert('Chyba: ' + (result.error || 'Nepodařilo se uložit změny'));
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Chyba při ukládání: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
