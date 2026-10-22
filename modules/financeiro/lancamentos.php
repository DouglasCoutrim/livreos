<?php
// Caminho: /modules/financeiro/lancamentos.php
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

// Helper para converter string monetária em float
function parseMoedaLocal(string $val): float {
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
        header('Location: ' . BASE_URL . '/modules/financeiro/lancamentos.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'quitar') {
        if (!Auth::hasPermission('financeiro.criar')) {
            Session::setFlash('error', 'Sem permissão para realizar baixas.');
            header('Location: ' . BASE_URL . '/modules/financeiro/lancamentos.php');
            exit;
        }

        $stmtL = $db->prepare("SELECT * FROM lancamentos_financeiros WHERE id = :id AND empresa_id = :emp");
        $stmtL->execute([':id' => $id, ':emp' => $empresaId]);
        $lancamento = $stmtL->fetch(PDO::FETCH_ASSOC);

        if ($lancamento && !in_array($lancamento['status'], ['pago', 'cancelado'])) {
            $dataQuitacao = trim($_POST['data_quitacao'] ?? date('Y-m-d'));
            $formaPgto = trim($_POST['forma_pagamento'] ?? $lancamento['forma_pagamento']);
            $multa = parseMoedaLocal($_POST['multa_val'] ?? '0');
            $juros = parseMoedaLocal($_POST['juros_val'] ?? '0');
            $desconto = parseMoedaLocal($_POST['desconto_val'] ?? '0');
            
            $valorOriginal = (float)$lancamento['valor'];
            $totalPago = ($valorOriginal + $multa + $juros) - $desconto;
            if ($totalPago < 0) $totalPago = 0;

            try {
                $db->beginTransaction();

                $stmtUp = $db->prepare("
                    UPDATE lancamentos_financeiros 
                    SET status = 'pago', pago = 1, data_quitacao = :dq, forma_pagamento = :fp, 
                        multa_val = :mul, juros_val = :jur, desconto_val = :desc, total_val = :tot
                    WHERE id = :id AND empresa_id = :emp
                ");
                $stmtUp->execute([
                    ':dq' => $dataQuitacao, ':fp' => $formaPgto, ':mul' => $multa, 
                    ':jur' => $juros, ':desc' => $desconto, ':tot' => $totalPago, 
                    ':id' => $id, ':emp' => $empresaId
                ]);

                // Atualiza o saldo da conta
                $fator = ($lancamento['tipo'] === 'receita') ? 1 : -1;
                $stmtConta = $db->prepare("UPDATE contas_financeiras SET saldo_atual = saldo_atual + (:val * :fat) WHERE id = :cid AND empresa_id = :emp");
                $stmtConta->execute([':val' => $totalPago, ':fat' => $fator, ':cid' => $lancamento['conta_id'], ':emp' => $empresaId]);

                $db->commit();
                AuditLogger::log('quitar', 'financeiro', "Baixa do lançamento ID {$id} (R$ {$totalPago})");
                Session::setFlash('success', 'Lançamento baixado com sucesso.');
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Erro ao quitar: " . $e->getMessage());
                Session::setFlash('error', 'Erro ao realizar a baixa.');
            }
        }
    } elseif ($acao === 'estornar') {
        if (!Auth::hasPermission('financeiro.criar')) {
            Session::setFlash('error', 'Sem permissão para estornos.');
            header('Location: ' . BASE_URL . '/modules/financeiro/lancamentos.php');
            exit;
        }

        $stmtL = $db->prepare("SELECT * FROM lancamentos_financeiros WHERE id = :id AND empresa_id = :emp");
        $stmtL->execute([':id' => $id, ':emp' => $empresaId]);
        $lancamento = $stmtL->fetch(PDO::FETCH_ASSOC);

        if ($lancamento && $lancamento['status'] === 'pago') {
            try {
                $db->beginTransaction();
                
                $totalPago = (float)$lancamento['total_val'] > 0 ? (float)$lancamento['total_val'] : (float)$lancamento['valor'];

                $stmtUp = $db->prepare("
                    UPDATE lancamentos_financeiros 
                    SET status = 'pendente', pago = 0, data_quitacao = NULL, total_val = 0
                    WHERE id = :id AND empresa_id = :emp
                ");
                $stmtUp->execute([':id' => $id, ':emp' => $empresaId]);

                $fator = ($lancamento['tipo'] === 'receita') ? -1 : 1;
                $stmtConta = $db->prepare("UPDATE contas_financeiras SET saldo_atual = saldo_atual + (:val * :fat) WHERE id = :cid AND empresa_id = :emp");
                $stmtConta->execute([':val' => $totalPago, ':fat' => $fator, ':cid' => $lancamento['conta_id'], ':emp' => $empresaId]);

                $db->commit();
                AuditLogger::log('estornar', 'financeiro', "Estorno do lançamento ID {$id}");
                Session::setFlash('success', 'Baixa estornada com sucesso.');
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Erro ao estornar: " . $e->getMessage());
                Session::setFlash('error', 'Erro ao realizar o estorno.');
            }
        }
    } elseif ($acao === 'cancelar') {
        $stmtUp = $db->prepare("UPDATE lancamentos_financeiros SET status = 'cancelado' WHERE id = :id AND empresa_id = :emp AND status != 'pago'");
        $stmtUp->execute([':id' => $id, ':emp' => $empresaId]);
        if ($stmtUp->rowCount() > 0) {
            AuditLogger::log('cancelar', 'financeiro', "Cancelamento do lançamento ID {$id}");
            Session::setFlash('success', 'Lançamento cancelado.');
        } else {
            Session::setFlash('error', 'Não foi possível cancelar (verifique se já está pago).');
        }
    } elseif ($acao === 'excluir') {
        // Exclusão apenas se não estiver pago
        $stmtDel = $db->prepare("DELETE FROM lancamentos_financeiros WHERE id = :id AND empresa_id = :emp AND status != 'pago'");
        $stmtDel->execute([':id' => $id, ':emp' => $empresaId]);
        if ($stmtDel->rowCount() > 0) {
            AuditLogger::log('excluir', 'financeiro', "Exclusão do lançamento ID {$id}");
            Session::setFlash('success', 'Lançamento excluído.');
        } else {
            Session::setFlash('error', 'Lançamentos pagos não podem ser excluídos. Estorne primeiro.');
        }
    }
    header('Location: ' . BASE_URL . '/modules/financeiro/lancamentos.php');
    exit;
}

// Atualiza lançamentos vencidos para "atrasado"
$db->prepare("UPDATE lancamentos_financeiros SET status = 'atrasado' WHERE empresa_id = :emp AND status = 'pendente' AND data_vencimento < CURDATE()")->execute([':emp' => $empresaId]);

// ============================================================================
// FILTROS E PAGINAÇÃO
// ============================================================================
$busca = trim($_GET['busca'] ?? '');
$fTipo = trim($_GET['tipo'] ?? '');
$fStatus = trim($_GET['status'] ?? '');
$fConta = (int)($_GET['conta'] ?? 0);
$dIni = trim($_GET['data_ini'] ?? date('Y-m-01'));
$dFim = trim($_GET['data_fim'] ?? date('Y-m-t'));

$where = ["l.empresa_id = :emp"];
$params = [':emp' => $empresaId];

if ($busca !== '') {
    $where[] = "(l.cliente_fornecedor LIKE :b1 OR l.observacoes LIKE :b2 OR cat.nome LIKE :b3)";
    $params[':b1'] = "%$busca%"; $params[':b2'] = "%$busca%"; $params[':b3'] = "%$busca%";
}
if ($fTipo) { $where[] = "l.tipo = :tipo"; $params[':tipo'] = $fTipo; }
if ($fStatus) { $where[] = "l.status = :status"; $params[':status'] = $fStatus; }
if ($fConta > 0) { $where[] = "l.conta_id = :conta"; $params[':conta'] = $fConta; }
if ($dIni && $dFim) { $where[] = "l.data_vencimento BETWEEN :ini AND :fim"; $params[':ini'] = $dIni; $params[':fim'] = $dFim; }

$whereClause = implode(" AND ", $where);

// Paginação
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$totalRows = (int)$db->prepare("SELECT COUNT(l.id) FROM lancamentos_financeiros l LEFT JOIN categorias_financeiras cat ON l.categoria_id = cat.id WHERE $whereClause")->execute($params) ? $db->query("SELECT FOUND_ROWS()")->fetchColumn() : 0; // Alternativa simplificada:
$stmtCount = $db->prepare("SELECT COUNT(l.id) FROM lancamentos_financeiros l LEFT JOIN categorias_financeiras cat ON l.categoria_id = cat.id WHERE $whereClause");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$stmtList = $db->prepare("
    SELECT l.*, cat.nome as categoria_nome, c.nome as conta_nome 
    FROM lancamentos_financeiros l 
    LEFT JOIN categorias_financeiras cat ON l.categoria_id = cat.id 
    LEFT JOIN contas_financeiras c ON l.conta_id = c.id 
    WHERE $whereClause ORDER BY l.data_vencimento ASC, l.id DESC LIMIT $limit OFFSET $offset
");
$stmtList->execute($params);
$lancamentos = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// ============================================================================
// KPIs E GRÁFICOS
// ============================================================================
$stmtKpis = $db->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'receita' AND status IN ('pendente', 'atrasado') THEN valor ELSE 0 END) as a_receber,
        SUM(CASE WHEN tipo = 'despesa' AND status IN ('pendente', 'atrasado') THEN valor ELSE 0 END) as a_pagar,
        SUM(CASE WHEN tipo = 'receita' AND status = 'pago' AND MONTH(data_quitacao) = MONTH(CURDATE()) AND YEAR(data_quitacao) = YEAR(CURDATE()) THEN total_val ELSE 0 END) as recebido_mes,
        SUM(CASE WHEN tipo = 'despesa' AND status = 'pago' AND MONTH(data_quitacao) = MONTH(CURDATE()) AND YEAR(data_quitacao) = YEAR(CURDATE()) THEN total_val ELSE 0 END) as pago_mes
    FROM lancamentos_financeiros WHERE empresa_id = :emp AND status != 'cancelado'
");
$stmtKpis->execute([':emp' => $empresaId]);
$kpis = $stmtKpis->fetch(PDO::FETCH_ASSOC);

// Rosca: Despesas por Categoria (top 5)
$stmtRosca = $db->prepare("
    SELECT COALESCE(cat.nome, 'Outros') as label, SUM(l.valor) as total
    FROM lancamentos_financeiros l
    LEFT JOIN categorias_financeiras cat ON l.categoria_id = cat.id
    WHERE l.empresa_id = :emp AND l.tipo = 'despesa' AND l.status != 'cancelado' AND l.data_vencimento BETWEEN :ini AND :fim
    GROUP BY label ORDER BY total DESC LIMIT 5
");
$stmtRosca->execute([':emp' => $empresaId, ':ini' => $dIni, ':fim' => $dFim]);
$roscaDados = $stmtRosca->fetchAll(PDO::FETCH_ASSOC);

// Próximos Vencimentos (A Pagar)
$stmtProx = $db->prepare("
    SELECT id, cliente_fornecedor, valor, data_vencimento, status 
    FROM lancamentos_financeiros 
    WHERE empresa_id = :emp AND tipo = 'despesa' AND status IN ('pendente', 'atrasado')
    ORDER BY data_vencimento ASC LIMIT 5
");
$stmtProx->execute([':emp' => $empresaId]);
$proximos = $stmtProx->fetchAll(PDO::FETCH_ASSOC);

// Contas para filtro
$stmtContas = $db->prepare("SELECT id, nome FROM contas_financeiras WHERE empresa_id = :emp AND ativo = 1");
$stmtContas->execute([':emp' => $empresaId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Lançamentos Financeiros';
$activeModule = 'financeiro';
$activeSub = 'lancamentos';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <div style="color:var(--primary); font-size:11px; font-weight:700; text-transform:uppercase; margin-bottom:4px;">Módulo Financeiro</div>
        <h1 class="ct" style="font-size:28px; margin:0; line-height:1.2;">Lançamentos Financeiros</h1>
        <div class="kl" style="margin-top:4px;">Gestão de contas a pagar, receber e conciliação.</div>
    </div>
    <a href="<?= BASE_URL ?>/modules/financeiro/novo_lancamento.php" class="btn btn-primary-brand" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Novo Lançamento
    </a>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="margin-bottom:24px;">
    <div class="card kpi-card tone-blue">
        <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
            <div class="kl" style="font-size:11px; font-weight:700; text-transform:uppercase;">A Receber (Aberto)</div>
            <div class="ib" style="background:var(--primary-soft); color:var(--primary)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline></svg></div>
        </div>
        <div style="font-size:28px; font-weight:800; color:var(--text);"><?= Security::formatMoney((float)$kpis['a_receber']) ?></div>
    </div>
    <div class="card kpi-card tone-orange">
        <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
            <div class="kl" style="font-size:11px; font-weight:700; text-transform:uppercase;">A Pagar (Aberto)</div>
            <div class="ib" style="background:#FFF3E3; color:#B45309"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline></svg></div>
        </div>
        <div style="font-size:28px; font-weight:800; color:var(--text);"><?= Security::formatMoney((float)$kpis['a_pagar']) ?></div>
    </div>
    <div class="card kpi-card tone-green">
        <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
            <div class="kl" style="font-size:11px; font-weight:700; text-transform:uppercase;">Recebido no Mês</div>
            <div class="ib" style="background:#EAF7F1; color:#0B7A55"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
        </div>
        <div style="font-size:28px; font-weight:800; color:var(--text);"><?= Security::formatMoney((float)$kpis['recebido_mes']) ?></div>
    </div>
    <div class="card kpi-card tone-purple">
        <div style="display:flex; justify-content:space-between; margin-bottom:12px;">
            <div class="kl" style="font-size:11px; font-weight:700; text-transform:uppercase;">Pago no Mês</div>
            <div class="ib" style="background:#F3EBFF; color:#7E22CE"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg></div>
        </div>
        <div style="font-size:28px; font-weight:800; color:var(--text);"><?= Security::formatMoney((float)$kpis['pago_mes']) ?></div>
    </div>
</div>

<!-- Layout Principal -->
<div style="display:grid; grid-template-columns: minmax(0, 2.5fr) minmax(0, 1fr); gap:24px; align-items:start;">
    
    <!-- Coluna Esquerda: Tabela -->
    <div style="display:flex; flex-direction:column; gap:24px;">
        <div class="card" style="padding:0; overflow:hidden;">
            <!-- Filtros -->
            <div style="padding:20px; border-bottom:1px solid var(--border);">
                <form method="GET" style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end;">
                    <div style="flex: 1; min-width:200px;">
                        <label class="form-label-os">Buscar</label>
                        <input type="text" name="busca" class="form-control-os" placeholder="Cliente, obs..." value="<?= Security::e($busca) ?>">
                    </div>
                    <div style="width:130px;">
                        <label class="form-label-os">Tipo</label>
                        <select name="tipo" class="form-select-os">
                            <option value="">Todos</option>
                            <option value="receita" <?= $fTipo === 'receita' ? 'selected' : '' ?>>Receita</option>
                            <option value="despesa" <?= $fTipo === 'despesa' ? 'selected' : '' ?>>Despesa</option>
                        </select>
                    </div>
                    <div style="width:140px;">
                        <label class="form-label-os">Status</label>
                        <select name="status" class="form-select-os">
                            <option value="">Todos</option>
                            <option value="pendente" <?= $fStatus === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                            <option value="pago" <?= $fStatus === 'pago' ? 'selected' : '' ?>>Pago</option>
                            <option value="atrasado" <?= $fStatus === 'atrasado' ? 'selected' : '' ?>>Atrasado</option>
                        </select>
                    </div>
                    <div style="width:140px;">
                        <label class="form-label-os">Início</label>
                        <input type="date" name="data_ini" class="form-control-os" value="<?= Security::e($dIni) ?>">
                    </div>
                    <div style="width:140px;">
                        <label class="form-label-os">Fim</label>
                        <input type="date" name="data_fim" class="form-control-os" value="<?= Security::e($dFim) ?>">
                    </div>
                    <div style="display:flex; gap:8px;">
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
                            <th style="width:40px;"><input type="checkbox"></th>
                            <th>Vencimento</th>
                            <th>Cliente / Fornecedor</th>
                            <th>Categoria / Conta</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th style="text-align:right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($lancamentos)): ?>
                            <tr><td colspan="7" style="text-align:center; padding:30px; color:var(--muted);">Nenhum lançamento encontrado.</td></tr>
                        <?php else: foreach($lancamentos as $l): ?>
                            <tr>
                                <td><input type="checkbox" value="<?= $l['id'] ?>"></td>
                                <td><b style="color:var(--text);"><?= Security::formatDate($l['data_vencimento']) ?></b></td>
                                <td>
                                    <div style="font-weight:600; color:var(--text);"><?= Security::e($l['cliente_fornecedor']) ?></div>
                                    <?php if($l['observacoes']): ?><div class="kl" style="font-size:12px;"><?= Security::e($l['observacoes']) ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <div><?= Security::e($l['categoria_nome'] ?: 'Sem Categoria') ?></div>
                                    <div class="kl" style="font-size:12px;"><?= Security::e($l['conta_nome']) ?></div>
                                </td>
                                <td>
                                    <b style="color:<?= $l['tipo'] === 'receita' ? '#0B7A55' : '#B45309' ?>">
                                        <?= Security::formatMoney((float)($l['status'] === 'pago' ? $l['total_val'] : $l['valor'])) ?>
                                    </b>
                                </td>
                                <td>
                                    <?php
                                        $chip = 'chip-info'; $lbl = ucfirst($l['status']);
                                        if($l['status'] === 'pago') $chip = 'chip-ok';
                                        elseif($l['status'] === 'atrasado') $chip = 'chip-bad';
                                        elseif($l['status'] === 'pendente') $chip = 'chip-warn';
                                    ?>
                                    <span class="bd <?= $chip ?>"><?= $lbl ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <div style="position:relative; display:inline-block;" class="dropdown-acao">
                                        <button type="button" class="btn btn-theme" style="padding:4px 8px;" onclick="this.nextElementSibling.classList.toggle('show')">⋮</button>
                                        <div class="dropdown-menu-os" style="position:absolute; right:0; top:100%; z-index:100; background:var(--card); border:1px solid var(--border); border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); width:160px; text-align:left; display:none;">
                                            <?php if($l['status'] !== 'pago' && $l['status'] !== 'cancelado'): ?>
                                                <a href="#" onclick="abrirBaixa(<?= htmlspecialchars(json_encode($l)) ?>); return false;" style="display:block; padding:10px 16px; color:var(--text); text-decoration:none; border-bottom:1px solid var(--border);">✅ Baixar / Quitar</a>
                                            <?php endif; ?>
                                            <?php if($l['status'] === 'pago'): ?>
                                                <form method="post" onsubmit="return confirm('Estornar este pagamento?');">
                                                    <?= Security::csrfField() ?><input type="hidden" name="acao" value="estornar"><input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                    <button type="submit" style="width:100%; text-align:left; background:none; border:none; padding:10px 16px; color:var(--text); cursor:pointer; border-bottom:1px solid var(--border);">↩️ Estornar</button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="novo_lancamento.php?id=<?= $l['id'] ?>" style="display:block; padding:10px 16px; color:var(--text); text-decoration:none; border-bottom:1px solid var(--border);">✏️ Editar</a>
                                            <form method="post" onsubmit="return confirm('Cancelar este lançamento?');">
                                                <?= Security::csrfField() ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                <button type="submit" style="width:100%; text-align:left; background:none; border:none; padding:10px 16px; color:var(--text); cursor:pointer; border-bottom:1px solid var(--border);">🚫 Cancelar</button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Excluir permanentemente?');">
                                                <?= Security::csrfField() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                <button type="submit" style="width:100%; text-align:left; background:none; border:none; padding:10px 16px; color:#DC2626; cursor:pointer;">🗑️ Excluir</button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginação Simples -->
            <?php if($totalPages > 1): ?>
            <div style="padding:16px 20px; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                <span class="kl">Página <?= $page ?> de <?= $totalPages ?></span>
                <div style="display:flex; gap:8px;">
                    <?php if($page > 1): ?><a href="?page=<?= $page-1 ?>&busca=<?= urlencode($busca) ?>" class="btn btn-theme">Anterior</a><?php endif; ?>
                    <?php if($page < $totalPages): ?><a href="?page=<?= $page+1 ?>&busca=<?= urlencode($busca) ?>" class="btn btn-theme">Próxima</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Coluna Direita: Gráficos e Listas -->
    <div style="display:flex; flex-direction:column; gap:24px;">
        
        <!-- Rosca de Despesas -->
        <div class="card" style="padding:20px;">
            <div style="font-weight:700; margin-bottom:16px; color:var(--text);">Despesas por Categoria</div>
            <div style="position:relative; height:200px; width:100%;">
                <canvas id="chartDespesas"></canvas>
            </div>
        </div>

        <!-- Próximos Pagamentos -->
        <div class="card" style="padding:0; overflow:hidden;">
            <div style="padding:16px 20px; border-bottom:1px solid var(--border); font-weight:700; color:var(--text);">Próximos Pagamentos</div>
            <div style="display:flex; flex-direction:column;">
                <?php if(empty($proximos)): ?>
                    <div style="padding:20px; text-align:center; color:var(--muted);">Nenhuma despesa pendente.</div>
                <?php else: foreach($proximos as $p): ?>
                    <div style="display:flex; align-items:center; padding:16px 20px; border-bottom:1px solid var(--grid);">
                        <div style="width:40px; height:40px; border-radius:10px; background:#FFF3E3; color:#B45309; display:flex; align-items:center; justify-content:center; margin-right:16px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        </div>
                        <div style="flex:1;">
                            <div style="font-weight:600; font-size:14px; color:var(--text);"><?= Security::e($p['cliente_fornecedor']) ?></div>
                            <div class="kl" style="font-size:12px;">Vence: <?= Security::formatDate($p['data_vencimento']) ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-weight:700; color:#B45309;"><?= Security::formatMoney((float)$p['valor']) ?></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- Modal de Baixa (Quitar) -->
<div id="modalBaixa" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2000; align-items:center; justify-content:center;">
    <div class="card" style="width:100%; max-width:450px; padding:24px; position:relative;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:12px;">
            <h3 style="margin:0; font-size:18px; color:var(--text);">Baixar Lançamento</h3>
            <button type="button" onclick="fecharModalBaixa()" style="background:none; border:none; font-size:20px; cursor:pointer; color:var(--muted);">&times;</button>
        </div>
        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="quitar">
            <input type="hidden" name="id" id="baixa_id">
            
            <div style="margin-bottom:16px;">
                <label class="form-label-os">Data do Pagamento / Recebimento</label>
                <input type="date" name="data_quitacao" class="form-control-os" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div style="margin-bottom:16px;">
                <label class="form-label-os">Forma de Pagamento</label>
                <select name="forma_pagamento" id="baixa_forma" class="form-select-os">
                    <option value="Dinheiro">Dinheiro</option>
                    <option value="Pix">Pix</option>
                    <option value="Boleto">Boleto</option>
                    <option value="Transferência">Transferência</option>
                    <option value="Cartão de Crédito">Cartão de Crédito</option>
                </select>
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                <div>
                    <label class="form-label-os">Juros (R$)</label>
                    <input type="text" name="juros_val" class="form-control-os" data-mask="money" value="0,00">
                </div>
                <div>
                    <label class="form-label-os">Multa (R$)</label>
                    <input type="text" name="multa_val" class="form-control-os" data-mask="money" value="0,00">
                </div>
            </div>
            <div style="margin-bottom:24px;">
                <label class="form-label-os">Desconto (R$)</label>
                <input type="text" name="desconto_val" class="form-control-os" data-mask="money" value="0,00">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-theme" onclick="fecharModalBaixa()">Cancelar</button>
                <button type="submit" class="btn btn-primary-brand">Confirmar Baixa</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Menu Dropdown da Tabela
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown-acao')) {
        document.querySelectorAll('.dropdown-menu-os.show').forEach(m => m.classList.remove('show'));
    }
});

// Controle do Modal de Baixa
function abrirBaixa(lancamento) {
    document.getElementById('baixa_id').value = lancamento.id;
    if(lancamento.forma_pagamento) {
        document.getElementById('baixa_forma').value = lancamento.forma_pagamento;
    }
    document.getElementById('modalBaixa').style.display = 'flex';
}
function fecharModalBaixa() {
    document.getElementById('modalBaixa').style.display = 'none';
}

// Gráfico de Rosca
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('chartDespesas');
    if(ctx) {
        const rawData = <?= json_encode($roscaDados) ?>;
        const labels = rawData.map(d => d.label);
        const values = rawData.map(d => d.total);
        
        // Cores padrão (podem ser lidas do CSS no modo dark/light)
        const colors = ['#0B7A55', '#B45309', '#1D4ED8', '#7E22CE', '#6B6A58'];

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8, font: { family: "'Plus Jakarta Sans', sans-serif", size: 12 } } }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>