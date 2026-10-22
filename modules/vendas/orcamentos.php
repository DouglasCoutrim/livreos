<?php
// Caminho: /modules/vendas/orcamentos.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('vendas.ver')) {
    if (isset($_REQUEST['ajax'])) {
        echo json_encode(['status' => 'error', 'message' => 'Sem permissão.']); exit;
    }
    Session::setFlash('error', 'Sem permissão para acessar o módulo de Orçamentos.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();

// ============================================================================
// 1. API: PROCESSAMENTO DE AÇÕES (VIA AJAX POST)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        echo json_encode(['status' => 'error', 'message' => 'Token de segurança inválido.']); exit;
    }

    $acao = $_POST['ajax_action'];
    $id = (int)($_POST['id'] ?? 0);

    try {
        if ($acao === 'converter') {
            if (!Auth::hasPermission('vendas.criar')) throw new Exception('Sem permissão para converter em pedido.');
            
            $stmt = $db->prepare("UPDATE vendas SET tipo = 'pedido', status = 'pendente' WHERE id = :id AND empresa_id = :emp AND tipo = 'orcamento'");
            $stmt->execute([':id' => $id, ':emp' => $empresaId]);
            
            if ($stmt->rowCount() > 0) {
                AuditLogger::log('editar', 'vendas', "Conversão de Orçamento para Pedido (ID {$id})");
                echo json_encode(['status' => 'success', 'message' => 'Orçamento convertido em Pedido com sucesso!']); exit;
            }
            throw new Exception('Erro ao converter orçamento. Verifique se ele já foi convertido.');

        } elseif ($acao === 'cancelar') {
            if (!Auth::hasPermission('vendas.excluir')) throw new Exception('Sem permissão para cancelar.');
            $stmt = $db->prepare("UPDATE vendas SET status = 'cancelado' WHERE id = :id AND empresa_id = :emp AND tipo = 'orcamento' AND status != 'faturado'");
            $stmt->execute([':id' => $id, ':emp' => $empresaId]);
            if ($stmt->rowCount() > 0) {
                AuditLogger::log('editar', 'vendas', "Cancelamento do orçamento ID {$id}");
                echo json_encode(['status' => 'success', 'message' => 'Orçamento cancelado com sucesso.']); exit;
            }
            throw new Exception('Erro ao cancelar o orçamento.');

        } elseif ($acao === 'excluir') {
            if (!Auth::hasPermission('vendas.excluir')) throw new Exception('Sem permissão para excluir.');
            $stmt = $db->prepare("DELETE FROM vendas WHERE id = :id AND empresa_id = :emp AND tipo = 'orcamento'");
            $stmt->execute([':id' => $id, ':emp' => $empresaId]);
            if ($stmt->rowCount() > 0) {
                AuditLogger::log('excluir', 'vendas', "Exclusão permanente do orçamento ID {$id}");
                echo json_encode(['status' => 'success', 'message' => 'Orçamento excluído permanentemente.']); exit;
            }
            throw new Exception('Erro ao tentar excluir o orçamento.');
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit;
    }
}

