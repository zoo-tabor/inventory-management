<?php
/**
 * Stock Receipt (Příjem)
 * Receive goods into warehouse
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Nový příjem';
$db = Database::getInstance();

// Pre-select item if provided
$preselectedItem = (int)($_GET['item'] ?? 0);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný bezpečnostní token.');
        redirect('movements/prijem');
    }

    $itemId = (int)$_POST['item_id'];
    $locationId = (int)$_POST['location_id'];
    $inputType = sanitize($_POST['input_type']); // 'pieces' or 'packages'
    $inputQuantity = (float)$_POST['quantity'];
    $note = sanitize($_POST['note']);
    $movementDate = sanitize($_POST['movement_date']);

    // Validate required fields
    if (!$itemId || !$locationId || $inputQuantity <= 0) {
        setFlash('error', 'Vyplňte všechna povinná pole.');
        redirect('movements/prijem');
    }

    // Get item details
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ? AND company_id = ?");
    $stmt->execute([$itemId, getCurrentCompanyId()]);
    $item = $stmt->fetch();

    if (!$item) {
        setFlash('error', 'Položka nenalezena.');
        redirect('movements/prijem');
    }

    // Calculate quantity in pieces
    if ($inputType === 'packages') {
        $quantityInPieces = packagesToPieces($inputQuantity, $item['pieces_per_package']);
    } else {
        $quantityInPieces = (int)$inputQuantity;
    }

    try {
        $db->beginTransaction();

        // Insert movement record
        $stmt = $db->prepare("
            INSERT INTO stock_movements (
                company_id, item_id, location_id, user_id, created_by,
                movement_type, quantity, note, movement_date
            ) VALUES (?, ?, ?, ?, ?, 'prijem', ?, ?, ?)
        ");
        $stmt->execute([
            getCurrentCompanyId(),
            $itemId,
            $locationId,
            $_SESSION['user_id'],
            $_SESSION['user_id'],  // created_by same as user_id
            $quantityInPieces,
            $note,
            $movementDate
        ]);

        $movementId = $db->lastInsertId();

        // Update stock level
        $stmt = $db->prepare("
            INSERT INTO stock (company_id, item_id, location_id, quantity)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + ?
        ");
        $stmt->execute([
            getCurrentCompanyId(),
            $itemId,
            $locationId,
            $quantityInPieces,
            $quantityInPieces
        ]);

        // Log audit
        logAudit(
            'stock_receipt',
            'stock_movement',
            $movementId,
            "Příjem: {$item['name']} (+{$quantityInPieces} {$item['unit']})"
        );

        $db->commit();

        setFlash('success', 'Příjem byl úspěšně zaznamenán.');
        redirect('movements/prijem');

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Stock receipt error: " . $e->getMessage());
        setFlash('error', 'Chyba při zaznamenávání příjmu: ' . $e->getMessage());
        redirect('movements/prijem');
    }
}

// Get active items
$stmt = $db->prepare("
    SELECT i.id, i.code, i.name, i.unit, i.pieces_per_package, i.minimum_stock,
           c.name as category_name,
           COALESCE(SUM(s.quantity), 0) as current_stock
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.id
    LEFT JOIN stock s ON i.id = s.item_id
    WHERE i.company_id = ? AND i.is_active = 1
    GROUP BY i.id, i.code, i.name, i.unit, i.pieces_per_package, i.minimum_stock, c.name
    ORDER BY i.name
");
$stmt->execute([getCurrentCompanyId()]);
$items = $stmt->fetchAll();

// Get active locations
$stmt = $db->prepare("SELECT * FROM locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$locations = $stmt->fetchAll();

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>➕ <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('movements/hromadny-prijem') ?>" class="btn btn-success">📦 Hromadný příjem</a>
        <a href="<?= url('movements') ?>" class="btn btn-secondary">📋 Historie pohybů</a>
        <a href="<?= url('stock') ?>" class="btn btn-secondary">📦 Přehled skladu</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" id="receiptForm">
            <?= csrfField() ?>

            <div class="form-grid">
                <!-- Item Selection -->
                <div class="form-group">
                    <label for="item_id" class="required">Položka</label>
                    <input
                        type="text"
                        id="item_search"
                        class="form-control"
                        placeholder="Začněte psát kód nebo název položky..."
                        autocomplete="off"
                    >
                    <select
                        name="item_id"
                        id="item_id"
                        class="form-control"
                        required
                        onchange="handleItemChange()"
                        style="display: none;"
                    >
                        <option value="">-- Vyberte položku --</option>
                        <?php foreach ($items as $item): ?>
                            <option
                                value="<?= $item['id'] ?>"
                                data-unit="<?= e($item['unit']) ?>"
                                data-pieces-per-package="<?= $item['pieces_per_package'] ?>"
                                data-current-stock="<?= $item['current_stock'] ?>"
                                data-minimum-stock="<?= $item['minimum_stock'] ?>"
                                data-search-text="<?= e(strtolower($item['code'] . ' ' . $item['name'])) ?>"
                                <?= $preselectedItem === $item['id'] ? 'selected' : '' ?>
                            >
                                <?= e($item['code']) ?> - <?= e($item['name']) ?>
                                (aktuálně: <?= formatNumber($item['current_stock']) ?> <?= e($item['unit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="item_dropdown" class="search-dropdown" style="display: none;"></div>
                </div>

                <!-- Location Selection -->
                <div class="form-group">
                    <label for="location_id" class="required">Sklad</label>
                    <select name="location_id" id="location_id" class="form-control" required>
                        <option value="">-- Vyberte sklad --</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?= $location['id'] ?>">
                                <?= e($location['name']) ?> (<?= e($location['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Movement Date -->
                <div class="form-group">
                    <label for="movement_date" class="required">Datum příjmu</label>
                    <input
                        type="date"
                        name="movement_date"
                        id="movement_date"
                        class="form-control"
                        value="<?= date('Y-m-d') ?>"
                        max="<?= date('Y-m-d') ?>"
                        required
                    >
                </div>

                <!-- Input Type Selection -->
                <div class="form-group">
                    <label for="input_type" class="required">Typ vstupu</label>
                    <select name="input_type" id="input_type" class="form-control" onchange="handleInputTypeChange()" required>
                        <option value="pieces">Kusy</option>
                        <option value="packages">Balení</option>
                    </select>
                </div>

                <!-- Quantity Input -->
                <div class="form-group">
                    <label for="quantity" class="required">Množství</label>
                    <input
                        type="number"
                        name="quantity"
                        id="quantity"
                        class="form-control"
                        min="0.01"
                        step="0.01"
                        required
                        onchange="handleQuantityChange()"
                    >
                    <small class="form-text" id="quantityHelper"></small>
                </div>

                <!-- Note -->
                <div class="form-group full-width">
                    <label for="note">Poznámka</label>
                    <textarea
                        name="note"
                        id="note"
                        class="form-control"
                        rows="3"
                        placeholder="Dodavatel, číslo objednávky, poznámky..."
                    ></textarea>
                </div>
            </div>

            <!-- Item Info Panel -->
            <div id="itemInfo" class="info-panel" style="display: none;">
                <h3>Informace o položce</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Aktuální stav:</span>
                        <span class="info-value" id="currentStock">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Minimální stav:</span>
                        <span class="info-value" id="minimumStock">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Stav po příjmu:</span>
                        <span class="info-value" id="afterStock">-</span>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-success btn-lg">✓ Zaznamenat příjem</button>
                <a href="<?= url('stock') ?>" class="btn btn-secondary">Zrušit</a>
            </div>
        </form>
    </div>
</div>

<style>
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.info-panel {
    background: #f9fafb;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.info-panel h3 {
    margin: 0 0 1rem 0;
    font-size: 1rem;
    color: #374151;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
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
    font-size: 1.25rem;
    font-weight: 700;
    color: #111827;
}

.form-actions {
    display: flex;
    gap: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e5e7eb;
}

.form-group {
    position: relative;
}

.search-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #d1d5db;
    border-top: none;
    border-radius: 0 0 4px 4px;
    max-height: 300px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.search-dropdown-item {
    padding: 10px 15px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
}

.search-dropdown-item:hover {
    background: #f9fafb;
}

.search-dropdown-item:last-child {
    border-bottom: none;
}

.search-dropdown-item.selected {
    background: #e0f2fe;
}
</style>

<script>
let selectedItem = null;

function handleItemChange() {
    const select = document.getElementById('item_id');
    const option = select.options[select.selectedIndex];

    if (option.value) {
        selectedItem = {
            id: option.value,
            unit: option.dataset.unit,
            piecesPerPackage: parseInt(option.dataset.piecesPerPackage),
            currentStock: parseInt(option.dataset.currentStock),
            minimumStock: parseInt(option.dataset.minimumStock)
        };

        document.getElementById('itemInfo').style.display = 'block';
        updateItemInfo();
        handleInputTypeChange();
    } else {
        selectedItem = null;
        document.getElementById('itemInfo').style.display = 'none';
    }
}

function handleInputTypeChange() {
    if (!selectedItem) return;

    const inputType = document.getElementById('input_type').value;
    const quantityInput = document.getElementById('quantity');

    if (inputType === 'packages') {
        quantityInput.step = '0.01';
        quantityInput.placeholder = 'Počet balení';
    } else {
        quantityInput.step = '1';
        quantityInput.placeholder = 'Počet kusů';
    }

    updateItemInfo();
}

function handleQuantityChange() {
    updateItemInfo();
}

function updateItemInfo() {
    if (!selectedItem) return;

    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const inputType = document.getElementById('input_type').value;

    let quantityInPieces;
    if (inputType === 'packages') {
        quantityInPieces = Math.round(quantity * selectedItem.piecesPerPackage);
    } else {
        quantityInPieces = Math.round(quantity);
    }

    const afterStock = selectedItem.currentStock + quantityInPieces;

    document.getElementById('currentStock').textContent =
        formatNumber(selectedItem.currentStock) + ' ' + selectedItem.unit;

    document.getElementById('minimumStock').textContent =
        formatNumber(selectedItem.minimumStock) + ' ' + selectedItem.unit;

    document.getElementById('afterStock').textContent =
        formatNumber(afterStock) + ' ' + selectedItem.unit;

    // Update quantity helper text
    const helper = document.getElementById('quantityHelper');
    if (inputType === 'packages' && selectedItem.piecesPerPackage > 1) {
        helper.textContent = `= ${formatNumber(quantityInPieces)} ${selectedItem.unit}`;
    } else if (inputType === 'pieces' && selectedItem.piecesPerPackage > 1) {
        const packages = quantity / selectedItem.piecesPerPackage;
        helper.textContent = `= ${formatNumber(packages, 2)} balení`;
    } else {
        helper.textContent = '';
    }
}

function formatNumber(num, decimals = 0) {
    return num.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ' ').replace('.', ',');
}

// Initialize if item is preselected
if (document.getElementById('item_id').value) {
    handleItemChange();
}

// Item search functionality
const itemSearchInput = document.getElementById('item_search');
const itemSelect = document.getElementById('item_id');
const itemDropdown = document.getElementById('item_dropdown');
let selectedItemIndex = -1;
let filteredItems = [];

// Initialize with preselected item
if (itemSelect.value) {
    const selectedOption = itemSelect.options[itemSelect.selectedIndex];
    if (selectedOption) {
        itemSearchInput.value = selectedOption.textContent.trim();
    }
}

itemSearchInput.addEventListener('focus', function() {
    // Show all items on focus if no search text
    showItemDropdown(this.value);
});

itemSearchInput.addEventListener('input', function(e) {
    showItemDropdown(e.target.value);
});

function showItemDropdown(searchText) {
    searchText = searchText.toLowerCase().trim();

    // Filter items
    filteredItems = [];
    const options = itemSelect.querySelectorAll('option');

    options.forEach((option, index) => {
        if (index === 0) return; // Skip the placeholder
        const optionSearchText = option.getAttribute('data-search-text') || '';
        const optionDisplayText = option.textContent.trim().toLowerCase();

        if (!searchText || optionSearchText.includes(searchText) || optionDisplayText.includes(searchText)) {
            filteredItems.push({
                value: option.value,
                text: option.textContent.trim(),
                unit: option.dataset.unit,
                piecesPerPackage: option.dataset.piecesPerPackage,
                currentStock: option.dataset.currentStock,
                minimumStock: option.dataset.minimumStock,
                element: option
            });
        }
    });

    // Display filtered results
    if (filteredItems.length > 0) {
        let html = '';
        filteredItems.forEach((item, index) => {
            html += `<div class="search-dropdown-item" data-index="${index}">${item.text}</div>`;
        });
        itemDropdown.innerHTML = html;
        itemDropdown.style.display = 'block';
        selectedItemIndex = -1;
    } else {
        itemDropdown.innerHTML = '<div class="search-dropdown-item" style="color: #999;">Žádná položka nenalezena</div>';
        itemDropdown.style.display = 'block';
    }
}

itemSearchInput.addEventListener('keydown', function(e) {
    const items = itemDropdown.querySelectorAll('.search-dropdown-item[data-index]');

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (selectedItemIndex < filteredItems.length - 1) {
            selectedItemIndex++;
            updateSelectedItemHighlight(items);
        }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (selectedItemIndex > 0) {
            selectedItemIndex--;
            updateSelectedItemHighlight(items);
        }
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (selectedItemIndex >= 0 && filteredItems[selectedItemIndex]) {
            selectItemFromDropdown(filteredItems[selectedItemIndex]);
        }
    } else if (e.key === 'Escape') {
        itemDropdown.style.display = 'none';
        selectedItemIndex = -1;
    }
});

function updateSelectedItemHighlight(items) {
    items.forEach((item, index) => {
        if (index === selectedItemIndex) {
            item.classList.add('selected');
            item.scrollIntoView({ block: 'nearest' });
        } else {
            item.classList.remove('selected');
        }
    });
}

itemDropdown.addEventListener('click', function(e) {
    const item = e.target.closest('.search-dropdown-item');
    if (item && item.hasAttribute('data-index')) {
        const index = parseInt(item.getAttribute('data-index'));
        selectItemFromDropdown(filteredItems[index]);
    }
});

function selectItemFromDropdown(item) {
    itemSelect.value = item.value;
    itemSearchInput.value = item.text;
    itemDropdown.style.display = 'none';
    selectedItemIndex = -1;

    // Trigger the item change handler
    handleItemChange();
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!itemSearchInput.contains(e.target) && !itemDropdown.contains(e.target)) {
        itemDropdown.style.display = 'none';
        selectedItemIndex = -1;
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
