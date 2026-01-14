<?php
/**
 * Departments Management
 * List, create, edit, delete departments
 */

$pageTitle = 'Oddělení';
$currentCompany = getCurrentCompany();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný CSRF token.');
        redirect('/departments');
    }

    $action = $_POST['action'] ?? '';

    try {
        $db = Database::getInstance();

        if ($action === 'create') {
            // Create new department
            $name = sanitize($_POST['name'] ?? '');
            $code = sanitize($_POST['code'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name)) {
                setFlash('error', 'Název oddělení je povinný.');
            } elseif (empty($code)) {
                setFlash('error', 'Kód oddělení je povinný.');
            } else {
                // Check if code is unique for this company
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM departments WHERE company_id = ? AND code = ?");
                $stmt->execute([getCurrentCompanyId(), $code]);
                if ($stmt->fetch()['count'] > 0) {
                    setFlash('error', 'Kód oddělení již existuje.');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO departments (company_id, name, code, description, is_active)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        getCurrentCompanyId(),
                        $name,
                        $code,
                        $description,
                        $isActive
                    ]);

                    logAudit('create', 'department', $db->lastInsertId(), "Vytvořeno oddělení: $name");
                    setFlash('success', 'Oddělení bylo úspěšně vytvořeno.');
                }
            }

        } elseif ($action === 'edit') {
            // Edit existing department
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $code = sanitize($_POST['code'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name)) {
                setFlash('error', 'Název oddělení je povinný.');
            } elseif (empty($code)) {
                setFlash('error', 'Kód oddělení je povinný.');
            } else {
                // Check if code is unique (excluding current department)
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM departments WHERE company_id = ? AND code = ? AND id != ?");
                $stmt->execute([getCurrentCompanyId(), $code, $id]);
                if ($stmt->fetch()['count'] > 0) {
                    setFlash('error', 'Kód oddělení již existuje.');
                } else {
                    $stmt = $db->prepare("
                        UPDATE departments
                        SET name = ?, code = ?, description = ?, is_active = ?
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$name, $code, $description, $isActive, $id, getCurrentCompanyId()]);

                    logAudit('update', 'department', $id, "Upraveno oddělení: $name");
                    setFlash('success', 'Oddělení bylo úspěšně upraveno.');
                }
            }

        } elseif ($action === 'delete') {
            // Delete department
            $id = (int)($_POST['id'] ?? 0);

            // Check if department has employees
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM employees WHERE department_id = ?");
            $stmt->execute([$id]);
            $employeeCount = $stmt->fetch()['count'];

            if ($employeeCount > 0) {
                setFlash('error', "Oddělení nelze smazat, obsahuje $employeeCount zaměstnanců.");
            } else {
                $stmt = $db->prepare("DELETE FROM departments WHERE id = ? AND company_id = ?");
                $stmt->execute([$id, getCurrentCompanyId()]);

                logAudit('delete', 'department', $id, "Smazáno oddělení");
                setFlash('success', 'Oddělení bylo úspěšně smazáno.');
            }
        }

    } catch (Exception $e) {
        error_log("Departments error: " . $e->getMessage());
        // Check for duplicate key error
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            setFlash('error', 'Kód oddělení již existuje. Zvolte prosím jiný kód.');
        } else {
            setFlash('error', 'Došlo k chybě při zpracování požadavku: ' . $e->getMessage());
        }
    }

    redirect('/departments');
}

// Get all departments for current company
try {
    $db = Database::getInstance();

    $stmt = $db->prepare("
        SELECT d.*,
               (SELECT COUNT(*) FROM employees WHERE department_id = d.id) as employee_count
        FROM departments d
        WHERE d.company_id = ?
        ORDER BY d.name
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $departments = $stmt->fetchAll();

} catch (Exception $e) {
    error_log("Departments fetch error: " . $e->getMessage());
    $departments = [];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>Oddělení</h1>
    <button type="button" class="btn btn-primary" onclick="openCreateModal()">
        ➕ Nové oddělení
    </button>
</div>

<!-- Departments Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($departments)): ?>
            <div class="empty-state">
                <p>Zatím nemáte vytvořená žádná oddělení.</p>
                <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                    Vytvořit první oddělení
                </button>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Název</th>
                        <th>Kód</th>
                        <th>Popis</th>
                        <th class="text-center">Zaměstnanci</th>
                        <th class="text-center">Stav</th>
                        <th class="text-right">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departments as $department): ?>
                        <tr>
                            <td><strong><?= e($department['name']) ?></strong></td>
                            <td><code><?= e($department['code']) ?></code></td>
                            <td><?= e($department['description']) ?: '—' ?></td>
                            <td class="text-center"><?= $department['employee_count'] ?></td>
                            <td class="text-center">
                                <?php if ($department['is_active']): ?>
                                    <span class="badge badge-success">Aktivní</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Neaktivní</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-sm btn-secondary"
                                        onclick='openEditModal(<?= json_encode($department) ?>)'>
                                    ✏️ Upravit
                                </button>
                                <?php if ($department['employee_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="confirmDelete(<?= $department['id'] ?>, '<?= e($department['name']) ?>')">
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
<div id="departmentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Nové oddělení</h2>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="<?= url('departments') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="departmentId">

            <div class="modal-body">
                <div class="form-group">
                    <label for="name">Název oddělení *</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="code">Kód oddělení *</label>
                    <input type="text" id="code" name="code" class="form-control" required maxlength="20">
                    <small class="form-text">Unikátní kód pro identifikaci oddělení (např. IT, HR, PROD, atd.)</small>
                </div>

                <div class="form-group">
                    <label for="description">Popis</label>
                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="is_active" name="is_active" checked>
                        Aktivní oddělení
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
            <h2>Smazat oddělení?</h2>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST" action="<?= url('departments') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">

            <div class="modal-body">
                <p>Opravdu chcete smazat oddělení <strong id="deleteName"></strong>?</p>
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
    document.getElementById('modalTitle').textContent = 'Nové oddělení';
    document.getElementById('formAction').value = 'create';
    document.getElementById('departmentId').value = '';
    document.getElementById('name').value = '';
    document.getElementById('code').value = '';
    document.getElementById('description').value = '';
    document.getElementById('is_active').checked = true;
    document.getElementById('departmentModal').classList.add('active');
}

function openEditModal(department) {
    document.getElementById('modalTitle').textContent = 'Upravit oddělení';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('departmentId').value = department.id;
    document.getElementById('name').value = department.name;
    document.getElementById('code').value = department.code;
    document.getElementById('description').value = department.description || '';
    document.getElementById('is_active').checked = department.is_active == 1;
    document.getElementById('departmentModal').classList.add('active');
}

function closeModal() {
    document.getElementById('departmentModal').classList.remove('active');
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
