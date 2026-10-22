<?php
// Caminho: /modules/estoque/estoques.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('estoque.ver')) {
    Session::setFlash('error', 'Sem permissão para acessar o módulo de Estoque.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();

// Helper para formulário - quantidades são inteiras
function parseIntQty(string $val): int {
    $limpo = str_replace(['.', ' '], '', trim($val));
    $limpo = str_replace(',', '.', $limpo);
    return is_numeric($limpo) ? (int)floatval($limpo) : 0;
}

// ============================================================================
// PROCESSAMENTO DE AÇÕES (POST) - AJUSTE RÁPIDO
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        Session::setFlash('error', 'Token de segurança inválido.');
        header('Location: ' . BASE_URL . '/modules/estoque/estoques.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';
    if ($acao === 'ajuste_rapido') {
        if (!Auth::hasPermission('estoque.editar')) {
            Session::setFlash('error', 'Sem permissão para alterar estoque.');
            header('Location: ' . BASE_URL . '/modules/estoque/estoques.php');
            exit;
        }

        $produtoId = (int)($_POST['produto_id'] ?? 0);
        $tipo = trim($_POST['tipo_ajuste'] ?? 'entrada'); // 'entrada' ou 'saida'
        $quantidade = parseIntQty($_POST['quantidade'] ?? '0');
        $motivo = trim($_POST['motivo'] ?? 'Ajuste Manual');

        if ($produtoId > 0 && $quantidade > 0) {
            try {
                $db->beginTransaction();

                // Busca o custo atual para registar no histórico
                $stmtProd = $db->prepare("SELECT preco_custo, estoque_atual FROM produtos WHERE id = :id AND empresa_id = :emp FOR UPDATE");
                $stmtProd->execute([':id' => $produtoId, ':emp' => $empresaId]);
                $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);

                if ($prod) {
                    // Verifica se há estoque suficiente em caso de saída
                    if ($tipo === 'saida' && $prod['estoque_atual'] < $quantidade) {
                        Session::setFlash('error', 'A quantidade de saída é maior do que o estoque atual disponível.');
                        $db->rollBack();
                    } else {
                        // Grava Histórico
                        $stmtHist = $db->prepare("
                            INSERT INTO estoque_movimentacoes (empresa_id, produto_id, tipo, quantidade, custo_unitario, motivo) 
                            VALUES (:emp, :prod, :tipo, :qtd, :custo, :motivo)
                        ");
                        $stmtHist->execute([
                            ':emp' => $empresaId, ':prod' => $produtoId, ':tipo' => $tipo,
                            ':qtd' => $quantidade, ':custo' => $prod['preco_custo'], ':motivo' => $motivo
                        ]);

                        // Atualiza Saldo no Produto
                        $sinal = ($tipo === 'entrada') ? '+' : '-';
                        $stmtUp = $db->prepare("UPDATE produtos SET estoque_atual = estoque_atual $sinal :qtd WHERE id = :id");
                        $stmtUp->execute([':qtd' => $quantidade, ':id' => $produtoId]);

                        $db->commit();
                        AuditLogger::log('editar', 'estoque', "Ajuste de $quantidade ($tipo) no produto ID $produtoId");
                        Session::setFlash('success', 'Estoque ajustado com sucesso.');
                    }
                } else {
                    $db->rollBack();
                    Session::setFlash('error', 'Produto não encontrado.');
                }
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Erro no ajuste de estoque: " . $e->getMessage());
                Session::setFlash('error', 'Ocorreu um erro ao processar o ajuste.');
            }
        } else {
            Session::setFlash('error', 'Quantidade inválida para ajuste.');
        }
    }
    header('Location: ' . BASE_URL . '/modules/estoque/estoques.php');
    exit;
}

// ============================================================================
// FILTROS E BUSCA
// ============================================================================
$busca = trim($_GET['busca'] ?? '');
$fStatus = trim($_GET['status'] ?? '');

$where = ["empresa_id = :emp", "ativo = 1"];
$params = [':emp' => $empresaId];

if ($busca !== '') {
    $where[] = "(nome LIKE :b1 OR sku LIKE :b2)";
    $params[':b1'] = "%$busca%";
    $params[':b2'] = "%$busca%";
}
if ($fStatus === 'baixo') {
    $where[] = "estoque_atual > 0 AND estoque_atual <= estoque_minimo";
} elseif ($fStatus === 'zerado') {
    $where[] = "estoque_atual <= 0";
} elseif ($fStatus === 'normal') {
    $where[] = "estoque_atual > estoque_minimo";
}

$whereClause = implode(" AND ", $where);

// Paginação
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$stmtCount = $db->prepare("SELECT COUNT(id) FROM produtos WHERE $whereClause");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Tabela principal
$stmtList = $db->prepare("
    SELECT id, nome, sku, preco_custo, preco_venda, estoque_atual, estoque_minimo 
    FROM produtos 
    WHERE $whereClause 
    ORDER BY nome ASC 
    LIMIT $limit OFFSET $offset
");
$stmtList->execute($params);
$produtos = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// ============================================================================
// KPIs E GRÁFICOS (Baseados em todos os produtos ativos)
// ============================================================================
$stmtKpis = $db->prepare("
    SELECT 
        COUNT(id) as total_itens,
        SUM(estoque_atual * preco_custo) as valor_total_custo,
        SUM(CASE WHEN estoque_atual <= 0 THEN 1 ELSE 0 END) as itens_zerados,
        SUM(CASE WHEN estoque_atual > 0 AND estoque_atual <= estoque_minimo THEN 1 ELSE 0 END) as itens_em_baixa,
        SUM(CASE WHEN estoque_atual > estoque_minimo THEN 1 ELSE 0 END) as itens_normais
    FROM produtos 
    WHERE empresa_id = :emp AND ativo = 1
");
$stmtKpis->execute([':emp' => $empresaId]);
$kpis = $stmtKpis->fetch(PDO::FETCH_ASSOC);

// Últimas movimentações para a barra lateral
$stmtMovs = $db->prepare("
    SELECT m.tipo, m.quantidade, m.data_movimentacao, p.nome 
    FROM estoque_movimentacoes m
    JOIN produtos p ON m.produto_id = p.id
    WHERE m.empresa_id = :emp
    ORDER BY m.id DESC LIMIT 5
");
$stmtMovs->execute([':emp' => $empresaId]);
$ultimasMovs = $stmtMovs->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Posição de Estoque';
$activeModule = 'estoque';
$activeSub = 'estoques';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Cabeçalho do Módulo -->
<div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
    <div>
        <div style="color: var(--primary); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Módulo Estoque</div>
        <h1 class="ct" style="font-size: 28px; font-weight: 800; margin: 0; line-height: 1.2;">Visão Geral de Estoques</h1>
        <div class="kl" style="margin-top: 4px;">Controle de quantidades, custos patrimoniais e alertas de reposição.</div>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/cadastros/produtos.php" class="btn btn-theme" style="text-decoration:none;">Gestão de Produtos</a>
    </div>
</div>

<!-- Grid de KPIs -->
<div class="kpi-grid" style="margin-bottom: 24px;">
    <!-- Custo Total do Estoque -->
    <div class="card kpi-card tone-blue">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Valor Patrimonial (Custo)</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"></path></svg></div>
        </div>
        <div class="kpi-value"><?= Security::formatMoney((float)$kpis['valor_total_custo']) ?></div>
        <div class="kpi-variation">Capital imobilizado nos itens ativos</div>
    </div>
    
    <!-- Itens Normais -->
    <div class="card kpi-card tone-green">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Produtos em Nível Normal</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
        </div>
        <div class="kpi-value"><?= (int)$kpis['itens_normais'] ?></div>
        <div class="kpi-variation">Estoque acima do mínimo</div>
    </div>

    <!-- Itens em Baixa -->
    <div class="card kpi-card tone-orange">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Produtos em Alerta</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg></div>
        </div>
        <div class="kpi-value"><?= (int)$kpis['itens_em_baixa'] ?></div>
        <div class="kpi-variation">Requerem reposição imediata</div>
    </div>

    <!-- Zerados -->
    <div class="card kpi-card tone-purple">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Produtos Esgotados</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg></div>
        </div>
        <div class="kpi-value"><?= (int)$kpis['itens_zerados'] ?></div>
        <div class="kpi-variation">Estoque <= 0</div>
    </div>
</div>

<!-- Layout Principal -->
<div style="display: grid; grid-template-columns: minmax(0, 2.5fr) minmax(0, 1fr); gap: 24px; align-items: start;" class="main-grid-responsive">
    
    <!-- Coluna Esquerda: Tabela de Estoque -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        <div class="card" style="padding: 0; overflow: hidden;">
            <!-- Filtros -->
            <div style="padding: 20px; border-bottom: 1px solid var(--border);">
                <form method="GET" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
                    <div style="flex: 1; min-width: 250px;">
                        <label class="form-label-os">Buscar Produto</label>
                        <input type="text" name="busca" class="form-control-os" placeholder="Nome ou SKU..." value="<?= Security::e($busca) ?>">
                    </div>
                    <div style="width: 200px;">
                        <label class="form-label-os">Situação do Estoque</label>
                        <select name="status" class="form-select-os">
                            <option value="">Todos</option>
                            <option value="normal" <?= $fStatus === 'normal' ? 'selected' : '' ?>>Normal</option>
                            <option value="baixo" <?= $fStatus === 'baixo' ? 'selected' : '' ?>>Em Alerta (Baixo)</option>
                            <option value="zerado" <?= $fStatus === 'zerado' ? 'selected' : '' ?>>Esgotados</option>
                        </select>
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
                            <th>Produto</th>
                            <th>SKU</th>
                            <th style="text-align: right;">Custo (R$)</th>
                            <th style="text-align: right;">Venda (R$)</th>
                            <th style="text-align: right;">Mínimo</th>
                            <th style="text-align: right;">Estoque Atual</th>
                            <th>Status</th>
                            <th style="text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($produtos)): ?>
                            <tr><td colspan="8" style="text-align: center; padding: 30px; color: var(--muted);">Nenhum produto encontrado.</td></tr>
                        <?php else: foreach ($produtos as $p): ?>
                            <tr>
                                <td><b style="color: var(--text);"><?= Security::e($p['nome']) ?></b></td>
                                <td><span class="kl" style="font-size: 13px;"><?= Security::e($p['sku'] ?: '—') ?></span></td>
                                <td style="text-align: right;"><?= Security::formatMoney((float)$p['preco_custo']) ?></td>
                                <td style="text-align: right; font-weight:600;"><?= Security::formatMoney((float)$p['preco_venda']) ?></td>
                                <td style="text-align: right; color: var(--muted);"><?= number_format((int)$p['estoque_minimo'], 0, ',', '.') ?></td>
                                <td style="text-align: right;">
                                    <b style="font-size: 15px; color: <?= ((int)$p['estoque_atual'] <= 0) ? '#DC2626' : (((int)$p['estoque_atual'] <= (int)$p['estoque_minimo']) ? '#B45309' : '#0B7A55') ?>">
                                        <?= number_format((int)$p['estoque_atual'], 0, ',', '.') ?>
                                    </b>
                                </td>
                                <td>
                                    <?php
                                        $qtd = (int)$p['estoque_atual'];
                                        $min = (int)$p['estoque_minimo'];
                                        if ($qtd <= 0) {
                                            echo '<span class="bd chip-bad">Esgotado</span>';
                                        } elseif ($qtd <= $min) {
                                            echo '<span class="bd chip-warn">Alerta</span>';
                                        } else {
                                            echo '<span class="bd chip-ok">Normal</span>';
                                        }
                                    ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if(Auth::hasPermission('estoque.editar')): ?>
                                        <button type="button" class="btn btn-theme" style="padding: 4px 12px; font-size:12px;" onclick='abrirAjuste(<?= htmlspecialchars(json_encode($p)) ?>)'>Ajustar</button>
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
                    <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&busca=<?= urlencode($busca) ?>&status=<?= $fStatus ?>" class="btn btn-theme">Anterior</a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>&busca=<?= urlencode($busca) ?>&status=<?= $fStatus ?>" class="btn btn-theme">Próxima</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Coluna Direita: Cards Auxiliares -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Status do Estoque (Donut Chart) -->
        <div class="card" style="padding: 20px;">
            <div style="font-weight: 700; margin-bottom: 16px; color: var(--text);">Distribuição de Saúde do Estoque</div>
            <div style="position: relative; height: 200px; width: 100%; display: flex; justify-content: center; align-items: center;">
                <?php if ($kpis['total_itens'] == 0): ?>
                    <div class="kl">Sem produtos cadastrados.</div>
                <?php else: ?>
                    <canvas id="chartSaude"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Últimas Movimentações -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 700; color: var(--text);">Movimentações Recentes</div>
            <div style="display: flex; flex-direction: column;">
                <?php if (empty($ultimasMovs)): ?>
                    <div style="padding: 20px; text-align: center; color: var(--muted);">Sem movimentações.</div>
                <?php else: foreach ($ultimasMovs as $m): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; border-bottom: 1px solid var(--grid);">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="width: 32px; height: 32px; border-radius: 8px; display:flex; align-items:center; justify-content:center; 
                                background: <?= $m['tipo'] === 'entrada' ? '#EAF7F1' : ($m['tipo'] === 'saida' ? '#FEF2F2' : '#FFF3E3') ?>;
                                color: <?= $m['tipo'] === 'entrada' ? '#0B7A55' : ($m['tipo'] === 'saida' ? '#DC2626' : '#B45309') ?>;">
                                <?php if($m['tipo'] === 'entrada') echo '▲'; elseif($m['tipo'] === 'saida') echo '▼'; else echo '⟳'; ?>
                            </div>
                            <div>
                                <div style="font-size: 13px; font-weight: 600; color: var(--text);"><?= Security::e(mb_strimwidth($p['nome'], 0, 25, '...')) ?></div>
                                <div class="kl" style="font-size: 11px;"><?= date('d/m/Y H:i', strtotime($m['data_movimentacao'])) ?></div>
                            </div>
                        </div>
                        <b style="color: var(--text);"><?= number_format((int)$m['quantidade'], 0, ',', '.') ?></b>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- Modal Ajuste de Estoque -->
<div id="modalAjuste" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 400px; padding: 24px; position: relative;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px;">
            <h3 style="margin: 0; font-size: 18px; color: var(--text);">Ajuste Rápido</h3>
            <button type="button" onclick="fecharModalAjuste()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--muted);">&times;</button>
        </div>
        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="ajuste_rapido">
            <input type="hidden" name="produto_id" id="ajuste_id">
            
            <div style="margin-bottom: 16px; padding:12px; background:var(--bg); border-radius:8px;">
                <div class="kl" style="font-size:12px; text-transform:uppercase;">Produto</div>
                <div id="ajuste_nome" style="font-weight:700; color:var(--text); font-size:15px; margin-bottom:4px;"></div>
                <div style="display:flex; justify-content:space-between; font-size:13px;">
                    <span class="kl">Estoque Atual:</span>
                    <b id="ajuste_atual" style="color:var(--primary);">0</b>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label-os">Tipo de Operação *</label>
                    <select name="tipo_ajuste" class="form-select-os" required>
                        <option value="entrada">Entrada (Somar ao estoque)</option>
                        <option value="saida">Saída / Perda (Subtrair do estoque)</option>
                    </select>
                </div>
                
                <div class="col-12">
                    <label class="form-label-os">Quantidade *</label>
                    <input type="number" name="quantidade" class="form-control-os" min="1" value="1" required>
                </div>

                <div class="col-12">
                    <label class="form-label-os">Motivo do Ajuste *</label>
                    <select name="motivo" class="form-select-os" required>
                        <option value="Ajuste Manual">Ajuste de Diferença (Inventário)</option>
                        <option value="Perda/Avaria">Perda / Avaria / Quebra</option>
                        <option value="Brinde/Amostra">Brinde / Amostra</option>
                        <option value="Devolução">Devolução sem nota</option>
                        <option value="Bonificação">Bonificação recebida</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                <button type="button" class="btn btn-theme" onclick="fecharModalAjuste()">Cancelar</button>
                <button type="submit" class="btn btn-primary-brand">Confirmar Ajuste</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Modal Ajuste Rápido
function abrirAjuste(produto) {
    document.getElementById('ajuste_id').value = produto.id;
    document.getElementById('ajuste_nome').innerText = produto.nome;
    document.getElementById('ajuste_atual').innerText = parseInt(produto.estoque_atual, 10).toLocaleString('pt-BR');
    document.getElementById('modalAjuste').style.display = 'flex';
}
function fecharModalAjuste() {
    document.getElementById('modalAjuste').style.display = 'none';
}

// Gráfico de Saúde
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('chartSaude');
    if (ctx && <?= $kpis['total_itens'] ?> > 0) {
        const labels = ['Normal (Estoque OK)', 'Alerta (Abaixo do Mínimo)', 'Esgotado (Zerado)'];
        const values = [<?= (int)$kpis['itens_normais'] ?>, <?= (int)$kpis['itens_em_baixa'] ?>, <?= (int)$kpis['itens_zerados'] ?>];
        const colors = ['#0B7A55', '#B45309', '#7E22CE']; // Verde, Laranja, Roxo (padrões CRM)

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