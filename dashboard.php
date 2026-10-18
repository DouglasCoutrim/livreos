<?php
// Caminho: /dashboard.php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

$pageTitle = 'Dashboard';
$activeModule = 'dashboard';
require_once __DIR__ . '/includes/header.php';

$db = Database::getConnection();
$empresaId = Auth::empresaId();

// 1. KPIs do Topo
$stmtReceberHoje = $db->prepare("
    SELECT COALESCE(SUM(valor), 0) FROM lancamentos_financeiros
    WHERE empresa_id = :emp AND tipo = 'receita' AND status != 'cancelado' AND data_vencimento = CURDATE()
");
$stmtReceberHoje->execute([':emp' => $empresaId]);
$totalReceberHoje = (float)$stmtReceberHoje->fetchColumn();

$stmtPagarHoje = $db->prepare("
    SELECT COALESCE(SUM(valor), 0) FROM lancamentos_financeiros
    WHERE empresa_id = :emp AND tipo = 'despesa' AND status != 'cancelado' AND data_vencimento = CURDATE()
");
$stmtPagarHoje->execute([':emp' => $empresaId]);
$totalPagarHoje = (float)$stmtPagarHoje->fetchColumn();

$stmtPedidos = $db->prepare("
    SELECT COUNT(*) AS qtd, COALESCE(SUM(valor_total), 0) AS total
    FROM pedidos_venda
    WHERE empresa_id = :emp AND status = 'faturado'
");
$stmtPedidos->execute([':emp' => $empresaId]);
$dadosPedidos = $stmtPedidos->fetch();
$pedidosFaturados = (int)($dadosPedidos['qtd'] ?? 0);
$faturamentoPedidos = (float)($dadosPedidos['total'] ?? 0);

// 2. Saldo por Conta
$stmtContas = $db->prepare("
    SELECT nome, saldo_atual FROM contas_financeiras
    WHERE empresa_id = :emp AND ativo = 1 ORDER BY saldo_atual DESC
");
$stmtContas->execute([':emp' => $empresaId]);
$contas = $stmtContas->fetchAll();
$saldoTotal = 0.0;
foreach ($contas as $c) {
    $saldoTotal += (float)$c['saldo_atual'];
}
$contaPrincipalNome = $contas[0]['nome'] ?? 'Banco da Empresa';
$contaPrincipalPct = ($saldoTotal > 0 && isset($contas[0])) ? (($contas[0]['saldo_atual'] / $saldoTotal) * 100) : 100.0;

// 3. Resumo CRM
$stmtCrm = $db->prepare("
    SELECT etapa, COUNT(*) AS qtd, COALESCE(SUM(valor), 0) AS total
    FROM crm_oportunidades
    WHERE empresa_id = :emp
    GROUP BY etapa
");
$stmtCrm->execute([':emp' => $empresaId]);
$crmRows = $stmtCrm->fetchAll();

$crmStats = [
    'prospeccao' => ['qtd' => 0, 'total' => 0.0],
    'negociacao' => ['qtd' => 0, 'total' => 0.0],
    'ganha'      => ['qtd' => 0, 'total' => 0.0],
    'perdida'    => ['qtd' => 0, 'total' => 0.0],
];
$totalRegistrosCrm = 0;
foreach ($crmRows as $r) {
    $et = $r['etapa'];
    if (isset($crmStats[$et])) {
        $crmStats[$et]['qtd'] = (int)$r['qtd'];
        $crmStats[$et]['total'] = (float)$r['total'];
        $totalRegistrosCrm += (int)$r['qtd'];
    }
}
$pctProspeccao = $totalRegistrosCrm > 0 ? round(($crmStats['prospeccao']['qtd'] / $totalRegistrosCrm) * 100) : 0;
$pctNegociacao = $totalRegistrosCrm > 0 ? round(($crmStats['negociacao']['qtd'] / $totalRegistrosCrm) * 100) : 0;
$pctGanha      = $totalRegistrosCrm > 0 ? round(($crmStats['ganha']['qtd'] / $totalRegistrosCrm) * 100) : 0;
$pctPerdida    = $totalRegistrosCrm > 0 ? round(($crmStats['perdida']['qtd'] / $totalRegistrosCrm) * 100) : 0;

// 4. Top Clientes
$stmtTopCli = $db->prepare("
    SELECT cliente_nome, SUM(valor_total) AS total
    FROM pedidos_venda
    WHERE empresa_id = :emp AND status = 'faturado'
    GROUP BY cliente_nome
    ORDER BY total DESC
    LIMIT 1
");
$stmtTopCli->execute([':emp' => $empresaId]);
$topCliente = $stmtTopCli->fetch();
$topClienteNome = $topCliente['cliente_nome'] ?? 'Cliente exemplo';
$concentracaoPct = ($faturamentoPedidos > 0 && $topCliente) ? (($topCliente['total'] / $faturamentoPedidos) * 100) : 100.0;

// 5. Próximos Vencimentos / Atrasados
$stmtVenc = $db->prepare("
    SELECT pessoa_nome, valor, data_vencimento, status
    FROM lancamentos_financeiros
    WHERE empresa_id = :emp AND status IN ('atrasado', 'pendente')
    ORDER BY data_vencimento ASC
    LIMIT 3
");
$stmtVenc->execute([':emp' => $empresaId]);
$vencimentos = $stmtVenc->fetchAll();

// 6. Top Produtos
$stmtProd = $db->prepare("
    SELECT produto_nome, qtd_vendida, valor_total
    FROM vendas_resumo_produtos
    WHERE empresa_id = :emp
    ORDER BY valor_total DESC
    LIMIT 3
");
$stmtProd->execute([':emp' => $empresaId]);
$topProdutos = $stmtProd->fetchAll();
$maiorValorProd = !empty($topProdutos) ? (float)$topProdutos[0]['valor_total'] : 1.0;

$mesesCurto = [1=>'Jan.',2=>'Fev.',3=>'Mar.',4=>'Abr.',5=>'Mai.',6=>'Jun.',7=>'Jul.',8=>'Ago.',9=>'Set.',10=>'Out.',11=>'Nov.',12=>'Dez.'];
?>

<!-- 1. KPIs Superiores -->
<div class="kpi-grid">
    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Total a receber hoje</div>
            <div class="kv"><?= Security::formatMoney($totalReceberHoje) ?></div>
        </div>
        <div class="ib" style="background:var(--csoft);color:var(--ctxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 2v20M17 6H9a3 3 0 000 6h6a3 3 0 010 6H7"></path>
            </svg>
        </div>
    </div>

    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Total a pagar hoje</div>
            <div class="kv"><?= Security::formatMoney($totalPagarHoje) ?></div>
        </div>
        <div class="ib" style="background:var(--soft2);color:var(--txt2)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 7h18v12H3zM3 11h18"></path>
            </svg>
        </div>
    </div>

    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Pedidos faturados</div>
            <div class="kv"><?= $pedidosFaturados ?></div>
        </div>
        <div class="ib" style="background:var(--osoft);color:var(--otxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 4h2l2 12h11l2-8H6"></path>
            </svg>
        </div>
    </div>

    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Faturamento de Pedidos</div>
            <div class="kv"><?= Security::formatMoney($faturamentoPedidos) ?></div>
        </div>
        <div class="ib" style="background:var(--bsoft2);color:var(--btxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 4h2l2 12h11l2-8H6"></path>
            </svg>
        </div>
    </div>
</div>

<!-- 2. Fluxo de Caixa Diário -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <span class="ct">Fluxo de Caixa Diário</span>
        <div style="display:flex;font-size:11px;font-weight:700">
            <span style="background:#F97316;color:#fff;padding:5px 10px;border-radius:6px 0 0 6px;cursor:pointer">Agrupado</span>
            <span style="border:1px solid var(--border);padding:4px 10px;border-radius:0 6px 6px 0;color:var(--text2);cursor:pointer">Detalhado</span>
        </div>
    </div>
    <div style="display:flex;gap:16px;font-size:11px;color:var(--muted);font-weight:600;justify-content:center;flex-wrap:wrap;margin-top:6px">
        <span><b style="color:#0E9F6E">●</b> Saldo</span>
        <span><b style="color:#A5B4FC">●</b> Insuficiente</span>
        <span><b style="color:#34C77B">●</b> Receitas</span>
        <span><b style="color:#EF4444">●</b> Despesas</span>
    </div>
    <svg viewBox="0 0 700 230" style="width:100%;height:auto;margin-top:10px" role="img" aria-label="Gráfico de fluxo de caixa diário">
        <g stroke="var(--grid)">
            <path d="M40 20H690M40 60H690M40 100H690M40 140H690M40 180H690"></path>
        </g>
        <path d="M40 120C150 120 180 60 260 50C360 40 450 45 690 45V180H40Z" fill="var(--area)"></path>
        <path d="M40 120C150 120 180 60 260 50C360 40 450 45 690 45" fill="none" stroke="#0E9F6E" stroke-width="2.5"></path>
        <rect x="195" y="130" width="26" height="50" rx="4" fill="#34C77B"></rect>
        <g font-size="10" fill="var(--muted2)" font-family="Plus Jakarta Sans,sans-serif">
            <text x="40" y="205">10/10</text>
            <text x="170" y="205">14/10</text>
            <text x="300" y="205">18/10</text>
            <text x="430" y="205">22/10</text>
            <text x="560" y="205">26/10</text>
        </g>
    </svg>
</div>

<!-- 3. Saldo Total e Saúde Financeira -->
<div class="g2 g">
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span class="ct">Saldo total</span>
        </div>
        <div class="kv"><?= Security::formatMoney($saldoTotal) ?></div>
        <div style="display:flex;justify-content:center">
            <svg viewBox="0 0 140 140" width="170" role="img" aria-label="Saldo por conta">
                <circle cx="70" cy="70" r="50" fill="none" stroke="#0E9F6E" stroke-width="18" stroke-dasharray="306 8" transform="rotate(-90 70 70)"></circle>
                <text x="70" y="68" text-anchor="middle" font-size="9" fill="var(--muted)" font-family="Plus Jakarta Sans,sans-serif">Total</text>
                <text x="70" y="84" text-anchor="middle" font-size="13" font-weight="800" fill="var(--text)" font-family="Plus Jakarta Sans,sans-serif"><?= Security::formatMoney($saldoTotal) ?></text>
            </svg>
        </div>
        <div class="kl">■ <?= Security::e($contaPrincipalNome) ?> · <?= number_format($contaPrincipalPct, 1, ',', '.') ?>%</div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span class="ct">Saúde Financeira</span>
            <span class="bd" style="background:var(--osoft);color:var(--otxt)">Bom</span>
        </div>
        <div style="display:flex;justify-content:center;margin-top:8px">
            <svg viewBox="0 0 160 100" width="180" role="img" aria-label="Saúde financeira 63%">
                <path d="M20 85A60 60 0 0 1 140 85" fill="none" stroke="var(--border)" stroke-width="14" stroke-linecap="round"></path>
                <path d="M20 85A60 60 0 0 1 140 85" fill="none" stroke="#F59E0B" stroke-width="14" stroke-linecap="round" stroke-dasharray="119 189"></path>
                <text x="80" y="82" text-anchor="middle" font-size="26" font-weight="800" fill="var(--text)" font-family="Plus Jakarta Sans,sans-serif">63%</text>
            </svg>
        </div>
        <div class="kl" style="text-align:center;margin-bottom:6px">Situação estável, mantenha o ritmo</div>
        <div class="mt">
            <span style="width:110px">Margem</span>
            <i style="background:#0E9F6E"></i>
            <span style="width:56px;text-align:right;font-size:12px;color:#0E9F6E">+100,0%</span>
        </div>
        <div class="mt">
            <span style="width:110px">Cobertura de caixa</span>
            <i style="background:#EF4444"></i>
            <span style="width:56px;text-align:right;font-size:12px;color:#EF4444">0,0 dias</span>
        </div>
        <div class="mt">
            <span style="width:110px">Dias no negativo</span>
            <i style="background:#0E9F6E"></i>
            <span style="width:56px;text-align:right;font-size:12px;color:#0E9F6E">+100,0%</span>
        </div>
        <div class="mt">
            <span style="width:110px">Tendência (MMS)</span>
            <i style="background:#CBD5E1"></i>
            <span style="width:56px;text-align:right;font-size:12px;color:#CBD5E1">—</span>
        </div>
    </div>
</div>

<!-- 4. Resumo CRM e Top Clientes -->
<div class="g2 g">
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span class="ct">Resumo CRM</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px">
            <div style="background:var(--bg);border-radius:10px;padding:10px">
                <div class="kl">Total de registros</div>
                <b><?= $totalRegistrosCrm ?></b>
            </div>
            <div style="background:var(--gsoft);border-radius:10px;padding:10px">
                <div class="kl">Ganhas (mês)</div>
                <b><?= Security::formatMoney($crmStats['ganha']['total']) ?></b>
            </div>
            <div style="background:var(--bsoft);border-radius:10px;padding:10px">
                <div class="kl">Em negociação</div>
                <b><?= Security::formatMoney($crmStats['negociacao']['total']) ?></b>
            </div>
        </div>
        <div class="mt">
            <span style="width:100px">Prospecção</span>
            <i style="background:var(--border)"><b style="display:block;height:5px;border-radius:9px;background:#94A3B8;width:<?= $pctProspeccao ?>%"></b></i>
            <span style="width:28px;text-align:right"><?= $crmStats['prospeccao']['qtd'] ?></span>
        </div>
        <div class="mt">
            <span style="width:100px">Negociação</span>
            <i style="background:var(--border)"><b style="display:block;height:5px;border-radius:9px;background:#3B82F6;width:<?= $pctNegociacao ?>%"></b></i>
            <span style="width:28px;text-align:right"><?= number_format($crmStats['negociacao']['qtd'] > 0 ? 1 : 0, 0) ?></span>
        </div>
        <div class="mt">
            <span style="width:100px">Ganhas</span>
            <i style="background:var(--border)"><b style="display:block;height:5px;border-radius:9px;background:#0E9F6E;width:<?= $pctGanha ?>%"></b></i>
            <span style="width:28px;text-align:right"><?= $crmStats['ganha']['qtd'] ?></span>
        </div>
        <div class="mt">
            <span style="width:100px">Perdidas</span>
            <i style="background:var(--border)"><b style="display:block;height:5px;border-radius:9px;background:#EF4444;width:<?= $pctPerdida ?>%"></b></i>
            <span style="width:28px;text-align:right"><?= $crmStats['perdida']['qtd'] ?></span>
        </div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span class="ct">Top clientes</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px">
            <div style="background:var(--bg);border-radius:10px;padding:10px">
                <div class="kl">Top cliente</div>
                <b style="font-size:12px"><?= Security::e($topClienteNome) ?></b>
            </div>
            <div style="background:var(--bg);border-radius:10px;padding:10px">
                <div class="kl">Concentração</div>
                <b><?= number_format($concentracaoPct, 1, ',', '.') ?>%</b>
            </div>
            <div style="background:var(--bg);border-radius:10px;padding:10px">
                <div class="kl">Risco</div>
                <span class="bd" style="background:var(--rsoft);color:var(--rtxt)">Alta concentração</span>
            </div>
        </div>
        <div style="margin-top:36px">
            <div class="kl" style="margin-bottom:6px"><?= Security::e($topClienteNome) ?> — <?= round($concentracaoPct) ?>%</div>
            <div style="height:14px;border-radius:7px;background:#3B82F6;width:<?= round($concentracaoPct) ?>%"></div>
        </div>
    </div>
</div>

<!-- 5. Próximos Vencimentos e Top Produtos -->
<div class="g2 g">
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span class="ct">Próximos Vencimentos</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:14px">
            <?php foreach ($vencimentos as $v):
                $ts = strtotime($v['data_vencimento']);
                $mesLabel = $mesesCurto[(int)date('n', $ts)] . ' ' . (int)date('j', $ts);
                $isAtrasado = ($v['status'] === 'atrasado');
            ?>
                <div class="row-item">
                    <div style="display:flex;gap:10px;align-items:center">
                        <div class="ib" style="background:var(--rsoft);color:#DC2626;width:30px;height:30px">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 8v5M12 17h.01"></path>
                            </svg>
                        </div>
                        <div>
                            <b><?= Security::e($v['pessoa_nome']) ?></b>
                            <div class="kl"><?= Security::e($mesLabel) ?></div>
                        </div>
                    </div>
                    <div style="text-align:right">
                        <b><?= Security::formatMoney($v['valor']) ?></b>
                        <div>
                            <span class="bd" style="background:<?= $isAtrasado ? 'var(--rsoft)' : 'var(--asoft)' ?>;color:<?= $isAtrasado ? 'var(--rtxt)' : 'var(--atxt)' ?>">
                                <?= $isAtrasado ? 'Atrasado' : 'Pendente' ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span class="ct">Top Produtos</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:14px">
            <?php foreach ($topProdutos as $idx => $prod):
                $rank = $idx + 1;
                $pctBarra = $maiorValorProd > 0 ? max(3, round(((float)$prod['valor_total'] / $maiorValorProd) * 100)) : 0;
                $qtdLabel = (int)$prod['qtd_vendida'] === 1 ? '1 vendido' : (int)$prod['qtd_vendida'] . ' vendidos';
            ?>
                <div class="row-item">
                    <div style="display:flex;gap:10px;align-items:center">
                        <div class="ib" style="background:var(--asoft);color:var(--atxt);width:30px;height:30px;font-weight:800">
                            <?= $rank ?>
                        </div>
                        <div>
                            <b><?= Security::e($prod['produto_nome']) ?></b>
                            <div class="kl"><?= Security::e($qtdLabel) ?></div>
                        </div>
                    </div>
                    <div style="text-align:right;width:110px">
                        <b style="color:#0E9F6E"><?= Security::formatMoney($prod['valor_total']) ?></b>
                        <div style="height:4px;border-radius:9px;background:var(--border);margin-top:6px">
                            <div style="height:4px;border-radius:9px;background:#6366F1;width:<?= $pctBarra ?>%"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>