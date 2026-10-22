<?php
// Caminho: /modules/financeiro/transferencias.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('financeiro.ver')) {
    Session::setFlash('error', 'Não tem permissão para aceder ao módulo Financeiro.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();

// Helper para converter string monetária em float
function parseMoedaTransf(string $val): float {
    $limpo = str_replace(['R$', '%', ' ', '.'], '', trim($val));
    $limpo = str_replace(',', '.', $limpo);
    return is_numeric($limpo) ? (float)$limpo : 0.0;
}

// ============================================================================
// PROCESSAMENTO DE AÇÕES (POST)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        Session::setFlash('error', 'Token de segurança inválido.');
        header('Location: ' . BASE_URL . '/modules/financeiro/transferencias.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'transferir') {
        if (!Auth::hasPermission('financeiro.criar')) {
            Session::setFlash('error', 'Sem permissão para realizar transferências.');
            header('Location: ' . BASE_URL . '/modules/financeiro/transferencias.php');
            exit;
        }

        $origemId = (int)($_POST['conta_origem_id'] ?? 0);
        $destinoId = (int)($_POST['conta_destino_id'] ?? 0);
        $valor = parseMoedaTransf($_POST['valor'] ?? '0');
        $dataTransf = trim($_POST['data_transferencia'] ?? date('Y-m-d'));
        $observacoes = trim($_POST['observacoes'] ?? '') ?: null;

        if ($origemId === $destinoId) {
            Session::setFlash('error', 'A conta de origem e destino não podem ser a mesma.');
        } elseif ($valor <= 0) {
            Session::setFlash('error', 'O valor da transferência deve ser maior que zero.');
        } elseif ($origemId > 0 && $destinoId > 0) {
            try {
                $db->beginTransaction();

                // Regista a transferência
                $stmtIns = $db->prepare("
                    INSERT INTO transferencias_bancarias (empresa_id, conta_origem_id, conta_destino_id, valor, data_transferencia, observacoes) 
                    VALUES (:emp, :orig, :dest, :val, :dt, :obs)
                ");
                $stmtIns->execute([
                    ':emp' => $empresaId, ':orig' => $origemId, ':dest' => $destinoId, 
                    ':val' => $valor, ':dt' => $dataTransf, ':obs' => $observacoes
                ]);
                $transferenciaId = $db->lastInsertId();

                // Atualiza Saldos (Debita Origem, Credita Destino)
                $db->prepare("UPDATE contas_financeiras SET saldo_atual = saldo_atual - :val WHERE id = :id AND empresa_id = :emp")
                   ->execute([':val' => $valor, ':id' => $origemId, ':emp' => $empresaId]);
                $db->prepare("UPDATE contas_financeiras SET saldo_atual = saldo_atual + :val WHERE id = :id AND empresa_id = :emp")
                   ->execute([':val' => $valor, ':id' => $destinoId, ':emp' => $empresaId]);

                $db->commit();
                AuditLogger::log('criar', 'financeiro', "Transferência ID {$transferenciaId} efetuada com sucesso (R$ {$valor})");
                Session::setFlash('success', 'Transferência realizada com sucesso!');
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Erro ao transferir: " . $e->getMessage());
                Session::setFlash('error', 'Erro ao processar a transferência.');
            }
        }
    } elseif ($acao === 'estornar') {
        if (!Auth::hasPermission('financeiro.excluir')) {
            Session::setFlash('error', 'Sem permissão para estornar/excluir transferências.');
            header('Location: ' . BASE_URL . '/modules/financeiro/transferencias.php');
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        $stmtT = $db->prepare("SELECT * FROM transferencias_bancarias WHERE id = :id AND empresa_id = :emp");
        $stmtT->execute([':id' => $id, ':emp' => $empresaId]);
        $transf = $stmtT->fetch(PDO::FETCH_ASSOC);

        if ($transf) {
            try {
                $db->beginTransaction();

                // Estorna Saldos (Credita Origem, Debita Destino)
                $valor = (float)$transf['valor'];
                $db->prepare("UPDATE contas_financeiras SET saldo_atual = saldo_atual + :val WHERE id = :id AND empresa_id = :emp")
                   ->execute([':val' => $valor, ':id' => $transf['conta_origem_id'], ':emp' => $empresaId]);
                $db->prepare("UPDATE contas_financeiras SET saldo_atual = saldo_atual - :val WHERE id = :id AND empresa_id = :emp")
                   ->execute([':val' => $valor, ':id' => $transf['conta_destino_id'], ':emp' => $empresaId]);

                // Elimina o registo de transferência
                $db->prepare("DELETE FROM transferencias_bancarias WHERE id = :id AND empresa_id = :emp")->execute([':id' => $id, ':emp' => $empresaId]);

                $db->commit();
                AuditLogger::log('excluir', 'financeiro', "Estorno da Transferência ID {$id}");
                Session::setFlash('success', 'Transferência estornada e saldos revertidos com sucesso.');
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Erro ao estornar transferência: " . $e->getMessage());
                Session::setFlash('error', 'Erro ao estornar a transferência.');
            }
        }
    }

    header('Location: ' . BASE_URL . '/modules/financeiro/transferencias.php');
    exit;
}

// ============================================================================
// FILTROS E PAGINAÇÃO
// ============================================================================
$dIni = trim($_GET['data_ini'] ?? date('Y-m-01'));
$dFim = trim($_GET['data_fim'] ?? date('Y-m-t'));
$fConta = (int)($_GET['conta'] ?? 0);

$where = ["t.empresa_id = :emp"];
$params = [':emp' => $empresaId];

if ($dIni && $dFim) {
    $where[] = "t.data_transferencia BETWEEN :ini AND :fim";
    $params[':ini'] = $dIni;
    $params[':fim'] = $dFim;
}
if ($fConta > 0) {
    $where[] = "(t.conta_origem_id = :conta OR t.conta_destino_id = :conta)";
    $params[':conta'] = $fConta;
}

$whereClause = implode(" AND ", $where);

// Paginação
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$stmtCount = $db->prepare("SELECT COUNT(t.id) FROM transferencias_bancarias t WHERE $whereClause");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$stmtList = $db->prepare("
    SELECT t.*, co.nome as origem_nome, cd.nome as destino_nome 
    FROM transferencias_bancarias t
    LEFT JOIN contas_financeiras co ON t.conta_origem_id = co.id
    LEFT JOIN contas_financeiras cd ON t.conta_destino_id = cd.id
    WHERE $whereClause 
    ORDER BY t.data_transferencia DESC, t.id DESC 
    LIMIT $limit OFFSET $offset
");
$stmtList->execute($params);
$transferencias = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// ============================================================================
// DADOS AUXILIARES (Contas e KPIs)
// ============================================================================
$stmtContas = $db->prepare("SELECT id, nome, saldo_atual FROM contas_financeiras WHERE empresa_id = :emp AND ativo = 1");
$stmtContas->execute([':emp' => $empresaId]);
$contasList = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

// KPIs do mês atual
$stmtKpi = $db->prepare("
    SELECT COUNT(id) as qtd, COALESCE(SUM(valor), 0) as total 
    FROM transferencias_bancarias 
    WHERE empresa_id = :emp AND MONTH(data_transferencia) = MONTH(CURDATE()) AND YEAR(data_transferencia) = YEAR(CURDATE())
");
$stmtKpi->execute([':emp' => $empresaId]);
$kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

// Gráfico: Volume por Conta de Destino no mês
$stmtGrafico = $db->prepare("
    SELECT cd.nome as label, SUM(t.valor) as total
    FROM transferencias_bancarias t
    LEFT JOIN contas_financeiras cd ON t.conta_destino_id = cd.id
    WHERE t.empresa_id = :emp AND MONTH(t.data_transferencia) = MONTH(CURDATE()) AND YEAR(t.data_transferencia) = YEAR(CURDATE())
    GROUP BY label ORDER BY total DESC LIMIT 5
");
$stmtGrafico->execute([':emp' => $empresaId]);
$graficoDados = $stmtGrafico->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Transferências Bancárias';
$activeModule = 'financeiro';
$activeSub = 'transferencias';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Cabeçalho do Módulo -->
<div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
    <div>
        <div style="color: var(--primary); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Módulo Financeiro</div>
        <h1 class="ct" style="font-size: 28px; font-weight: 800; margin: 0; line-height: 1.2;">Transferências de Saldos</h1>
        <div class="kl" style="margin-top: 4px;">Registe e gira o fluxo de capital entre as suas contas e caixas.</div>
    </div>
    <?php if(Auth::hasPermission('financeiro.criar')): ?>
        <button type="button" class="btn btn-primary-brand" onclick="abrirModalTransferencia()" style="display:inline-flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="14" x2="21" y2="3"></line><polyline points="8 21 3 21 3 16"></polyline><line x1="20" y1="10" x2="3" y2="21"></line></svg>
            Nova Transferência
        </button>
    <?php endif; ?>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="margin-bottom: 24px;">
    <div class="card kpi-card tone-blue">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Volume Transferido (Mês)</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"></path></svg></div>
        </div>
        <div class="kpi-value"><?= Security::formatMoney((float)$kpis['total']) ?></div>
        <div class="kpi-variation">Total movimentado entre contas internas</div>
    </div>
    
    <div class="card kpi-card tone-purple">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Qtd. de Movimentos (Mês)</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
        </div>
        <div class="kpi-value"><?= (int)$kpis['qtd'] ?></div>
        <div class="kpi-variation">Registos efetuados este mês</div>
    </div>
</div>

<!-- Layout Principal -->
<div style="display: grid; grid-template-columns: minmax(0, 2.5fr) minmax(0, 1fr); gap: 24px; align-items: start;" class="main-grid-responsive">
    
    <!-- Coluna Esquerda: Tabela -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        <div class="card" style="padding: 0; overflow: hidden;">
            <!-- Filtros -->
            <div style="padding: 20px; border-bottom: 1px solid var(--border);">
                <form method="GET" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
                    <div style="flex: 1; min-width: 200px;">
                        <label class="form-label-os">Filtrar por Conta</label>
                        <select name="conta" class="form-select-os">
                            <option value="">Todas as Contas</option>
                            <?php foreach ($contasList as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $fConta === (int)$c['id'] ? 'selected' : '' ?>><?= Security::e($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="width: 150px;">
                        <label class="form-label-os">Data Inicial</label>
                        <input type="date" name="data_ini" class="form-control-os" value="<?= Security::e($dIni) ?>">
                    </div>
                    <div style="width: 150px;">
                        <label class="form-label-os">Data Final</label>
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
                            <th>Data</th>
                            <th>Conta de Origem</th>
                            <th>Conta de Destino</th>
                            <th>Valor</th>
                            <th>Observações</th>
                            <th style="text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transferencias)): ?>
                            <tr><td colspan="6" style="text-align: center; padding: 30px; color: var(--muted);">Nenhum registo encontrado.</td></tr>
                        <?php else: foreach ($transferencias as $t): ?>
                            <tr>
                                <td><b style="color: var(--text);"><?= Security::formatDate($t['data_transferencia']) ?></b></td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <span style="color:#DC2626;">▼</span> 
                                        <span style="font-weight: 600; color: var(--text);"><?= Security::e($t['origem_nome']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <span style="color:#0B7A55;">▲</span> 
                                        <span style="font-weight: 600; color: var(--text);"><?= Security::e($t['destino_nome']) ?></span>
                                    </div>
                                </td>
                                <td><b style="color: var(--text);"><?= Security::formatMoney((float)$t['valor']) ?></b></td>
                                <td><span class="kl" style="font-size: 13px;"><?= Security::e($t['observacoes'] ?: '-') ?></span></td>
                                <td style="text-align: right;">
                                    <?php if(Auth::hasPermission('financeiro.excluir')): ?>
                                    <form method="post" onsubmit="return confirm('Deseja estornar esta transferência e reverter os saldos das contas?');">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="acao" value="estornar">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-theme" style="padding: 4px 10px; color: #DC2626; border: 1px solid #FCA5A5; background: #FEF2F2;">↩️ Estornar</button>
                                    </form>
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
                    <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&data_ini=<?= urlencode($dIni) ?>&data_fim=<?= urlencode($dFim) ?>&conta=<?= $fConta ?>" class="btn btn-theme">Anterior</a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>&data_ini=<?= urlencode($dIni) ?>&data_fim=<?= urlencode($dFim) ?>&conta=<?= $fConta ?>" class="btn btn-theme">Próxima</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Coluna Direita: Gráficos e Saldos -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Saldo das Contas -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 700; color: var(--text);">Saldos Atuais</div>
            <div style="display: flex; flex-direction: column;">
                <?php if (empty($contasList)): ?>
                    <div style="padding: 20px; text-align: center; color: var(--muted);">Sem contas cadastradas.</div>
                <?php else: foreach ($contasList as $ca): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid var(--grid);">
                        <span style="font-size: 14px; font-weight: 600; color: var(--text);"><?= Security::e($ca['nome']) ?></span>
                        <b style="color: <?= (float)$ca['saldo_atual'] >= 0 ? '#0B7A55' : '#DC2626' ?>;"><?= Security::formatMoney((float)$ca['saldo_atual']) ?></b>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Volume de Destino (Donut Chart) -->
        <div class="card" style="padding: 20px;">
            <div style="font-weight: 700; margin-bottom: 16px; color: var(--text);">Volume Recebido por Conta (Mês)</div>
            <div style="position: relative; height: 200px; width: 100%; display: flex; justify-content: center; align-items: center;">
                <?php if (empty($graficoDados)): ?>
                    <div class="kl">Sem dados no período.</div>
                <?php else: ?>
                    <canvas id="chartTransferencias"></canvas>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- Modal Nova Transferência -->
<div id="modalTransferencia" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 500px; padding: 24px; position: relative;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px;">
            <h3 style="margin: 0; font-size: 18px; color: var(--text);">Efetuar Transferência</h3>
            <button type="button" onclick="fecharModalTransferencia()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--muted);">&times;</button>
        </div>
        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="transferir">
            
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label-os">Conta de Origem (Debitar) *</label>
                    <select name="conta_origem_id" class="form-select-os" required>
                        <option value="">Selecione a origem...</option>
                        <?php foreach ($contasList as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= Security::e($c['nome']) ?> (Saldo: <?= Security::formatMoney((float)$c['saldo_atual']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-12">
                    <label class="form-label-os">Conta de Destino (Creditar) *</label>
                    <select name="conta_destino_id" class="form-select-os" required>
                        <option value="">Selecione o destino...</option>
                        <?php foreach ($contasList as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= Security::e($c['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label-os">Valor (R$) *</label>
                    <input type="text" name="valor" class="form-control-os" data-mask="money" value="0,00" required>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label-os">Data *</label>
                    <input type="date" name="data_transferencia" class="form-control-os" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="col-md-12">
                    <label class="form-label-os">Observações</label>
                    <textarea name="observacoes" rows="2" class="form-control-os" placeholder="Detalhes opcionais..."></textarea>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                <button type="button" class="btn btn-theme" onclick="fecharModalTransferencia()">Cancelar</button>
                <button type="submit" class="btn btn-primary-brand">Confirmar Transferência</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Controlo do Modal
function abrirModalTransferencia() {
    document.getElementById('modalTransferencia').style.display = 'flex';
}
function fecharModalTransferencia() {
    document.getElementById('modalTransferencia').style.display = 'none';
}

// Gráfico de Rosca (Volume de Destino)
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('chartTransferencias');
    if (ctx) {
        const rawData = <?= json_encode($graficoDados) ?>;
        const labels = rawData.map(d => d.label);
        const values = rawData.map(d => parseFloat(d.total));
        const colors = ['#0B7A55', '#1D4ED8', '#B45309', '#7E22CE', '#2A9D8A']; // Cores padronizadas

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: { family: "'Plus Jakarta Sans', sans-serif", size: 12 } } }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>