// ============================================================================
// 2. API: RETORNO DA LISTAGEM (VIA AJAX GET)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_list'])) {
    header('Content-Type: application/json');
    
    $busca = trim($_GET['busca'] ?? '');
    $fStatus = trim($_GET['status'] ?? '');
    $dIni = trim($_GET['data_ini'] ?? '');
    $dFim = trim($_GET['data_fim'] ?? '');

    $where = ["v.empresa_id = :emp", "v.tipo = 'orcamento'"];
    $params = [':emp' => $empresaId];

    if ($busca !== '') {
        $where[] = "(c.nome_razao LIKE :b1 OR vend.nome_fantasia LIKE :b2 OR v.id = :b3)";
        $params[':b1'] = "%$busca%"; $params[':b2'] = "%$busca%"; $params[':b3'] = (int)$busca;
    }
    if ($fStatus !== '') { $where[] = "v.status = :status"; $params[':status'] = $fStatus; }
    if ($dIni !== '' && $dFim !== '') { $where[] = "v.data_emissao BETWEEN :ini AND :fim"; $params[':ini'] = $dIni; $params[':fim'] = $dFim; }

    $whereClause = implode(" AND ", $where);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;

    $stmtCount = $db->prepare("SELECT COUNT(v.id) FROM vendas v LEFT JOIN clientes c ON v.cliente_id = c.id LEFT JOIN vendedores vend ON v.vendedor_id = vend.id WHERE $whereClause");
    $stmtCount->execute($params);
    $totalRecords = (int)$stmtCount->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $stmtList = $db->prepare("SELECT v.id, v.data_emissao, v.data_validade, v.valor_liquido, v.status, v.condicao_pagamento, c.nome_razao as cliente_nome, vend.nome_fantasia as vendedor_nome FROM vendas v LEFT JOIN clientes c ON v.cliente_id = c.id LEFT JOIN vendedores vend ON v.vendedor_id = vend.id WHERE $whereClause ORDER BY v.id DESC LIMIT $limit OFFSET $offset");
    $stmtList->execute($params);
    $orcamentos = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    // Constrói o HTML das linhas da tabela dinamicamente
    ob_start();
    if (empty($orcamentos)) {
        echo '<tr><td colspan="8" style="text-align: center; padding: 30px; color: var(--muted);">Nenhum orçamento encontrado com estes filtros.</td></tr>';
    } else {
        foreach ($orcamentos as $o) {
            $chip = 'chip-info'; $lbl = ucfirst($o['status']);
            if ($o['status'] === 'aprovado') { $chip = 'chip-ok'; }
            elseif ($o['status'] === 'pendente') { $chip = 'chip-warn'; }
            elseif ($o['status'] === 'rascunho') { $chip = 'chip-info'; }
            elseif ($o['status'] === 'cancelado') { $chip = 'chip-bad'; }

            $idFormatado = str_pad((string)$o['id'], 6, '0', STR_PAD_LEFT);
            $dataFmt = Security::formatDate($o['data_emissao']);
            $validadeFmt = $o['data_validade'] ? Security::formatDate($o['data_validade']) : '<span class="kl">N/A</span>';
            $cliNome = Security::e($o['cliente_nome']);
            $condPgto = Security::e($o['condicao_pagamento'] ?: 'À vista');
            $vendNome = Security::e($o['vendedor_nome'] ?: 'Sem Vendedor');
            $valorFmt = Security::formatMoney((float)$o['valor_liquido']);

            // Monta o menu de ações
            $acoesHTML = "";
            $acoesHTML .= "<a href='visualizar_pedido.php?id={$o['id']}' style='display: block; padding: 10px 16px; color: #0B7A55; text-decoration: none; border-bottom: 1px solid var(--border); font-weight: 600;'>📄 Ver / Imprimir Documento</a>";
            $acoesHTML .= "<a href='novo_pedido.php?id={$o['id']}' style='display: block; padding: 10px 16px; color: var(--text); text-decoration: none; border-bottom: 1px solid var(--border);'>✏️ Editar Dados</a>";
            
            if ($o['status'] !== 'cancelado') {
                $acoesHTML .= "<button type='button' onclick=\"executarAcao({$o['id']}, 'converter', 'Deseja converter este orçamento em Pedido de Venda?')\" style='width: 100%; text-align: left; background: none; border: none; padding: 10px 16px; color: var(--primary); cursor: pointer; border-bottom: 1px solid var(--border); font-weight: 600;'>🔄 Converter em Pedido</button>";
            }

            if ($o['status'] !== 'cancelado' && Auth::hasPermission('vendas.excluir')) {
                $acoesHTML .= "<button type='button' onclick=\"executarAcao({$o['id']}, 'cancelar', 'Cancelar este orçamento?')\" style='width: 100%; text-align: left; background: none; border: none; padding: 10px 16px; color: #B45309; cursor: pointer; border-bottom: 1px solid var(--border);'>🚫 Cancelar</button>";
            }

            if (Auth::hasPermission('vendas.excluir')) {
                $acoesHTML .= "<button type='button' onclick=\"executarAcao({$o['id']}, 'excluir', 'ATENÇÃO: Deseja excluir este orçamento permanentemente?')\" style='width: 100%; text-align: left; background: none; border: none; padding: 10px 16px; color: #DC2626; cursor: pointer;'>🗑️ Excluir</button>";
            }

            echo "<tr>
                <td><input type='checkbox' value='{$o['id']}'></td>
                <td><b style='color: var(--primary);'>#{$idFormatado}</b></td>
                <td><div style='font-weight: 600; color: var(--text);'>{$dataFmt}</div><div class='kl' style='font-size: 11px;'>Val: {$validadeFmt}</div></td>
                <td><div style='font-weight: 600; color: var(--text);'>{$cliNome}</div><div class='kl' style='font-size: 12px;'>{$condPgto}</div></td>
                <td><span style='color: var(--text2);'>{$vendNome}</span></td>
                <td style='text-align: right;'><b style='color: #0B7A55;'>{$valorFmt}</b></td>
                <td><span class='bd {$chip}'>{$lbl}</span></td>
                <td style='text-align: right;'>
                    <div style='position: relative; display: inline-block;' class='dropdown-acao'>
                        <button type='button' class='btn btn-theme' style='padding: 4px 8px;' onclick='this.nextElementSibling.classList.toggle(\"show\")'>⋮</button>
                        <div class='dropdown-menu-os' style='position: absolute; right: 0; top: 100%; z-index: 100; background: var(--card); border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 220px; text-align: left; display: none;'>
                            {$acoesHTML}
                        </div>
                    </div>
                </td>
            </tr>";
        }
    }
    $htmlRows = ob_get_clean();

    // Constrói Paginação HTML
    ob_start();
    if ($totalPages > 1) {
        echo "<span class='kl'>Página {$page} de {$totalPages}</span><div style='display: flex; gap: 8px;'>";
        if ($page > 1) { echo "<button type='button' class='btn btn-theme' onclick='loadOrcamentos(".($page-1).")'>Anterior</button>"; }
        if ($page < $totalPages) { echo "<button type='button' class='btn btn-theme' onclick='loadOrcamentos(".($page+1).")'>Próxima</button>"; }
        echo "</div>";
    }
    $htmlPag = ob_get_clean();

    // Atualiza KPIs
    $stmtKpis = $db->prepare("
        SELECT 
            SUM(valor_liquido) as total_orcado,
            SUM(CASE WHEN status = 'aprovado' THEN valor_liquido ELSE 0 END) as total_aprovado,
            COUNT(CASE WHEN status IN ('pendente', 'rascunho') THEN 1 END) as qtd_pendente,
            COUNT(id) as total_qtd,
            COUNT(CASE WHEN status = 'aprovado' THEN 1 END) as qtd_aprovada
        FROM vendas 
        WHERE empresa_id = :emp AND tipo = 'orcamento' AND status != 'cancelado' 
        AND MONTH(data_emissao) = MONTH(CURDATE()) AND YEAR(data_emissao) = YEAR(CURDATE())
    ");
    $stmtKpis->execute([':emp' => $empresaId]);
    $kpisData = $stmtKpis->fetch(PDO::FETCH_ASSOC);

    $taxaConversao = $kpisData['total_qtd'] > 0 ? ($kpisData['qtd_aprovada'] / $kpisData['total_qtd']) * 100 : 0;

    $kpis = [
        'total_orcado' => Security::formatMoney((float)$kpisData['total_orcado']),
        'total_aprovado' => Security::formatMoney((float)$kpisData['total_aprovado']),
        'qtd_pendente' => (int)$kpisData['qtd_pendente'],
        'taxa_conversao' => number_format($taxaConversao, 1, ',', '.') . '%'
    ];

    echo json_encode(['html_rows' => $htmlRows, 'html_pagination' => $htmlPag, 'kpis' => $kpis]);
    exit;
}

