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
    <meta name="theme-color" content="#0E9F6E">
    <title>LivreOS – <?= Security::e($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/livreos-icone.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/livreos-icone.png">
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap-grid.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme.css">
    <script>window.LIVREOS_BASE_URL = "<?= BASE_URL ?>";</script>
    <script src="<?= BASE_URL ?>/assets/js/theme.js"></script>
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
                Modo claro/escuro
            </button>

            <a href="<?= BASE_URL ?>/modules/financeiro/novo_lancamento.php" class="btn-primary-brand">
                + Novo lançamento
            </a>

            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:40px;height:40px;border-radius:50%;background:var(--avatar);color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;font-size:14px">
                    <?= Security::e(Auth::iniciais()) ?>
                </div>
                <div style="font-size:13px;line-height:1.3">
                    <b><?= Security::e(Auth::usuarioNome()) ?></b><br>
                    <span style="color:var(--muted)"><?= Security::e(Auth::perfilNome()) ?></span>
                    · <a href="<?= BASE_URL ?>/logout.php" style="color:var(--rtxt);text-decoration:none;font-weight:700;font-size:12px">Sair</a>
                </div>
            </div>
        </header>

        <div class="page-content">