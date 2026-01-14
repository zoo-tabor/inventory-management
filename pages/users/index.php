<?php
/**
 * Users Management
 * List, create, edit, delete system users
 */

$pageTitle = 'Uživatelé';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný CSRF token.');
        redirect('/users');
    }

    $action = $_POST['action'] ?? '';

    try {
        $db = Database::getInstance();

        if ($action === 'create') {
            // Create new user
            $username = sanitize($_POST['username'] ?? '');
            $fullName = sanitize($_POST['full_name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = sanitize($_POST['role'] ?? 'user');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($username)) {
                setFlash('error', 'Uživatelské jméno je povinné.');
            } elseif (empty($fullName)) {
                setFlash('error', 'Celé jméno je povinné.');
            } elseif (empty($password)) {
                setFlash('error', 'Heslo je povinné.');
            } elseif (strlen($password) < 6) {
                setFlash('error', 'Heslo musí mít alespoň 6 znaků.');
            } else {
                // Check if username is unique
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()['count'] > 0) {
                    setFlash('error', 'Uživatelské jméno již existuje.');
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $db->prepare("
                        INSERT INTO users (username, password_hash, full_name, email, role, is_active)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $username,
                        $passwordHash,
                        $fullName,
                        $email,
                        $role,
                        $isActive
                    ]);

                    logAudit('create', 'user', $db->lastInsertId(), "Vytvořen uživatel: $username");
                    setFlash('success', 'Uživatel byl úspěšně vytvořen.');
                }
            }

        } elseif ($action === 'edit') {
            // Edit existing user
            $id = (int)($_POST['id'] ?? 0);
            $username = sanitize($_POST['username'] ?? '');
            $fullName = sanitize($_POST['full_name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = sanitize($_POST['role'] ?? 'user');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($username)) {
                setFlash('error', 'Uživatelské jméno je povinné.');
            } elseif (empty($fullName)) {
                setFlash('error', 'Celé jméno je povinné.');
            } else {
                // Check if username is unique (excluding current user)
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$username, $id]);
                if ($stmt->fetch()['count'] > 0) {
                    setFlash('error', 'Uživatelské jméno již existuje.');
                } else {
                    // Update password only if provided
                    if (!empty($password)) {
                        if (strlen($password) < 6) {
                            setFlash('error', 'Heslo musí mít alespoň 6 znaků.');
                            redirect('/users');
                        }
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("
                            UPDATE users
                            SET username = ?, password_hash = ?, full_name = ?, email = ?, role = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$username, $passwordHash, $fullName, $email, $role, $isActive, $id]);
                    } else {
                        $stmt = $db->prepare("
                            UPDATE users
                            SET username = ?, full_name = ?, email = ?, role = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$username, $fullName, $email, $role, $isActive, $id]);
                    }

                    logAudit('update', 'user', $id, "Upraven uživatel: $username");
                    setFlash('success', 'Uživatel byl úspěšně upraven.');
                }
            }

        } elseif ($action === 'delete') {
            // Delete user
            $id = (int)($_POST['id'] ?? 0);
            $currentUserId = $_SESSION['user_id'] ?? 0;

            // Prevent deleting yourself
            if ($id == $currentUserId) {
                setFlash('error', 'Nemůžete smazat sami sebe.');
            } else {
                // Check if user has audit logs
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM audit_log WHERE user_id = ?");
                $stmt->execute([$id]);
                $auditCount = $stmt->fetch()['count'];

                if ($auditCount > 0) {
                    setFlash('error', "Uživatele nelze smazat, má $auditCount záznamů v historii. Deaktivujte jej místo toho.");
                } else {
                    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$id]);

                    logAudit('delete', 'user', $id, "Smazán uživatel");
                    setFlash('success', 'Uživatel byl úspěšně smazán.');
                }
            }
        }

    } catch (Exception $e) {
        error_log("Users error: " . $e->getMessage());
        // Check for duplicate key error
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            setFlash('error', 'Uživatelské jméno již existuje. Zvolte prosím jiné jméno.');
        } else {
            setFlash('error', 'Došlo k chybě při zpracování požadavku: ' . $e->getMessage());
        }
    }

    redirect('/users');
}