// ============================================================================
// 3. RENDERIZAÇÃO DA PÁGINA (CARREGAMENTO INICIAL)
// ============================================================================
$pageTitle = 'Orçamentos de Venda';
$activeModule = 'vendas'; $activeSub = 'orcamentos';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Container de Toast -->
<div id="toastContainer" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

<!-- Cabeçalho do Módulo -->
<div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
    <div>
        <div style="color: var(--primary); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Módulo Vendas</div>
        <h1 class="ct" style="font-size: 28px; font-weight: 800; margin: 0; line-height: 1.2;">Orçamentos</h1>
        <div class="kl" style="margin-top: 4px;">Gestão de propostas comerciais e conversão em pedidos.</div>
    </div>
    <?php if(Auth::hasPermission('vendas.criar')): ?>
        <a href="<?= BASE_URL ?>/modules/vendas/novo_pedido.php?tipo=orcamento" class="btn btn-primary-brand" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Novo Orçamento
        </a>
    <?php endif; ?>
</div>

<!-- Grid de KPIs Dinâmicos -->
<div class="kpi-grid" style="margin-bottom: 24px;">
    <div class="card kpi-card tone-blue">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Total Orçado (Mês)</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"></path></svg></div>
        </div>
        <div class="kpi-value" id="kpiTotalOrcado">R$ 0,00</div>
        <div class="kpi-variation">Propostas enviadas</div>
    </div>
    <div class="card kpi-card tone-green">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Orçamentos Aprovados</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg></div>
        </div>
        <div class="kpi-value" id="kpiTotalAprovado">R$ 0,00</div>
        <div class="kpi-variation">Valor fechado no mês</div>
    </div>
    <div class="card kpi-card tone-orange">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Pendentes / Aguardando</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
        </div>
        <div class="kpi-value" id="kpiQtdPendente">0</div>
        <div class="kpi-variation">Em negociação</div>
    </div>
    <div class="card kpi-card tone-purple">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Taxa de Conversão</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 118 2.83"></path><path d="M22 12A10 10 0 0012 2v10z"></path></svg></div>
        </div>
        <div class="kpi-value" id="kpiTaxaConversao">0,0%</div>
        <div class="kpi-variation">Aprovados vs Total</div>
    </div>
