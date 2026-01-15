<?php
/**
 * Stocktaking List
 * List all inventory count sessions
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Seznam inventur';
$db = Database::getInstance();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný bezpečnostní token.');
        redirect('stocktaking');
    }

    $stocktakingId = (int)$_POST['stocktaking_id'];
    $action = sanitize($_POST['action']);

    // Verify ownership
    $stmt = $db->prepare("SELECT * FROM stocktakings WHERE id = ? AND company_id = ?");
    $stmt->execute([$stocktakingId, getCurrentCompanyId()]);
    $stocktaking = $stmt->fetch();

    if (!$stocktaking) {
        setFlash('error', 'Inventura nenalezena.');
        redirect('stocktaking');
    }

    try {
        if ($action === 'complete') {
            // Complete stocktaking and apply adjustments
            $db->beginTransaction();

            // Get all stocktaking items with differences
            $stmt = $db->prepare("
                SELECT * FROM stocktaking_items
                WHERE stocktaking_id = ? AND counted_quantity IS NOT NULL
            ");
            $stmt->execute([$stocktakingId]);
            $items = $stmt->fetchAll();

            foreach ($items as $item) {
                $difference = $item['counted_quantity'] - $item['expected_quantity'];

                if ($difference != 0) {
                    // Use location from stocktaking_item (supports multi-location inventories)
                    $locationId = $item['location_id'];

                    if (!$locationId) {
                        // Skip items without location
                        continue;
                    }

                    // Create adjustment movement
                    $movementType = $difference > 0 ? 'prijem' : 'vydej';
                    $quantity = abs($difference);

                    $stmt = $db->prepare("
                        INSERT INTO stock_movements (
                            company_id, item_id, location_id, user_id,
                            movement_type, quantity, note, movement_date, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        getCurrentCompanyId(),
                        $item['item_id'],
                        $locationId,
                        $_SESSION['user_id'],
                        $movementType,
                        $quantity,
                        "Inventurní úprava - Inventura #{$stocktakingId}",
                        date('Y-m-d'),
                        $_SESSION['user_id']
                    ]);

                    // Update stock
                    if ($difference > 0) {
                        // Add to stock
                        $stmt = $db->prepare("
                            UPDATE stock
                            SET quantity = quantity + ?
                            WHERE item_id = ? AND location_id = ?
                        ");
                        $stmt->execute([$quantity, $item['item_id'], $locationId]);
                    } else {
                        // Subtract from stock
                        $stmt = $db->prepare("
                            UPDATE stock
                            SET quantity = quantity - ?
                            WHERE item_id = ? AND location_id = ?
                        ");
                        $stmt->execute([$quantity, $item['item_id'], $locationId]);
                    }
                }
            }

            // Update stocktaking status
            $stmt = $db->prepare("
                UPDATE stocktakings
                SET status = 'completed', completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$stocktakingId]);

            logAudit('stocktaking_complete', 'stocktaking', $stocktakingId, "Dokončena inventura #{$stocktakingId}");

            $db->commit();
            setFlash('success', 'Inventura byla dokončena a úpravy skladu byly provedeny.');

        } elseif ($action === 'cancel') {
            $stmt = $db->prepare("
                UPDATE stocktakings
                SET status = 'cancelled'
                WHERE id = ?
            ");
            $stmt->execute([$stocktakingId]);

            logAudit('stocktaking_cancel', 'stocktaking', $stocktakingId, "Zrušena inventura #{$stocktakingId}");

            setFlash('success', 'Inventura byla zrušena.');
        }

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Stocktaking action error: " . $e->getMessage());
        setFlash('error', 'Chyba při provádění akce: ' . $e->getMessage());
    }

    redirect('stocktaking');
}

// Get filters
$statusFilter = sanitize($_GET['status'] ?? '');
$locationFilter = (int)($_GET['location'] ?? 0);

// Build WHERE clause
$whereClauses = ['s.company_id = ?'];
$params = [getCurrentCompanyId()];

if ($statusFilter) {
    $whereClauses[] = 's.status = ?';
    $params[] = $statusFilter;
}

if ($locationFilter) {
    $whereClauses[] = 's.location_id = ?';
    $params[] = $locationFilter;
}

$whereSQL = implode(' AND ', $whereClauses);

// Get locations for filter
$stmt = $db->prepare("SELECT id, name FROM locations WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$locations = $stmt->fetchAll();

// Get stocktaking sessions
$stmt = $db->prepare("
    SELECT
        s.*,
        l.name as location_name,
        u.full_name as user_name,
        (SELECT COUNT(*) FROM stocktaking_items WHERE stocktaking_id = s.id) as total_items,
        (SELECT COUNT(*) FROM stocktaking_items WHERE stocktaking_id = s.id AND counted_quantity IS NOT NULL) as counted_items,
        (SELECT SUM(ABS(counted_quantity - expected_quantity))
         FROM stocktaking_items
         WHERE stocktaking_id = s.id AND counted_quantity IS NOT NULL) as total_difference
    FROM stocktakings s
    LEFT JOIN locations l ON s.location_id = l.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE $whereSQL
    ORDER BY s.created_at DESC
");
$stmt->execute($params);
$stocktakings = $stmt->fetchAll();

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>📋 <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('stocktaking/start') ?>" class="btn btn-primary">✨ Nová inventura</a>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="<?= url('stocktaking') ?>" class="filter-form">
            <input type="hidden" name="route" value="stocktaking">

            <div class="form-row">
                <div class="form-group">
                    <label>Stav</label>
                    <select name="status" class="form-control">
                        <option value="">Všechny stavy</option>
                        <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>Probíhá</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Dokončeno</option>
                        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Zrušeno</option>
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
                    <label>&nbsp;</label>
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">🔍 Filtrovat</button>
                        <a href="<?= url('stocktaking') ?>" class="btn btn-secondary">✕ Zrušit</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Stocktaking List -->
<div class="card">
    <div class="card-body">
        <?php if (empty($stocktakings)): ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <h3>Žádné inventury</h3>
                <p>Nebyla nalezena žádná inventura.</p>
                <a href="<?= url('stocktaking/start') ?>" class="btn btn-primary">✨ Zahájit inventuru</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Datum zahájení</th>
                            <th>Sklad</th>
                            <th>Stav</th>
                            <th>Položky</th>
                            <th>Průběh</th>
                            <th>Rozdíly</th>
                            <th>Zahájil</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stocktakings as $st): ?>
                            <tr>
                                <td><strong>#<?= $st['id'] ?></strong></td>
                                <td>
                                    <?= formatDateTime($st['created_at']) ?>
                                    <?php if ($st['completed_at']): ?>
                                        <br>
                                        <small class="text-muted">
                                            Dokončeno: <?= formatDateTime($st['completed_at']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($st['location_name'] ?? 'Všechny') ?></td>
                                <td>
                                    <?php
                                    $statusBadges = [
                                        'in_progress' => '<span class="badge badge-warning">Probíhá</span>',
                                        'completed' => '<span class="badge badge-success">Dokončeno</span>',
                                        'cancelled' => '<span class="badge badge-secondary">Zrušeno</span>'
                                    ];
                                    echo $statusBadges[$st['status']] ?? '';
                                    ?>
                                </td>
                                <td><?= formatNumber($st['total_items']) ?></td>
                                <td>
                                    <?php if ($st['total_items'] > 0): ?>
                                        <div class="progress">
                                            <?php
                                            $progress = ($st['counted_items'] / $st['total_items']) * 100;
                                            ?>
                                            <div class="progress-bar" style="width: <?= $progress ?>%">
                                                <?= round($progress) ?>%
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            <?= $st['counted_items'] ?> / <?= $st['total_items'] ?> spočteno
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($st['total_difference'] !== null && $st['total_difference'] > 0): ?>
                                        <span class="badge badge-warning">
                                            <?= formatNumber($st['total_difference']) ?> ks
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted"><?= e($st['user_name'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <?php if ($st['status'] === 'in_progress'): ?>
                                            <a href="<?= url('stocktaking/count', ['id' => $st['id']]) ?>"
                                               class="btn btn-sm btn-primary"
                                               title="Pokračovat v inventuře">
                                                📝
                                            </a>
                                            <?php if ($st['counted_items'] === $st['total_items'] && $st['total_items'] > 0): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Opravdu chcete dokončit inventuru? Budou provedeny úpravy skladu.')">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="stocktaking_id" value="<?= $st['id'] ?>">
                                                    <input type="hidden" name="action" value="complete">
                                                    <button type="submit" class="btn btn-sm btn-success" title="Dokončit inventuru">
                                                        ✓
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Opravdu chcete zrušit inventuru?')">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="stocktaking_id" value="<?= $st['id'] ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Zrušit inventuru">
                                                    ✕
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <a href="<?= url('stocktaking/report', ['id' => $st['id']]) ?>"
                                               class="btn btn-sm btn-secondary"
                                               title="Zobrazit report">
                                                📊
                                            </a>
                                        <?php endif; ?>
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
.filter-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 1rem;
    align-items: end;
}

.progress {
    width: 150px;
    height: 20px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: #3b82f6;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
    transition: width 0.3s ease;
}

.badge-warning {
    background: #f59e0b;
    color: white;
}

.badge-secondary {
    background: #6b7280;
    color: white;
}
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
