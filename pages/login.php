<?php
/**
 * Login Page
 * Czech UI with company logo
 */

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    redirect('/dashboard');
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        setFlash('error', 'Vyplňte prosím uživatelské jméno i heslo.');
    } else {
        if (attemptLogin($username, $password)) {
            setFlash('success', 'Přihlášení proběhlo úspěšně.');
            redirect('/dashboard');
        } else {
            setFlash('error', 'Neplatné přihlašovací údaje.');
        }
    }
}

// Get flash messages
$flashMessages = getFlash();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Přihlášení - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-page theme-ekospol">
    <div class="login-container">
        <div class="login-box">
            <!-- Logo -->
            <div class="login-logo">
                <img src="/assets/img/logo-ekospol.png" alt="EKOSPOL" class="company-logo" id="logo-ekospol">
                <img src="/assets/img/logo-zoo.png" alt="ZOO Tábor" class="company-logo" id="logo-zoo" style="display:none;">
            </div>

            <h1>Skladový systém</h1>
            <p class="login-subtitle">Přihlaste se do svého účtu</p>

            <!-- Flash Messages -->
            <?php if (!empty($flashMessages)): ?>
                <?php foreach ($flashMessages as $flash): ?>
                    <div class="alert alert-<?= e($flash['type']) ?>">
                        <?= e($flash['message']) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="/login" class="login-form">
                <?= csrfField() ?>

                <div class="form-group">
                    <label for="username">Uživatelské jméno</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        required
                        autofocus
                        value="<?= e($_POST['username'] ?? '') ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="password">Heslo</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    Přihlásit se
                </button>
            </form>

            <!-- Company Switcher -->
            <div class="login-company-switch">
                <p>Vyberte společnost:</p>
                <div class="company-buttons">
                    <button type="button" class="company-btn active" data-company="ekospol">
                        EKOSPOL
                    </button>
                    <button type="button" class="company-btn" data-company="zoo">
                        ZOO Tábor
                    </button>
                </div>
            </div>

            <div class="login-footer">
                <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></p>
            </div>
        </div>
    </div>

    <script>
        // Company switcher
        document.querySelectorAll('.company-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const company = this.dataset.company;

                // Update active button
                document.querySelectorAll('.company-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                // Switch logo
                document.getElementById('logo-ekospol').style.display = company === 'ekospol' ? 'block' : 'none';
                document.getElementById('logo-zoo').style.display = company === 'zoo' ? 'block' : 'none';

                // Update body theme
                document.body.className = 'login-page theme-' + company;
            });
        });
    </script>
</body>
</html>