</div>

<!-- Container da Tabela Dinâmica -->
<div style="display: flex; flex-direction: column; gap: 24px;">
    <div class="card" style="padding: 0; overflow: hidden;">
        
        <!-- Filtros -->
        <div style="padding: 20px; border-bottom: 1px solid var(--border);">
            <form id="formFiltros" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
                <input type="hidden" id="global_csrf" name="csrf_token" value="<?= Security::csrfToken() ?>">
                
                <div style="flex: 1; min-width: 200px;">
                    <label class="form-label-os">Busca Rápida</label>
                    <input type="text" name="busca" class="form-control-os" placeholder="Nº, cliente, vendedor...">
                </div>
                <div style="width: 150px;">
                    <label class="form-label-os">Status</label>
                    <select name="status" class="form-select-os">
                        <option value="">Todos</option>
                        <option value="rascunho">Rascunho</option>
                        <option value="pendente">Pendente</option>
                        <option value="aprovado">Aprovado</option>
                        <option value="cancelado">Cancelado</option>
                    </select>
                </div>
                <div style="width: 140px;">
                    <label class="form-label-os">Data Inicial</label>
                    <input type="date" name="data_ini" class="form-control-os" value="<?= date('Y-m-01') ?>">
                </div>
                <div style="width: 140px;">
                    <label class="form-label-os">Data Final</label>
                    <input type="date" name="data_fim" class="form-control-os" value="<?= date('Y-m-t') ?>">
                </div>
            </form>
        </div>

        <div class="table-responsive-os">
            <table class="table-os">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox"></th>
                        <th>Nº Orçamento</th>
                        <th>Data / Validade</th>
                        <th>Cliente</th>
                        <th>Vendedor</th>
                        <th style="text-align: right;">Total (R$)</th>
                        <th>Status</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody id="tbodyOrcamentos">
                    <!-- JS injects rows here -->
                </tbody>
            </table>
        </div>
        
        <!-- Paginador -->
        <div id="paginacaoContainer" style="padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;"></div>
    </div>
