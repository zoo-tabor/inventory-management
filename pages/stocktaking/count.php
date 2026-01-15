<?php
/**
 * Stocktaking Count
 * Perform actual inventory counting
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Provádění inventury';
$db = Database::getInstance();

$stocktakingId = (int)($_GET['id'] ?? 0);

if (!$stocktakingId) {
    setFlash('error', 'ID inventury nebylo zadáno.');
    redirect('stocktaking');
}

// Get stocktaking details
$stmt = $db->prepare("
    SELECT s.*, l.name as location_name, u.full_name as user_name
    FROM stocktakings s
    LEFT JOIN locations l ON s.location_id = l.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.id = ? AND s.company_id = ?
");
$stmt->execute([$stocktakingId, getCurrentCompanyId()]);
$stocktaking = $stmt->fetch();

if (!$stocktaking) {
    setFlash('error', 'Inventura nenalezena.');
    redirect('stocktaking');
}

if ($stocktaking['status'] !== 'in_progress') {
    setFlash('error', 'Tuto inventuru nelze upravovat.');
    redirect('stocktaking');
}

// Handle item count submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný bezpečnostní token.');
        redirect('stocktaking/count', ['id' => $stocktakingId]);
    }

    $itemId = (int)$_POST['item_id'];
    $countedQuantity = (int)$_POST['counted_quantity'];
    $note = sanitize($_POST['note'] ?? '');

    try {
        $stmt = $db->prepare("
            UPDATE stocktaking_items
            SET counted_quantity = ?, note = ?, counted_at = NOW()
            WHERE stocktaking_id = ? AND item_id = ?
        ");
        $stmt->execute([$countedQuantity, $note, $stocktakingId, $itemId]);

        setFlash('success', 'Počet byl zaznamenán.');

    } catch (Exception $e) {
        error_log("Stocktaking count error: " . $e->getMessage());
        setFlash('error', 'Chyba při zaznamenávání: ' . $e->getMessage());
    }

    redirect('stocktaking/count', ['id' => $stocktakingId]);
}

// Get filter
$statusFilter = sanitize($_GET['status'] ?? '');
$search = sanitize($_GET['search'] ?? '');

// Get stocktaking items
$whereClauses = ['si.stocktaking_id = ?'];
$params = [$stocktakingId];

if ($statusFilter === 'counted') {
    $whereClauses[] = 'si.counted_quantity IS NOT NULL';
} elseif ($statusFilter === 'uncounted') {
    $whereClauses[] = 'si.counted_quantity IS NULL';
} elseif ($statusFilter === 'diff') {
    $whereClauses[] = 'si.counted_quantity IS NOT NULL AND si.counted_quantity != si.expected_quantity';
}

if (!empty($search)) {
    $whereClauses[] = '(i.name LIKE ? OR i.code LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSQL = implode(' AND ', $whereClauses);

$stmt = $db->prepare("
    SELECT
        si.*,
        i.name as item_name,
        i.code as item_code,
        i.unit as item_unit,
        i.pieces_per_package,
        c.name as category_name
    FROM stocktaking_items si
    INNER JOIN items i ON si.item_id = i.id
    LEFT JOIN categories c ON i.category_id = c.id
    WHERE $whereSQL
    ORDER BY i.name
");
$stmt->execute($params);
$items = $stmt->fetchAll();

// Calculate statistics
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total,
        COUNT(counted_quantity) as counted,
        SUM(CASE WHEN counted_quantity IS NOT NULL AND counted_quantity != expected_quantity THEN 1 ELSE 0 END) as with_diff
    FROM stocktaking_items
    WHERE stocktaking_id = ?
");
$stmt->execute([$stocktakingId]);
$stats = $stmt->fetch();

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>📝 <?= e($pageTitle) ?> #<?= $stocktakingId ?></h1>
    <div class="page-actions">
        <a href="<?= url('stocktaking') ?>" class="btn btn-secondary">📋 Seznam inventur</a>
    </div>
</div>

<!-- Stocktaking Info -->
<div class="card">
    <div class="card-body">
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Sklad:</span>
                <span class="info-value"><?= e($stocktaking['location_name'] ?? 'Všechny sklady') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Zahájil:</span>
                <span class="info-value"><?= e($stocktaking['user_name']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Datum:</span>
                <span class="info-value"><?= formatDateTime($stocktaking['created_at']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Průběh:</span>
                <span class="info-value">
                    <?= $stats['counted'] ?> / <?= $stats['total'] ?> položek
                    (<?= $stats['total'] > 0 ? round(($stats['counted'] / $stats['total']) * 100) : 0 ?>%)
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Progress Bar -->
<div class="card">
    <div class="card-body">
        <div class="progress-wrapper">
            <?php
            $progress = $stats['total'] > 0 ? ($stats['counted'] / $stats['total']) * 100 : 0;
            ?>
            <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: <?= $progress ?>%"></div>
            </div>
            <div class="progress-stats">
                <span><strong><?= $stats['counted'] ?></strong> spočteno</span>
                <span><strong><?= $stats['total'] - $stats['counted'] ?></strong> zbývá</span>
                <span><strong><?= $stats['with_diff'] ?></strong> s rozdílem</span>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="<?= url('stocktaking/count') ?>" class="filter-form">
            <input type="hidden" name="route" value="stocktaking/count">
            <input type="hidden" name="id" value="<?= $stocktakingId ?>">

            <div class="form-row">
                <div class="form-group">
                    <label>Hledat</label>
                    <input
                        type="text"
                        name="search"
                        placeholder="Název nebo kód položky..."
                        value="<?= e($search) ?>"
                        class="form-control"
                    >
                </div>

                <div class="form-group">
                    <label>Stav</label>
                    <select name="status" class="form-control">
                        <option value="">Všechny položky</option>
                        <option value="uncounted" <?= $statusFilter === 'uncounted' ? 'selected' : '' ?>>Nespočtené</option>
                        <option value="counted" <?= $statusFilter === 'counted' ? 'selected' : '' ?>>Spočtené</option>
                        <option value="diff" <?= $statusFilter === 'diff' ? 'selected' : '' ?>>S rozdílem</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">🔍 Filtrovat</button>
                        <a href="<?= url('stocktaking/count', ['id' => $stocktakingId]) ?>" class="btn btn-secondary">✕ Zrušit</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Items Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($items)): ?>
            <div class="empty-state">
                <div class="empty-icon">📦</div>
                <h3>Žádné položky</h3>
                <p>Nebyla nalezena žádná položka odpovídající filtrům.</p>
                <a href="<?= url('stocktaking/count', ['id' => $stocktakingId]) ?>" class="btn btn-secondary">Zrušit filtry</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kód</th>
                            <th>Název položky</th>
                            <th>Kategorie</th>
                            <th>Očekávaný stav</th>
                            <th>Napočítáno</th>
                            <th>Rozdíl</th>
                            <th>Poznámka</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $isCounted = $item['counted_quantity'] !== null;
                            $difference = $isCounted ? $item['counted_quantity'] - $item['expected_quantity'] : 0;
                            $rowClass = '';
                            if ($isCounted) {
                                $rowClass = $difference == 0 ? 'row-ok' : 'row-diff';
                            }
                            ?>
                            <tr class="<?= $rowClass ?>" id="row-<?= $item['item_id'] ?>">
                                <td><strong><?= e($item['item_code']) ?></strong></td>
                                <td><?= e($item['item_name']) ?></td>
                                <td><?= e($item['category_name'] ?? '-') ?></td>
                                <td>
                                    <strong><?= formatNumber($item['expected_quantity']) ?></strong> <?= e($item['item_unit']) ?>
                                    <?php if ($item['pieces_per_package'] > 1): ?>
                                        <br>
                                        <small class="text-muted">
                                            (<?= formatNumber(piecesToPackages($item['expected_quantity'], $item['pieces_per_package']), 2) ?> bal)
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isCounted): ?>
                                        <strong><?= formatNumber($item['counted_quantity']) ?></strong> <?= e($item['item_unit']) ?>
                                        <br>
                                        <small class="text-muted"><?= formatDateTime($item['counted_at']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Nespočteno</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isCounted): ?>
                                        <?php if ($difference > 0): ?>
                                            <span class="badge badge-success">+<?= formatNumber($difference) ?></span>
                                        <?php elseif ($difference < 0): ?>
                                            <span class="badge badge-danger"><?= formatNumber($difference) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">0</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item['note']): ?>
                                        <small><?= e($item['note']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-primary"
                                        onclick="openCountModal(<?= htmlspecialchars(json_encode([
                                            'item_id' => $item['item_id'],
                                            'item_name' => $item['item_name'],
                                            'item_code' => $item['item_code'],
                                            'item_unit' => $item['item_unit'],
                                            'expected_quantity' => $item['expected_quantity'],
                                            'counted_quantity' => $item['counted_quantity'],
                                            'note' => $item['note'],
                                            'pieces_per_package' => $item['pieces_per_package']
                                        ]), ENT_QUOTES) ?>)"
                                    >
                                        <?= $isCounted ? '✏️ Upravit' : '📝 Počítat' ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Count Modal -->
<div id="countModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Počítání položky</h2>
            <button type="button" class="modal-close" onclick="closeCountModal()">×</button>
        </div>
        <form method="POST" id="countForm">
            <?= csrfField() ?>
            <input type="hidden" name="item_id" id="modal_item_id">

            <div class="modal-body">
                <div class="item-info-box">
                    <div class="item-info-row">
                        <span class="label">Kód:</span>
                        <span id="modal_item_code"></span>
                    </div>
                    <div class="item-info-row">
                        <span class="label">Název:</span>
                        <span id="modal_item_name"></span>
                    </div>
                    <div class="item-info-row">
                        <span class="label">Očekávaný stav:</span>
                        <strong id="modal_expected"></strong>
                    </div>
                </div>

                <div class="form-group">
                    <label for="counted_quantity" class="required">Napočtené množství</label>
                    <input
                        type="number"
                        name="counted_quantity"
                        id="counted_quantity"
                        class="form-control form-control-lg"
                        min="0"
                        step="1"
                        required
                        autofocus
                        onchange="calculateDifference()"
                    >
                    <small class="form-text" id="differenceHelper"></small>
                </div>

                <div class="form-group">
                    <label for="note">Poznámka</label>
                    <textarea
                        name="note"
                        id="modal_note"
                        class="form-control"
                        rows="3"
                        placeholder="Poznámka k inventuře (volitelné)..."
                    ></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-lg">✓ Zaznamenat</button>
                <button type="button" class="btn btn-secondary" onclick="closeCountModal()">Zrušit</button>
            </div>
        </form>
    </div>
</div>

<style>
.info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.info-label {
    font-size: 0.875rem;
    color: #6b7280;
}

.info-value {
    font-size: 1rem;
    font-weight: 600;
    color: #111827;
}

.progress-wrapper {
    padding: 1rem 0;
}

.progress-bar-container {
    height: 30px;
    background: #e5e7eb;
    border-radius: 15px;
    overflow: hidden;
    margin-bottom: 1rem;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #2563eb);
    transition: width 0.3s ease;
}

.progress-stats {
    display: flex;
    justify-content: space-around;
    text-align: center;
}

.filter-form .form-row {
    display: grid;
    grid-template-columns: 2fr 1fr auto;
    gap: 1rem;
    align-items: end;
}

.row-ok {
    background-color: #dcfce7;
}

.row-diff {
    background-color: #fef3c7;
}

.badge-danger {
    background: #dc2626;
    color: white;
}

.item-info-box {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.item-info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #e5e7eb;
}

.item-info-row:last-child {
    border-bottom: none;
}

.item-info-row .label {
    color: #6b7280;
    font-weight: 500;
}

.form-control-lg {
    font-size: 1.5rem;
    padding: 0.75rem 1rem;
    text-align: center;
}
</style>

<script>
let currentItem = null;

function openCountModal(itemData) {
    currentItem = itemData;

    document.getElementById('modal_item_id').value = itemData.item_id;
    document.getElementById('modal_item_code').textContent = itemData.item_code;
    document.getElementById('modal_item_name').textContent = itemData.item_name;
    document.getElementById('modal_expected').textContent =
        formatNumber(itemData.expected_quantity) + ' ' + itemData.item_unit;

    const quantityInput = document.getElementById('counted_quantity');
    quantityInput.value = itemData.counted_quantity !== null ? itemData.counted_quantity : '';

    document.getElementById('modal_note').value = itemData.note || '';

    calculateDifference();

    document.getElementById('countModal').classList.add('active');
    setTimeout(() => quantityInput.focus(), 100);
}

function closeCountModal() {
    document.getElementById('countModal').classList.remove('active');
    currentItem = null;
}

function calculateDifference() {
    if (!currentItem) return;

    const countedQty = parseInt(document.getElementById('counted_quantity').value) || 0;
    const difference = countedQty - currentItem.expected_quantity;

    const helper = document.getElementById('differenceHelper');

    if (difference > 0) {
        helper.textContent = `Rozdíl: +${formatNumber(difference)} ${currentItem.item_unit} (přebytek)`;
        helper.style.color = '#16a34a';
    } else if (difference < 0) {
        helper.textContent = `Rozdíl: ${formatNumber(difference)} ${currentItem.item_unit} (manko)`;
        helper.style.color = '#dc2626';
    } else {
        helper.textContent = 'Rozdíl: 0 (shoda)';
        helper.style.color = '#6b7280';
    }
}

function formatNumber(num, decimals = 0) {
    return num.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCountModal();
    }
});

// Close modal on outside click
document.getElementById('countModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCountModal();
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
