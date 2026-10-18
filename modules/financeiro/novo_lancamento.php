<?php
// Caminho: /modules/financeiro/novo_lancamento.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('financeiro.criar') && !Auth::hasPermission('financeiro.ver')) {
    Session::setFlash('error', 'Sem permissão.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

function parseMoedaNL(string $val): float {
    $limpo = str_replace(['R$', '%', ' ', '.'], '', trim($val));
    $limpo = str_replace(',', '.', $limpo);
    return is_numeric($limpo) ? (float)$limpo : 0.0;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();

// Próximo código sequencial
$stmtMax = $db->prepare("SELECT COALESCE(MAX(id), 0) + 1 FROM lancamentos_financeiros WHERE empresa_id = :emp");
$stmtMax->execute([':emp' => $empresaId]);
$proximoCodigo = $stmtMax->fetchColumn();

// Busca contas financeiras e categorias (Plano de Contas)
$contas = $db->prepare("SELECT id, nome FROM contas_financeiras WHERE empresa_id = :emp AND ativo = 1");
$contas->execute([':emp' => $empresaId]);
$listaContas = $contas->fetchAll();

$categorias = $db->prepare("SELECT id, tipo, nome FROM categorias_financeiras WHERE empresa_id = :emp AND ativo = 1 ORDER BY nome");
$categorias->execute([':emp' => $empresaId]);
$listaCategorias = $categorias->fetchAll();

// Processamento POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (Security::validateCsrf($csrf)) {
        $clienteFornecedor = trim($_POST['cliente_fornecedor'] ?? '');
        $empresaNome       = trim($_POST['empresa'] ?? '');
        $banco             = trim($_POST['banco'] ?? '') ?: null;
        $categoriaId       = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
        $tipo              = ($_POST['tipo'] ?? 'Receita') === 'Despesa' ? 'despesa' : 'receita';
        $valor             = parseMoedaNL($_POST['valor'] ?? '0');
        $competencia       = trim($_POST['competencia'] ?? date('Y-m-d'));
        $vencimento        = trim($_POST['vencimento'] ?? date('Y-m-d'));
        $quitacao          = trim($_POST['quitacao'] ?? '') ?: null;
        $formaPgto         = trim($_POST['forma_pagamento'] ?? '') ?: null;
        $centroCusto       = trim($_POST['centro_custo'] ?? '') ?: null;
        $pago              = isset($_POST['pago']) ? 1 : 0;
        $descMultaJuros    = isset($_POST['desconsiderar_multa_juros']) ? 1 : 0;
        $documento         = trim($_POST['documento'] ?? '') ?: null;
        $multaVal          = parseMoedaNL($_POST['multa_val'] ?? '0');
        $jurosVal          = parseMoedaNL($_POST['juros_val'] ?? '0');
        $moraDiaria        = parseMoedaNL($_POST['mora_diaria'] ?? '0');
        $descontoVal       = parseMoedaNL($_POST['desconto_val'] ?? '0');
        $descontoPerc      = parseMoedaNL($_POST['desconto_perc'] ?? '0');
        $aPagar            = parseMoedaNL($_POST['a_pagar'] ?? '0');
        $totalVal          = parseMoedaNL($_POST['total_val'] ?? '0');
        $observacoes       = trim($_POST['observacoes'] ?? '') ?: null;
        $gerarRecorrencia  = isset($_POST['gerar_recorrencia']) ? 1 : 0;
        $gerarParcelamento = isset($_POST['gerar_parcelamento']) ? 1 : 0;

        $contaId = !empty($listaContas) ? (int)$listaContas[0]['id'] : 1;
        $status = ($pago === 1) ? 'pago' : 'pendente';

        if ($clienteFornecedor !== '' && $valor > 0) {
            $stmt = $db->prepare("
                INSERT INTO lancamentos_financeiros (
                    empresa_id, conta_id, categoria_id, cliente_fornecedor, banco, tipo, valor,
                    competencia, data_vencimento, data_quitacao, forma_pagamento, centro_custo,
                    pago, desconsiderar_multa_juros, documento, multa_val, juros_val, mora_diaria_perc,
                    desconto_val, desconto_perc, a_pagar, total_val, observacoes, gerar_recorrencia,
                    gerar_parcelamento, status
                ) VALUES (
                    :emp, :conta, :cat, :cli, :banco, :tipo, :val, :comp, :venc, :quit, :forma, :cc,
                    :pago, :dmj, :doc, :multa, :juros, :mora, :dval, :dperc, :apagar, :total, :obs, :rec, :parc, :st
                )
            ");
            $stmt->execute([
                ':emp'    => $empresaId,
                ':conta'  => $contaId,
                ':cat'    => $categoriaId,
                ':cli'    => $clienteFornecedor,
                ':banco'  => $banco,
                ':tipo'   => $tipo,
                ':val'    => $valor,
                ':comp'   => $competencia,
                ':venc'   => $vencimento,
                ':quit'   => $quitacao,
                ':forma'  => $formaPgto,
                ':cc'     => $centroCusto,
                ':pago'   => $pago,
                ':dmj'    => $descMultaJuros,
                ':doc'    => $documento,
                ':multa'  => $multaVal,
                ':juros'  => $jurosVal,
                ':mora'   => $moraDiaria,
                ':dval'   => $descontoVal,
                ':dperc'  => $descontoPerc,
                ':apagar' => $aPagar,
                ':total'  => $totalVal,
                ':obs'    => $observacoes,
                ':rec'    => $gerarRecorrencia,
                ':parc'   => $gerarParcelamento,
                ':st'     => $status,
            ]);

            if ($pago === 1) {
                $fator = ($tipo === 'receita') ? 1 : -1;
                $db->prepare("UPDATE contas_financeiras SET saldo_atual = saldo_atual + (:val * :fator) WHERE id = :id AND empresa_id = :emp")
                   ->execute([':val' => ($totalVal > 0 ? $totalVal : $valor), ':fator' => $fator, ':id' => $contaId, ':emp' => $empresaId]);
            }

            Session::setFlash('success', 'Lançamento financeiro cadastrado com sucesso!');
            header('Location: ' . BASE_URL . '/modules/financeiro/lancamentos.php');
            exit;
        }
    }
}

$pageTitle    = 'Novo Lançamento';
$activeModule = 'financeiro';
$activeSub    = 'novo_lancamento';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div>
        <div class="ct" style="font-size:20px">Novo Lançamento</div>
        <div class="kl" style="margin-top:2px">
            <a href="<?= BASE_URL ?>/modules/financeiro/lancamentos.php" style="color:var(--muted);text-decoration:none">Início</a> &gt; 
            <a href="<?= BASE_URL ?>/modules/financeiro/lancamentos.php" style="color:var(--muted);text-decoration:none">Lançamentos</a> &gt; Novo Lançamento
        </div>
    </div>
    <div style="display:flex;gap:10px">
        <button type="button" class="btn-theme" onclick="window.location.href='<?= BASE_URL ?>/modules/financeiro/lancamentos.php'">Cancelar</button>
        <button type="button" class="btn-primary-brand" onclick="document.getElementById('formNovoLancamento').requestSubmit()">Salvar</button>
    </div>
</div>

<div class="card" style="max-width:1200px;margin:0 auto;padding:24px">
    <form method="post" id="formNovoLancamento">
        <?= Security::csrfField() ?>

        <div class="row g-3" style="margin-bottom:18px">
            <div class="col-md-2">
                <label class="form-label-os">Código</label>
                <input type="text" class="form-control-os" value="<?= $proximoCodigo ?>" readonly style="background:var(--bg)">
            </div>
            <div class="col-md-4">
                <label class="form-label-os">Cliente / Fornecedor *</label>
                <input type="text" name="cliente_fornecedor" class="form-control-os" placeholder="Nome do cliente ou fornecedor" required>
            </div>
            <div class="col-md-4">
                <label class="form-label-os">Empresa *</label>
                <input type="text" name="empresa" class="form-control-os" value="Minha Loja de Comércio, Serviços e Distribuição" required>
            </div>
            <div class="col-md-2">
                <label class="form-label-os">Banco</label>
                <input type="text" name="banco" class="form-control-os" placeholder="Banco / Caixa">
            </div>

            <div class="col-md-4">
                <label class="form-label-os">Plano de Contas</label>
                <select name="categoria_id" class="form-select-os">
                    <option value="">Selecione...</option>
                    <?php foreach ($listaCategorias as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= Security::e(ucfirst($cat['tipo'])) ?>: <?= Security::e($cat['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label-os">Tipo *</label>
                <select name="tipo" class="form-select-os">
                    <option value="Receita">Receita</option>
                    <option value="Despesa">Despesa</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label-os">Valor (R$) *</label>
                <input type="text" name="valor" class="form-control-os" data-mask="money" value="0,00" required>
            </div>

            <div class="col-md-4">
                <label class="form-label-os">Competência *</label>
                <input type="date" name="competencia" class="form-control-os" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label-os">Vencimento *</label>
                <input type="date" name="vencimento" class="form-control-os" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label-os">Quitação</label>
                <input type="date" name="quitacao" class="form-control-os">
            </div>

            <div class="col-md-4">
                <label class="form-label-os">Forma de Pagamento</label>
                <select name="forma_pagamento" class="form-select-os">
                    <option value="Dinheiro">Dinheiro</option>
                    <option value="Boleto">Boleto</option>
                    <option value="Pix">Pix</option>
                    <option value="Cartão de Crédito">Cartão de Crédito</option>
                    <option value="Cartão de Débito">Cartão de Débito</option>
                    <option value="Transferência">Transferência</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label-os">Centro de Custos</label>
                <select name="centro_custo" class="form-select-os">
                    <option value="">Selecione</option>
                    <option value="Administrativo">Administrativo</option>
                    <option value="Operacional">Operacional</option>
                    <option value="Comercial">Comercial</option>
                </select>
            </div>
            <div class="col-md-2" style="display:flex;flex-direction:column;justify-content:flex-end">
                <label class="form-label-os">Pago</label>
                <div style="display:flex;align-items:center;height:44px">
                    <input type="checkbox" name="pago" value="1" style="width:20px;height:20px;accent-color:#0E9F6E">
                </div>
            </div>
            <div class="col-md-2" style="display:flex;flex-direction:column;justify-content:flex-end">
                <label class="form-label-os" style="font-size:11.5px">Desconsiderar multa e juros?</label>
                <div style="display:flex;align-items:center;height:44px">
                    <input type="checkbox" name="desconsiderar_multa_juros" value="1" style="width:20px;height:20px;accent-color:#0E9F6E">
                </div>
            </div>

            <div class="col-md-3">
                <label class="form-label-os">Documento</label>
                <input type="text" name="documento" class="form-control-os">
            </div>
            <div class="col-md-3">
                <label class="form-label-os">Multa (R$)</label>
                <input type="text" name="multa_val" class="form-control-os" data-mask="money" value="0,00">
            </div>
            <div class="col-md-3">
                <label class="form-label-os">Juros (R$)</label>
                <input type="text" name="juros_val" class="form-control-os" data-mask="money" value="0,00">
            </div>
            <div class="col-md-3">
                <label class="form-label-os">Mora Diária (%)</label>
                <input type="text" name="mora_diaria" class="form-control-os" data-mask="money" value="0,00">
            </div>

            <div class="col-md-3">
                <label class="form-label-os">Desconto (R$)</label>
                <input type="text" name="desconto_val" class="form-control-os" data-mask="money" value="0,00">
            </div>
            <div class="col-md-3">
                <label class="form-label-os">Desconto (%)</label>
                <input type="text" name="desconto_perc" class="form-control-os" data-mask="money" value="0,00">
            </div>
            <div class="col-md-3">
                <label class="form-label-os">A pagar (R$)</label>
                <input type="text" name="a_pagar" class="form-control-os" data-mask="money" value="0,00">
            </div>
            <div class="col-md-3">
                <label class="form-label-os">Total (R$)</label>
                <input type="text" name="total_val" class="form-control-os" data-mask="money" value="0,00">
            </div>
        </div>

        <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:14px">
            <label class="form-label-os">Observações</label>
            <textarea name="observacoes" rows="3" class="form-control-os" placeholder="Informações adicionais..."></textarea>
        </div>

        <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:18px">
            <div class="ct" style="font-size:15px;margin-bottom:12px">Arquivos</div>
            <div style="background:var(--bg);padding:16px;border-radius:8px;text-align:center;color:var(--muted);font-size:13.5px">
                Para adicionar arquivos, primeiro salve o lançamento. · 🛈 Nenhum arquivo encontrado!
            </div>
        </div>

        <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:18px">
            <div class="ct" style="font-size:15px;margin-bottom:12px">Gerar parcelamento ou recorrência</div>
            <div style="display:flex;gap:30px">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13.5px">
                    <input type="checkbox" name="gerar_recorrencia" value="1" style="width:18px;height:18px;accent-color:#0E9F6E"> Gerar Recorrência
                </label>
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13.5px">
                    <input type="checkbox" name="gerar_parcelamento" value="1" style="width:18px;height:18px;accent-color:#0E9F6E"> Gerar Parcelamento
                </label>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>