<?php
/**
 * Dashboard Page
 * Overview of stock, recent movements, alerts
 */

$pageTitle = 'Dashboard';
$currentCompany = getCurrentCompany();

// Get basic statistics
try {
    $db = Database::getInstance();

    // Total items
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM items WHERE company_id = ? AND is_active = 1");
    $stmt->execute([getCurrentCompanyId()]);
    $totalItems = $stmt->fetch()['total'];

    // Low stock items
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT i.id) as total
        FROM items i
        LEFT JOIN stock s ON i.id = s.item_id
        WHERE i.company_id = ? AND i.is_active = 1
        AND (s.quantity IS NULL OR s.quantity <= i.minimum_stock)
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $lowStockItems = $stmt->fetch()['total'];

    // Recent movements (last 7 days)
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM stock_movements sm
        JOIN items i ON sm.item_id = i.id
        WHERE i.company_id = ?
        AND sm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $recentMovements = $stmt->fetch()['total'];

    // Active stocktakings
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM stocktakings st
        JOIN locations l ON st.location_id = l.id
        WHERE l.company_id = ?
        AND st.status IN ('in_progress', 'review')
    ");
    $stmt->execute([getCurrentCompanyId()]);
    $activeStocktakings = $stmt->fetch()['total'];

} catch (Exception $e) {
    $totalItems = 0;
    $lowStockItems = 0;
    $recentMovements = 0;
    $activeStocktakings = 0;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="dashboard">
    <div class="page-header">
        <h1>Dashboard</h1>
        <p class="text-secondary">Přehled skladu pro <?= e($currentCompany['name']) ?></p>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon stat-icon-primary">📦</div>
            <div class="stat-content">
                <div class="stat-label">Aktivní položky</div>
                <div class="stat-value"><?= formatNumber($totalItems) ?></div>
            </div>
        </div>

        <div class="stat-card stat-card-warning">
            <div class="stat-icon stat-icon-warning">⚠️</div>
            <div class="stat-content">
                <div class="stat-label">Nízký stav</div>
                <div class="stat-value"><?= formatNumber($lowStockItems) ?></div>
            </div>
            <?php if ($lowStockItems > 0): ?>
                <a href="/stock?filter=low" class="stat-link">Zobrazit →</a>
            <?php endif; ?>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-info">🔄</div>
            <div class="stat-content">
                <div class="stat-label">Pohyby (7 dní)</div>
                <div class="stat-value"><?= formatNumber($recentMovements) ?></div>
            </div>
            <a href="<?= url('movements') ?>" class="stat-link">Zobrazit →</a>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-success">📋</div>
            <div class="stat-content">
                <div class="stat-label">Aktivní inventury</div>
                <div class="stat-value"><?= formatNumber($activeStocktakings) ?></div>
            </div>
            <?php if ($activeStocktakings > 0): ?>
                <a href="<?= url('stocktaking') ?>" class="stat-link">Zobrazit →</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <h2>Rychlé akce</h2>
        <div class="action-grid">
            <a href="<?= url('movements/vydej') ?>" class="action-card">
                <span class="action-icon">➖</span>
                <span class="action-title">Nový výdej</span>
                <span class="action-desc">Zaznamenat vydání zboží</span>
            </a>

            <a href="<?= url('movements/prijem') ?>" class="action-card">
                <span class="action-icon">➕</span>
                <span class="action-title">Nový příjem</span>
                <span class="action-desc">Zaznamenat příjem zboží</span>
            </a>

            <a href="<?= url('stock') ?>" class="action-card">
                <span class="action-icon">📦</span>
                <span class="action-title">Přehled skladu</span>
                <span class="action-desc">Zobrazit aktuální stav</span>
            </a>

            <a href="<?= url('stocktaking/start') ?>" class="action-card">
                <span class="action-icon">📋</span>
                <span class="action-title">Nová inventura</span>
                <span class="action-desc">Zahájit inventuru skladu</span>
            </a>
        </div>
    </div>

    <!-- Welcome Message -->
    <div class="welcome-message">
        <h3>Vítejte v systému!</h3>
        <p>Aktuálně pracujete se společností <strong><?= e($currentCompany['name']) ?></strong>.</p>
        <p>Pro přepnutí společnosti použijte přepínač v horní liště.</p>
    </div>
</div>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: var(--spacing-lg);
        margin-bottom: var(--spacing-xl);
    }

    .stat-card {
        background: white;
        padding: var(--spacing-lg);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-sm);
        display: flex;
        align-items: center;
        gap: var(--spacing-md);
        position: relative;
    }

    .stat-card-warning {
        border-left: 4px solid var(--status-low);
    }

    .stat-icon {
        font-size: 2.5rem;
    }

    .stat-content {
        flex: 1;
    }

    .stat-label {
        font-size: 0.875rem;
        color: var(--text-secondary);
        margin-bottom: var(--spacing-xs);
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .stat-link {
        position: absolute;
        bottom: var(--spacing-md);
        right: var(--spacing-md);
        font-size: 0.875rem;
        color: var(--primary);
    }

    .quick-actions {
        margin-bottom: var(--spacing-xl);
    }

    .action-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: var(--spacing-md);
        margin-top: var(--spacing-md);
    }

    .action-card {
        background: white;
        padding: var(--spacing-lg);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-sm);
        text-decoration: none;
        transition: all 0.15s ease-in-out;
        display: flex;
        flex-direction: column;
        gap: var(--spacing-sm);
    }

    .action-card:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
        text-decoration: none;
    }

    .action-icon {
        font-size: 2rem;
    }

    .action-title {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 1.125rem;
    }

    .action-desc {
        font-size: 0.875rem;
        color: var(--text-secondary);
    }

    .welcome-message {
        background: white;
        padding: var(--spacing-lg);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-sm);
    }

    .welcome-message h3 {
        color: var(--primary);
        margin-bottom: var(--spacing-md);
    }

    .welcome-message p {
        margin-bottom: var(--spacing-sm);
    }

    .welcome-message p:last-child {
        margin-bottom: 0;
    }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
