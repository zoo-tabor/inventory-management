<?php
/**
 * Items Management
 * List, create, edit, delete inventory items
 */

$pageTitle = 'Položky';
$currentCompany = getCurrentCompany();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný CSRF token.');
        redirect('items');
    }

    $action = $_POST['action'] ?? '';

    try {
        $db = Database::getInstance();

        if ($action === 'create') {
            // Create new item
            $name = sanitize($_POST['name'] ?? '');
            $code = sanitize($_POST['code'] ?? '');
            $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
            $description = sanitize($_POST['description'] ?? '');
            $unit = sanitize($_POST['unit'] ?? 'ks');
            $piecesPerPackage = !empty($_POST['pieces_per_package']) ? (int)$_POST['pieces_per_package'] : 1;
            $minimumStock = !empty($_POST['minimum_stock']) ? (int)$_POST['minimum_stock'] : 0;
            $optimalStock = !empty($_POST['optimal_stock']) ? (int)$_POST['optimal_stock'] : null;
            $price = !empty($_POST['price']) ? (float)$_POST['price'] : null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name)) {
                setFlash('error', 'Název položky je povinný.');
            } elseif (empty($code)) {
                setFlash('error', 'Kód položky je povinný.');
            } else {
                // Check if code is unique for this company
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM items WHERE company_id = ? AND code = ?");
                $stmt->execute([getCurrentCompanyId(), $code]);
                if ($stmt->fetch()['count'] > 0) {
                    setFlash('error', 'Kód položky již existuje.');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO items (company_id, category_id, location_id, name, code, description, unit, pieces_per_package, minimum_stock, optimal_stock, price, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        getCurrentCompanyId(),
                        $categoryId,
                        $locationId,
                        $name,
                        $code,
                        $description,
                        $unit,
                        $piecesPerPackage,
                        $minimumStock,
                        $optimalStock,
                        $price,
                        $isActive
                    ]);

                    logAudit('create', 'item', $db->lastInsertId(), "Vytvořena položka: $name");
                    setFlash('success', 'Položka byla úspěšně vytvořena.');
                }
            }

        } elseif ($action === 'edit') {
            // Edit existing item
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $code = sanitize($_POST['code'] ?? '');
            $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
            $description = sanitize($_POST['description'] ?? '');
            $unit = sanitize($_POST['unit'] ?? 'ks');
            $piecesPerPackage = !empty($_POST['pieces_per_package']) ? (int)$_POST['pieces_per_package'] : 1;
            $minimumStock = !empty($_POST['minimum_stock']) ? (int)$_POST['minimum_stock'] : 0;
            $optimalStock = !empty($_POST['optimal_stock']) ? (int)$_POST['optimal_stock'] : null;
            $price = !empty($_POST['price']) ? (float)$_POST['price'] : null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name)) {
                setFlash('error', 'Název položky je povinný.');
            } elseif (empty($code)) {
                setFlash('error', 'Kód položky je povinný.');
            } else {
                // Check if code is unique (excluding current item)
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM items WHERE company_id = ? AND code = ? AND id != ?");
                $stmt->execute([getCurrentCompanyId(), $code, $id]);
                if ($stmt->fetch()['count'] > 0) {
                    setFlash('error', 'Kód položky již existuje.');
                } else {
                    $stmt = $db->prepare("
                        UPDATE items
                        SET category_id = ?, location_id = ?, name = ?, code = ?, description = ?, unit = ?, pieces_per_package = ?, minimum_stock = ?, optimal_stock = ?, price = ?, is_active = ?
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$categoryId, $locationId, $name, $code, $description, $unit, $piecesPerPackage, $minimumStock, $optimalStock, $price, $isActive, $id, getCurrentCompanyId()]);

                    logAudit('update', 'item', $id, "Upravena položka: $name");
                    setFlash('success', 'Položka byla úspěšně upravena.');
                }
            }

        } elseif ($action === 'delete') {
            // Delete item
            $id = (int)($_POST['id'] ?? 0);

            // Check if item has stock
            $stmt = $db->prepare("SELECT SUM(quantity) as total FROM stock WHERE item_id = ?");
            $stmt->execute([$id]);
            $stockTotal = $stmt->fetch()['total'] ?? 0;

            if ($stockTotal > 0) {
                setFlash('error', "Položku nelze smazat, má skladové zásoby ($stockTotal ks). Deaktivujte ji místo toho.");
            } else {
                // Check if item has movements
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM stock_movements WHERE item_id = ?");
                $stmt->execute([$id]);
                $movementCount = $stmt->fetch()['count'];

                if ($movementCount > 0) {
                    setFlash('error', "Položku nelze smazat, má $movementCount skladových pohybů. Deaktivujte ji místo toho.");
                } else {
                    $stmt = $db->prepare("DELETE FROM items WHERE id = ? AND company_id = ?");
                    $stmt->execute([$id, getCurrentCompanyId()]);

                    logAudit('delete', 'item', $id, "Smazána položka");
                    setFlash('success', 'Položka byla úspěšně smazána.');
                }
            }
        }

    } catch (Exception $e) {
        error_log("Items error: " . $e->getMessage());
        setFlash('error', 'Došlo k chybě při zpracování požadavku.');
    }

    redirect('items');
}

