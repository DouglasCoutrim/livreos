<?php
// Caminho: /modules/financeiro/dre.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('financeiro.ver')) {
    Session::setFlash('error', 'Você não tem permissão para acessar o módulo Financeiro.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();

// ============================================================================
// FILTROS DO DRE
// ============================================================================
$dIni = trim($_GET['data_ini'] ?? date('Y-m-01'));
$dFim = trim($_GET['data_fim'] ?? date('Y-m-t'));
$regime = trim($_GET['regime'] ?? 'competencia'); // 'competencia' ou 'caixa'

$params = [
    ':emp' => $empresaId,
    ':ini' => $dIni,
    ':fim' => $dFim
];

if ($regime === 'caixa') {
    // Regime de Caixa: Lançamentos pagos, pela data de quitação.
    // Usamos 'total_val' se for maior que zero (pois inclui juros/multas/descontos), senão 'valor' original.
    $condicaoData = "l.data_quitacao BETWEEN :ini AND :fim";
    $condicaoStatus = "l.status = 'pago'";
    $colunaValor = "CASE WHEN l.total_val > 0 THEN l.total_val ELSE l.valor END";
} else {
    // Regime de Competência: Lançamentos gerados, pela data de competência.
    $condicaoData = "l.competencia BETWEEN :ini AND :fim";
    $condicaoStatus = "l.status != 'cancelado'";
    $colunaValor = "l.valor";
}

// Busca as categorias e consolida os valores
$sqlDre = "
    SELECT 
        l.tipo, 
        COALESCE(cat.nome, 'Sem Categoria') AS categoria_nome, 
        SUM($colunaValor) AS total
    FROM lancamentos_financeiros l
    LEFT JOIN categorias_financeiras cat ON l.categoria_id = cat.id
    WHERE l.empresa_id = :emp 
      AND $condicaoData 
      AND $condicaoStatus
    GROUP BY l.tipo, categoria_nome
    ORDER BY l.tipo ASC, total DESC
";

$stmtDre = $db->prepare($sqlDre);
$stmtDre->execute($params);
$linhasDre = $stmtDre->fetchAll(PDO::FETCH_ASSOC);

// Processa os dados para o layout do DRE
$receitasBrutas = 0.0;
$despesasOperacionais = 0.0;
$catReceitas = [];
$catDespesas = [];

foreach ($linhasDre as $row) {
    $val = (float)$row['total'];
    if ($row['tipo'] === 'receita') {
        $receitasBrutas += $val;
        $catReceitas[$row['categoria_nome']] = $val;
    } else {
        $despesasOperacionais += $val;
        $catDespesas[$row['categoria_nome']] = $val;
    }
}

$lucroLiquido = $receitasBrutas - $despesasOperacionais;
$margem = $receitasBrutas > 0 ? ($lucroLiquido / $receitasBrutas) * 100 : 0;

$pageTitle = 'DRE Gerencial';
$activeModule = 'financeiro';
$activeSub = 'dre';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Cabeçalho do Módulo -->
<div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
    <div>
        <div style="color: var(--primary); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Módulo Financeiro</div>
        <h1 class="ct" style="font-size: 28px; font-weight: 800; margin: 0; line-height: 1.2;">DRE Gerencial</h1>
        <div class="kl" style="margin-top: 4px;">Demonstração do Resultado do Exercício - Análise de Lucratividade</div>
    </div>
    <button type="button" class="btn btn-theme" onclick="window.print()" style="display:inline-flex; align-items:center; gap:8px;">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
        Imprimir
    </button>
</div>

<!-- Filtros -->
<div class="card" style="padding: 20px; margin-bottom: 24px;">
    <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
        <div style="width: 150px;">
            <label class="form-label-os">Regime</label>
            <select name="regime" class="form-select-os">
                <option value="competencia" <?= $regime === 'competencia' ? 'selected' : '' ?>>Competência (Gerados)</option>
                <option value="caixa" <?= $regime === 'caixa' ? 'selected' : '' ?>>Caixa (Pagos/Recebidos)</option>
            </select>
        </div>
        <div style="width: 150px;">
            <label class="form-label-os">Data Inicial</label>
            <input type="date" name="data_ini" class="form-control-os" value="<?= Security::e($dIni) ?>" required>
        </div>
        <div style="width: 150px;">
            <label class="form-label-os">Data Final</label>
            <input type="date" name="data_fim" class="form-control-os" value="<?= Security::e($dFim) ?>" required>
        </div>
        <button type="submit" class="btn btn-primary-brand">Gerar DRE</button>
    </form>
</div>

<!-- Grid de KPIs -->
<div class="kpi-grid" style="margin-bottom: 24px;">
    <!-- Receita Bruta -->
    <div class="card kpi-card tone-green">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Receita Bruta</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"></path></svg></div>
        </div>
        <div class="kpi-value"><?= Security::formatMoney($receitasBrutas) ?></div>
        <div class="kpi-variation">Total de entradas no período</div>
    </div>
    
    <!-- Despesas Operacionais -->
    <div class="card kpi-card tone-orange">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Despesas Operacionais</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline></svg></div>
        </div>
        <div class="kpi-value"><?= Security::formatMoney($despesasOperacionais) ?></div>
        <div class="kpi-variation">Custos e despesas consolidadas</div>
    </div>

    <!-- Lucro Líquido -->
    <div class="card kpi-card <?= $lucroLiquido >= 0 ? 'tone-blue' : 'tone-orange' ?>">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Lucro Líquido</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"></path></svg></div>
        </div>
        <div class="kpi-value"><?= Security::formatMoney($lucroLiquido) ?></div>
        <div class="kpi-variation">Resultado líquido do período</div>
    </div>

    <!-- Margem de Lucro -->
    <div class="card kpi-card tone-purple">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Margem de Lucro</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 118 2.83"></path><path d="M22 12A10 10 0 0012 2v10z"></path></svg></div>
        </div>
        <div class="kpi-value"><?= number_format($margem, 2, ',', '.') ?>%</div>
        <div class="kpi-variation">Representatividade sobre a receita</div>
    </div>
</div>

<!-- Estrutura DRE (Tabela) -->
<div class="card" style="padding: 0; overflow: hidden; margin-bottom: 24px;">
    <div style="padding: 18px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between;">
        <span class="ct" style="font-size: 16px;">Estrutura do DRE</span>
        <span class="kl">Período: <?= Security::formatDate($dIni) ?> até <?= Security::formatDate($dFim) ?></span>
    </div>
    <div class="table-responsive-os">
        <table class="table-os" style="margin-bottom: 0;">
            <thead>
                <tr>
                    <th>Descrição</th>
                    <th style="text-align: right; width: 200px;">Valor (R$)</th>
                </tr>
            </thead>
            <tbody>
                <!-- 1. RECEITAS -->
                <tr style="background: var(--bg);">
                    <td><b style="color: var(--text);">1. RECEITA BRUTA OPERACIONAL</b></td>
                    <td style="text-align: right;"><b style="color: #0B7A55;"><?= Security::formatMoney($receitasBrutas) ?></b></td>
                </tr>
                <?php if (empty($catReceitas)): ?>
                    <tr><td colspan="2" style="padding-left: 30px; color: var(--muted);">Nenhuma receita no período.</td></tr>
                <?php else: foreach ($catReceitas as $cat => $val): ?>
                    <tr>
                        <td style="padding-left: 30px; color: var(--text2);">1.1 - <?= Security::e($cat) ?></td>
                        <td style="text-align: right; font-weight: 500; color: var(--text);"><?= Security::formatMoney($val) ?></td>
                    </tr>
                <?php endforeach; endif; ?>

                <!-- 2. DESPESAS -->
                <tr style="background: var(--bg);">
                    <td><b style="color: var(--text);">2. (-) DESPESAS OPERACIONAIS E CUSTOS</b></td>
                    <td style="text-align: right;"><b style="color: #B45309;"><?= Security::formatMoney($despesasOperacionais) ?></b></td>
                </tr>
                <?php if (empty($catDespesas)): ?>
                    <tr><td colspan="2" style="padding-left: 30px; color: var(--muted);">Nenhuma despesa no período.</td></tr>
                <?php else: foreach ($catDespesas as $cat => $val): ?>
                    <tr>
                        <td style="padding-left: 30px; color: var(--text2);">2.1 - <?= Security::e($cat) ?></td>
                        <td style="text-align: right; font-weight: 500; color: var(--text);"><?= Security::formatMoney($val) ?></td>
                    </tr>
                <?php endforeach; endif; ?>

                <!-- 3. RESULTADO -->
                <tr style="background: var(--primary-soft);">
                    <td style="padding: 16px 20px;"><b style="color: var(--text); font-size: 15px;">(=) LUCRO / PREJUÍZO LÍQUIDO DO PERÍODO</b></td>
                    <td style="text-align: right; padding: 16px 20px;">
                        <b style="font-size: 16px; color: <?= $lucroLiquido >= 0 ? '#0B7A55' : '#B45309' ?>;">
                            <?= Security::formatMoney($lucroLiquido) ?>
                        </b>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>