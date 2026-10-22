<?php
// Caminho: /modules/estoque/inventario.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('estoque.editar')) {
    Session::setFlash('error', 'Sem permissão para realizar inventários de estoque.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();

// Helper para conversão - quantidades são inteiras
function parseIntQtyIn(string $val): int {
    $limpo = str_replace(['.', ' '], '', trim($val));
    $limpo = str_replace(',', '.', $limpo);
    return is_numeric($limpo) ? (int)floatval($limpo) : 0;
}

// ============================================================================
// PROCESSAMENTO DO INVENTÁRIO (POST EM LOTE)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        Session::setFlash('error', 'Token de segurança inválido.');
        header('Location: ' . BASE_URL . '/modules/estoque/inventario.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'salvar_inventario') {
        $contagens = $_POST['contagem'] ?? []; // Array [produto_id => qtd_contada]
        $itensAjustados = 0;
        
        if (!empty($contagens)) {
            try {
                $db->beginTransaction();
                
                $stmtBusca = $db->prepare("SELECT estoque_atual, preco_custo FROM produtos WHERE id = :id AND empresa_id = :emp FOR UPDATE");
                $stmtHist = $db->prepare("
                    INSERT INTO estoque_movimentacoes (empresa_id, produto_id, tipo, quantidade, custo_unitario, motivo, observacoes) 
                    VALUES (:emp, :prod, :tipo, :qtd, :custo, 'Balanço / Inventário', 'Ajuste em lote via tela de Inventário')
                ");
                $stmtUpd = $db->prepare("UPDATE produtos SET estoque_atual = :novaQtd WHERE id = :id AND empresa_id = :emp");

                foreach ($contagens as $prodId => $qtdStr) {
                    if (trim($qtdStr) === '') continue; // Ignora os campos deixados em branco
                    
                    $prodId = (int)$prodId;
                    $novaQtd = parseIntQtyIn($qtdStr);
                    
                    $stmtBusca->execute([':id' => $prodId, ':emp' => $empresaId]);
                    $prod = $stmtBusca->fetch(PDO::FETCH_ASSOC);
                    
                    if ($prod) {
                        $qtdSistema = (float)$prod['estoque_atual'];
                        
                        if (abs($novaQtd - $qtdSistema) > 0.001) { // Se houver divergência
                            $diff = $novaQtd - $qtdSistema;
                            $tipo = ($diff > 0) ? 'entrada' : 'saida';
                            $absQtd = abs($diff);
                            
                            // 1. Grava no Histórico
                            $stmtHist->execute([
                                ':emp' => $empresaId,
                                ':prod' => $prodId,
                                ':tipo' => $tipo,
                                ':qtd' => $absQtd,
                                ':custo' => $prod['preco_custo']
                            ]);
                            
                            // 2. Atualiza Saldo do Produto
                            $stmtUpd->execute([
                                ':novaQtd' => $novaQtd,
                                ':id' => $prodId,
                                ':emp' => $empresaId
                            ]);
                            
                            $itensAjustados++;
                        }
                    }
                }
                
                $db->commit();
                
                if ($itensAjustados > 0) {
                    AuditLogger::log('editar', 'estoque', "Inventário processado: {$itensAjustados} itens ajustados.");
                    Session::setFlash('success', "Inventário gravado! {$itensAjustados} produtos foram ajustados.");
                } else {
                    Session::setFlash('info', 'Nenhuma divergência encontrada. Os estoques estão corretos.');
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Erro no inventário: " . $e->getMessage());
                Session::setFlash('error', 'Erro ao processar o inventário em lote.');
            }
        }
        
        header('Location: ' . BASE_URL . '/modules/estoque/inventario.php');
        exit;
    }
}

// ============================================================================
// LISTAGEM DE PRODUTOS PARA CONTAGEM
// ============================================================================
$busca = trim($_GET['busca'] ?? '');
$fStatus = trim($_GET['status'] ?? '');

$where = ["empresa_id = :emp", "ativo = 1"];
$params = [':emp' => $empresaId];

if ($busca !== '') {
    $where[] = "(nome LIKE :b1 OR sku LIKE :b2)";
    $params[':b1'] = "%$busca%"; $params[':b2'] = "%$busca%";
}
if ($fStatus === 'zerado') {
    $where[] = "estoque_atual <= 0";
} elseif ($fStatus === 'positivo') {
    $where[] = "estoque_atual > 0";
}

$whereClause = implode(" AND ", $where);

// Sem paginação rígida (inventário costuma carregar muitos itens), limitando a 150 por tela para não travar
$limit = 150; 
$stmtList = $db->prepare("
    SELECT id, nome, sku, preco_custo, estoque_atual 
    FROM produtos 
    WHERE $whereClause 
    ORDER BY nome ASC 
    LIMIT $limit
");
$stmtList->execute($params);
$produtos = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// Resumo rápido
$totalItens = count($produtos);
$valorSistema = 0;
foreach($produtos as $p) {
    $valorSistema += ((float)$p['estoque_atual'] * (float)$p['preco_custo']);
}

$pageTitle = 'Inventário e Balanço';
$activeModule = 'estoque';
$activeSub = 'inventario';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Cabeçalho do Módulo -->
<div class="page-header" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
    <div>
        <div style="color: var(--primary); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Módulo Estoque</div>
        <h1 class="ct" style="font-size: 28px; font-weight: 800; margin: 0; line-height: 1.2;">Balanço / Contagem Física</h1>
        <div class="kl" style="margin-top: 4px;">Lance a contagem real para ajustar divergências no sistema em lote.</div>
    </div>
    <div style="display:flex; gap:12px;">
        <button type="button" class="btn btn-theme" onclick="window.print()">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"></path></svg>
            Imprimir Ficha
        </button>
        <button type="button" class="btn btn-primary-brand" onclick="document.getElementById('formInventario').requestSubmit()">
            💾 Gravar Inventário
        </button>
    </div>
</div>

<!-- KPIs Interativos -->
<div class="kpi-grid" style="margin-bottom: 24px;">
    <div class="card kpi-card tone-blue">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Itens Listados</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"></path></svg></div>
        </div>
        <div class="kpi-value"><?= $totalItens ?></div>
        <div class="kpi-variation">Produtos carregados no filtro atual</div>
    </div>
    
    <div class="card kpi-card tone-purple">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Valor (Sistema)</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"></path></svg></div>
        </div>
        <div class="kpi-value"><?= Security::formatMoney($valorSistema) ?></div>
        <div class="kpi-variation">Baseado no custo unitário atual</div>
    </div>

    <div class="card kpi-card tone-orange">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
            <div class="kpi-label">Divergências Encontradas</div>
            <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg></div>
        </div>
        <div class="kpi-value" id="kpi_divergencias">0</div>
        <div class="kpi-variation">Itens que sofrerão reajuste</div>
    </div>
</div>

<!-- Filtros -->
<div class="card" style="padding: 20px; margin-bottom: 24px;">
    <form method="GET" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
        <div style="flex: 1; min-width: 250px;">
            <label class="form-label-os">Buscar (Produto ou SKU)</label>
            <input type="text" name="busca" class="form-control-os" value="<?= Security::e($busca) ?>" placeholder="Pesquise por nome...">
        </div>
        <div style="width: 200px;">
            <label class="form-label-os">Situação Atual</label>
            <select name="status" class="form-select-os">
                <option value="">Todos os itens</option>
                <option value="positivo" <?= $fStatus === 'positivo' ? 'selected' : '' ?>>Apenas com saldo (> 0)</option>
                <option value="zerado" <?= $fStatus === 'zerado' ? 'selected' : '' ?>>Apenas Zerados</option>
            </select>
        </div>
        <button type="submit" class="btn btn-theme">Filtrar Lista</button>
    </form>
</div>

<!-- Formulário em Lote da Tabela -->
<div class="card" style="padding: 0; overflow: hidden; margin-bottom: 24px;">
    <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg);">
        <div class="kl">Deixe em branco os campos dos produtos que estão corretos.</div>
    </div>
    <div class="table-responsive-os">
        <form method="POST" id="formInventario">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="salvar_inventario">
            
            <table class="table-os" id="tabelaInventario">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>Produto / SKU</th>
                        <th style="text-align: right; width: 140px;">Saldo Sistema</th>
                        <th style="text-align: right; width: 160px; background: var(--primary-soft);">Nova Contagem</th>
                        <th style="text-align: center; width: 140px;">Diferença</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($produtos)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 30px; color: var(--muted);">Utilize os filtros para listar produtos.</td></tr>
                    <?php else: foreach ($produtos as $p): ?>
                        <tr>
                            <td class="kl"><?= $p['id'] ?></td>
                            <td>
                                <div style="font-weight: 600; color: var(--text);"><?= Security::e($p['nome']) ?></div>
                                <div class="kl" style="font-size: 12px;"><?= Security::e($p['sku'] ?: 'Sem SKU') ?></div>
                            </td>
                            <td style="text-align: right; font-size: 16px;">
                                <b data-sis="<?= (int)$p['estoque_atual'] ?>" id="sis_<?= $p['id'] ?>">
                                    <?= number_format((int)$p['estoque_atual'], 0, ',', '.') ?>
                                </b>
                            </td>
                            <td style="text-align: right; background: var(--primary-soft);">
                                <input type="number" 
                                   name="contagem[<?= $p['id'] ?>]" 
                                   class="form-control-os input-contagem" 
                                   data-id="<?= $p['id'] ?>"
                                   min="0"
                                   placeholder="—" 
                                   style="text-align: right; font-weight: bold; color: var(--primary-strong); border-color: var(--primary);">
                            </td>
                            <td style="text-align: center;">
                                <span id="diff_<?= $p['id'] ?>" class="bd" style="background: transparent; color: var(--muted);">0,00</span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('.input-contagem');
    let kpiDivergencias = document.getElementById('kpi_divergencias');

    inputs.forEach(input => {
        input.addEventListener('input', function() {
            calcularDiferenca(this);
            atualizarKPIDivergencias();
        });
        input.addEventListener('blur', function() {
            calcularDiferenca(this);
            atualizarKPIDivergencias();
        });
    });

    function calcularDiferenca(el) {
    const id = el.getAttribute('data-id');
    const spanDiff = document.getElementById('diff_' + id);
    const bSis = document.getElementById('sis_' + id);
    
    const sisStr = bSis.getAttribute('data-sis');
    const qtdSistema = parseInt(sisStr, 10);
    
    const valStr = el.value.trim();
    if (valStr === '') {
        // Volta ao estado neutro
        spanDiff.textContent = '0';
        spanDiff.className = 'bd';
        spanDiff.style.background = 'transparent';
        spanDiff.style.color = 'var(--muted)';
        return;
    }

    const qtdContada = parseInt(valStr, 10) || 0;
    const diff = qtdContada - qtdSistema;

    if (Math.abs(diff) < 1) {
        spanDiff.textContent = 'OK';
        spanDiff.className = 'bd chip-info'; // Cinza/Neutro
    } else if (diff > 0) {
        spanDiff.textContent = '+' + diff.toLocaleString('pt-BR');
        spanDiff.className = 'bd chip-ok'; // Verde (Sobra)
    } else {
        spanDiff.textContent = diff.toLocaleString('pt-BR');
        spanDiff.className = 'bd chip-bad'; // Vermelho (Falta)
    }
}

    function atualizarKPIDivergencias() {
    let count = 0;
    inputs.forEach(input => {
        const valStr = input.value.trim();
        if (valStr !== '') {
            const id = input.getAttribute('data-id');
            const qtdSistema = parseInt(document.getElementById('sis_' + id).getAttribute('data-sis'), 10);
            const qtdContada = parseInt(valStr, 10) || 0;
            if (Math.abs(qtdContada - qtdSistema) >= 1) {
                count++;
            }
        }
    });
    kpiDivergencias.textContent = count;
}
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>