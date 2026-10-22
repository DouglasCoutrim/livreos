<?php
// Caminho: /modules/financeiro/contratos.php
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

// Helper para formatar moeda vinda do formulário
function parseMoedaContrato(string $val): float {
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
        header('Location: ' . BASE_URL . '/modules/financeiro/contratos.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'salvar') {
        if (!Auth::hasPermission('financeiro.criar') && !Auth::hasPermission('financeiro.editar')) {
            Session::setFlash('error', 'Sem permissão para salvar contratos.');
            header('Location: ' . BASE_URL . '/modules/financeiro/contratos.php');
            exit;
        }

        $clienteNome = trim($_POST['cliente_nome'] ?? '');
        $titulo = trim($_POST['titulo'] ?? '');
        $valorMensal = parseMoedaContrato($_POST['valor_mensal'] ?? '0');
        $dataInicio = trim($_POST['data_inicio'] ?? date('Y-m-d'));
        $dataFim = trim($_POST['data_fim'] ?? '') ?: null;
        $diaVenc = (int)($_POST['dia_vencimento'] ?? 10);
        $status = trim($_POST['status'] ?? 'ativo');
        $observacoes = trim($_POST['observacoes'] ?? '') ?: null;

        if ($clienteNome !== '' && $titulo !== '' && $valorMensal >= 0) {
            try {
                if ($id > 0) {
                    $stmt = $db->prepare("
                        UPDATE contratos 
                        SET cliente_nome = :cli, titulo = :tit, valor_mensal = :val, 
                            data_inicio = :dini, data_fim = :dfim, dia_vencimento = :dia, 
                            status = :st, observacoes = :obs
                        WHERE id = :id AND empresa_id = :emp
                    ");
                    $stmt->execute([
                        ':cli' => $clienteNome, ':tit' => $titulo, ':val' => $valorMensal,
                        ':dini' => $dataInicio, ':dfim' => $dataFim, ':dia' => $diaVenc,
                        ':st' => $status, ':obs' => $observacoes, ':id' => $id, ':emp' => $empresaId
                    ]);
                    AuditLogger::log('editar', 'financeiro', "Edição do contrato ID {$id}");
                    Session::setFlash('success', 'Contrato atualizado com sucesso!');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO contratos (empresa_id, cliente_nome, titulo, valor_mensal, data_inicio, data_fim, dia_vencimento, status, observacoes) 
                        VALUES (:emp, :cli, :tit, :val, :dini, :dfim, :dia, :st, :obs)
                    ");
                    $stmt->execute([
                        ':emp' => $empresaId, ':cli' => $clienteNome, ':tit' => $titulo, 
                        ':val' => $valorMensal, ':dini' => $dataInicio, ':dfim' => $dataFim, 
                        ':dia' => $diaVenc, ':st' => $status, ':obs' => $observacoes
                    ]);
                    AuditLogger::log('criar', 'financeiro', "Criação de contrato para {$clienteNome}");
                    Session::setFlash('success', 'Contrato cadastrado com sucesso!');
                }
            } catch (Exception $e) {
                error_log("Erro ao salvar contrato: " . $e->getMessage());
                Session::setFlash('error', 'Erro ao processar contrato.');
            }
        } else {
            Session::setFlash('error', 'Preencha os campos obrigatórios corretamente.');
        }

    } elseif ($acao === 'alterar_status') {
        if (!Auth::hasPermission('financeiro.editar')) {
            Session::setFlash('error', 'Sem permissão para editar contratos.');
            header('Location: ' . BASE_URL . '/modules/financeiro/contratos.php');
            exit;
        }
        $novoStatus = trim($_POST['novo_status'] ?? '');
        $stmtStatus = $db->prepare("UPDATE contratos SET status = :st WHERE id = :id AND empresa_id = :emp");
        $stmtStatus->execute([':st' => $novoStatus, ':id' => $id, ':emp' => $empresaId]);
        AuditLogger::log('editar', 'financeiro', "Status do contrato {$id} alterado para {$novoStatus}");
        Session::setFlash('success', 'Status atualizado.');

    } elseif ($acao === 'excluir') {
        if (!Auth::hasPermission('financeiro.excluir')) {
             Session::setFlash('error', 'Sem permissão para excluir contratos.');
             header('Location: ' . BASE_URL . '/modules/financeiro/contratos.php');
             exit;
        }
        $stmtDel = $db->prepare("DELETE FROM contratos WHERE id = :id AND empresa_id = :emp");
        $stmtDel->execute([':id' => $id, ':emp' => $empresaId]);
        AuditLogger::log('excluir', 'financeiro', "Exclusão do contrato ID {$id}");
        Session::setFlash('success', 'Contrato excluído permanentemente.');
    }
    
    header('Location: ' . BASE_URL . '/modules/financeiro/contratos.php');
    exit;
}

// ============================================================================
// FILTROS E PAGINAÇÃO
// ============================================================================
$busca = trim($_GET['busca'] ?? '');
$fStatus = trim($_GET['status'] ?? '');

$where = ["empresa_id = :emp"];
$params = [':emp' => $empresaId];

if ($busca !== '') {
    $where[] = "(cliente_nome LIKE :b1 OR titulo LIKE :b2)";
    $params[':b1'] = "%$busca%"; 
    $params[':b2'] = "%$busca%";
}
if ($fStatus !== '') {
    $where[] = "status = :status";
    $params[':status'] = $fStatus;
}

$whereClause = implode(" AND ", $where);

// Paginação
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$stmtCount = $db->prepare("SELECT COUNT(id) FROM contratos WHERE $whereClause");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$stmtList = $db->prepare("SELECT * FROM contratos WHERE $whereClause ORDER BY id DESC LIMIT $limit OFFSET $offset");
$stmtList->execute($params);
$contratos = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// ============================================================================
// KPIs E GRÁFICOS
// ============================================================================
$stmtKpis = $db->prepare("
    SELECT 
        COUNT(id) as total_contratos,
        SUM(CASE WHEN status = 'ativo' THEN 1 ELSE 0 END) as ativos,
        SUM(CASE WHEN status = 'ativo' THEN valor_mensal ELSE 0 END) as mrr,
        SUM(CASE WHEN status = 'suspenso' THEN 1 ELSE 0 END) as suspensos
    FROM contratos WHERE empresa_id = :emp AND status != 'cancelado'
");
$stmtKpis->execute([':emp' => $empresaId]);
$kpis = $stmtKpis->fetch(PDO::FETCH_ASSOC);

// Gráfico de Rosca: Status dos contratos
$stmtRosca = $db->prepare("
    SELECT status, COUNT(id) as total
    FROM contratos
    WHERE empresa_id = :emp
    GROUP BY status
");
$stmtRosca->execute([':emp' => $empresaId]);
$roscaDados = $stmtRosca->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Contratos Digitais';
$activeModule = 'financeiro';
$activeSub = 'contratos';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Cabeçalho do Módulo -->
<div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
    <div>
        <div style="color: var(--primary); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Módulo Financeiro</div>
        <h1 class="ct" style="font-size: 28px; font-weight: 800; margin: 0; line-height: 1.2;">Contratos Recorrentes</h1>
        <div class="kl" style="margin-top: 4px;">Geração, acompanhamento e MRR (Receita Recorrente) em um só fluxo.</div>
    </div>
    <?php if(Auth::hasPermission('financeiro.criar')): ?>
        <button type="button" class="btn btn-primary-brand" onclick="abrirModalContrato()" style="display:inline-flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
            Novo Contrato
        </button>
    <?php endif; ?>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="margin-bottom: 24px;">
    <!-- KPI 1: MRR (Verde) -->
    <div class="card kpi-card tone-green">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Receita Recorrente (MRR)</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"></path></svg></div>
        </div>
        <div class="kpi-value"><?= Security::formatMoney((float)$kpis['mrr']) ?></div>
        <div class="kpi-variation">Projeção mensal de contratos ativos</div>
    </div>
    
    <!-- KPI 2: Ativos (Azul) -->
    <div class="card kpi-card tone-blue">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Contratos Ativos</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
        </div>
        <div class="kpi-value"><?= (int)$kpis['ativos'] ?></div>
        <div class="kpi-variation"><?= $kpis['total_contratos'] > 0 ? round(($kpis['ativos'] / $kpis['total_contratos']) * 100) : 0 ?>% do total</div>
    </div>

    <!-- KPI 3: Suspensos (Laranja) -->
    <div class="card kpi-card tone-orange">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Pendentes / Suspensos</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
        </div>
        <div class="kpi-value"><?= (int)$kpis['suspensos'] ?></div>
        <div class="kpi-variation">Requerem atenção ou assinatura</div>
    </div>

    <!-- KPI 4: Total Geral (Roxo) -->
    <div class="card kpi-card tone-purple">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Total de Contratos</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
        </div>
        <div class="kpi-value"><?= (int)$kpis['total_contratos'] ?></div>
        <div class="kpi-variation">Cadastrados no sistema</div>
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
                        <label class="form-label-os">Buscar</label>
                        <input type="text" name="busca" class="form-control-os" placeholder="Cliente ou Serviço..." value="<?= Security::e($busca) ?>">
                    </div>
                    <div style="width: 150px;">
                        <label class="form-label-os">Status</label>
                        <select name="status" class="form-select-os">
                            <option value="">Todos</option>
                            <option value="ativo" <?= $fStatus === 'ativo' ? 'selected' : '' ?>>Ativos (Assinados)</option>
                            <option value="suspenso" <?= $fStatus === 'suspenso' ? 'selected' : '' ?>>Suspensos (Pendentes)</option>
                            <option value="encerrado" <?= $fStatus === 'encerrado' ? 'selected' : '' ?>>Encerrados</option>
                            <option value="cancelado" <?= $fStatus === 'cancelado' ? 'selected' : '' ?>>Cancelados</option>
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
                            <th>Contrato</th>
                            <th>Cliente</th>
                            <th>Produto / Serviço</th>
                            <th>Valor Mensal</th>
                            <th>Status</th>
                            <th style="text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contratos)): ?>
                            <tr><td colspan="6" style="text-align: center; padding: 30px; color: var(--muted);">Nenhum contrato encontrado.</td></tr>
                        <?php else: foreach ($contratos as $c): ?>
                            <tr>
                                <td><b style="color: var(--primary);">#<?= str_pad((string)$c['id'], 4, '0', STR_PAD_LEFT) ?></b></td>
                                <td><div style="font-weight: 600; color: var(--text);"><?= Security::e($c['cliente_nome']) ?></div></td>
                                <td>
                                    <div><?= Security::e($c['titulo']) ?></div>
                                    <div class="kl" style="font-size: 12px;">Início: <?= Security::formatDate($c['data_inicio']) ?></div>
                                </td>
                                <td><b style="color: var(--text);"><?= Security::formatMoney((float)$c['valor_mensal']) ?></b></td>
                                <td>
                                    <?php
                                        $chip = 'chip-info'; $lbl = ucfirst($c['status']);
                                        if($c['status'] === 'ativo') { $chip = 'chip-ok'; $lbl = 'Ativo'; }
                                        elseif($c['status'] === 'suspenso') { $chip = 'chip-warn'; $lbl = 'Aguardando'; }
                                        elseif($c['status'] === 'cancelado') { $chip = 'chip-bad'; }
                                        elseif($c['status'] === 'encerrado') { $chip = 'chip-info'; }
                                    ?>
                                    <span class="bd <?= $chip ?>"><?= $lbl ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <div style="position: relative; display: inline-block;" class="dropdown-acao">
                                        <button type="button" class="btn btn-theme" style="padding: 4px 8px;" onclick="this.nextElementSibling.classList.toggle('show')">⋮</button>
                                        <div class="dropdown-menu-os" style="position: absolute; right: 0; top: 100%; z-index: 100; background: var(--card); border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 180px; text-align: left; display: none;">
                                            <a href="#" onclick='editarContrato(<?= htmlspecialchars(json_encode($c)) ?>); return false;' style="display: block; padding: 10px 16px; color: var(--text); text-decoration: none; border-bottom: 1px solid var(--border);">✏️ Editar Contrato</a>
                                            
                                            <?php if($c['status'] === 'ativo'): ?>
                                                <form method="post" onsubmit="return confirm('Suspender este contrato?');">
                                                    <?= Security::csrfField() ?><input type="hidden" name="acao" value="alterar_status"><input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="novo_status" value="suspenso">
                                                    <button type="submit" style="width: 100%; text-align: left; background: none; border: none; padding: 10px 16px; color: var(--text); cursor: pointer; border-bottom: 1px solid var(--border);">⏸️ Suspender</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" onsubmit="return confirm('Ativar este contrato?');">
                                                    <?= Security::csrfField() ?><input type="hidden" name="acao" value="alterar_status"><input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="novo_status" value="ativo">
                                                    <button type="submit" style="width: 100%; text-align: left; background: none; border: none; padding: 10px 16px; color: #0B7A55; cursor: pointer; border-bottom: 1px solid var(--border);">▶️ Ativar</button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="post" onsubmit="return confirm('Excluir este contrato permanentemente?');">
                                                <?= Security::csrfField() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                <button type="submit" style="width: 100%; text-align: left; background: none; border: none; padding: 10px 16px; color: #DC2626; cursor: pointer;">🗑️ Excluir</button>
                                            </form>
                                        </div>
                                    </div>
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
                    <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&busca=<?= urlencode($busca) ?>" class="btn btn-theme">Anterior</a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>&busca=<?= urlencode($busca) ?>" class="btn btn-theme">Próxima</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Coluna Direita: Cards Auxiliares -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Status dos Contratos (Donut Chart) -->
        <div class="card" style="padding: 20px;">
            <div style="font-weight: 700; margin-bottom: 16px; color: var(--text);">Status dos contratos</div>
            <div style="position: relative; height: 200px; width: 100%; display: flex; justify-content: center; align-items: center;">
                <?php if (empty($roscaDados)): ?>
                    <div class="kl">Sem dados para exibir.</div>
                <?php else: ?>
                    <canvas id="chartContratos"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ações Rápidas -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 700; color: var(--text);">Ações rápidas</div>
            <div style="display: flex; flex-direction: column;">
                <a href="#" onclick="abrirModalContrato(); return false;" style="display: flex; justify-content: space-between; padding: 14px 20px; color: var(--text2); text-decoration: none; border-bottom: 1px solid var(--grid);">
                    <span style="display: flex; align-items: center; gap: 8px;"><span style="color:var(--muted)">+</span> Novo contrato</span>
                    <span style="color: var(--muted);">›</span>
                </a>
                <a href="#" style="display: flex; justify-content: space-between; padding: 14px 20px; color: var(--text2); text-decoration: none; border-bottom: 1px solid var(--grid);">
                    <span style="display: flex; align-items: center; gap: 8px;"><span style="color:var(--muted)">✉</span> Enviar para assinatura</span>
                    <span style="color: var(--muted);">›</span>
                </a>
                <a href="#" style="display: flex; justify-content: space-between; padding: 14px 20px; color: var(--text2); text-decoration: none;">
                    <span style="display: flex; align-items: center; gap: 8px;"><span style="color:var(--muted)">📄</span> Modelos de contrato</span>
                    <span style="color: var(--muted);">›</span>
                </a>
            </div>
        </div>

    </div>
</div>

<!-- Modal Salvar Contrato -->
<div id="modalContrato" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; padding: 16px;">
    <div class="card" style="width: 100%; max-width: 600px; padding: 24px; position: relative; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 12px;">
            <h3 id="modalContratoTitle" style="margin: 0; font-size: 18px; color: var(--text);">Novo Contrato</h3>
            <button type="button" onclick="fecharModalContrato()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--muted);">&times;</button>
        </div>
        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" id="c_id" value="0">
            
            <div class="row g-3" style="margin-bottom: 16px;">
                <div class="col-md-12">
                    <label class="form-label-os">Cliente (Nome ou Razão Social) *</label>
                    <input type="text" name="cliente_nome" id="c_cliente" class="form-control-os" required>
                </div>
                <div class="col-md-12">
                    <label class="form-label-os">Produto ou Serviço (Título) *</label>
                    <input type="text" name="titulo" id="c_titulo" class="form-control-os" placeholder="Ex: Suporte Premium Mensal" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label-os">Valor Mensal (R$) *</label>
                    <input type="text" name="valor_mensal" id="c_valor" class="form-control-os" data-mask="money" value="0,00" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label-os">Dia de Vencimento *</label>
                    <input type="number" name="dia_vencimento" id="c_dia" class="form-control-os" min="1" max="31" value="10" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label-os">Data de Início *</label>
                    <input type="date" name="data_inicio" id="c_inicio" class="form-control-os" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label-os">Data Final (Opcional)</label>
                    <input type="date" name="data_fim" id="c_fim" class="form-control-os">
                </div>
                <div class="col-md-12">
                    <label class="form-label-os">Status Inicial</label>
                    <select name="status" id="c_status" class="form-select-os">
                        <option value="ativo">Ativo (Assinado)</option>
                        <option value="suspenso">Aguardando (Suspenso)</option>
                        <option value="encerrado">Encerrado</option>
                        <option value="cancelado">Cancelado</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label-os">Observações</label>
                    <textarea name="observacoes" id="c_obs" rows="2" class="form-control-os"></textarea>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                <button type="button" class="btn btn-theme" onclick="fecharModalContrato()">Cancelar</button>
                <button type="submit" class="btn btn-primary-brand">Salvar Contrato</button>
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

// Funções do Modal
function abrirModalContrato() {
    document.getElementById('c_id').value = '0';
    document.getElementById('c_cliente').value = '';
    document.getElementById('c_titulo').value = '';
    document.getElementById('c_valor').value = '0,00';
    document.getElementById('c_dia').value = '10';
    document.getElementById('c_inicio').value = '<?= date('Y-m-d') ?>';
    document.getElementById('c_fim').value = '';
    document.getElementById('c_status').value = 'ativo';
    document.getElementById('c_obs').value = '';
    document.getElementById('modalContratoTitle').innerText = 'Novo Contrato';
    document.getElementById('modalContrato').style.display = 'flex';
}

function editarContrato(c) {
    document.getElementById('c_id').value = c.id;
    document.getElementById('c_cliente').value = c.cliente_nome;
    document.getElementById('c_titulo').value = c.titulo;
    document.getElementById('c_valor').value = parseFloat(c.valor_mensal).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    document.getElementById('c_dia').value = c.dia_vencimento;
    document.getElementById('c_inicio').value = c.data_inicio;
    document.getElementById('c_fim').value = c.data_fim || '';
    document.getElementById('c_status').value = c.status;
    document.getElementById('c_obs').value = c.observacoes || '';
    document.getElementById('modalContratoTitle').innerText = 'Editar Contrato #' + String(c.id).padStart(4, '0');
    document.getElementById('modalContrato').style.display = 'flex';
}

function fecharModalContrato() {
    document.getElementById('modalContrato').style.display = 'none';
}

// Gráfico de Rosca (Status dos Contratos)
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('chartContratos');
    if (ctx) {
        const rawData = <?= json_encode($roscaDados) ?>;
        const statusMap = {
            'ativo': { label: 'Assinados', color: '#0B7A55' }, // Verde
            'suspenso': { label: 'Pendentes', color: '#B45309' }, // Laranja
            'cancelado': { label: 'Cancelados', color: '#9AA89F' }, // Cinza
            'encerrado': { label: 'Em Análise', color: '#7E22CE' } // Roxo
        };
        
        const labels = rawData.map(d => statusMap[d.status] ? statusMap[d.status].label : d.status);
        const values = rawData.map(d => parseInt(d.total));
        const colors = rawData.map(d => statusMap[d.status] ? statusMap[d.status].color : '#cccccc');

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
                    legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8, font: { family: "'Plus Jakarta Sans', sans-serif", size: 12 } } }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>