// Pagination
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;

// Filters
$search = $_GET['search'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$statusFilter = $_GET['status'] ?? 'active';  // Default to active items

// Get items for current company with filters
try {
    $db = Database::getInstance();

    // Build WHERE clause
    $whereClauses = ['i.company_id = ?'];
    $params = [getCurrentCompanyId()];

    if (!empty($search)) {
        $whereClauses[] = '(i.name LIKE ? OR i.code LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($categoryFilter)) {
        $whereClauses[] = 'i.category_id = ?';
        $params[] = $categoryFilter;
    }

    if ($statusFilter === 'active') {
        $whereClauses[] = 'i.is_active = 1';
    } elseif ($statusFilter === 'inactive') {
        $whereClauses[] = 'i.is_active = 0';
    }

    $whereSQL = implode(' AND ', $whereClauses);

    // Get total count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM items i WHERE $whereSQL");
    $stmt->execute($params);
    $totalItems = $stmt->fetch()['total'];
    $totalPages = ceil($totalItems / $perPage);

    // Get items
    $stmt = $db->prepare("
        SELECT i.*,
               c.name as category_name,
               (SELECT SUM(quantity) FROM stock WHERE item_id = i.id) as total_stock
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        WHERE $whereSQL
        ORDER BY i.name
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    // Get categories for dropdown
    $stmt = $db->prepare("
        SELECT id, name
        FROM categories
        WHERE company_id = ?
        ORDER BY name
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $categories = $stmt->fetchAll();

    // Get locations
    $stmt = $db->prepare("
        SELECT id, name, code
        FROM locations
        WHERE company_id = ? AND is_active = 1
        ORDER BY name
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $locations = $stmt->fetchAll();

} catch (Exception $e) {
    error_log("Items fetch error: " . $e->getMessage());
    $items = [];
    $categories = [];
    $locations = [];
    $totalItems = 0;
    $totalPages = 0;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>Položky</h1>
    <button type="button" class="btn btn-primary" onclick="openCreateModal()">
        ➕ Nová položka
    </button>
</div>

<!-- Items Table -->
<div class="card">
    <!-- Tabs -->
    <div class="tabs">
        <a href="<?= url('items', ['status' => 'active', 'search' => $search, 'category' => $categoryFilter]) ?>"
           class="tab <?= $statusFilter === 'active' || empty($statusFilter) ? 'active' : '' ?>">
            Aktivní položky
        </a>
        <a href="<?= url('items', ['status' => 'inactive', 'search' => $search, 'category' => $categoryFilter]) ?>"
           class="tab <?= $statusFilter === 'inactive' ? 'active' : '' ?>">
            Neaktivní položky
        </a>
    </div>

    <!-- Filters -->
    <div class="card-body" style="border-bottom: 1px solid var(--gray-200); padding-bottom: var(--spacing-lg);">
        <form method="GET" action="<?= url('items') ?>" class="filters-form">
            <input type="hidden" name="route" value="items">
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <div class="filter-row">
                <div class="form-group">
                    <label for="search">Hledat</label>
                    <input type="text" id="search" name="search" class="form-control" placeholder="Název nebo kód..." value="<?= e($search) ?>">
                </div>

                <div class="form-group">
                    <label for="category">Kategorie</label>
                    <select id="category" name="category" class="form-control">
                        <option value="">Všechny kategorie</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: var(--spacing-sm);">
                    <button type="submit" class="btn btn-secondary">Filtrovat</button>
                    <a href="<?= url('items', ['status' => $statusFilter]) ?>" class="btn btn-secondary">Zrušit</a>
                </div>
            </div>
        </form>
    </div>

    <div class="card-body">
        <?php if (empty($items)): ?>
            <div class="empty-state">
                <p>Žádné položky nenalezeny.</p>
                <?php if (empty($search) && empty($categoryFilter) && empty($statusFilter)): ?>
                    <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                        Vytvořit první položku
                    </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Kód</th>
                        <th>Název</th>
                        <th>ks/balení</th>
                        <th class="text-right">Min. stav</th>
                        <th class="text-right">Optimální stav</th>
                        <th class="text-right">Skladem</th>
                        <th class="text-right">Cena</th>
                        <th class="text-right">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $stockStatus = getStockStatus($item['total_stock'] ?? 0, $item['minimum_stock']);
                        ?>
                        <tr>
                            <td><code><?= e($item['code']) ?></code></td>
                            <td>
                                <strong><?= e($item['name']) ?></strong>
                                <?php if ($item['category_name']): ?>
                                    <br><small class="text-muted"><?= e($item['category_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= $item['pieces_per_package'] ?> <?= e($item['unit']) ?></td>
                            <td class="text-right"><?= formatNumber($item['minimum_stock']) ?></td>
                            <td class="text-right">
                                <?= $item['optimal_stock'] ? formatNumber($item['optimal_stock']) : '—' ?>
                            </td>
                            <td class="text-right">
                                <span class="badge badge-<?= $stockStatus ?>">
                                    <?= formatNumber($item['total_stock'] ?? 0) ?>
                                </span>
                            </td>
                            <td class="text-right">
                                <?= $item['price'] ? formatNumber($item['price'], 2) . ' Kč' : '—' ?>
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-sm btn-secondary"
                                        onclick='openEditModal(<?= json_encode($item) ?>)'>
                                    ✏️ Upravit
                                </button>
                                <?php if (($item['total_stock'] ?? 0) == 0): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="confirmDelete(<?= $item['id'] ?>, '<?= e($item['name']) ?>')">
                                        🗑️ Smazat
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= url('items', array_merge($_GET, ['p' => $page - 1])) ?>">← Předchozí</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= url('items', array_merge($_GET, ['p' => $i])) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= url('items', array_merge($_GET, ['p' => $page + 1])) ?>">Další →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Create/Edit Modal -->
<div id="itemModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 id="modalTitle">Nová položka</h2>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="<?= url('items') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="itemId">

            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group" style="flex: 2;">
                        <label for="name">Název položky *</label>
                        <input type="text" id="name" name="name" class="form-control" required>
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label for="code">Kód *</label>
                        <input type="text" id="code" name="code" class="form-control" required maxlength="50">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="category_id">Kategorie</label>
                        <select id="category_id" name="category_id" class="form-control">
                            <option value="">— Bez kategorie —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="location_id">Výchozí sklad</label>
                        <select id="location_id" name="location_id" class="form-control">
                            <option value="">— Bez výchozího skladu —</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?> (<?= e($loc['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Popis</label>
                    <textarea id="description" name="description" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="unit">Jednotka *</label>
                        <select id="unit" name="unit" class="form-control" required>
                            <option value="ks">kusy (ks)</option>
                            <option value="bal">balení (bal)</option>
                            <option value="kg">kilogramy (kg)</option>
                            <option value="l">litry (l)</option>
                            <option value="m">metry (m)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="pieces_per_package">Kusů v balení</label>
                        <input type="number" id="pieces_per_package" name="pieces_per_package" class="form-control" value="1" min="1">
                        <small class="form-text">Kolik kusů obsahuje jedno balení</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="minimum_stock">Minimální stav</label>
                        <input type="number" id="minimum_stock" name="minimum_stock" class="form-control" value="0" min="0">
                        <small class="form-text">Při poklesu pod tuto hodnotu se zobrazí upozornění</small>
                    </div>

                    <div class="form-group">
                        <label for="optimal_stock">Optimální stav</label>
                        <input type="number" id="optimal_stock" name="optimal_stock" class="form-control" min="0">
                        <small class="form-text">Doporučený stav skladu</small>
                    </div>

                    <div class="form-group">
                        <label for="price">Cena (Kč)</label>
                        <input type="number" id="price" name="price" class="form-control" step="0.01" min="0">
                    </div>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="is_active" name="is_active" checked>
                        Aktivní položka
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Zrušit</button>
                <button type="submit" class="btn btn-primary">Uložit</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h2>Smazat položku?</h2>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST" action="<?= url('items') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">

            <div class="modal-body">
                <p>Opravdu chcete smazat položku <strong id="deleteName"></strong>?</p>
                <p class="text-secondary">Tato akce je nevratná.</p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Zrušit</button>
                <button type="submit" class="btn btn-danger">Smazat</button>
            </div>
        </form>
    </div>
</div>

<style>
    .empty-state {
        text-align: center;
        padding: var(--spacing-xl) 0;
    }

    .empty-state p {
        color: var(--text-secondary);
        margin-bottom: var(--spacing-lg);
    }

    code {
        background: var(--gray-100);
        padding: 0.2em 0.4em;
        border-radius: var(--radius-sm);
        font-family: 'Courier New', monospace;
        font-size: 0.875em;
    }

    .badge {
        padding: 0.25rem 0.5rem;
        border-radius: var(--radius-sm);
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .badge-success {
        background: #dcfce7;
        color: #166534;
    }

    .badge-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .badge-ok {
        background: #dcfce7;
        color: #166534;
    }

    .badge-low {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-critical {
        background: #fee2e2;
        color: #991b1b;
    }

    .checkbox-label {
        display: flex;
        align-items: center;
        gap: var(--spacing-sm);
        cursor: pointer;
    }

    .checkbox-label input[type="checkbox"] {
        width: auto;
        margin: 0;
    }

    .form-text {
        display: block;
        margin-top: var(--spacing-xs);
        font-size: 0.875rem;
        color: var(--text-secondary);
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: var(--spacing-md);
    }

    .modal-lg {
        max-width: 800px;
    }

    .filters-form {
        display: flex;
        gap: var(--spacing-md);
    }

    .filter-row {
        display: grid;
        grid-template-columns: 2fr 1.5fr auto;
        gap: var(--spacing-md);
        align-items: end;
    }

    .tabs {
        display: flex;
        border-bottom: 2px solid var(--gray-200);
        background: var(--gray-50);
    }

    .tab {
        padding: var(--spacing-md) var(--spacing-xl);
        color: var(--text-secondary);
        text-decoration: none;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        font-weight: 500;
        transition: all 0.15s ease-in-out;
    }

    .tab:hover {
        color: var(--text-primary);
        background: var(--gray-100);
        text-decoration: none;
    }

    .tab.active {
        color: var(--primary);
        border-bottom-color: var(--primary);
        background: white;
    }
</style>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Nová položka';
    document.getElementById('formAction').value = 'create';
    document.getElementById('itemId').value = '';
    document.getElementById('name').value = '';
    document.getElementById('code').value = '';
    document.getElementById('category_id').value = '';
    document.getElementById('location_id').value = '';
    document.getElementById('description').value = '';
    document.getElementById('unit').value = 'ks';
    document.getElementById('pieces_per_package').value = '1';
    document.getElementById('minimum_stock').value = '0';
    document.getElementById('optimal_stock').value = '';
    document.getElementById('price').value = '';
    document.getElementById('is_active').checked = true;
    document.getElementById('itemModal').classList.add('active');
}

function openEditModal(item) {
    document.getElementById('modalTitle').textContent = 'Upravit položku';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('itemId').value = item.id;
    document.getElementById('name').value = item.name;
    document.getElementById('code').value = item.code;
    document.getElementById('category_id').value = item.category_id || '';
    document.getElementById('location_id').value = item.location_id || '';
    document.getElementById('description').value = item.description || '';
    document.getElementById('unit').value = item.unit;
    document.getElementById('pieces_per_package').value = item.pieces_per_package;
    document.getElementById('minimum_stock').value = item.minimum_stock;
    document.getElementById('optimal_stock').value = item.optimal_stock || '';
    document.getElementById('price').value = item.price || '';
    document.getElementById('is_active').checked = item.is_active == 1;
    document.getElementById('itemModal').classList.add('active');
}

function closeModal() {
    document.getElementById('itemModal').classList.remove('active');
}

function confirmDelete(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});

// Close modal on background click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
            closeDeleteModal();
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
