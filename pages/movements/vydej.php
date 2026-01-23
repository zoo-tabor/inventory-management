<?php
/**
 * Stock Issue (Výdej)
 * Issue goods from warehouse
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Nový výdej';
$db = Database::getInstance();

// Pre-select item if provided
$preselectedItem = (int)($_GET['item'] ?? 0);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        setFlash('error', 'Neplatný bezpečnostní token.');
        redirect('movements/vydej');
    }

    $itemId = (int)$_POST['item_id'];
    $locationId = (int)$_POST['location_id'];
    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $inputType = sanitize($_POST['input_type']); // 'pieces' or 'packages'
    $inputQuantity = (float)$_POST['quantity'];
    $note = sanitize($_POST['note']);
    $movementDate = sanitize($_POST['movement_date']);

    // Validate required fields
    if (!$itemId || !$locationId || $inputQuantity <= 0) {
        setFlash('error', 'Vyplňte všechna povinná pole.');
        redirect('movements/vydej');
    }

    // Get item details
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ? AND company_id = ?");
    $stmt->execute([$itemId, getCurrentCompanyId()]);
    $item = $stmt->fetch();

    if (!$item) {
        setFlash('error', 'Položka nenalezena.');
        redirect('movements/vydej');
    }

    // Calculate quantity in pieces
    if ($inputType === 'packages') {
        $quantityInPieces = packagesToPieces($inputQuantity, $item['pieces_per_package']);
    } else {
        $quantityInPieces = (int)$inputQuantity;
    }

    // Check available stock
    $stmt = $db->prepare("SELECT quantity FROM stock WHERE item_id = ? AND location_id = ?");
    $stmt->execute([$itemId, $locationId]);
    $stockRow = $stmt->fetch();
    $availableStock = $stockRow ? $stockRow['quantity'] : 0;

    if ($quantityInPieces > $availableStock) {
        setFlash('error', "Nedostatečná zásoba. Dostupné množství: {$availableStock} {$item['unit']}");
        redirect('movements/vydej');
    }

    try {
        $db->beginTransaction();

        // Insert movement record
        $stmt = $db->prepare("
            INSERT INTO stock_movements (
                company_id, item_id, location_id, employee_id, department_id, user_id, created_by,
                movement_type, quantity, note, movement_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'vydej', ?, ?, ?)
        ");
        $stmt->execute([
            getCurrentCompanyId(),
            $itemId,
            $locationId,
            $employeeId ?: null,
            $departmentId ?: null,
            $_SESSION['user_id'],
            $_SESSION['user_id'],  // created_by same as user_id
            $quantityInPieces,
            $note,
            $movementDate
        ]);

        $movementId = $db->lastInsertId();

        // Update stock level
        $stmt = $db->prepare("
            UPDATE stock
            SET quantity = quantity - ?
            WHERE item_id = ? AND location_id = ?
        ");
        $stmt->execute([$quantityInPieces, $itemId, $locationId]);

        // Log audit
        logAudit(
            'stock_issue',
            'stock_movement',
            $movementId,
            "Výdej: {$item['name']} (-{$quantityInPieces} {$item['unit']})"
        );

        $db->commit();

        setFlash('success', 'Výdej byl úspěšně zaznamenán.');
        redirect('movements/vydej');

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Stock issue error: " . $e->getMessage());
        setFlash('error', 'Chyba při zaznamenávání výdeje: ' . $e->getMessage());
        redirect('movements/vydej');
    }
}

// Get active items with stock
$stmt = $db->prepare("
    SELECT i.id, i.code, i.name, i.unit, i.pieces_per_package, i.minimum_stock,
           c.name as category_name,
           COALESCE(SUM(s.quantity), 0) as current_stock
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.id
    LEFT JOIN stock s ON i.id = s.item_id
    WHERE i.company_id = ? AND i.is_active = 1
    GROUP BY i.id, i.code, i.name, i.unit, i.pieces_per_package, i.minimum_stock, c.name
    HAVING current_stock > 0
    ORDER BY i.name
");
$stmt->execute([getCurrentCompanyId()]);
$items = $stmt->fetchAll();

// Get active locations
$stmt = $db->prepare("SELECT * FROM locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$locations = $stmt->fetchAll();

// Get active employees
$stmt = $db->prepare("
    SELECT e.*, d.name as department_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.company_id = ? AND e.is_active = 1
    ORDER BY e.full_name
");
$stmt->execute([getCurrentCompanyId()]);
$employees = $stmt->fetchAll();

// Get active departments
$stmt = $db->prepare("SELECT * FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$departments = $stmt->fetchAll();

// Get stock by item and location for dynamic checking
$stmt = $db->prepare("
    SELECT item_id, location_id, quantity
    FROM stock
    WHERE item_id IN (SELECT id FROM items WHERE company_id = ?)
");
$stmt->execute([getCurrentCompanyId()]);
$stockData = [];
foreach ($stmt->fetchAll() as $row) {
    $key = $row['item_id'] . '_' . $row['location_id'];
    $stockData[$key] = $row['quantity'];
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>➖ <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('movements') ?>" class="btn btn-secondary">📋 Historie pohybů</a>
        <a href="<?= url('stock') ?>" class="btn btn-secondary">📦 Přehled skladu</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" id="issueForm">
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
                                (dostupné: <?= formatNumber($item['current_stock']) ?> <?= e($item['unit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="item_dropdown" class="search-dropdown" style="display: none;"></div>
                </div>

                <!-- Location Selection -->
                <div class="form-group">
                    <label for="location_id" class="required">Sklad</label>
                    <select
                        name="location_id"
                        id="location_id"
                        class="form-control"
                        required
                        onchange="handleLocationChange()"
                    >
                        <option value="">-- Vyberte sklad --</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?= $location['id'] ?>">
                                <?= e($location['name']) ?> (<?= e($location['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text" id="locationStockHelper"></small>
                </div>

                <!-- Employee Selection -->
                <div class="form-group">
                    <label for="employee_id">Zaměstnanec</label>
                    <input
                        type="text"
                        id="employee_search"
                        class="form-control"
                        placeholder="Začněte psát jméno zaměstnance..."
                        autocomplete="off"
                    >
                    <select name="employee_id" id="employee_id" class="form-control" style="display: none;">
                        <option value="">-- Vyberte zaměstnance --</option>
                        <?php foreach ($employees as $employee): ?>
                            <option
                                value="<?= $employee['id'] ?>"
                                data-department-id="<?= $employee['department_id'] ?>"
                                data-search-text="<?= e(strtolower($employee['full_name'] . ' ' . ($employee['department_name'] ?? ''))) ?>"
                            >
                                <?= e($employee['full_name']) ?>
                                <?php if ($employee['department_name']): ?>
                                    - <?= e($employee['department_name']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="employee_dropdown" class="search-dropdown" style="display: none;"></div>
                    <small class="form-text">Volitelné - komu byl výdej předán</small>
                </div>

                <!-- Department Selection -->
                <div class="form-group">
                    <label for="department_id_display">Oddělení</label>
                    <input type="hidden" name="department_id" id="department_id_hidden" value="">
                    <select id="department_id_display" class="form-control" disabled>
                        <option value="">-- Vyberte oddělení --</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= $department['id'] ?>">
                                <?= e($department['name']) ?> (<?= e($department['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text">Automaticky vyplněno dle zaměstnance</small>
                </div>

                <!-- Movement Date -->
                <div class="form-group">
                    <label for="movement_date" class="required">Datum výdeje</label>
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
                        placeholder="Účel výdeje, poznámky..."
                    ></textarea>
                </div>
            </div>

            <!-- Item Info Panel -->
            <div id="itemInfo" class="info-panel" style="display: none;">
                <h3>Informace o položce</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Dostupné na skladu:</span>
                        <span class="info-value" id="availableStock">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Minimální stav:</span>
                        <span class="info-value" id="minimumStock">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Stav po výdeji:</span>
                        <span class="info-value" id="afterStock">-</span>
                    </div>
                </div>
                <div id="stockWarning" class="alert alert-warning" style="display: none; margin-top: 1rem;">
                    <strong>⚠️ Upozornění:</strong> <span id="warningText"></span>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">✓ Zaznamenat výdej</button>
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

.form-group {
    position: relative;
}
</style>

<script>
const stockData = <?= json_encode($stockData) ?>;
let selectedItem = null;
let selectedLocation = null;
let selectedEmployeeIndex = -1;
let filteredEmployees = [];

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
        handleLocationChange();
    } else {
        selectedItem = null;
        document.getElementById('itemInfo').style.display = 'none';
    }
}

function handleLocationChange() {
    if (!selectedItem) return;

    const locationSelect = document.getElementById('location_id');
    selectedLocation = locationSelect.value;

    if (selectedLocation) {
        const key = selectedItem.id + '_' + selectedLocation;
        const availableStock = stockData[key] || 0;

        const helper = document.getElementById('locationStockHelper');
        helper.textContent = `Dostupné množství: ${formatNumber(availableStock)} ${selectedItem.unit}`;

        // Update max quantity
        updateItemInfo();
    }

    handleInputTypeChange();
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
    if (!selectedItem || !selectedLocation) return;

    const key = selectedItem.id + '_' + selectedLocation;
    const availableStock = stockData[key] || 0;

    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const inputType = document.getElementById('input_type').value;

    let quantityInPieces;
    if (inputType === 'packages') {
        quantityInPieces = Math.round(quantity * selectedItem.piecesPerPackage);
    } else {
        quantityInPieces = Math.round(quantity);
    }

    const afterStock = availableStock - quantityInPieces;

    document.getElementById('availableStock').textContent =
        formatNumber(availableStock) + ' ' + selectedItem.unit;

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

    // Show warnings
    const warningDiv = document.getElementById('stockWarning');
    const warningText = document.getElementById('warningText');
    const submitBtn = document.getElementById('submitBtn');

    if (quantityInPieces > availableStock) {
        warningDiv.style.display = 'block';
        warningDiv.className = 'alert alert-danger';
        warningText.textContent = 'Požadované množství převyšuje dostupnou zásobu!';
        submitBtn.disabled = true;
    } else if (afterStock < selectedItem.minimumStock) {
        warningDiv.style.display = 'block';
        warningDiv.className = 'alert alert-warning';
        warningText.textContent = 'Po výdeji bude stav pod minimální úrovní.';
        submitBtn.disabled = false;
    } else {
        warningDiv.style.display = 'none';
        submitBtn.disabled = false;
    }
}

function formatNumber(num, decimals = 0) {
    return num.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ' ').replace('.', ',');
}

// Initialize if item is preselected
if (document.getElementById('item_id').value) {
    handleItemChange();
}

// Employee search functionality
const employeeSearchInput = document.getElementById('employee_search');
const employeeSelect = document.getElementById('employee_id');
const employeeDropdown = document.getElementById('employee_dropdown');
const departmentSelect = document.getElementById('department_id_display');
const departmentHidden = document.getElementById('department_id_hidden');

employeeSearchInput.addEventListener('input', function(e) {
    const searchText = e.target.value.toLowerCase().trim();

    if (searchText === '') {
        employeeDropdown.style.display = 'none';
        employeeSelect.value = '';
        departmentSelect.value = '';
        departmentHidden.value = '';
        selectedEmployeeIndex = -1;
        return;
    }

    // Filter employees
    filteredEmployees = [];
    const options = employeeSelect.querySelectorAll('option');

    options.forEach((option, index) => {
        if (index === 0) return; // Skip the placeholder
        const optionSearchText = option.getAttribute('data-search-text');
        if (optionSearchText && optionSearchText.includes(searchText)) {
            filteredEmployees.push({
                value: option.value,
                text: option.textContent.trim(),
                departmentId: option.getAttribute('data-department-id'),
                element: option
            });
        }
    });

    // Display filtered results
    if (filteredEmployees.length > 0) {
        let html = '';
        filteredEmployees.forEach((emp, index) => {
            html += `<div class="search-dropdown-item" data-index="${index}">${emp.text}</div>`;
        });
        employeeDropdown.innerHTML = html;
        employeeDropdown.style.display = 'block';
        selectedEmployeeIndex = -1;
    } else {
        employeeDropdown.innerHTML = '<div class="search-dropdown-item" style="color: #999;">Žádný zaměstnanec nenalezen</div>';
        employeeDropdown.style.display = 'block';
    }
});

employeeSearchInput.addEventListener('keydown', function(e) {
    const items = employeeDropdown.querySelectorAll('.search-dropdown-item');

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (selectedEmployeeIndex < filteredEmployees.length - 1) {
            selectedEmployeeIndex++;
            updateSelectedItem(items);
        }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (selectedEmployeeIndex > 0) {
            selectedEmployeeIndex--;
            updateSelectedItem(items);
        }
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (selectedEmployeeIndex >= 0 && filteredEmployees[selectedEmployeeIndex]) {
            selectEmployee(filteredEmployees[selectedEmployeeIndex]);
        }
    } else if (e.key === 'Escape') {
        employeeDropdown.style.display = 'none';
        selectedEmployeeIndex = -1;
    }
});

function updateSelectedItem(items) {
    items.forEach((item, index) => {
        if (index === selectedEmployeeIndex) {
            item.classList.add('selected');
            item.scrollIntoView({ block: 'nearest' });
        } else {
            item.classList.remove('selected');
        }
    });
}

employeeDropdown.addEventListener('click', function(e) {
    const item = e.target.closest('.search-dropdown-item');
    if (item && item.hasAttribute('data-index')) {
        const index = parseInt(item.getAttribute('data-index'));
        selectEmployee(filteredEmployees[index]);
    }
});

function selectEmployee(employee) {
    employeeSelect.value = employee.value;
    employeeSearchInput.value = employee.text;
    employeeDropdown.style.display = 'none';
    selectedEmployeeIndex = -1;

    // Auto-populate department (both display and hidden field)
    if (employee.departmentId) {
        departmentSelect.value = employee.departmentId;
        departmentHidden.value = employee.departmentId;
    } else {
        departmentSelect.value = '';
        departmentHidden.value = '';
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!employeeSearchInput.contains(e.target) && !employeeDropdown.contains(e.target)) {
        employeeDropdown.style.display = 'none';
        selectedEmployeeIndex = -1;
    }
    if (!itemSearchInput.contains(e.target) && !itemDropdown.contains(e.target)) {
        itemDropdown.style.display = 'none';
        selectedItemIndex = -1;
    }
});

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
            selectItem(filteredItems[selectedItemIndex]);
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
        selectItem(filteredItems[index]);
    }
});

function selectItem(item) {
    itemSelect.value = item.value;
    itemSearchInput.value = item.text;
    itemDropdown.style.display = 'none';
    selectedItemIndex = -1;

    // Trigger the item change handler
    handleItemChange();
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
