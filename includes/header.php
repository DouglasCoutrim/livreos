<?php
// Caminho: /includes/header.php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
Auth::requireLogin();

$pageTitle = $pageTitle ?? 'Dashboard';
$activeModule = $activeModule ?? 'dashboard';
$activeSub = $activeSub ?? '';
$flash = Session::getFlash();
?>
<!doctype html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0B7A55">
    <title>LivreOS – <?= Security::e($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/livreos-icone.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/livreos-icone.png">
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap-grid.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme.css?v=<?= APP_VERSION ?>">
    <script>
        window.LIVREOS_BASE_URL = "<?= BASE_URL ?>";
        // Aplica o estado do menu antes de desenhar a página (evita "piscar")
        try { if (localStorage.getItem('sbCollapsed') === '1') document.documentElement.classList.add('sb-collapsed'); } catch (e) {}
    </script>
    <script src="<?= BASE_URL ?>/assets/js/theme.js?v=<?= APP_VERSION ?>"></script>
</head>
<body>

<?php if ($flash): ?>
    <div id="appToast" class="toast-os" role="status">
        <span><?= Security::e($flash['message']) ?></span>
    </div>
<?php endif; ?>

<div id="sbBackdrop" class="sb-backdrop"></div>

<div class="app-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="app-main">
        <header class="app-header">
            <input type="search" class="search-box" placeholder="Buscar no sistema…" aria-label="Buscar no sistema">
            <div style="flex:1"></div>

            <button id="tg" type="button" class="btn-theme" aria-label="Alternar modo claro/escuro">
                <svg class="ico-moon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"></path></svg>
                <span class="btn-theme-txt">Claro/Escuro</span>
            </button>

            <a href="<?= BASE_URL ?>/modules/financeiro/novo_lancamento.php" class="btn-primary-brand">
                + Novo lançamento
            </a>

            <div class="user-box">
                <div class="user-avatar"><?= Security::e(Auth::iniciais()) ?></div>
                <div class="user-info">
                    <b><?= Security::e(Auth::usuarioNome()) ?></b><br>
                    <span><?= Security::e(Auth::perfilNome()) ?></span>
                    · <a href="<?= BASE_URL ?>/logout.php" class="user-logout">Sair</a>
                </div>
            </div>
        </header>

        <?php require __DIR__ . '/abas.php'; ?>

        <div class="page-content">