<?php
/**
 * Start New Stocktaking
 * Initialize inventory count session
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Nová inventura';
$db = Database::getInstance();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný bezpečnostní token.');
        redirect('stocktaking/start');
    }

    $locationId = (int)($_POST['location_id'] ?? 0);
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $includeZeroStock = isset($_POST['include_zero_stock']);

    try {
        $db->beginTransaction();

        // Create stocktaking record
        $stmt = $db->prepare("
            INSERT INTO stocktakings (company_id, location_id, user_id, status)
            VALUES (?, ?, ?, 'in_progress')
        ");
        $stmt->execute([
            getCurrentCompanyId(),
            $locationId ?: null,
            $_SESSION['user_id']
        ]);

        $stocktakingId = $db->lastInsertId();

        // Build query to get items
        $whereClauses = ['i.company_id = ?', 'i.is_active = 1'];
        $params = [getCurrentCompanyId()];

        if ($categoryId > 0) {
            $whereClauses[] = 'i.category_id = ?';
            $params[] = $categoryId;
        }

        $whereSQL = implode(' AND ', $whereClauses);

        // Get items with current stock
        if ($locationId > 0) {
            // Specific location
            $stmt = $db->prepare("
                SELECT
                    i.id as item_id,
                    COALESCE(s.quantity, 0) as current_stock
                FROM items i
                LEFT JOIN stock s ON i.id = s.item_id AND s.location_id = ?
                WHERE $whereSQL
                ORDER BY i.name
            ");
            $params = [$locationId, ...$params];
            $stmt->execute($params);
        } else {
            // All locations - sum up stock
            $stmt = $db->prepare("
                SELECT
                    i.id as item_id,
                    COALESCE(SUM(s.quantity), 0) as current_stock
                FROM items i
                LEFT JOIN stock s ON i.id = s.item_id
                WHERE $whereSQL
                GROUP BY i.id
                ORDER BY i.name
            ");
            $stmt->execute($params);
        }

        $items = $stmt->fetchAll();

        // Insert stocktaking items
        $insertStmt = $db->prepare("
            INSERT INTO stocktaking_items (stocktaking_id, item_id, expected_quantity)
            VALUES (?, ?, ?)
        ");

        $itemCount = 0;
        foreach ($items as $item) {
            // Skip zero stock items if not included
            if (!$includeZeroStock && $item['current_stock'] == 0) {
                continue;
            }

            $insertStmt->execute([
                $stocktakingId,
                $item['item_id'],
                $item['current_stock']
            ]);
            $itemCount++;
        }

        if ($itemCount === 0) {
            $db->rollBack();
            setFlash('error', 'Nebyla nalezena žádná položka pro inventuru. Zkuste změnit filtry.');
            redirect('stocktaking/start');
        }

        logAudit(
            'stocktaking_start',
            'stocktaking',
            $stocktakingId,
            "Zahájena inventura #{$stocktakingId} - {$itemCount} položek"
        );

        $db->commit();

        setFlash('success', "Inventura byla zahájena. Celkem {$itemCount} položek k inventuře.");
        redirect('stocktaking/count', ['id' => $stocktakingId]);

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Stocktaking start error: " . $e->getMessage());
        setFlash('error', 'Chyba při zahájení inventury: ' . $e->getMessage());
        redirect('stocktaking/start');
    }
}

// Get categories
$stmt = $db->prepare("SELECT id, name FROM categories WHERE company_id = ? ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$categories = $stmt->fetchAll();

// Get locations
$stmt = $db->prepare("SELECT id, name, code FROM locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$locations = $stmt->fetchAll();

// Get item counts for preview
$stmt = $db->prepare("
    SELECT
        COUNT(DISTINCT i.id) as total_items,
        COUNT(DISTINCT CASE WHEN COALESCE(SUM(s.quantity), 0) > 0 THEN i.id END) as items_with_stock
    FROM items i
    LEFT JOIN stock s ON i.id = s.item_id
    WHERE i.company_id = ? AND i.is_active = 1
");
$stmt->execute([getCurrentCompanyId()]);
$itemStats = $stmt->fetch();

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>✨ <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('stocktaking') ?>" class="btn btn-secondary">📋 Seznam inventur</a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h2>Nastavení inventury</h2>
            </div>
            <div class="card-body">
                <form method="POST" id="stocktakingForm">
                    <?= csrfField() ?>

                    <div class="form-group">
                        <label for="location_id">Sklad</label>
                        <select name="location_id" id="location_id" class="form-control">
                            <option value="">Všechny sklady</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= $location['id'] ?>">
                                    <?= e($location['name']) ?> (<?= e($location['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text">
                            Vyberte konkrétní sklad nebo ponechte prázdné pro inventuru všech skladů.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="category_id">Kategorie</label>
                        <select name="category_id" id="category_id" class="form-control">
                            <option value="">Všechny kategorie</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>">
                                    <?= e($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text">
                            Volitelně omezit inventuru na konkrétní kategorii.
                        </small>
                    </div>

                    <div class="form-group">
                        <div class="form-check">
                            <input
                                type="checkbox"
                                name="include_zero_stock"
                                id="include_zero_stock"
                                class="form-check-input"
                            >
                            <label for="include_zero_stock" class="form-check-label">
                                Zahrnout položky s nulovým stavem
                            </label>
                        </div>
                        <small class="form-text">
                            Zaškrtněte pro inventuru i položek, které jsou aktuálně vyprodané.
                        </small>
                    </div>

                    <div class="alert alert-info">
                        <strong>ℹ️ Informace:</strong>
                        <ul>
                            <li>Inventura vytvoří seznam položek s jejich očekávaným stavem</li>
                            <li>Budete moci zadat skutečně napočítané množství</li>
                            <li>Po dokončení inventury budou automaticky provedeny skladové úpravy</li>
                            <li>Všechny změny budou zaznamenány v historii pohybů</li>
                        </ul>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-lg">
                            ✓ Zahájit inventuru
                        </button>
                        <a href="<?= url('stocktaking') ?>" class="btn btn-secondary">
                            Zrušit
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h3>Statistiky</h3>
            </div>
            <div class="card-body">
                <div class="stat-item">
                    <div class="stat-label">Celkem aktivních položek:</div>
                    <div class="stat-value"><?= formatNumber($itemStats['total_items']) ?></div>
                </div>

                <div class="stat-item">
                    <div class="stat-label">Položek s aktuálním stavem:</div>
                    <div class="stat-value"><?= formatNumber($itemStats['items_with_stock']) ?></div>
                </div>

                <div class="stat-item">
                    <div class="stat-label">Položek s nulovým stavem:</div>
                    <div class="stat-value">
                        <?= formatNumber($itemStats['total_items'] - $itemStats['items_with_stock']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <h3>Průběh inventury</h3>
            </div>
            <div class="card-body">
                <ol class="process-list">
                    <li><strong>Zahájení:</strong> Nastavení parametrů inventury</li>
                    <li><strong>Počítání:</strong> Zadání napočítaných množství</li>
                    <li><strong>Kontrola:</strong> Přehled rozdílů</li>
                    <li><strong>Dokončení:</strong> Úprava skladových stavů</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<style>
.row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1.5rem;
}

.card-header h2,
.card-header h3 {
    margin: 0;
    font-size: 1.25rem;
}

.form-check {
    padding-left: 1.5rem;
}

.form-check-input {
    margin-left: -1.5rem;
    margin-top: 0.25rem;
}

.form-check-label {
    font-weight: 600;
}

.stat-item {
    padding: 1rem 0;
    border-bottom: 1px solid #e5e7eb;
}

.stat-item:last-child {
    border-bottom: none;
}

.stat-label {
    font-size: 0.875rem;
    color: #6b7280;
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #111827;
}

.process-list {
    padding-left: 1.5rem;
    margin: 0;
}

.process-list li {
    padding: 0.5rem 0;
    line-height: 1.5;
}

.form-actions {
    display: flex;
    gap: 1rem;
    padding-top: 1.5rem;
    margin-top: 1.5rem;
    border-top: 1px solid #e5e7eb;
}
</style>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
