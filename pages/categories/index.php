<?php
/**
 * Categories Management
 * List, create, edit, delete categories
 */

$pageTitle = 'Kategorie';
$currentCompany = getCurrentCompany();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný CSRF token.');
        redirect('/categories');
    }

    $action = $_POST['action'] ?? '';

    try {
        $db = Database::getInstance();

        if ($action === 'create') {
            // Create new category
            $name = sanitize($_POST['name'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

            if (empty($name)) {
                setFlash('error', 'Název kategorie je povinný.');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO categories (company_id, name, description, parent_id)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    getCurrentCompanyId(),
                    $name,
                    $description,
                    $parentId
                ]);

                logAudit('create', 'category', $db->lastInsertId(), "Vytvořena kategorie: $name");
                setFlash('success', 'Kategorie byla úspěšně vytvořena.');
            }

        } elseif ($action === 'edit') {
            // Edit existing category
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

            if (empty($name)) {
                setFlash('error', 'Název kategorie je povinný.');
            } else {
                $stmt = $db->prepare("
                    UPDATE categories
                    SET name = ?, description = ?, parent_id = ?
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$name, $description, $parentId, $id, getCurrentCompanyId()]);

                logAudit('update', 'category', $id, "Upravena kategorie: $name");
                setFlash('success', 'Kategorie byla úspěšně upravena.');
            }

        } elseif ($action === 'delete') {
            // Delete category
            $id = (int)($_POST['id'] ?? 0);

            // Check if category has items
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM items WHERE category_id = ?");
            $stmt->execute([$id]);
            $itemCount = $stmt->fetch()['count'];

            if ($itemCount > 0) {
                setFlash('error', "Kategorie nelze smazat, obsahuje $itemCount položek.");
            } else {
                // Check if category has subcategories
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM categories WHERE parent_id = ?");
                $stmt->execute([$id]);
                $subCount = $stmt->fetch()['count'];

                if ($subCount > 0) {
                    setFlash('error', "Kategorie nelze smazat, obsahuje $subCount podkategorií.");
                } else {
                    $stmt = $db->prepare("DELETE FROM categories WHERE id = ? AND company_id = ?");
                    $stmt->execute([$id, getCurrentCompanyId()]);

                    logAudit('delete', 'category', $id, "Smazána kategorie");
                    setFlash('success', 'Kategorie byla úspěšně smazána.');
                }
            }
        }

    } catch (Exception $e) {
        error_log("Categories error: " . $e->getMessage());
        setFlash('error', 'Došlo k chybě při zpracování požadavku.');
    }

    redirect('/categories');
}

// Get all categories for current company
try {
    $db = Database::getInstance();

    $stmt = $db->prepare("
        SELECT c.*,
               p.name as parent_name,
               (SELECT COUNT(*) FROM items WHERE category_id = c.id) as item_count,
               (SELECT COUNT(*) FROM categories WHERE parent_id = c.id) as subcat_count
        FROM categories c
        LEFT JOIN categories p ON c.parent_id = p.id
        WHERE c.company_id = ?
        ORDER BY COALESCE(c.parent_id, c.id), c.name
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $categories = $stmt->fetchAll();

    // Get categories for parent dropdown (only top-level)
    $stmt = $db->prepare("
        SELECT id, name
        FROM categories
        WHERE company_id = ? AND parent_id IS NULL
        ORDER BY name
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $parentCategories = $stmt->fetchAll();

} catch (Exception $e) {
    error_log("Categories fetch error: " . $e->getMessage());
    $categories = [];
    $parentCategories = [];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>Kategorie</h1>
    <button type="button" class="btn btn-primary" onclick="openCreateModal()">
        ➕ Nová kategorie
    </button>
</div>

<!-- Categories Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($categories)): ?>
            <div class="empty-state">
                <p>Zatím nemáte vytvořené žádné kategorie.</p>
                <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                    Vytvořit první kategorii
                </button>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Název</th>
                        <th>Nadřazená kategorie</th>
                        <th>Popis</th>
                        <th class="text-center">Položky</th>
                        <th class="text-center">Podkategorie</th>
                        <th class="text-right">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td>
                                <?php if ($category['parent_id']): ?>
                                    <span style="margin-left: 20px;">└─</span>
                                <?php endif; ?>
                                <strong><?= e($category['name']) ?></strong>
                            </td>
                            <td><?= $category['parent_name'] ? e($category['parent_name']) : '—' ?></td>
                            <td><?= e($category['description']) ?: '—' ?></td>
                            <td class="text-center"><?= $category['item_count'] ?></td>
                            <td class="text-center"><?= $category['subcat_count'] ?></td>
                            <td class="text-right">
                                <button type="button" class="btn btn-sm btn-secondary"
                                        onclick='openEditModal(<?= json_encode($category) ?>)'>
                                    ✏️ Upravit
                                </button>
                                <?php if ($category['item_count'] == 0 && $category['subcat_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="confirmDelete(<?= $category['id'] ?>, '<?= e($category['name']) ?>')">
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
<div id="categoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Nová kategorie</h2>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="/categories">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="categoryId">

            <div class="modal-body">
                <div class="form-group">
                    <label for="name">Název kategorie *</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="parent_id">Nadřazená kategorie</label>
                    <select id="parent_id" name="parent_id" class="form-control">
                        <option value="">— Hlavní kategorie —</option>
                        <?php foreach ($parentCategories as $parent): ?>
                            <option value="<?= $parent['id'] ?>"><?= e($parent['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="description">Popis</label>
                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
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
            <h2>Smazat kategorii?</h2>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST" action="/categories">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">

            <div class="modal-body">
                <p>Opravdu chcete smazat kategorii <strong id="deleteName"></strong>?</p>
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
</style>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Nová kategorie';
    document.getElementById('formAction').value = 'create';
    document.getElementById('categoryId').value = '';
    document.getElementById('name').value = '';
    document.getElementById('parent_id').value = '';
    document.getElementById('description').value = '';
    document.getElementById('categoryModal').classList.add('active');
}

function openEditModal(category) {
    document.getElementById('modalTitle').textContent = 'Upravit kategorii';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('categoryId').value = category.id;
    document.getElementById('name').value = category.name;
    document.getElementById('parent_id').value = category.parent_id || '';
    document.getElementById('description').value = category.description || '';
    document.getElementById('categoryModal').classList.add('active');
}

function closeModal() {
    document.getElementById('categoryModal').classList.remove('active');
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