// Get all users
try {
    $db = Database::getInstance();

    $stmt = $db->query("
        SELECT u.*,
               (SELECT COUNT(*) FROM audit_log WHERE user_id = u.id) as audit_count
        FROM users u
        ORDER BY u.username
    ");
    $users = $stmt->fetchAll();

} catch (Exception $e) {
    error_log("Users fetch error: " . $e->getMessage());
    $users = [];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>Uživatelé</h1>
    <button type="button" class="btn btn-primary" onclick="openCreateModal()">
        ➕ Nový uživatel
    </button>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($users)): ?>
            <div class="empty-state">
                <p>Zatím nemáte vytvořené žádné uživatele.</p>
                <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                    Vytvořit prvního uživatele
                </button>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Uživatelské jméno</th>
                        <th>Celé jméno</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Poslední přihlášení</th>
                        <th class="text-center">Stav</th>
                        <th class="text-right">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><code><?= e($user['username']) ?></code></td>
                            <td><strong><?= e($user['full_name']) ?></strong></td>
                            <td><?= e($user['email']) ?: '—' ?></td>
                            <td>
                                <?php if ($user['role'] === 'admin'): ?>
                                    <span class="badge badge-primary">Admin</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Uživatel</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['last_login']): ?>
                                    <?= date('d.m.Y H:i', strtotime($user['last_login'])) ?>
                                <?php else: ?>
                                    <span class="text-secondary">Nikdy</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($user['is_active']): ?>
                                    <span class="badge badge-success">Aktivní</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Neaktivní</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-sm btn-secondary"
                                        onclick='openEditModal(<?= json_encode($user) ?>)'>
                                    ✏️ Upravit
                                </button>
                                <?php if ($user['id'] != ($_SESSION['user_id'] ?? 0) && $user['audit_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="confirmDelete(<?= $user['id'] ?>, '<?= e($user['username']) ?>')">
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
<div id="userModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Nový uživatel</h2>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="<?= url('users') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="userId">

            <div class="modal-body">
                <div class="form-group">
                    <label for="username">Uživatelské jméno *</label>
                    <input type="text" id="username" name="username" class="form-control" required maxlength="50">
                    <small class="form-text">Použijte pouze písmena, čísla a podtržítka</small>
                </div>

                <div class="form-group">
                    <label for="full_name">Celé jméno *</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required maxlength="150">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" maxlength="150">
                </div>

                <div class="form-group">
                    <label for="password">Heslo <span id="passwordRequired">*</span></label>
                    <input type="password" id="password" name="password" class="form-control" minlength="6">
                    <small class="form-text" id="passwordHelp">Minimálně 6 znaků</small>
                </div>

                <div class="form-group">
                    <label for="role">Role *</label>
                    <select id="role" name="role" class="form-control" required>
                        <option value="user">Uživatel</option>
                        <option value="admin">Admin</option>
                    </select>
                    <small class="form-text">Admin má přístup ke všem funkcím včetně správy uživatelů</small>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="is_active" name="is_active" checked>
                        Aktivní uživatel
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
            <h2>Smazat uživatele?</h2>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST" action="<?= url('users') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">

            <div class="modal-body">
                <p>Opravdu chcete smazat uživatele <strong id="deleteName"></strong>?</p>
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

    .badge-primary {
        background: #dbeafe;
        color: #1e40af;
    }

    .badge-success {
        background: #dcfce7;
        color: #166534;
    }

    .badge-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }

    .badge-danger {
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

    .text-secondary {
        color: var(--text-secondary);
    }
</style>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Nový uživatel';
    document.getElementById('formAction').value = 'create';
    document.getElementById('userId').value = '';
    document.getElementById('username').value = '';
    document.getElementById('full_name').value = '';
    document.getElementById('email').value = '';
    document.getElementById('password').value = '';
    document.getElementById('password').required = true;
    document.getElementById('passwordRequired').style.display = 'inline';
    document.getElementById('passwordHelp').textContent = 'Minimálně 6 znaků';
    document.getElementById('role').value = 'user';
    document.getElementById('is_active').checked = true;
    document.getElementById('userModal').classList.add('active');
}

function openEditModal(user) {
    document.getElementById('modalTitle').textContent = 'Upravit uživatele';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('userId').value = user.id;
    document.getElementById('username').value = user.username;
    document.getElementById('full_name').value = user.full_name;
    document.getElementById('email').value = user.email || '';
    document.getElementById('password').value = '';
    document.getElementById('password').required = false;
    document.getElementById('passwordRequired').style.display = 'none';
    document.getElementById('passwordHelp').textContent = 'Nechte prázdné, pokud nechcete změnit heslo';
    document.getElementById('role').value = user.role;
    document.getElementById('is_active').checked = user.is_active == 1;
    document.getElementById('userModal').classList.add('active');
}

function closeModal() {
    document.getElementById('userModal').classList.remove('active');
}

function confirmDelete(id, username) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = username;
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
