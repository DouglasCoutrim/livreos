<?php
// Caminho: /modules/vendas/pedidos.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('vendas.ver')) {
    if (isset($_REQUEST['ajax'])) {
        echo json_encode(['status' => 'error', 'message' => 'Sem permissão.']); exit;
    }
    Session::setFlash('error', 'Sem permissão para acessar o módulo de Vendas.');
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
        if ($acao === 'cancelar') {
            if (!Auth::hasPermission('vendas.excluir')) throw new Exception('Sem permissão para cancelar.');
            $stmt = $db->prepare("UPDATE vendas SET status = 'cancelado' WHERE id = :id AND empresa_id = :emp AND status != 'faturado'");
            $stmt->execute([':id' => $id, ':emp' => $empresaId]);
            if ($stmt->rowCount() > 0) {
                AuditLogger::log('editar', 'vendas', "Cancelamento do pedido ID {$id}");
                echo json_encode(['status' => 'success', 'message' => 'Pedido cancelado com sucesso.']); exit;
            }
            throw new Exception('Apenas pedidos não faturados podem ser cancelados.');

        } elseif ($acao === 'excluir') {
            if (!Auth::hasPermission('vendas.excluir')) throw new Exception('Sem permissão para excluir.');
            $stmt = $db->prepare("DELETE FROM vendas WHERE id = :id AND empresa_id = :emp AND status != 'faturado'");
            $stmt->execute([':id' => $id, ':emp' => $empresaId]);
            if ($stmt->rowCount() > 0) {
                AuditLogger::log('excluir', 'vendas', "Exclusão permanente do pedido ID {$id}");
                echo json_encode(['status' => 'success', 'message' => 'Pedido excluído permanentemente.']); exit;
            }
            throw new Exception('Pedidos faturados não podem ser excluídos. Cancele ou estorne antes.');

        } elseif ($acao === 'faturar') {
            if (!Auth::hasPermission('vendas.faturar')) throw new Exception('Sem permissão para faturar.');
            $stmt = $db->prepare("UPDATE vendas SET status = 'faturado' WHERE id = :id AND empresa_id = :emp AND status IN ('pendente', 'aprovado')");
            $stmt->execute([':id' => $id, ':emp' => $empresaId]);
            if ($stmt->rowCount() > 0) {
                AuditLogger::log('faturar', 'vendas', "Faturamento do pedido ID {$id}");
                echo json_encode(['status' => 'success', 'message' => 'Pedido faturado com sucesso!']); exit;
            }
            throw new Exception('Apenas pedidos pendentes ou aprovados podem ser faturados.');
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

    $where = ["v.empresa_id = :emp", "v.tipo = 'pedido'"];
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
    $totalPages = ceil((int)$stmtCount->fetchColumn() / $limit);

    $stmtList =$db->prepare("SELECT v.id, v.data_emissao, v.valor_liquido, v.status, v.condicao_pagamento, c.nome_razao as cliente_nome, vend.nome_fantasia as vendedor_nome FROM vendas v LEFT JOIN clientes c ON v.cliente_id = c.id LEFT JOIN vendedores vend ON v.vendedor_id = vend.id WHERE $whereClause ORDER BY v.id DESC LIMIT $limit OFFSET $offset");
    $stmtList->execute($params);
    $pedidos =$stmtList->fetchAll(PDO::FETCH_ASSOC);

    // Constrói o HTML das linhas da tabela dinamicamente
    ob_start();
    if (empty($pedidos)) {
        echo '<tr><td colspan="8" style="text-align: center; padding: 30px; color: var(--muted);">Nenhum pedido encontrado com estes filtros.</td></tr>';
    } else {
        foreach ($pedidos as $p) {$chip = 'chip-info'; $lbl = ucfirst($p['status']);
            if ($p['status'] === 'faturado') {$chip = 'chip-ok'; }
            elseif ($p['status'] === 'pendente') {$chip = 'chip-warn'; }
            elseif ($p['status'] === 'aprovado') {$chip = 'chip-blue'; }
            elseif ($p['status'] === 'cancelado') {$chip = 'chip-bad'; }

            $idFormatado = str_pad((string)$p['id'], 6, '0', STR_PAD_LEFT);
            $dataFmt = Security::formatDate($p['data_emissao']);
            $cliNome = Security::e($p['cliente_nome']);
            $condPgto = Security::e($p['condicao_pagamento'] ?: 'À vista');
            $vendNome = Security::e($p['vendedor_nome'] ?: 'Sem Vendedor');
            $valorFmt = Security::formatMoney((float)$p['valor_liquido']);

            // Monta o menu de ações baseado nas permissões e status
            $acoesHTML = "";
            $acoesHTML .= "<a href='visualizar_pedido.php?id={$p['id']}' style='display: block; padding: 10px 16px; color: #0B7A55; text-decoration: none; border-bottom: 1px solid var(--border); font-weight: 600;'>📄 Ver / Imprimir Documento</a>";
            $acoesHTML .= "<a href='novo_pedido.php?id={$p['id']}' style='display: block; padding: 10px 16px; color: var(--text); text-decoration: none; border-bottom: 1px solid var(--border);'>✏️ Editar Dados</a>";
            
            if (in_array($p['status'], ['pendente', 'aprovado']) && Auth::hasPermission('vendas.faturar')) {$acoesHTML .= "<button type='button' onclick=\"executarAcao({$p['id']}, 'faturar', 'Deseja realmente faturar este pedido?')\" style='width: 100%; text-align: left; background: none; border: none; padding: 10px 16px; color: var(--text); cursor: pointer; border-bottom: 1px solid var(--border);'>✅ Faturar</button>";
            }
            if ($p['status'] !== 'faturado' && $p['status'] !== 'cancelado' && Auth::hasPermission('vendas.excluir')) {$acoesHTML .= "<button type='button' onclick=\"executarAcao({$p['id']}, 'cancelar', 'Cancelar este pedido?')\" style='width: 100%; text-align: left; background: none; border: none; padding: 10px 16px; color: #B45309; cursor: pointer; border-bottom: 1px solid var(--border);'>🚫 Cancelar</button>";
            }
            if (Auth::hasPermission('vendas.excluir')) {
                $acoesHTML .= "<button type='button' onclick=\"executarAcao({$p['id']}, 'excluir', 'ATENÇÃO: Deseja excluir este pedido permanentemente?')\" style='width: 100%; text-align: left; background: none; border: none; padding: 10px 16px; color: #DC2626; cursor: pointer;'>🗑️ Excluir</button>";
            }

            echo "<tr>
                <td><input type='checkbox' value='{$p['id']}'></td>
                <td><b style='color: var(--primary);'>#{$idFormatado}</b></td>
                <td><b style='color: var(--text);'>{$dataFmt}</b></td>
                <td><div style='font-weight: 600; color: var(--text);'>{$cliNome}</div><div class='kl' style='font-size: 12px;'>{$condPgto}</div></td>
                <td><span style='color: var(--text2);'>{$vendNome}</span></td>
                <td style='text-align: right;'><b style='color: #0B7A55;'>{$valorFmt}</b></td>
                <td><span class='bd {$chip}'>{$lbl}</span></td>
                <td style='text-align: right;'>
                    <div style='position: relative; display: inline-block;' class='dropdown-acao'>
                        <button type='button' class='btn btn-theme' style='padding: 4px 8px;' onclick='this.nextElementSibling.classList.toggle(\"show\")'>⋮</button>
                        <div class='dropdown-menu-os' style='position: absolute; right: 0; top: 100%; z-index: 100; background: var(--card); border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 200px; text-align: left; display: none;'>
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
        if ($page > 1) { echo "<button type='button' class='btn btn-theme' onclick='loadPedidos(".($page-1).")'>Anterior</button>"; }
        if ($page <$totalPages) { echo "<button type='button' class='btn btn-theme' onclick='loadPedidos(".($page+1).")'>Próxima</button>"; }
        echo "</div>";
    }
    $htmlPag = ob_get_clean();

    // Atualiza KPIs do mês atual
    $stmtKpis =$db->prepare("SELECT COUNT(id) as total_pedidos, SUM(valor_liquido) as faturamento_total, SUM(CASE WHEN status = 'faturado' THEN valor_liquido ELSE 0 END) as faturamento_efetivo, SUM(CASE WHEN status IN ('pendente', 'aprovado') THEN 1 ELSE 0 END) as pedidos_pendentes FROM vendas WHERE empresa_id = :emp AND tipo = 'pedido' AND status != 'cancelado' AND MONTH(data_emissao) = MONTH(CURDATE()) AND YEAR(data_emissao) = YEAR(CURDATE())");
    $stmtKpis->execute([':emp' =>$empresaId]);
    $kpis =$stmtKpis->fetch(PDO::FETCH_ASSOC);
    $kpis['faturamento_total'] = Security::formatMoney((float)$kpis['faturamento_total']);
    $kpis['faturamento_efetivo'] = Security::formatMoney((float)$kpis['faturamento_efetivo']);
    $kpis['ticket_medio'] = Security::formatMoney((float)($kpis['total_pedidos'] > 0 ? ((float)$kpis['faturamento_total'] /$kpis['total_pedidos']) : 0));

    echo json_encode(['html_rows' => $htmlRows, 'html_pagination' => $htmlPag, 'kpis' =>$kpis]);
    exit;
}

// ============================================================================
// 3. RENDERIZAÇÃO DA PÁGINA (CARREGAMENTO INICIAL)
// ============================================================================
$pageTitle = 'Pedidos de Venda';
$activeModule = 'vendas';$activeSub = 'pedidos';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Container de Toast (Notificações Flutuantes JS) -->
<div id="toastContainer" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

<!-- Cabeçalho do Módulo -->
<div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
    <div>
        <div style="color: var(--primary); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Módulo Vendas</div>
        <h1 class="ct" style="font-size: 28px; font-weight: 800; margin: 0; line-height: 1.2;">Pedidos de Venda</h1>
        <div class="kl" style="margin-top: 4px;">Gestão dinâmica de faturamento e aprovações.</div>
    </div>
    <?php if(Auth::hasPermission('vendas.criar')): ?>
        <a href="<?= BASE_URL ?>/modules/vendas/novo_pedido.php" class="btn btn-primary-brand" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Novo Pedido
        </a>
    <?php endif; ?>
</div>

<!-- Grid de KPIs Dinâmicos -->
<div class="kpi-grid" style="margin-bottom: 24px;">
    <div class="card kpi-card tone-green">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Volume de Vendas (Mês)</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"></path></svg></div>
        </div>
        <div class="kpi-value" id="kpiFatTotal">R$ 0,00</div>
        <div class="kpi-variation">Total negociado</div>
    </div>
    <div class="card kpi-card tone-blue">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Faturado / Concluído</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
        </div>
        <div class="kpi-value" id="kpiFatEfetivo">R$ 0,00</div>
        <div class="kpi-variation">Pedidos já faturados</div>
    </div>
    <div class="card kpi-card tone-purple">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Ticket Médio</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg></div>
        </div>
        <div class="kpi-value" id="kpiTicket">R$ 0,00</div>
        <div class="kpi-variation">Média por pedido</div>
    </div>
    <div class="card kpi-card tone-orange">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Aguardando Faturamento</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
        </div>
        <div class="kpi-value" id="kpiPendentes">0</div>
        <div class="kpi-variation">Pedidos na fila</div>
    </div>
</div>

<!-- Container da Tabela Dinâmica -->
<div style="display: flex; flex-direction: column; gap: 24px;">
    <div class="card" style="padding: 0; overflow: hidden;">
        
        <!-- Formulário de Filtros Dinâmico -->
        <div style="padding: 20px; border-bottom: 1px solid var(--border);">
            <form id="formFiltros" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
                <!-- Usado pelo AJAX para CSRF POST -->
                <input type="hidden" id="global_csrf" name="csrf_token" value="<?= Security::csrfToken() ?>">
                
                <div style="flex: 1; min-width: 200px;">
                    <label class="form-label-os">Busca Rápida (Digite para filtrar)</label>
                    <input type="text" name="busca" class="form-control-os" placeholder="Nº, cliente, vendedor...">
                </div>
                <div style="width: 150px;">
                    <label class="form-label-os">Status</label>
                    <select name="status" class="form-select-os">
                        <option value="">Todos</option>
                        <option value="pendente">Pendente / Novo</option>
                        <option value="aprovado">Aprovado</option>
                        <option value="faturado">Faturado</option>
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
                        <th>Nº Pedido</th>
                        <th>Data Emissão</th>
                        <th>Cliente</th>
                        <th>Vendedor</th>
                        <th style="text-align: right;">Total (R$)</th>
                        <th>Status</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody id="tbodyPedidos">
                    <!-- O JavaScript vai injetar as linhas aqui -->
                </tbody>
            </table>
        </div>
        
        <!-- Paginador Dinâmico -->
        <div id="paginacaoContainer" style="padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;"></div>
    </div>
</div>

<script>
// ============================================================================
// JAVASCRIPT DINÂMICO (FETCH API)
// ============================================================================

const formFiltros = document.getElementById('formFiltros');
let searchTimeout;

// 1. Ouvintes de Eventos (Live Search)
formFiltros.addEventListener('input', (e) => {
    if(e.target.name === 'busca') {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => loadPedidos(1), 400); // 400ms de debounce
    }
});

