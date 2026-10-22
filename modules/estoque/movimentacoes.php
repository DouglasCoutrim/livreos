<?php
// Caminho: /modules/estoque/movimentacoes.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('estoque.ver')) {
    Session::setFlash('error', 'Sem permissão para aceder ao módulo de Estoque.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();

// ============================================================================
// FILTROS E PAGINAÇÃO
// ============================================================================
$busca = trim($_GET['busca'] ?? '');
$fTipo = trim($_GET['tipo'] ?? '');
$dIni = trim($_GET['data_ini'] ?? date('Y-m-01'));
$dFim = trim($_GET['data_fim'] ?? date('Y-m-t'));

$where = ["m.empresa_id = :emp"];
$params = [':emp' => $empresaId];

if ($busca !== '') {
    $where[] = "(p.nome LIKE :b1 OR p.sku LIKE :b2 OR m.motivo LIKE :b3)";
    $params[':b1'] = "%$busca%";
    $params[':b2'] = "%$busca%";
    $params[':b3'] = "%$busca%";
}
if ($fTipo !== '') {
    $where[] = "m.tipo = :tipo";
    $params[':tipo'] = $fTipo;
}
if ($dIni !== '' && $dFim !== '') {
    // Adiciona 23:59:59 ao final do dia para apanhar movimentações com hora
    $where[] = "m.data_movimentacao BETWEEN :ini AND :fim";
    $params[':ini'] = $dIni . ' 00:00:00';
    $params[':fim'] = $dFim . ' 23:59:59';
}

$whereClause = implode(" AND ", $where);

// Paginação
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$stmtCount = $db->prepare("
    SELECT COUNT(m.id) 
    FROM estoque_movimentacoes m
    LEFT JOIN produtos p ON m.produto_id = p.id
    WHERE $whereClause
");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Tabela principal
$stmtList = $db->prepare("
    SELECT 
        m.*, 
        p.nome AS produto_nome, 
        p.sku 
    FROM estoque_movimentacoes m
    LEFT JOIN produtos p ON m.produto_id = p.id
    WHERE $whereClause 
    ORDER BY m.data_movimentacao DESC, m.id DESC 
    LIMIT $limit OFFSET $offset
");
$stmtList->execute($params);
$movimentacoes = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// ============================================================================
// KPIs DO PERÍODO
// ============================================================================
$stmtKpis = $db->prepare("
    SELECT 
        COUNT(id) as total_movs,
        SUM(CASE WHEN tipo = 'entrada' THEN quantidade ELSE 0 END) as volume_entradas,
        SUM(CASE WHEN tipo = 'saida' THEN quantidade ELSE 0 END) as volume_saidas,
        SUM(CASE WHEN tipo = 'ajuste' THEN quantidade ELSE 0 END) as volume_ajustes
    FROM estoque_movimentacoes m
    WHERE empresa_id = :emp AND data_movimentacao BETWEEN :ini AND :fim
");
$stmtKpis->execute([
    ':emp' => $empresaId, 
    ':ini' => $dIni . ' 00:00:00', 
    ':fim' => $dFim . ' 23:59:59'
]);
$kpis = $stmtKpis->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Movimentações de Estoque';
$activeModule = 'estoque';
$activeSub = 'movimentacoes';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Cabeçalho do Módulo -->
<div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
    <div>
        <div style="color: var(--primary); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Módulo Estoque</div>
        <h1 class="ct" style="font-size: 28px; font-weight: 800; margin: 0; line-height: 1.2;">Histórico de Movimentações</h1>
        <div class="kl" style="margin-top: 4px;">Registo auditável de entradas, saídas e ajustes de inventário.</div>
    </div>
    <a href="<?= BASE_URL ?>/modules/estoque/estoques.php" class="btn btn-theme" style="text-decoration:none;">Ir para Posição Atual</a>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="margin-bottom: 24px;">
    <div class="card kpi-card tone-purple">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Total de Registos</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
        </div>
        <div class="kpi-value"><?= (int)$kpis['total_movs'] ?></div>
        <div class="kpi-variation">Movimentações no período selecionado</div>
    </div>
    
    <div class="card kpi-card tone-green">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Volume de Entradas</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
        </div>
        <div class="kpi-value"><?= number_format((int)$kpis['volume_entradas'], 0, ',', '.') ?></div>
<div class="kpi-variation">Unidades somadas ao estoque</div>
</div>

<div class="card kpi-card tone-orange">
<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
<div class="kpi-label">Volume de Saídas / Perdas</div>
<div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline></svg></div>
</div>
<div class="kpi-value"><?= number_format((int)$kpis['volume_saidas'], 0, ',', '.') ?></div>
<div class="kpi-variation">Unidades subtraídas do estoque</div>
</div>

<div class="card kpi-card tone-blue">
<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
<div class="kpi-label">Ajustes de Inventário</div>
<div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg></div>
</div>
<div class="kpi-value"><?= number_format((int)$kpis['volume_ajustes'], 0, ',', '.') ?></div>
<div class="kpi-variation">Divergências ou acertos manuais</div>
</div>
</div>

<!-- Container da Tabela -->
<div class="card" style="padding: 0; overflow: hidden; margin-bottom: 24px;">
    <!-- Filtros -->
    <div style="padding: 20px; border-bottom: 1px solid var(--border);">
        <form method="GET" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
            <div style="flex: 1; min-width: 250px;">
                <label class="form-label-os">Buscar (Produto ou Motivo)</label>
                <input type="text" name="busca" class="form-control-os" placeholder="Digite para filtrar..." value="<?= Security::e($busca) ?>">
            </div>
            <div style="width: 150px;">
                <label class="form-label-os">Tipo</label>
                <select name="tipo" class="form-select-os">
                    <option value="">Todas</option>
                    <option value="entrada" <?= $fTipo === 'entrada' ? 'selected' : '' ?>>Entradas</option>
                    <option value="saida" <?= $fTipo === 'saida' ? 'selected' : '' ?>>Saídas</option>
                    <option value="ajuste" <?= $fTipo === 'ajuste' ? 'selected' : '' ?>>Ajustes</option>
                </select>
            </div>
            <div style="width: 150px;">
                <label class="form-label-os">Data Início</label>
                <input type="date" name="data_ini" class="form-control-os" value="<?= Security::e($dIni) ?>">
            </div>
            <div style="width: 150px;">
                <label class="form-label-os">Data Fim</label>
                <input type="date" name="data_fim" class="form-control-os" value="<?= Security::e($dFim) ?>">
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary-brand">Filtrar</button>
                <a href="?" class="btn btn-theme">Limpar</a>
            </div>
        </form>
    </div>

    <!-- Tabela -->
    <div class="table-responsive-os">
        <table class="table-os">
            <thead>
                <tr>
                    <th>Data / Hora</th>
                    <th>Produto / SKU</th>
                    <th>Tipo de Movimento</th>
                    <th style="text-align: right;">Quantidade</th>
                    <th style="text-align: right;">Custo Ref. (R$)</th>
                    <th>Motivo / Observação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movimentacoes)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 30px; color: var(--muted);">Nenhuma movimentação encontrada no período.</td></tr>
                <?php else: foreach ($movimentacoes as $m): ?>
                    <tr>
                        <td>
                            <b style="color: var(--text);"><?= date('d/m/Y', strtotime($m['data_movimentacao'])) ?></b>
                            <div class="kl" style="font-size: 12px;"><?= date('H:i:s', strtotime($m['data_movimentacao'])) ?></div>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text);"><?= Security::e($m['produto_nome']) ?></div>
                            <div class="kl" style="font-size: 12px;">SKU: <?= Security::e($m['sku'] ?: '—') ?></div>
                        </td>
                        <td>
                            <?php if ($m['tipo'] === 'entrada'): ?>
                                <span class="bd chip-ok">Entrada</span>
                            <?php elseif ($m['tipo'] === 'saida'): ?>
                                <span class="bd chip-bad">Saída</span>
                            <?php else: ?>
                                <span class="bd chip-info">Ajuste</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <b style="font-size: 15px; color: <?= ($m['tipo'] === 'saida') ? '#DC2626' : '#0B7A55' ?>;">
                                <?= ($m['tipo'] === 'saida' ? '-' : '+') . number_format((int)$m['quantidade'], 0, ',', '.') ?>
                            </b>
                        </td>
                        <td style="text-align: right; color: var(--text);">
                            <?= Security::formatMoney((float)$m['custo_unitario']) ?>
                        </td>
                        <td>
                            <div style="font-weight: 500; color: var(--text2);"><?= Security::e($m['motivo']) ?></div>
                            <?php if ($m['observacoes']): ?>
                                <div class="kl" style="font-size: 12px;"><?= Security::e($m['observacoes']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Paginação -->
    <?php if ($totalPages > 1): ?>
    <div style="padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
        <span class="kl">Página <?= $page ?> de <?= $totalPages ?></span>
        <div style="display: flex; gap: 8px;">
            <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&busca=<?= urlencode($busca) ?>&tipo=<?= $fTipo ?>&data_ini=<?= $dIni ?>&data_fim=<?= $dFim ?>" class="btn btn-theme">Anterior</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>&busca=<?= urlencode($busca) ?>&tipo=<?= $fTipo ?>&data_ini=<?= $dIni ?>&data_fim=<?= $dFim ?>" class="btn btn-theme">Próxima</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>