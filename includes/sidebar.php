<?php
// Caminho: /includes/sidebar.php
// Sidebar com módulos (nível 1). Os submenus aparecem como abas no topo (includes/abas.php).
declare(strict_types=1);
require_once __DIR__ . '/../config/modulos.php';

$modulos = livreos_modulos();
?>
<aside id="appSidebar" class="sb" aria-label="Menu principal">
    <div class="sb-brand">
        <a href="<?= BASE_URL ?>/dashboard.php" aria-label="LivreOS – Dashboard">
            <img src="<?= BASE_URL ?>/assets/livreos-logo.png" alt="LivreOS" class="brand-logo brand-full">
            <img src="<?= BASE_URL ?>/assets/livreos-icone.png" alt="LivreOS" class="brand-logo brand-mini">
        </a>
    </div>

    <nav class="sb-nav">
        <a class="ni <?= ($activeModule === 'dashboard') ? 'on' : '' ?>" href="<?= BASE_URL ?>/dashboard.php" title="Dashboard"
           <?= ($activeModule === 'dashboard') ? 'aria-current="page"' : '' ?>>
            <?= livreos_svg('home') ?>
            <span class="lbl">Dashboard</span>
        </a>

        <?php foreach ($modulos as $modKey => $mod):
            $ativo = ($activeModule === $modKey);
        ?>
            <a class="ni <?= $ativo ? 'on' : '' ?>" href="<?= BASE_URL . livreos_modulo_url($mod) ?>"
               title="<?= Security::e($mod['label']) ?>" <?= $ativo ? 'aria-current="page"' : '' ?>>
                <?= livreos_svg($mod['icone']) ?>
                <span class="lbl"><?= Security::e($mod['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sb-foot">
        <button type="button" id="sbCollapse" class="ni" title="Recolher menu" aria-label="Recolher menu">
            <svg class="sb-collapse-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"></path></svg>
            <span class="lbl">Recolher menu</span>
        </button>
    </div>
</aside>