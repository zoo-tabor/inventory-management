<?php
/**
 * Locations Management
 * List, create, edit, delete warehouse locations
 */

$pageTitle = 'Sklady';
$currentCompany = getCurrentCompany();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný CSRF token.');
        redirect('/locations');
    }

    $action = $_POST['action'] ?? '';

    try {
        $db = Database::getInstance();

        if ($action === 'create') {
            // Create new location
            $name = sanitize($_POST['name'] ?? '');
            $code = sanitize($_POST['code'] ?? '');
            $address = sanitize($_POST['address'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name)) {
                setFlash('error', 'Název skladu je povinný.');
            } elseif (empty($code)) {
                setFlash('error', 'Kód skladu je povinný.');
            } else {
                // Check if code is unique for this company
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM locations WHERE company_id = ? AND code = ?");
                $stmt->execute([getCurrentCompanyId(), $code]);
                if ($stmt->fetch()['count'] > 0) {
                    setFlash('error', 'Kód skladu již existuje.');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO locations (company_id, name, code, address, description, is_active)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        getCurrentCompanyId(),
                        $name,
                        $code,
                        $address,
                        $description,
                        $isActive
                    ]);

                    logAudit('create', 'location', $db->lastInsertId(), "Vytvořen sklad: $name");
                    setFlash('success', 'Sklad byl úspěšně vytvořen.');
                }
            }

        } elseif ($action === 'edit') {
            // Edit existing location
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $code = sanitize($_POST['code'] ?? '');
            $address = sanitize($_POST['address'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name)) {
                setFlash('error', 'Název skladu je povinný.');
            } elseif (empty($code)) {
                setFlash('error', 'Kód skladu je povinný.');
            } else {
                // Check if code is unique (excluding current location)
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM locations WHERE company_id = ? AND code = ? AND id != ?");
                $stmt->execute([getCurrentCompanyId(), $code, $id]);
                if ($stmt->fetch()['count'] > 0) {
                    setFlash('error', 'Kód skladu již existuje.');
                } else {
                    $stmt = $db->prepare("
                        UPDATE locations
                        SET name = ?, code = ?, address = ?, description = ?, is_active = ?
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$name, $code, $address, $description, $isActive, $id, getCurrentCompanyId()]);

                    logAudit('update', 'location', $id, "Upraven sklad: $name");
                    setFlash('success', 'Sklad byl úspěšně upraven.');
                }
            }

        } elseif ($action === 'delete') {
            // Delete location
            $id = (int)($_POST['id'] ?? 0);

            // Check if location has stock
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM stock WHERE location_id = ?");
            $stmt->execute([$id]);
            $stockCount = $stmt->fetch()['count'];

            if ($stockCount > 0) {
                setFlash('error', "Sklad nelze smazat, obsahuje $stockCount skladových záznamů.");
            } else {
                $stmt = $db->prepare("DELETE FROM locations WHERE id = ? AND company_id = ?");
                $stmt->execute([$id, getCurrentCompanyId()]);

                logAudit('delete', 'location', $id, "Smazán sklad");
                setFlash('success', 'Sklad byl úspěšně smazán.');
            }
        }

    } catch (Exception $e) {
        error_log("Locations error: " . $e->getMessage());
        setFlash('error', 'Došlo k chybě při zpracování požadavku.');
    }

    redirect('/locations');
}

// Get all locations for current company
try {
    $db = Database::getInstance();

    $stmt = $db->prepare("
        SELECT l.*,
               (SELECT COUNT(*) FROM stock WHERE location_id = l.id) as stock_count,
               (SELECT COUNT(DISTINCT item_id) FROM stock WHERE location_id = l.id) as item_count
        FROM locations l
        WHERE l.company_id = ?
        ORDER BY l.name
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $locations = $stmt->fetchAll();

} catch (Exception $e) {
    error_log("Locations fetch error: " . $e->getMessage());
    $locations = [];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>Sklady</h1>
    <button type="button" class="btn btn-primary" onclick="openCreateModal()">
        ➕ Nový sklad
    </button>
</div>

<!-- Locations Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($locations)): ?>
            <div class="empty-state">
                <p>Zatím nemáte vytvořené žádné sklady.</p>
                <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                    Vytvořit první sklad
                </button>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Název</th>
                        <th>Kód</th>
                        <th>Adresa</th>
                        <th>Popis</th>
                        <th class="text-center">Položky</th>
                        <th class="text-center">Stav</th>
                        <th class="text-right">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($locations as $location): ?>
                        <tr>
                            <td><strong><?= e($location['name']) ?></strong></td>
                            <td><code><?= e($location['code']) ?></code></td>
                            <td><?= e($location['address']) ?: '—' ?></td>
                            <td><?= e($location['description']) ?: '—' ?></td>
                            <td class="text-center"><?= $location['item_count'] ?></td>
                            <td class="text-center">
                                <?php if ($location['is_active']): ?>
                                    <span class="badge badge-success">Aktivní</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Neaktivní</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-sm btn-secondary"
                                        onclick='openEditModal(<?= json_encode($location) ?>)'>
                                    ✏️ Upravit
                                </button>
                                <?php if ($location['stock_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="confirmDelete(<?= $location['id'] ?>, '<?= e($location['name']) ?>')">
                                        🗑️ Smazat
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Create/Edit Modal -->
<div id="locationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Nový sklad</h2>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="/locations">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="locationId">

            <div class="modal-body">
                <div class="form-group">
                    <label for="name">Název skladu *</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="code">Kód skladu *</label>
                    <input type="text" id="code" name="code" class="form-control" required maxlength="20">
                    <small class="form-text">Unikátní kód pro identifikaci skladu (např. SK01, MAIN, atd.)</small>
                </div>

                <div class="form-group">
                    <label for="address">Adresa</label>
                    <textarea id="address" name="address" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-group">
                    <label for="description">Popis</label>
                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="is_active" name="is_active" checked>
                        Aktivní sklad
                    </label>
                    <small class="form-text">Pouze aktivní sklady lze vybrat při skladových operacích</small>
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
            <h2>Smazat sklad?</h2>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST" action="/locations">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">

            <div class="modal-body">
                <p>Opravdu chcete smazat sklad <strong id="deleteName"></strong>?</p>
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
</style>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Nový sklad';
    document.getElementById('formAction').value = 'create';
    document.getElementById('locationId').value = '';
    document.getElementById('name').value = '';
    document.getElementById('code').value = '';
    document.getElementById('address').value = '';
    document.getElementById('description').value = '';
    document.getElementById('is_active').checked = true;
    document.getElementById('locationModal').classList.add('active');
}

function openEditModal(location) {
    document.getElementById('modalTitle').textContent = 'Upravit sklad';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('locationId').value = location.id;
    document.getElementById('name').value = location.name;
    document.getElementById('code').value = location.code;
    document.getElementById('address').value = location.address || '';
    document.getElementById('description').value = location.description || '';
    document.getElementById('is_active').checked = location.is_active == 1;
    document.getElementById('locationModal').classList.add('active');
}

function closeModal() {
    document.getElementById('locationModal').classList.remove('active');
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