</div>

<script>
const formFiltros = document.getElementById('formFiltros');
let searchTimeout;

// 1. Live Search
formFiltros.addEventListener('input', (e) => {
    if(e.target.name === 'busca') {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => loadOrcamentos(1), 400);
    }
});

formFiltros.addEventListener('change', (e) => {
    if(['status', 'data_ini', 'data_fim'].includes(e.target.name)) {
        loadOrcamentos(1);
    }
});

formFiltros.addEventListener('submit', (e) => { e.preventDefault(); loadOrcamentos(1); });

// Fecha dropdowns
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown-acao')) {
        document.querySelectorAll('.dropdown-menu-os.show').forEach(m => m.classList.remove('show'));
    }
});

// 2. Toast
function showToast(mensagem, tipo = 'success') {
    const toast = document.createElement('div');
    const bg = tipo === 'success' ? '#0B7A55' : '#DC2626';
    toast.style.cssText = `background: ${bg}; color: #fff; padding: 12px 24px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-weight: 600; opacity: 0; transition: opacity 0.3s; transform: translateY(-20px);`;
    toast.innerText = mensagem;
    document.getElementById('toastContainer').appendChild(toast);
    setTimeout(() => { toast.style.opacity = '1'; toast.style.transform = 'translateY(0)'; }, 10);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
}

// 3. Load Data
async function loadOrcamentos(page = 1) {
    const tbody = document.getElementById('tbodyOrcamentos');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 30px;"><span style="color:var(--primary);">⏳ A buscar dados...</span></td></tr>';
    
    const params = new URLSearchParams(new FormData(formFiltros));
    params.append('ajax_list', '1');
    params.append('page', page);
    
    try {
        const response = await fetch(`orcamentos.php?${params.toString()}&_=${Date.now()}`);
        const data = await response.json();
        
        tbody.innerHTML = data.html_rows;
        document.getElementById('paginacaoContainer').innerHTML = data.html_pagination;
        
        document.getElementById('kpiTotalOrcado').innerText = data.kpis.total_orcado;
        document.getElementById('kpiTotalAprovado').innerText = data.kpis.total_aprovado;
        document.getElementById('kpiQtdPendente').innerText = data.kpis.qtd_pendente;
        document.getElementById('kpiTaxaConversao').innerText = data.kpis.taxa_conversao;
        
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 30px; color:#DC2626;">Erro ao carregar dados.</td></tr>';
    }
}

// 4. Ações AJAX
async function executarAcao(id, acao, mensagemConfirmacao) {
    if (!confirm(mensagemConfirmacao)) return;
    
    const formData = new FormData();
    formData.append('ajax_action', acao);
    formData.append('id', id);
    formData.append('csrf_token', document.getElementById('global_csrf').value);
    
    try {
        const req = await fetch('orcamentos.php', { method: 'POST', body: formData });
        const res = await req.json();
        
        if (res.status === 'success') {
            showToast(res.message, 'success');
            loadOrcamentos(1);
        } else {
            showToast(res.message, 'error');
        }
    } catch (err) {
        showToast('Erro de comunicação com o servidor.', 'error');
    }
}

document.addEventListener('DOMContentLoaded', () => loadOrcamentos(1));
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