formFiltros.addEventListener('change', (e) => {
    if(['status', 'data_ini', 'data_fim'].includes(e.target.name)) {
        loadPedidos(1);
    }
});

formFiltros.addEventListener('submit', (e) => { e.preventDefault(); loadPedidos(1); });

// Fecha menus suspensos ao clicar fora
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown-acao')) {
        document.querySelectorAll('.dropdown-menu-os.show').forEach(m => m.classList.remove('show'));
    }
});

// 2. Função de Toast (Notificação Fluida)
function showToast(mensagem, tipo = 'success') {
    const toast = document.createElement('div');
    const bg = tipo === 'success' ? '#0B7A55' : '#DC2626';
    toast.style.cssText = `background: ${bg}; color: #fff; padding: 12px 24px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-weight: 600; opacity: 0; transition: opacity 0.3s; transform: translateY(-20px);`;
    toast.innerText = mensagem;
    
    document.getElementById('toastContainer').appendChild(toast);
    
    // Anima entrada
    setTimeout(() => { toast.style.opacity = '1'; toast.style.transform = 'translateY(0)'; }, 10);
    // Anima saída e remove
    setTimeout(() => { 
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// 3. Função Central de Carregamento da Tabela
async function loadPedidos(page = 1) {
    const tbody = document.getElementById('tbodyPedidos');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 30px;"><span style="color:var(--primary);">⏳ A buscar dados...</span></td></tr>';
    
    const params = new URLSearchParams(new FormData(formFiltros));
    params.append('ajax_list', '1');
    params.append('page', page);
    
    try {
        const response = await fetch(`pedidos.php?${params.toString()}`);
        const data = await response.json();
        
        // Atualiza a tabela e paginação
        tbody.innerHTML = data.html_rows;
        document.getElementById('paginacaoContainer').innerHTML = data.html_pagination;
        
        // Atualiza KPIs
        document.getElementById('kpiFatTotal').innerText = data.kpis.faturamento_total;
        document.getElementById('kpiFatEfetivo').innerText = data.kpis.faturamento_efetivo;
        document.getElementById('kpiTicket').innerText = data.kpis.ticket_medio;
        document.getElementById('kpiPendentes').innerText = data.kpis.pedidos_pendentes;
        
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 30px; color:#DC2626;">Erro ao carregar dados.</td></tr>';
    }
}

// 4. Executa Ações (Faturar, Cancelar, Excluir) sem recarregar a tela
async function executarAcao(id, acao, mensagemConfirmacao) {
    if (!confirm(mensagemConfirmacao)) return;
    
    const formData = new FormData();
    formData.append('ajax_action', acao);
    formData.append('id', id);
    formData.append('csrf_token', document.getElementById('global_csrf').value);
    
    try {
        const req = await fetch('pedidos.php', { method: 'POST', body: formData });
        const res = await req.json();
        
        if (res.status === 'success') {
            showToast(res.message, 'success');
            loadPedidos(1); // Recarrega a tabela para refletir os novos status/KPIs
        } else {
            showToast(res.message, 'error');
        }
    } catch (err) {
        showToast('Erro de comunicação com o servidor.', 'error');
    }
}

// Carrega a primeira página ao iniciar
document.addEventListener('DOMContentLoaded', () => loadPedidos(1));

</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
