<?php
// Caminho: /includes/abas.php
// Barra de abas do módulo ativo (nível 2 da navegação).
declare(strict_types=1);
require_once __DIR__ . '/../config/modulos.php';

$modulosAbas = livreos_modulos();
$moduloAtual = $modulosAbas[$activeModule ?? ''] ?? null;

if ($moduloAtual !== null && !empty($moduloAtual['items'])):
?>
<nav class="mod-tabs" aria-label="Seções de <?= Security::e($moduloAtual['label']) ?>">
    <?php foreach ($moduloAtual['items'] as $subKey => $sub):
        $ativa = (($activeSub ?? '') === $subKey);
    ?>
        <a href="<?= BASE_URL . $sub[1] ?>" class="mod-tab <?= $ativa ? 'on' : '' ?>" <?= $ativa ? 'aria-current="page"' : '' ?>>
            <?= livreos_svg($sub[2] ?? 'doc', 16) ?>
            <span><?= Security::e($sub[0]) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>