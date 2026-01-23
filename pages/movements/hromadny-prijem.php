<?php
/**
 * Bulk Stock Receipt (Hromadný příjem)
 * Receive multiple items at once by pasting codes and quantities
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$pageTitle = 'Hromadný příjem';
$db = Database::getInstance();

// Get active locations
$stmt = $db->prepare("SELECT * FROM locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([getCurrentCompanyId()]);
$locations = $stmt->fetchAll();

// Get all active items indexed by code for lookup
$stmt = $db->prepare("
    SELECT id, code, name, unit, pieces_per_package
    FROM items
    WHERE company_id = ? AND is_active = 1
");
$stmt->execute([getCurrentCompanyId()]);
$allItems = $stmt->fetchAll();
$itemsByCode = [];
foreach ($allItems as $item) {
    $itemsByCode[$item['code']] = $item;
}

// Handle AJAX parse request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'parse') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $inputText = $_POST['input_text'] ?? '';
        $parsed = [];
        $errors = [];

    // Parse input text - each line is "code quantity"
    $lines = preg_split('/[\r\n]+/', trim($inputText));

    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Try to parse line: code followed by quantity (space, tab, comma, or semicolon separated)
        if (preg_match('/^([^\s,;]+)[\s,;]+(\d+(?:[.,]\d+)?)$/', $line, $matches)) {
            $code = trim($matches[1]);
            $quantity = (float)str_replace(',', '.', $matches[2]);

            if (isset($itemsByCode[$code])) {
                $item = $itemsByCode[$code];
                $piecesPerPackage = $item['pieces_per_package'] ?: 1;
                $totalPieces = ceil($quantity) * $piecesPerPackage; // Always full packages

                $parsed[] = [
                    'item_id' => $item['id'],
                    'code' => $item['code'],
                    'name' => $item['name'],
                    'unit' => $item['unit'],
                    'pieces_per_package' => $piecesPerPackage,
                    'packages' => ceil($quantity),
                    'total_pieces' => $totalPieces
                ];
            } else {
                $errors[] = "Řádek " . ($lineNum + 1) . ": Kód '$code' nenalezen";
            }
        } else if (!empty($line)) {
            $errors[] = "Řádek " . ($lineNum + 1) . ": Neplatný formát '$line'";
        }
    }

        echo json_encode(['success' => true, 'items' => $parsed, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Handle import submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    header('Content-Type: application/json; charset=utf-8');

    if (!validateCsrfToken()) {
        echo json_encode(['success' => false, 'error' => 'Neplatný bezpečnostní token.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $locationId = (int)$_POST['location_id'];
    $items = json_decode($_POST['items'], true);
    $note = sanitize($_POST['note'] ?? '');
    $movementDate = sanitize($_POST['movement_date'] ?? date('Y-m-d'));

    if (!$locationId) {
        echo json_encode(['success' => false, 'error' => 'Vyberte sklad.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($items)) {
        echo json_encode(['success' => false, 'error' => 'Žádné položky k importu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $db->beginTransaction();

        $importedCount = 0;
        $totalPieces = 0;

        foreach ($items as $item) {
            $itemId = (int)$item['item_id'];
            $packages = (float)$item['packages'];
            $piecesInPackages = (int)$item['total_pieces'];

            if ($packages <= 0) continue;

            // Insert movement record (storing packages in quantity_packages)
            $stmt = $db->prepare("
                INSERT INTO stock_movements (
                    company_id, item_id, location_id, user_id, created_by,
                    movement_type, quantity, quantity_packages, note, movement_date
                ) VALUES (?, ?, ?, ?, ?, 'prijem', 0, ?, ?, ?)
            ");
            $stmt->execute([
                getCurrentCompanyId(),
                $itemId,
                $locationId,
                $_SESSION['user_id'],
                $_SESSION['user_id'],
                $packages,
                $note ? $note . ' (hromadný příjem)' : 'Hromadný příjem',
                $movementDate
            ]);

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
                $piecesInPackages,
                $piecesInPackages
            ]);

            $importedCount++;
            $totalPieces += $piecesInPackages;
        }

        // Log audit
        logAudit(
            'bulk_stock_receipt',
            'stock_movement',
            null,
            "Hromadný příjem: $importedCount položek (+$totalPieces ks)"
        );

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => "Úspěšně importováno $importedCount položek ($totalPieces ks)."
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Bulk stock receipt error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Chyba při importu: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>📦 <?= e($pageTitle) ?></h1>
    <div class="page-actions">
        <a href="<?= url('movements') ?>" class="btn btn-secondary">📋 Historie pohybů</a>
        <a href="<?= url('movements/prijem') ?>" class="btn btn-secondary">➕ Jednotlivý příjem</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <?= csrfField() ?>

        <!-- Step 1: Input -->
        <div id="step1" class="step-section">
            <h3>1. Vložte seznam položek</h3>
            <p class="text-muted">Vložte seznam ve formátu "kód množství" (jedno na řádek). Množství je v balení.</p>

            <div class="form-group">
                <textarea
                    id="inputText"
                    class="form-control"
                    rows="10"
                    placeholder="123.100 5&#10;456.200 3&#10;789.300 2"
                    style="font-family: monospace;"
                ></textarea>
            </div>

            <button type="button" class="btn btn-primary" onclick="parseInput()">
                Zpracovat seznam
            </button>
        </div>

        <!-- Step 2: Review and Configure -->
        <div id="step2" class="step-section" style="display: none;">
            <h3>2. Zkontrolujte a upravte</h3>

            <div class="form-row">
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

                <div class="form-group">
                    <label for="note">Poznámka</label>
                    <input
                        type="text"
                        name="note"
                        id="note"
                        class="form-control"
                        placeholder="Volitelná poznámka..."
                    >
                </div>
            </div>

            <!-- Errors -->
            <div id="parseErrors" class="alert alert-warning" style="display: none;"></div>

            <!-- Items table -->
            <div class="table-responsive">
                <table class="table" id="itemsTable">
                    <thead>
                        <tr>
                            <th>Kód</th>
                            <th>Název</th>
                            <th>Balení (bal)</th>
                            <th>Celkem (ks)</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                    </tbody>
                </table>
            </div>

            <div class="summary-row">
                <span id="summaryText">0 položek, 0 ks celkem</span>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="goBack()">
                    ← Zpět
                </button>
                <button type="button" class="btn btn-success btn-lg" onclick="submitImport()">
                    ✓ Importovat vše
                </button>
            </div>
        </div>

        <!-- Step 3: Success -->
        <div id="step3" class="step-section" style="display: none;">
            <div class="success-message">
                <div class="success-icon">✓</div>
                <h3 id="successTitle">Import dokončen</h3>
                <p id="successMessage"></p>
                <div class="btn-group">
                    <a href="<?= url('movements/hromadny-prijem') ?>" class="btn btn-primary">Další import</a>
                    <a href="<?= url('movements') ?>" class="btn btn-secondary">Historie pohybů</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.step-section {
    margin-bottom: 2rem;
}

.step-section h3 {
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.alert-warning {
    background: #fef3c7;
    border: 1px solid #fcd34d;
    color: #92400e;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.alert-warning ul {
    margin: 0.5rem 0 0 1.5rem;
    padding: 0;
}

#itemsTable {
    margin-bottom: 1rem;
}

#itemsTable input[type="number"] {
    width: 80px;
    text-align: center;
}

.summary-row {
    background: #f3f4f6;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1.5rem;
    font-weight: 600;
}

.form-actions {
    display: flex;
    gap: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e5e7eb;
}

.success-message {
    text-align: center;
    padding: 3rem;
}

.success-icon {
    width: 80px;
    height: 80px;
    background: #dcfce7;
    color: #16a34a;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    margin: 0 auto 1.5rem;
}

.success-message h3 {
    border: none;
    margin-bottom: 0.5rem;
}

.success-message p {
    color: #6b7280;
    margin-bottom: 1.5rem;
}

.btn-remove {
    background: #fee2e2;
    color: #dc2626;
    border: none;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    cursor: pointer;
}

.btn-remove:hover {
    background: #fecaca;
}
</style>

<script>
let parsedItems = [];

async function parseInput() {
    const inputText = document.getElementById('inputText').value.trim();

    if (!inputText) {
        alert('Vložte seznam položek.');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'parse');
        formData.append('input_text', inputText);

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON response:', text);
            alert('Chyba při zpracování: Server vrátil neplatnou odpověď');
            return;
        }

        if (result.success) {
            parsedItems = result.items || [];

            if (parsedItems.length === 0 && (!result.errors || result.errors.length === 0)) {
                alert('Nebyly nalezeny žádné platné položky.');
                return;
            }

            // Show errors if any
            const errorsDiv = document.getElementById('parseErrors');
            if (errorsDiv) {
                if (result.errors && result.errors.length > 0) {
                    errorsDiv.innerHTML = '<strong>Varování:</strong><ul>' +
                        result.errors.map(e => `<li>${escapeHtml(e)}</li>`).join('') + '</ul>';
                    errorsDiv.style.display = 'block';
                } else {
                    errorsDiv.style.display = 'none';
                }
            }

            // Render items table
            renderItemsTable();

            // Switch to step 2
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = 'block';
        } else {
            alert('Chyba: ' + (result.error || 'Neznámá chyba'));
        }
    } catch (error) {
        alert('Chyba při zpracování: ' + error.message);
    }
}

function renderItemsTable() {
    const tbody = document.getElementById('itemsBody');
    tbody.innerHTML = '';

    parsedItems.forEach((item, index) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong>${escapeHtml(item.code)}</strong></td>
            <td>${escapeHtml(item.name)}</td>
            <td>
                <input type="number"
                       value="${item.packages}"
                       min="0"
                       step="1"
                       onchange="updatePackages(${index}, this.value)"
                       class="form-control">
                <small class="text-muted">(1 bal = ${item.pieces_per_package} ks)</small>
            </td>
            <td><span id="total-${index}">${item.total_pieces}</span> ${escapeHtml(item.unit)}</td>
            <td>
                <button type="button" class="btn-remove" onclick="removeItem(${index})">✕</button>
            </td>
        `;
        tbody.appendChild(tr);
    });

    updateSummary();
}

function updatePackages(index, value) {
    const packages = Math.max(0, parseInt(value) || 0);
    parsedItems[index].packages = packages;
    parsedItems[index].total_pieces = packages * parsedItems[index].pieces_per_package;
    document.getElementById(`total-${index}`).textContent = parsedItems[index].total_pieces;
    updateSummary();
}

function removeItem(index) {
    parsedItems.splice(index, 1);
    renderItemsTable();
}

function updateSummary() {
    const itemCount = parsedItems.filter(i => i.packages > 0).length;
    const totalPieces = parsedItems.reduce((sum, i) => sum + i.total_pieces, 0);
    document.getElementById('summaryText').textContent =
        `${itemCount} položek, ${formatNumber(totalPieces)} ks celkem`;
}

function goBack() {
    document.getElementById('step1').style.display = 'block';
    document.getElementById('step2').style.display = 'none';
}

async function submitImport() {
    const locationId = document.getElementById('location_id').value;
    const movementDate = document.getElementById('movement_date').value;
    const note = document.getElementById('note').value;

    if (!locationId) {
        alert('Vyberte sklad.');
        return;
    }

    // Filter out items with 0 packages
    const itemsToImport = parsedItems.filter(i => i.packages > 0);

    if (itemsToImport.length === 0) {
        alert('Žádné položky k importu.');
        return;
    }

    if (!confirm(`Opravdu chcete importovat ${itemsToImport.length} položek?`)) {
        return;
    }

    try {
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;

        const formData = new FormData();
        formData.append('action', 'import');
        formData.append('csrf_token', csrfToken);
        formData.append('location_id', locationId);
        formData.append('movement_date', movementDate);
        formData.append('note', note);
        formData.append('items', JSON.stringify(itemsToImport));

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            document.getElementById('successMessage').textContent = result.message;
            document.getElementById('step2').style.display = 'none';
            document.getElementById('step3').style.display = 'block';
        } else {
            alert('Chyba: ' + result.error);
        }
    } catch (error) {
        alert('Chyba při importu: ' + error.message);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
