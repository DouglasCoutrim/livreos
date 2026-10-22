<?php
// Caminho: /modules/vendas/novo_pedido.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('vendas.criar')) {
    Session::setFlash('error', 'Sem permissão para criar ou editar vendas.');
    header('Location: ' . BASE_URL . '/modules/vendas/pedidos.php');
    exit;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();

// Função auxiliar para converter valores monetários em float no PHP
function parseVendasNum(string $val): float {
    $limpo = preg_replace('/[^\d,-]/', '', trim($val));
    $limpo = str_replace(',', '.', $limpo);
    return is_numeric($limpo) ? (float)$limpo : 0.0;
}

$id = (int)($_GET['id'] ?? 0);

// ============================================================================
// PROCESSAMENTO DO FORMULÁRIO (POST)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        Session::setFlash('error', 'Token de segurança inválido.');
        header("Location: " . BASE_URL . "/modules/vendas/novo_pedido.php" . ($id > 0 ? "?id=$id" : ""));
        exit;
    }

    $vendaId = (int)($_POST['venda_id'] ?? 0);
    $tipo = trim($_POST['tipo'] ?? 'pedido');
    $clienteId = (int)($_POST['cliente_id'] ?? 0);
    $vendedorId = !empty($_POST['vendedor_id']) ? (int)$_POST['vendedor_id'] : null;
    $status = trim($_POST['status'] ?? 'pendente');
    $dataEmissao = trim($_POST['data_emissao'] ?? date('Y-m-d'));
    $dataValidade = trim($_POST['data_validade'] ?? '') ?: null;
    $condicaoPgto = trim($_POST['condicao_pagamento'] ?? '') ?: null;
    $observacoes = trim($_POST['observacoes'] ?? '') ?: null;
    
    // O valor que vem do formulário é o R$ final calculado
    $valorDesconto = parseVendasNum($_POST['valor_desconto'] ?? '0');

    $arrProdutos = $_POST['produto_id'] ?? [];
    $arrQtds = $_POST['quantidade'] ?? [];
    $arrValores = $_POST['valor_unitario'] ?? [];

    if ($clienteId > 0 && !empty($arrProdutos)) {
        try {
            // Debug: Logar entrada no processamento
            error_log("Iniciando salvamento de venda POST: Cliente=$clienteId, Tipo=$tipo");

            $db->beginTransaction();

            $valorBruto = 0.0;
            $itensProcessados = [];
            
            for ($i = 0; $i < count($arrProdutos); $i++) {
                $pId = (int)$arrProdutos[$i];
                $qtd = (int)($arrQtds[$i] ?? 0);
                $vUnit = parseVendasNum($arrValores[$i] ?? '0');
                
                if ($pId > 0 && $qtd > 0) {
                    $subtotal = $qtd * $vUnit;
                    $valorBruto += $subtotal;
                    $itensProcessados[] = [
                        'produto_id' => $pId,
                        'quantidade' => $qtd,
                        'valor_unitario' => $vUnit,
                        'subtotal' => $subtotal
                    ];
                }
            }

            $valorLiquido = $valorBruto - $valorDesconto;
            if ($valorLiquido < 0) $valorLiquido = 0;

            if (empty($itensProcessados)) {
                throw new Exception("Nenhum item válido com quantidade informada.");
            }

            if ($vendaId > 0) {
                $stmtVenda = $db->prepare("
                    UPDATE vendas 
                    SET cliente_id = :cli, vendedor_id = :vend, tipo = :tipo, status = :st, 
                        data_emissao = :demis, data_validade = :dval, valor_bruto = :vbrut, 
                        valor_desconto = :vdesc, valor_liquido = :vliq, condicao_pagamento = :cond, observacoes = :obs
                    WHERE id = :id AND empresa_id = :emp
                ");
                $stmtVenda->execute([
                    ':cli' => $clienteId, ':vend' => $vendedorId, ':tipo' => $tipo, ':st' => $status,
                    ':demis' => $dataEmissao, ':dval' => $dataValidade, ':vbrut' => $valorBruto,
                    ':vdesc' => $valorDesconto, ':vliq' => $valorLiquido, ':cond' => $condicaoPgto,
                    ':obs' => $observacoes, ':id' => $vendaId, ':emp' => $empresaId
                ]);
                AuditLogger::log('editar', 'vendas', "Edição da Venda/Orçamento ID {$vendaId}");
            } else {
                $stmtVenda = $db->prepare("
                    INSERT INTO vendas (empresa_id, cliente_id, vendedor_id, tipo, status, data_emissao, data_validade, valor_bruto, valor_desconto, valor_liquido, condicao_pagamento, observacoes) 
                    VALUES (:emp, :cli, :vend, :tipo, :st, :demis, :dval, :vbrut, :vdesc, :vliq, :cond, :obs)
                ");
                $stmtVenda->execute([
                    ':emp' => $empresaId, ':cli' => $clienteId, ':vend' => $vendedorId, ':tipo' => $tipo, ':st' => $status,
                    ':demis' => $dataEmissao, ':dval' => $dataValidade, ':vbrut' => $valorBruto,
                    ':vdesc' => $valorDesconto, ':vliq' => $valorLiquido, ':cond' => $condicaoPgto, ':obs' => $observacoes
                ]);
                $vendaId = (int)$db->lastInsertId();
                AuditLogger::log('criar', 'vendas', "Nova Venda/Orçamento gerada (ID {$vendaId})");
            }

            $db->prepare("DELETE FROM vendas_itens WHERE venda_id = :vid")->execute([':vid' => $vendaId]);
            $stmtItem = $db->prepare("INSERT INTO vendas_itens (venda_id, produto_id, quantidade, valor_unitario, subtotal) VALUES (:vid, :pid, :qtd, :vu, :subt)");
            
            foreach ($itensProcessados as $it) {
                $stmtItem->execute([
                    ':vid' => $vendaId, ':pid' => $it['produto_id'], ':qtd' => $it['quantidade'],
                    ':vu' => $it['valor_unitario'], ':subt' => $it['subtotal']
                ]);
            }

            $db->commit();
            error_log("Venda salva com sucesso ID: $vendaId");
            Session::setFlash('success', 'Pedido / Orçamento salvo com sucesso!');
            header('Location: ' . BASE_URL . '/modules/vendas/' . ($tipo === 'orcamento' ? 'orcamentos.php' : 'pedidos.php'));
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("Erro ao salvar venda: " . $e->getMessage());
            Session::setFlash('error', 'Erro ao salvar: ' . $e->getMessage());
        }
    } else {
        Session::setFlash('error', 'Selecione um cliente e adicione pelo menos um item.');
    }

    // Se chegou aqui, é porque houve erro. Repopula os dados para não "desaparecer"
    $venda = [
        'id' => $vendaId, 'tipo' => $tipo, 'cliente_id' => $clienteId, 'vendedor_id' => $vendedorId,
        'status' => $status, 'data_emissao' => $dataEmissao, 'data_validade' => $dataValidade,
        'condicao_pagamento' => $condicaoPgto, 'valor_bruto' => $valorBruto ?? 0,
        'valor_desconto' => $valorDesconto, 'valor_liquido' => $valorLiquido ?? 0, 'observacoes' => $observacoes
    ];
    $itensVenda = [];
    foreach ($arrProdutos as $i => $pId) {
        if (!empty($pId)) {
            $itensVenda[] = [
                'produto_id' => (int)$pId,
                'quantidade' => (int)($arrQtds[$i] ?? 0),
                'valor_unitario' => parseVendasNum($arrValores[$i] ?? '0'),
            ];
        }
    }
}

// ============================================================================
// CARREGAR DADOS INICIAIS (SE NÃO FOR POST COM ERRO)
// ============================================================================
if (!isset($venda)) {
    $venda = [
        'id' => 0, 
        'tipo' => (isset($_GET['tipo']) && $_GET['tipo'] === 'orcamento') ? 'orcamento' : 'pedido',
        'cliente_id' => '', 'vendedor_id' => '', 'status' => 'pendente',
        'data_emissao' => date('Y-m-d'), 'data_validade' => '', 'condicao_pagamento' => '',
        'valor_bruto' => 0, 'valor_desconto' => 0, 'valor_liquido' => 0, 'observacoes' => ''
    ];
    $itensVenda = [];

    if ($id > 0) {
        $stmtV = $db->prepare("SELECT * FROM vendas WHERE id = :id AND empresa_id = :emp");
        $stmtV->execute([':id' => $id, ':emp' => $empresaId]);
        $vendaTemp = $stmtV->fetch(PDO::FETCH_ASSOC);
        if ($vendaTemp) {
            $venda = $vendaTemp;
            $stmtI = $db->prepare("SELECT * FROM vendas_itens WHERE venda_id = :vid");
            $stmtI->execute([':vid' => $id]);
            $itensVenda = $stmtI->fetchAll(PDO::FETCH_ASSOC);
        } else {
            Session::setFlash('error', 'Venda não encontrada.');
            header('Location: ' . BASE_URL . '/modules/vendas/pedidos.php');
            exit;
        }
    }
}

$clientes = $db->prepare("SELECT id, nome_razao, cpf_cnpj FROM clientes WHERE empresa_id = :emp AND ativo = 1 ORDER BY nome_razao ASC");
$clientes->execute([':emp' => $empresaId]);
$listaClientes = $clientes->fetchAll(PDO::FETCH_ASSOC);

$vendedores = $db->prepare("SELECT id, nome_fantasia FROM vendedores WHERE empresa_id = :emp AND ativo = 1 ORDER BY nome_fantasia ASC");
$vendedores->execute([':emp' => $empresaId]);
$listaVendedores = $vendedores->fetchAll(PDO::FETCH_ASSOC);

$produtos = $db->prepare("SELECT id, nome, sku, preco_venda, estoque_atual FROM produtos WHERE empresa_id = :emp AND ativo = 1 ORDER BY nome ASC");
$produtos->execute([':emp' => $empresaId]);
$listaProdutos = $produtos->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = $id > 0 ? 'Editar Pedido #' . str_pad((string)$id, 6, '0', STR_PAD_LEFT) : 'Novo Pedido / Orçamento';
$activeModule = 'vendas';
$activeSub = 'novo_pedido';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
    <div>
        <div style="color: var(--primary); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Módulo Vendas</div>
        <h1 class="ct" style="font-size: 28px; font-weight: 800; margin: 0; line-height: 1.2;"><?= $pageTitle ?></h1>
        <div class="kl" style="margin-top: 4px;">Preencha os dados e adicione os itens negociados.</div>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="<?= BASE_URL ?>/modules/vendas/pedidos.php" class="btn btn-theme" style="text-decoration:none; display:inline-flex; align-items:center;">Cancelar</a>
        <button type="submit" form="formVenda" class="btn btn-primary-brand" style="display:inline-flex; align-items:center; gap:8px;">
            💾 Salvar Pedido
        </button>
    </div>
</div>

<form method="POST" id="formVenda">
    <?= Security::csrfField() ?>
    <input type="hidden" name="venda_id" value="<?= $venda['id'] ?>">

    <!-- BLOCO 1: Cabeçalho -->
    <div class="card" style="padding: 24px; margin-bottom: 24px;">
        <div class="ct" style="font-size: 16px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 8px;">Dados da Venda</div>
        <div class="row g-3">
            <div class="col-md-2">
                <label class="form-label-os">Tipo de Registo *</label>
                <select name="tipo" class="form-select-os" required>
                    <option value="pedido" <?= $venda['tipo'] === 'pedido' ? 'selected' : '' ?>>Pedido de Venda</option>
                    <option value="orcamento" <?= $venda['tipo'] === 'orcamento' ? 'selected' : '' ?>>Orçamento</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label-os">Cliente *</label>
                <select name="cliente_id" class="form-select-os" required>
                    <option value="">Selecione um cliente...</option>
                    <?php foreach ($listaClientes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $venda['cliente_id'] == $c['id'] ? 'selected' : '' ?>>
                            <?= Security::e($c['nome_razao']) ?> <?= $c['cpf_cnpj'] ? '('.$c['cpf_cnpj'].')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label-os">Vendedor (Opcional)</label>
                <select name="vendedor_id" class="form-select-os">
                    <option value="">Sem Vendedor</option>
                    <?php foreach ($listaVendedores as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= $venda['vendedor_id'] == $v['id'] ? 'selected' : '' ?>><?= Security::e($v['nome_fantasia']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label-os">Status Inicial</label>
                <select name="status" class="form-select-os">
                    <option value="rascunho" <?= $venda['status'] === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                    <option value="pendente" <?= $venda['status'] === 'pendente' ? 'selected' : '' ?>>Pendente / Novo</option>
                    <option value="aprovado" <?= $venda['status'] === 'aprovado' ? 'selected' : '' ?>>Aprovado</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label-os">Data de Emissão *</label>
                <input type="date" name="data_emissao" class="form-control-os" value="<?= Security::e($venda['data_emissao']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label-os">Válido até (Opcional)</label>
                <input type="date" name="data_validade" class="form-control-os" value="<?= Security::e($venda['data_validade'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label-os">Condição de Pagamento</label>
                <input type="text" name="condicao_pagamento" class="form-control-os" placeholder="Ex: Entrada 50% + 30 dias Boleto" value="<?= Security::e($venda['condicao_pagamento'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- BLOCO 2: Itens do Pedido -->
    <div class="card" style="padding: 0; overflow: hidden; margin-bottom: 24px;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg);">
            <div class="ct" style="font-size: 16px;">Itens Negociados</div>
            <button type="button" class="btn btn-theme" onclick="adicionarItem()">+ Adicionar Linha</button>
        </div>
        <div class="table-responsive-os">
            <table class="table-os" id="tabelaItens">
                <thead>
                    <tr>
                        <th style="min-width: 300px;">Produto / Serviço</th>
                        <th style="width: 120px;">Estoque Ref.</th>
                        <th style="text-align: center; width: 140px;">Quantidade</th>
                        <th style="text-align: right; width: 180px;">Valor Unitário (R$)</th>
                        <th style="text-align: right; width: 180px;">Subtotal (R$)</th>
                        <th style="text-align: center; width: 60px;">Ação</th>
                    </tr>
                </thead>
                <tbody id="tbodyItens">
                    <!-- Linhas injetadas via JS -->
                </tbody>
            </table>
        </div>
        <?php if (empty($itensVenda)): ?>
            <div id="emptyItens" style="padding: 30px; text-align: center; color: var(--muted);">Adicione produtos a este pedido.</div>
        <?php endif; ?>
    </div>

    <!-- BLOCO 3: Totais e Fechamento -->
    <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 24px; align-items: start;" class="main-grid-responsive">
        <div class="card" style="padding: 24px;">
            <div class="ct" style="font-size: 15px; margin-bottom: 12px;">Observações Internas / Cliente</div>
            <textarea name="observacoes" rows="5" class="form-control-os" placeholder="Detalhes da entrega, avisos, etc..."><?= Security::e($venda['observacoes'] ?? '') ?></textarea>
        </div>

        <div class="card" style="padding: 24px; background: var(--primary-soft); border-color: var(--primary);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <span class="kl" style="color: var(--text);">Total Bruto dos Itens</span>
                <b id="lblBruto" style="font-size: 16px; color: var(--text);">0,00</b>
            </div>
            
            <!-- Linha de Desconto com Interatividade (%) e (R$) -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 16px;">
                <span class="kl" style="color: var(--text);">Desconto / Abatimento</span>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <div style="position: relative;">
                        <span style="position: absolute; left: 10px; top: 9px; color: var(--muted); font-size: 12px;">%</span>
                        <input type="text" id="inputDescontoPerc" class="form-control-os" data-mask="money" style="width: 80px; text-align: right; padding-left: 24px; color: #DC2626;" value="0,00">
                    </div>
                    <span class="kl" style="font-weight: 600;">=</span>
                    <div style="position: relative;">
                        <span style="position: absolute; left: 10px; top: 9px; color: var(--muted); font-size: 12px;">R$</span>
                        <input type="text" name="valor_desconto" id="inputDescontoVal" class="form-control-os" data-mask="money" style="width: 120px; text-align: right; font-weight: bold; color: #DC2626; padding-left: 30px;" value="<?= number_format((float)$venda['valor_desconto'], 2, ',', '.') ?>">
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span class="ct" style="color: var(--primary-strong); font-size: 18px;">Total Líquido</span>
                <b id="lblLiquido" style="font-size: 24px; color: var(--primary-strong);">0,00</b>
            </div>
        </div>
    </div>
</form>

<!-- Template invisível para clonagem de linha -->
<table style="display: none;">
    <tbody id="templateLinha">
        <tr>
            <td>
                <select name="produto_id[]" class="form-select-os select-produto" required onchange="selecionarProduto(this)">
                    <option value="">Selecione...</option>
                    <?php foreach ($listaProdutos as $p): ?>
                        <option value="<?= $p['id'] ?>" data-preco="<?= $p['preco_venda'] ?>" data-estoque="<?= $p['estoque_atual'] ?>">
                            <?= Security::e($p['nome']) ?> <?= $p['sku'] ? "({$p['sku']})" : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <span class="kl span-estoque">0</span>
            </td>
            <td style="text-align: center;">
                <input type="number" name="quantidade[]" class="form-control-os input-qtd" min="1" step="1" value="1" style="text-align: center; width: 100px; margin: 0 auto;" required>
            </td>
            <td>
                <input type="text" name="valor_unitario[]" class="form-control-os input-valor" data-mask="money" value="0,00" style="text-align: right;" required>
            </td>
            <td style="text-align: right; vertical-align: middle;">
                <b class="lbl-subtotal">0,00</b>
            </td>
            <td style="text-align: center; vertical-align: middle;">
                <button type="button" class="btn btn-theme" style="padding: 6px; color: #DC2626;" onclick="removerLinha(this)">✖</button>
            </td>
        </tr>
    </tbody>
</table>

<script>
const formatadorMoeda = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Limpa sujeiras e retorna float (evita quebra por espaços invisíveis)
function parseMoneyJS(valorStr) {
    if (!valorStr) return 0;
    let str = valorStr.toString().replace(/[^\d,-]/g, '').replace(',', '.');
    return parseFloat(str) || 0;
}

function selecionarProduto(selectElem) {
    const tr = selectElem.closest('tr');
    const option = selectElem.options[selectElem.selectedIndex];
    
    if (option.value !== "") {
        const preco = parseFloat(option.getAttribute('data-preco')) || 0;
        const estoque = parseFloat(option.getAttribute('data-estoque')) || 0;
        
        tr.querySelector('.span-estoque').textContent = Math.floor(estoque); 
        
        const inputValor = tr.querySelector('.input-valor');
        if (parseMoneyJS(inputValor.value) === 0) {
            inputValor.value = formatadorMoeda.format(preco);
        }
    } else {
        tr.querySelector('.span-estoque').textContent = '0';
    }
    calcularLinha(selectElem);
}

function calcularLinha(elem) {
    const tr = elem.closest('tr');
    const qtdStr = tr.querySelector('.input-qtd').value;
    const valStr = tr.querySelector('.input-valor').value;
    
    const qtd = parseInt(qtdStr, 10) || 0;
    tr.querySelector('.input-qtd').value = qtd; // Garante que o campo visual seja inteiro
    const val = parseMoneyJS(valStr);
    const subtotal = qtd * val;
    
    tr.querySelector('.lbl-subtotal').textContent = formatadorMoeda.format(subtotal);
    calcularTotalGeral();
}

// Controle de edição do desconto para saber qual lado recalcular
let editandoPorcentagem = false;
let editandoValor = false;

const inputDescPerc = document.getElementById('inputDescontoPerc');
const inputDescVal = document.getElementById('inputDescontoVal');

inputDescPerc.addEventListener('focus', () => { editandoPorcentagem = true; editandoValor = false; });
inputDescVal.addEventListener('focus', () => { editandoValor = true; editandoPorcentagem = false; });

inputDescPerc.addEventListener('input', () => { setTimeout(calcularTotalGeral, 50); });
inputDescVal.addEventListener('input', () => { setTimeout(calcularTotalGeral, 50); });

function calcularTotalGeral() {
    let bruto = 0;
    document.querySelectorAll('#tbodyItens .lbl-subtotal').forEach(el => {
        bruto += parseMoneyJS(el.textContent);
    });
    
    let descontoAbsoluto = 0;
    
    if (editandoPorcentagem) {
        // Usuário digitou no %: Calcular o R$
        let perc = parseMoneyJS(inputDescPerc.value);
        if (perc > 100) { perc = 100; inputDescPerc.value = '100,00'; }
        descontoAbsoluto = bruto * (perc / 100);
        inputDescVal.value = formatadorMoeda.format(descontoAbsoluto);
    } else {
        // Usuário digitou no R$ (ou é carregamento inicial): Calcular o %
        descontoAbsoluto = parseMoneyJS(inputDescVal.value);
        if (descontoAbsoluto > bruto) { descontoAbsoluto = bruto; inputDescVal.value = formatadorMoeda.format(bruto); }
        let perc = bruto > 0 ? (descontoAbsoluto / bruto) * 100 : 0;
        
        // Só atualiza o campo de % visualmente se ele não for o foco principal da digitação atual
        inputDescPerc.value = formatadorMoeda.format(perc);
    }
    
    let liquido = bruto - descontoAbsoluto;
    if (liquido < 0) liquido = 0;
    
    document.getElementById('lblBruto').textContent = formatadorMoeda.format(bruto);
    document.getElementById('lblLiquido').textContent = formatadorMoeda.format(liquido);
}

function adicionarItem(prodId = null, qtd = 1, vUnit = '0,00') {
    const emptyMsg = document.getElementById('emptyItens');
    if (emptyMsg) emptyMsg.style.display = 'none';

    const tbody = document.getElementById('tbodyItens');
    const template = document.getElementById('templateLinha').innerHTML;
    
    const tr = document.createElement('tr');
    tr.innerHTML = template.trim();
    
    tbody.appendChild(tr);
    
    if (prodId !== null) {
        const select = tr.querySelector('.select-produto');
        select.value = prodId;
        tr.querySelector('.input-qtd').value = parseInt(qtd, 10);
        tr.querySelector('.input-valor').value = vUnit;
        selecionarProduto(select);
    }
    
    if (typeof applyMasks === 'function') {
        applyMasks();
    }
}

function removerLinha(btn) {
    const tr = btn.closest('tr');
    tr.remove();
    calcularTotalGeral();
    
    if (document.getElementById('tbodyItens').children.length === 0) {
        const emptyMsg = document.getElementById('emptyItens');
        if (emptyMsg) emptyMsg.style.display = 'block';
    }
}

// Ouve as mudanças na tabela com atraso seguro para a máscara
document.getElementById('tbodyItens').addEventListener('input', function(e) {
    if (e.target.classList.contains('input-qtd') || e.target.classList.contains('input-valor')) {
        setTimeout(() => calcularLinha(e.target), 50);
    }
});

// Inicialização
document.addEventListener('DOMContentLoaded', () => {
    const itensSalvos = <?= json_encode($itensVenda) ?>;
    
    if (itensSalvos.length > 0) {
        itensSalvos.forEach(it => {
            const qtdInt = Math.floor(parseFloat(it.quantidade));
            const valStr = formatadorMoeda.format(it.valor_unitario);
            adicionarItem(it.produto_id, qtdInt, valStr);
        });
    } else {
        adicionarItem();
    }
    
    // Força o cálculo inicial
    setTimeout(calcularTotalGeral, 100);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>