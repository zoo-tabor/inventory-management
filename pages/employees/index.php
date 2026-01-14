<?php
/**
 * Employees Management
 * List, create, edit, delete employees
 */

$pageTitle = 'Zaměstnanci';
$currentCompany = getCurrentCompany();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný CSRF token.');
        redirect('/employees');
    }

    $action = $_POST['action'] ?? '';

    try {
        $db = Database::getInstance();

        if ($action === 'create') {
            // Create new employee
            $fullName = sanitize($_POST['full_name'] ?? '');
            $employeeNumber = sanitize($_POST['employee_number'] ?? '');
            $departmentId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
            $position = sanitize($_POST['position'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $phone = sanitize($_POST['phone'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($fullName)) {
                setFlash('error', 'Jméno zaměstnance je povinné.');
            } elseif (empty($employeeNumber)) {
                setFlash('error', 'Osobní číslo je povinné.');
            } else {
                // Check if employee number is unique for this company
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM employees WHERE company_id = ? AND employee_number = ?");
                $stmt->execute([getCurrentCompanyId(), $employeeNumber]);
                if ($stmt->fetch()['count'] > 0) {
                    setFlash('error', 'Osobní číslo již existuje.');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO employees (company_id, full_name, employee_number, department_id, position, email, phone, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        getCurrentCompanyId(),
                        $fullName,
                        $employeeNumber,
                        $departmentId,
                        $position,
                        $email,
                        $phone,
                        $isActive
                    ]);

                    logAudit('create', 'employee', $db->lastInsertId(), "Vytvořen zaměstnanec: $fullName");
                    setFlash('success', 'Zaměstnanec byl úspěšně vytvořen.');
                }
            }

        } elseif ($action === 'edit') {
            // Edit existing employee
            $id = (int)($_POST['id'] ?? 0);
            $fullName = sanitize($_POST['full_name'] ?? '');
            $employeeNumber = sanitize($_POST['employee_number'] ?? '');
            $departmentId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
            $position = sanitize($_POST['position'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $phone = sanitize($_POST['phone'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($fullName)) {
                setFlash('error', 'Jméno zaměstnance je povinné.');
            } elseif (empty($employeeNumber)) {
                setFlash('error', 'Osobní číslo je povinné.');
            } else {
                // Check if employee number is unique (excluding current employee)
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM employees WHERE company_id = ? AND employee_number = ? AND id != ?");
                $stmt->execute([getCurrentCompanyId(), $employeeNumber, $id]);
                if ($stmt->fetch()['count'] > 0) {
                    setFlash('error', 'Osobní číslo již existuje.');
                } else {
                    $stmt = $db->prepare("
                        UPDATE employees
                        SET full_name = ?, employee_number = ?, department_id = ?, position = ?, email = ?, phone = ?, is_active = ?
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$fullName, $employeeNumber, $departmentId, $position, $email, $phone, $isActive, $id, getCurrentCompanyId()]);

                    logAudit('update', 'employee', $id, "Upraven zaměstnanec: $fullName");
                    setFlash('success', 'Zaměstnanec byl úspěšně upraven.');
                }
            }

        } elseif ($action === 'delete') {
            // Delete employee
            $id = (int)($_POST['id'] ?? 0);

            // Check if employee has stock movements
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM stock_movements WHERE employee_id = ?");
            $stmt->execute([$id]);
            $movementCount = $stmt->fetch()['count'];

            if ($movementCount > 0) {
                setFlash('error', "Zaměstnance nelze smazat, má $movementCount skladových pohybů. Deaktivujte jej místo toho.");
            } else {
                $stmt = $db->prepare("DELETE FROM employees WHERE id = ? AND company_id = ?");
                $stmt->execute([$id, getCurrentCompanyId()]);

                logAudit('delete', 'employee', $id, "Smazán zaměstnanec");
                setFlash('success', 'Zaměstnanec byl úspěšně smazán.');
            }
        }

    } catch (Exception $e) {
        error_log("Employees error: " . $e->getMessage());
        setFlash('error', 'Došlo k chybě při zpracování požadavku.');
    }

    redirect('/employees');
}

// Get all employees for current company
try {
    $db = Database::getInstance();

    $stmt = $db->prepare("
        SELECT e.*,
               d.name as department_name,
               (SELECT COUNT(*) FROM stock_movements WHERE employee_id = e.id) as movement_count
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE e.company_id = ?
        ORDER BY e.full_name
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $employees = $stmt->fetchAll();

    // Get active departments for dropdown
    $stmt = $db->prepare("
        SELECT id, name
        FROM departments
        WHERE company_id = ? AND is_active = 1
        ORDER BY name
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $departments = $stmt->fetchAll();

} catch (Exception $e) {
    error_log("Employees fetch error: " . $e->getMessage());
    $employees = [];
    $departments = [];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>Zaměstnanci</h1>
    <button type="button" class="btn btn-primary" onclick="openCreateModal()">
        ➕ Nový zaměstnanec
    </button>
</div>

<!-- Employees Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($employees)): ?>
            <div class="empty-state">
                <p>Zatím nemáte vytvořené žádné zaměstnance.</p>
                <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                    Vytvořit prvního zaměstnance
                </button>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Jméno</th>
                        <th>Osobní číslo</th>
                        <th>Oddělení</th>
                        <th>Pozice</th>
                        <th>Email</th>
                        <th>Telefon</th>
                        <th class="text-center">Stav</th>
                        <th class="text-right">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><strong><?= e($employee['full_name']) ?></strong></td>
                            <td><code><?= e($employee['employee_number']) ?></code></td>
                            <td><?= $employee['department_name'] ? e($employee['department_name']) : '—' ?></td>
                            <td><?= e($employee['position']) ?: '—' ?></td>
                            <td><?= e($employee['email']) ?: '—' ?></td>
                            <td><?= e($employee['phone']) ?: '—' ?></td>
                            <td class="text-center">
                                <?php if ($employee['is_active']): ?>
                                    <span class="badge badge-success">Aktivní</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Neaktivní</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-sm btn-secondary"
                                        onclick='openEditModal(<?= json_encode($employee) ?>)'>
                                    ✏️ Upravit
                                </button>
                                <?php if ($employee['movement_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="confirmDelete(<?= $employee['id'] ?>, '<?= e($employee['full_name']) ?>')">
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
<div id="employeeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Nový zaměstnanec</h2>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="<?= url('employees') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="employeeId">

            <div class="modal-body">
                <div class="form-group">
                    <label for="full_name">Jméno a příjmení *</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="employee_number">Osobní číslo *</label>
                    <input type="text" id="employee_number" name="employee_number" class="form-control" required maxlength="20">
                    <small class="form-text">Unikátní identifikátor zaměstnance</small>
                </div>

                <div class="form-group">
                    <label for="department_id">Oddělení</label>
                    <select id="department_id" name="department_id" class="form-control">
                        <option value="">— Bez oddělení —</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>"><?= e($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="position">Pozice</label>
                    <input type="text" id="position" name="position" class="form-control">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control">
                </div>

                <div class="form-group">
                    <label for="phone">Telefon</label>
                    <input type="text" id="phone" name="phone" class="form-control">
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="is_active" name="is_active" checked>
                        Aktivní zaměstnanec
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
            <h2>Smazat zaměstnance?</h2>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST" action="<?= url('employees') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">

            <div class="modal-body">
                <p>Opravdu chcete smazat zaměstnance <strong id="deleteName"></strong>?</p>
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
    document.getElementById('modalTitle').textContent = 'Nový zaměstnanec';
    document.getElementById('formAction').value = 'create';
    document.getElementById('employeeId').value = '';
    document.getElementById('full_name').value = '';
    document.getElementById('employee_number').value = '';
    document.getElementById('department_id').value = '';
    document.getElementById('position').value = '';
    document.getElementById('email').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('is_active').checked = true;
    document.getElementById('employeeModal').classList.add('active');
}

function openEditModal(employee) {
    document.getElementById('modalTitle').textContent = 'Upravit zaměstnance';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('employeeId').value = employee.id;
    document.getElementById('full_name').value = employee.full_name;
    document.getElementById('employee_number').value = employee.employee_number;
    document.getElementById('department_id').value = employee.department_id || '';
    document.getElementById('position').value = employee.position || '';
    document.getElementById('email').value = employee.email || '';
    document.getElementById('phone').value = employee.phone || '';
    document.getElementById('is_active').checked = employee.is_active == 1;
    document.getElementById('employeeModal').classList.add('active');
}

function closeModal() {
    document.getElementById('employeeModal').classList.remove('active');
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
