<?php
/**
 * Page Header
 * Includes navigation, company switcher, notifications, user menu
 */

if (!isLoggedIn()) {
    redirect('/login');
}

$currentUser = getCurrentUser();
$currentCompany = getCurrentCompany();
$themeClass = getThemeClass();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?><?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body class="<?= e($themeClass) ?>">
    <div class="app-wrapper">
        <!-- Header -->
        <header class="app-header">
            <div class="header-left">
                <!-- Logo -->
                <div class="header-logo">
                    <img src="/assets/img/<?= e($currentCompany['logo']) ?>" alt="<?= e($currentCompany['name']) ?>" height="40">
                    <span class="app-title"><?= e(APP_NAME) ?></span>
                </div>
            </div>

            <div class="header-right">
                <!-- Company Switcher -->
                <div class="company-switcher">
                    <button type="button" class="company-switcher-btn" id="companySwitcherBtn">
                        <?= e($currentCompany['name']) ?> ▼
                    </button>
                    <div class="company-switcher-dropdown" id="companySwitcherDropdown">
                        <?php foreach (COMPANIES as $compId => $company): ?>
                            <a href="<?= url('switch-company', ['id' => $compId]) ?>"
                               class="company-item <?= $compId === getCurrentCompanyId() ? 'active' : '' ?>">
                                <?= e($company['name']) ?>
                                <?php if ($compId === getCurrentCompanyId()): ?>
                                    <span class="checkmark">✓</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Notifications (placeholder for now) -->
                <div class="header-notifications">
                    <button type="button" class="notification-btn">
                        🔔
                        <span class="notification-badge" style="display:none;">0</span>
                    </button>
                </div>

                <!-- User Menu -->
                <div class="user-menu">
                    <button type="button" class="user-menu-btn" id="userMenuBtn">
                        <span class="user-avatar"><?= strtoupper(substr($currentUser['full_name'], 0, 1)) ?></span>
                        <span class="user-name"><?= e($currentUser['full_name']) ?></span>
                        ▼
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <div class="user-menu-header">
                            <div class="user-menu-name"><?= e($currentUser['full_name']) ?></div>
                            <div class="user-menu-role"><?= e($currentUser['role'] === 'admin' ? 'Administrátor' : 'Uživatel') ?></div>
                        </div>
                        <div class="user-menu-divider"></div>
                        <?php if (isAdmin()): ?>
                            <a href="<?= url('settings') ?>" class="user-menu-item">⚙️ Nastavení</a>
                        <?php endif; ?>
                        <a href="<?= url('logout') ?>" class="user-menu-item text-danger">🚪 Odhlásit se</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Navigation Sidebar -->
        <nav class="app-sidebar">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="<?= url('dashboard') ?>" class="nav-link <?= ($_GET['route'] ?? '') === 'dashboard' ? 'active' : '' ?>">
                        📊 Dashboard
                    </a>
                </li>

                <li class="nav-section">Sklad</li>
                <li class="nav-item">
                    <a href="<?= url('stock') ?>" class="nav-link">
                        📦 Přehled skladu
                    </a>
                </li>

                <li class="nav-section">Pohyby</li>
                <li class="nav-item">
                    <a href="<?= url('movements/vydej') ?>" class="nav-link">
                        ➖ Nový výdej
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('movements/prijem') ?>" class="nav-link">
                        ➕ Nový příjem
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('movements') ?>" class="nav-link">
                        🔄 Historie pohybů
                    </a>
                </li>

                <li class="nav-section">Inventura</li>
                <li class="nav-item">
                    <a href="<?= url('stocktaking') ?>" class="nav-link">
                        📋 Seznam inventur
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('stocktaking/start') ?>" class="nav-link">
                        ✨ Nová inventura
                    </a>
                </li>

                <li class="nav-section">Reporty</li>
                <li class="nav-item">
                    <a href="<?= url('reports/by-department') ?>" class="nav-link">
                        📈 Dle oddělení
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('reports/by-employee') ?>" class="nav-link">
                        👤 Dle zaměstnance
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('reports/by-item') ?>" class="nav-link">
                        📦 Dle položky
                    </a>
                </li>

                <li class="nav-section">Objednávky</li>
                <li class="nav-item">
                    <a href="<?= url('orders') ?>" class="nav-link">
                        🛒 Návrhy objednávek
                    </a>
                </li>

                <?php if (isAdmin()): ?>
                    <li class="nav-section">Správa</li>
                    <li class="nav-item">
                        <a href="<?= url('items') ?>" class="nav-link">
                            📝 Položky
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= url('categories') ?>" class="nav-link">
                            🏷️ Kategorie
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= url('locations') ?>" class="nav-link">
                            📍 Sklady
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= url('departments') ?>" class="nav-link">
                            🏢 Oddělení
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= url('employees') ?>" class="nav-link">
                            👥 Zaměstnanci
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= url('users') ?>" class="nav-link">
                            👤 Uživatelé
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>

        <!-- Main Content Area -->
        <main class="app-content">
            <!-- Flash Messages -->
            <?php
            $flashMessages = getFlash();
            if (!empty($flashMessages)):
            ?>
                <div class="flash-messages">
                    <?php foreach ($flashMessages as $flash): ?>
                        <div class="alert alert-<?= e($flash['type']) ?>">
                            <?= e($flash['message']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Page content starts here -->
