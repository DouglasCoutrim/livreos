<?php
// Caminho: /modules/cadastros/produtos.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('cadastros.ver')) {
    Session::setFlash('error', 'Você não tem permissão para acessar o módulo de Cadastros.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();

/**
 * Converte string numérica BR ("1.250,50") para float (1250.50)
 */
function parseDecimalBr(string $valor): float
{
    $limpo = str_replace(['R$', '%', ' ', '.'], '', trim($valor));
    $limpo = str_replace(',', '.', $limpo);
    return is_numeric($limpo) ? (float)$limpo : 0.0;
}

// =========================================================================
// PROCESSAMENTO DE FORMULÁRIOS (POST: SALVAR, ALTERNAR STATUS, EXCLUIR)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        Session::setFlash('error', 'Token de segurança inválido. Recarregue a página.');
        header('Location: ' . BASE_URL . '/modules/cadastros/produtos.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';

    // 1. SALVAR PRODUTO (NOVO OU EDIÇÃO)
    if ($acao === 'salvar') {
        $id            = (int)($_POST['id'] ?? 0);
        $codigoSku     = strtoupper(trim($_POST['codigo_sku'] ?? ''));
        $codigoBarras  = strtoupper(trim($_POST['codigo_barras'] ?? '')) ?: 'SEM GTIN';
        $nome          = trim($_POST['nome'] ?? '');
        $categoria     = trim($_POST['categoria'] ?? '') ?: null;
        $unidade       = strtoupper(trim($_POST['unidade'] ?? 'UN'));
        $precoCusto    = parseDecimalBr($_POST['preco_custo'] ?? '0,00');
        $margemLucro   = parseDecimalBr($_POST['margem_lucro'] ?? '0,00');
        $precoVenda    = parseDecimalBr($_POST['preco_venda'] ?? '0,00');
        $estoqueAtual  = max(0.0, Security::parseQty($_POST['estoque_atual'] ?? '0'));
        $estoqueMinimo = max(0.0, Security::parseQty($_POST['estoque_minimo'] ?? '0'));
        $pesoLiquido   = parseDecimalBr($_POST['peso_liquido'] ?? '0,00');
        $pesoBruto     = parseDecimalBr($_POST['peso_bruto'] ?? '0,00');

        // Dados Fiscais (NF-e / NFC-e)
        $ncm           = preg_replace('/\D/', '', $_POST['ncm'] ?? '') ?: null;
        $cest          = preg_replace('/\D/', '', $_POST['cest'] ?? '') ?: null;
        $origem        = (int)($_POST['origem'] ?? 0);
        $cfopInterno   = preg_replace('/\D/', '', $_POST['cfop_interno'] ?? '5102') ?: '5102';
        $cfopExterno   = preg_replace('/\D/', '', $_POST['cfop_externo'] ?? '6102') ?: '6102';
        $csosnCst      = trim($_POST['csosn_cst'] ?? '102');
        $cstPis        = trim($_POST['cst_pis'] ?? '07');
        $aliqPis       = parseDecimalBr($_POST['aliq_pis'] ?? '0,00');
        $cstCofins     = trim($_POST['cst_cofins'] ?? '07');
        $aliqCofins    = parseDecimalBr($_POST['aliq_cofins'] ?? '0,00');
        $aliqIcms      = parseDecimalBr($_POST['aliq_icms'] ?? '0,00');
        $aliqIpi       = parseDecimalBr($_POST['aliq_ipi'] ?? '0,00');
        $observacoes   = trim($_POST['observacoes'] ?? '') ?: null;
        $ativo         = isset($_POST['ativo']) && (int)$_POST['ativo'] === 0 ? 0 : 1;

        // Gera SKU automático caso não tenha sido preenchido
        if ($codigoSku === '') {
            $codigoSku = 'PRD-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        }

        // Validações Server-Side
        $erros = [];
        if ($nome === '' || mb_strlen($nome) < 2) {
            $erros[] = 'Informe a descrição/nome do produto (mínimo 2 caracteres).';
        }
        if ($precoVenda < 0 || $precoCusto < 0) {
            $erros[] = 'Os preços de custo e venda não podem ser negativos.';
        }
        if ($ncm !== null && strlen($ncm) !== 8) {
            $erros[] = 'O código NCM deve conter exatamente 8 dígitos numéricos.';
        }
        if ($cest !== null && strlen($cest) !== 7) {
            $erros[] = 'O código CEST deve conter exatamente 7 dígitos numéricos.';
        }
        if (strlen($cfopInterno) !== 4 || strlen($cfopExterno) !== 4) {
            $erros[] = 'Os códigos CFOP devem conter exatamente 4 dígitos.';
        }
        if ($origem < 0 || $origem > 8) {
            $origem = 0;
        }

        // Verifica duplicidade de SKU na mesma empresa
        if (empty($erros)) {
            $stmtDup = $db->prepare("
                SELECT id FROM produtos
                WHERE empresa_id = :emp AND codigo_sku = :sku AND id != :id
                LIMIT 1
            ");
            $stmtDup->execute([':emp' => $empresaId, ':sku' => $codigoSku, ':id' => $id]);
            if ($stmtDup->fetch()) {
                $erros[] = "Já existe um produto cadastrado com o código SKU ({$codigoSku}).";
            }
        }

        if (!empty($erros)) {
            Session::setFlash('error', implode(' ', $erros));
            header('Location: ' . BASE_URL . '/modules/cadastros/produtos.php');
            exit;
        }

        $params = [
            ':empresa_id'     => $empresaId,
            ':codigo_sku'     => $codigoSku,
            ':codigo_barras'  => $codigoBarras,
            ':nome'           => $nome,
            ':categoria'      => $categoria,
            ':unidade'        => $unidade,
            ':preco_custo'    => $precoCusto,
            ':margem_lucro'   => $margemLucro,
            ':preco_venda'    => $precoVenda,
            ':estoque_atual'  => $estoqueAtual,
            ':estoque_minimo' => $estoqueMinimo,
            ':peso_liquido'   => $pesoLiquido,
            ':peso_bruto'     => $pesoBruto,
            ':ncm'            => $ncm,
            ':cest'           => $cest,
            ':origem'         => $origem,
            ':cfop_interno'   => $cfopInterno,
            ':cfop_externo'   => $cfopExterno,
            ':csosn_cst'      => $csosnCst,
            ':cst_pis'        => $cstPis,
            ':aliq_pis'       => $aliqPis,
            ':cst_cofins'     => $cstCofins,
            ':aliq_cofins'    => $aliqCofins,
            ':aliq_icms'      => $aliqIcms,
            ':aliq_ipi'       => $aliqIpi,
            ':observacoes'    => $observacoes,
            ':ativo'          => $ativo,
        ];

        if ($id > 0) {
            $params[':id'] = $id;
            $sql = "
                UPDATE produtos SET
                    codigo_sku = :codigo_sku,
                    codigo_barras = :codigo_barras,
                    nome = :nome,
                    categoria = :categoria,
                    unidade = :unidade,
                    preco_custo = :preco_custo,
                    margem_lucro = :margem_lucro,
                    preco_venda = :preco_venda,
                    estoque_atual = :estoque_atual,
                    estoque_minimo = :estoque_minimo,
                    peso_liquido = :peso_liquido,
                    peso_bruto = :peso_bruto,
                    ncm = :ncm,
                    cest = :cest,
                    origem = :origem,
                    cfop_interno = :cfop_interno,
                    cfop_externo = :cfop_externo,
                    csosn_cst = :csosn_cst,
                    cst_pis = :cst_pis,
                    aliq_pis = :aliq_pis,
                    cst_cofins = :cst_cofins,
                    aliq_cofins = :aliq_cofins,
                    aliq_icms = :aliq_icms,
                    aliq_ipi = :aliq_ipi,
                    observacoes = :observacoes,
                    ativo = :ativo
                WHERE id = :id AND empresa_id = :empresa_id
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            AuditLogger::log('PRODUTO_EDITAR', 'Cadastros', "Editou o produto #{$id} ({$codigoSku} - {$nome})");
            Session::setFlash('success', 'Produto atualizado com sucesso!');
        } else {
            $sql = "
                INSERT INTO produtos (
                    empresa_id, codigo_sku, codigo_barras, nome, categoria, unidade,
                    preco_custo, margem_lucro, preco_venda, estoque_atual, estoque_minimo,
                    peso_liquido, peso_bruto, ncm, cest, origem, cfop_interno, cfop_externo,
                    csosn_cst, cst_pis, aliq_pis, cst_cofins, aliq_cofins, aliq_icms, aliq_ipi,
                    observacoes, ativo
                ) VALUES (
                    :empresa_id, :codigo_sku, :codigo_barras, :nome, :categoria, :unidade,
                    :preco_custo, :margem_lucro, :preco_venda, :estoque_atual, :estoque_minimo,
                    :peso_liquido, :peso_bruto, :ncm, :cest, :origem, :cfop_interno, :cfop_externo,
                    :csosn_cst, :cst_pis, :aliq_pis, :cst_cofins, :aliq_cofins, :aliq_icms, :aliq_ipi,
                    :observacoes, :ativo
                )
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $novoId = (int)$db->lastInsertId();

            AuditLogger::log('PRODUTO_CRIAR', 'Cadastros', "Cadastrou o produto #{$novoId} ({$codigoSku} - {$nome})");
            Session::setFlash('success', 'Produto cadastrado com sucesso!');
        }

        header('Location: ' . BASE_URL . '/modules/cadastros/produtos.php');
        exit;
    }

    // 2. ALTERNAR STATUS
    if ($acao === 'alternar_status') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE produtos SET ativo = IF(ativo = 1, 0, 1) WHERE id = :id AND empresa_id = :emp");
        $stmt->execute([':id' => $id, ':emp' => $empresaId]);

        AuditLogger::log('PRODUTO_STATUS', 'Cadastros', "Alternou status do produto #{$id}");
        Session::setFlash('success', 'Status do produto atualizado.');
        header('Location: ' . BASE_URL . '/modules/cadastros/produtos.php');
        exit;
    }

    // 3. EXCLUIR PRODUTO
    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $stmtNome = $db->prepare("SELECT nome, codigo_sku FROM produtos WHERE id = :id AND empresa_id = :emp");
        $stmtNome->execute([':id' => $id, ':emp' => $empresaId]);
        $prod = $stmtNome->fetch();

        if ($prod) {
            try {
                // Produto com vendas ou histórico de estoque não pode ser apagado (preserva o histórico)
                $stmtRef = $db->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM vendas_itens vi INNER JOIN vendas v ON v.id = vi.venda_id WHERE vi.produto_id = :p1 AND v.empresa_id = :e1)
                      + (SELECT COUNT(*) FROM estoque_movimentacoes WHERE produto_id = :p2 AND empresa_id = :e2) AS refs
                ");
                $stmtRef->execute([':p1' => $id, ':e1' => $empresaId, ':p2' => $id, ':e2' => $empresaId]);

                if ((int)$stmtRef->fetchColumn() > 0) {
                    Session::setFlash('error', 'Este produto possui vendas ou movimentações de estoque e não pode ser excluído. Use "Inativar".');
                } else {
                    $stmtDel = $db->prepare("DELETE FROM produtos WHERE id = :id AND empresa_id = :emp");
                    $stmtDel->execute([':id' => $id, ':emp' => $empresaId]);
                    AuditLogger::log('PRODUTO_EXCLUIR', 'Cadastros', "Excluiu o produto #{$id} ({$prod['codigo_sku']} - {$prod['nome']})");
                    Session::setFlash('success', 'Produto excluído com sucesso.');
                }
            } catch (PDOException $e) {
                error_log('Erro ao excluir produto: ' . $e->getMessage());
                Session::setFlash('error', 'Não foi possível excluir: o produto está em uso (notas de compra, vendas etc.). Use "Inativar".');
            }
        }

        header('Location: ' . BASE_URL . '/modules/cadastros/produtos.php');
        exit;
    }
}

// =========================================================================
// INDICADORES (KPIs) DE PRODUTOS
// =========================================================================
$stmtKpi = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS ativos,
        SUM(CASE WHEN ativo = 1 AND estoque_atual <= estoque_minimo THEN 1 ELSE 0 END) AS estoque_baixo,
        COALESCE(SUM(CASE WHEN ativo = 1 AND estoque_atual > 0 THEN (estoque_atual * preco_custo) ELSE 0 END), 0) AS valor_estoque,
        SUM(CASE WHEN LENGTH(ncm) = 8 AND LENGTH(cfop_interno) = 4 AND csosn_cst != '' THEN 1 ELSE 0 END) AS aptos_nfe
    FROM produtos
    WHERE empresa_id = :emp
");
$stmtKpi->execute([':emp' => $empresaId]);
$kpis = $stmtKpi->fetch();

// Categorias distintas para filtro
$stmtCats = $db->prepare("SELECT DISTINCT categoria FROM produtos WHERE empresa_id = :emp AND categoria IS NOT NULL AND categoria != '' ORDER BY categoria ASC");
$stmtCats->execute([':emp' => $empresaId]);
$categoriasLista = $stmtCats->fetchAll(PDO::FETCH_COLUMN);

// =========================================================================
// FILTROS, ORDENAÇÃO E PAGINAÇÃO (GET)
// =========================================================================
$busca          = trim($_GET['busca'] ?? '');
$categoriaFiltro = trim($_GET['categoria'] ?? '');
$estoqueFiltro  = trim($_GET['estoque'] ?? '');
$statusFiltro   = trim($_GET['status'] ?? '');

$colunasPermitidas = [
    'nome'          => 'nome',
    'codigo_sku'    => 'codigo_sku',
    'categoria'     => 'categoria',
    'preco_venda'   => 'preco_venda',
    'estoque_atual' => 'estoque_atual',
];
$ordem = $_GET['ordem'] ?? 'nome';
if (!isset($colunasPermitidas[$ordem])) {
    $ordem = 'nome';
}
$dir = (strtoupper($_GET['dir'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';

$paginaAtual = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina   = 10;
$offset      = ($paginaAtual - 1) * $porPagina;

$where = ["empresa_id = :emp"];
$bind  = [':emp' => $empresaId];

if ($busca !== '') {
    $where[] = "(nome LIKE :b1 OR codigo_sku LIKE :b2 OR codigo_barras LIKE :b3 OR ncm LIKE :b4)";
    $termo = '%' . $busca . '%';
    $bind[':b1'] = $termo;
    $bind[':b2'] = $termo;
    $bind[':b3'] = $termo;
    $bind[':b4'] = $termo;
}
if ($categoriaFiltro !== '') {
    $where[] = "categoria = :cat";
    $bind[':cat'] = $categoriaFiltro;
}
if ($estoqueFiltro === 'baixo') {
    $where[] = "estoque_atual <= estoque_minimo AND estoque_atual > 0";
} elseif ($estoqueFiltro === 'zerado') {
    $where[] = "estoque_atual <= 0";
}
if ($statusFiltro === '1' || $statusFiltro === '0') {
    $where[] = "ativo = :ativo";
    $bind[':ativo'] = (int)$statusFiltro;
}

$whereSql = implode(' AND ', $where);

$stmtCount = $db->prepare("SELECT COUNT(*) FROM produtos WHERE {$whereSql}");
$stmtCount->execute($bind);
$totalRegistros = (int)$stmtCount->fetchColumn();
$totalPaginas   = max(1, (int)ceil($totalRegistros / $porPagina));

$sqlLista = "
    SELECT *
    FROM produtos
    WHERE {$whereSql}
    ORDER BY {$colunasPermitidas[$ordem]} {$dir}
    LIMIT :limit OFFSET :offset
";
$stmtLista = $db->prepare($sqlLista);
foreach ($bind as $k => $v) {
    $stmtLista->bindValue($k, $v);
}
$stmtLista->bindValue(':limit', $porPagina, PDO::PARAM_INT);
$stmtLista->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtLista->execute();
$produtos = $stmtLista->fetchAll();

function linkOrdenacaoProd(string $col, string $label, string $ordemAtual, string $dirAtual): string
{
    $novaDir = ($ordemAtual === $col && $dirAtual === 'ASC') ? 'DESC' : 'ASC';
    $seta = '';
    if ($ordemAtual === $col) {
        $seta = $dirAtual === 'ASC' ? ' ↑' : ' ↓';
    }
    $query = $_GET;
    $query['ordem'] = $col;
    $query['dir']   = $novaDir;
    $url = '?' . http_build_query($query);
    return '<a href="' . Security::e($url) . '" style="color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:4px">' . Security::e($label) . '<b>' . $seta . '</b></a>';
}

$unidadesMedida = ['UN' => 'UN - Unidade', 'CX' => 'CX - Caixa', 'PC' => 'PC - Peça', 'KIT' => 'KIT - Kit', 'KG' => 'KG - Quilograma', 'LT' => 'LT - Litro', 'MT' => 'MT - Metro', 'PAR' => 'PAR - Par'];

$pageTitle    = 'Produtos';
$activeModule = 'cadastros';
$activeSub    = 'produtos';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Cabeçalho da Página -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
        <div class="ct" style="font-size:20px">Produtos</div>
        <div class="kl" style="margin-top:2px">Catálogo de produtos, controle de margens, saldos de estoque e tributação SEFAZ (NF-e / NFC-e)</div>
    </div>
    <button type="button" class="btn-primary-brand" onclick="abrirModalProduto()">
        + Novo produto
    </button>
</div>

<!-- 1. Cards de Indicadores (KPIs) -->
<div class="kpi-grid">
    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Total de produtos (Ativos)</div>
            <div class="kv"><?= (int)($kpis['total'] ?? 0) ?> <span style="font-size:14px;color:var(--muted);font-weight:600">(<?= (int)($kpis['ativos'] ?? 0) ?>)</span></div>
        </div>
        <div class="ib" style="background:var(--bsoft2);color:var(--btxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 7l9-4 9 4v10l-9 4-9-4z"></path>
            </svg>
        </div>
    </div>

    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Alerta de estoque mínimo</div>
            <div class="kv" style="color:<?= ((int)($kpis['estoque_baixo'] ?? 0) > 0) ? 'var(--rtxt)' : 'var(--text)' ?>">
                <?= (int)($kpis['estoque_baixo'] ?? 0) ?>
            </div>
        </div>
        <div class="ib" style="background:var(--rsoft);color:var(--rtxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 8v5M12 17h.01"></path>
                <circle cx="12" cy="12" r="9"></circle>
            </svg>
        </div>
    </div>

    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Custo total em estoque</div>
            <div class="kv"><?= Security::formatMoney((float)($kpis['valor_estoque'] ?? 0)) ?></div>
        </div>
        <div class="ib" style="background:var(--osoft);color:var(--otxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2v20M17 6H9a3 3 0 000 6h6a3 3 0 010 6H7"></path>
            </svg>
        </div>
    </div>

    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Produtos aptos p/ NF-e</div>
            <div class="kv"><?= (int)($kpis['aptos_nfe'] ?? 0) ?></div>
        </div>
        <div class="ib" style="background:var(--asoft);color:var(--atxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 3h8l4 4v14H6z"></path>
            </svg>
        </div>
    </div>
</div>

<!-- 2. Barra de Busca e Filtros -->
<div class="card">
    <form method="get" action="<?= BASE_URL ?>/modules/cadastros/produtos.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end">
        <div style="grid-column:span 2;min-width:220px">
            <label class="form-label-os" for="f_busca">Buscar produto</label>
            <input type="text" id="f_busca" name="busca" class="form-control-os" placeholder="Nome, SKU, Código de Barras (EAN) ou NCM…" value="<?= Security::e($busca) ?>">
        </div>

        <div>
            <label class="form-label-os" for="f_cat">Categoria</label>
            <select id="f_cat" name="categoria" class="form-select-os">
                <option value="">Todas</option>
                <?php foreach ($categoriasLista as $cat): ?>
                    <option value="<?= Security::e($cat) ?>" <?= $categoriaFiltro === $cat ? 'selected' : '' ?>><?= Security::e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label-os" for="f_est">Nível de Estoque</label>
            <select id="f_est" name="estoque" class="form-select-os">
                <option value="">Todos</option>
                <option value="baixo" <?= $estoqueFiltro === 'baixo' ? 'selected' : '' ?>>Abaixo do mínimo</option>
                <option value="zerado" <?= $estoqueFiltro === 'zerado' ? 'selected' : '' ?>>Sem estoque (Zerado)</option>
            </select>
        </div>

        <div>
            <label class="form-label-os" for="f_status">Status</label>
            <select id="f_status" name="status" class="form-select-os">
                <option value="">Todos</option>
                <option value="1" <?= $statusFiltro === '1' ? 'selected' : '' ?>>Ativos</option>
                <option value="0" <?= $statusFiltro === '0' ? 'selected' : '' ?>>Inativos</option>
            </select>
        </div>

        <div style="display:flex;gap:8px">
            <button type="submit" class="btn-primary-brand" style="flex:1">Filtrar</button>
            <a href="<?= BASE_URL ?>/modules/cadastros/produtos.php" class="btn-theme" style="text-decoration:none;justify-content:center">Limpar</a>
        </div>
    </form>
</div>

<!-- 3. Tabela de Produtos -->
<div class="card" style="padding:0;overflow:hidden">
    <div style="padding:18px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <span class="ct">Catálogo de Produtos (<?= $totalRegistros ?>)</span>
        <span class="kl">Clique nos títulos para ordenar</span>
    </div>

    <div class="table-responsive-os">
        <table class="table-os">
            <thead>
                <tr>
                    <th><?= linkOrdenacaoProd('codigo_sku', 'SKU / EAN', $ordem, $dir) ?></th>
                    <th><?= linkOrdenacaoProd('nome', 'Produto / Categoria', $ordem, $dir) ?></th>
                    <th>Fiscal (NCM / CFOP)</th>
                    <th>Custo / Margem</th>
                    <th><?= linkOrdenacaoProd('preco_venda', 'Preço Venda', $ordem, $dir) ?></th>
                    <th><?= linkOrdenacaoProd('estoque_atual', 'Estoque Atual', $ordem, $dir) ?></th>
                    <th>Situação</th>
                    <th style="text-align:right">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($produtos)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:36px 20px;color:var(--muted)">
                            Nenhum produto encontrado para os filtros selecionados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($produtos as $p):
                        $aptoFiscal = !empty($p['ncm']) && strlen((string)$p['ncm']) === 8 && !empty($p['cfop_interno']);
                        $estAtual   = (float)$p['estoque_atual'];
                        $estMin     = (float)$p['estoque_minimo'];

                        if ($estAtual <= 0) {
                            $corEstoque   = 'var(--rsoft)';
                            $txtEstoque   = 'var(--rtxt)';
                            $labelEstoque = 'Sem estoque';
                        } elseif ($estAtual <= $estMin) {
                            $corEstoque   = 'var(--asoft)';
                            $txtEstoque   = 'var(--atxt)';
                            $labelEstoque = 'Estoque baixo';
                        } else {
                            $corEstoque   = 'var(--osoft)';
                            $txtEstoque   = 'var(--otxt)';
                            $labelEstoque = 'Normal';
                        }

                        $jsonProd = htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr>
                            <td>
                                <b><?= Security::e($p['codigo_sku']) ?></b>
                                <div class="kl"><?= Security::e($p['codigo_barras'] ?: 'SEM GTIN') ?></div>
                            </td>

                            <td>
                                <b style="font-size:13.5px"><?= Security::e($p['nome']) ?></b>
                                <div class="kl">
                                    <?= Security::e($p['categoria'] ?: 'Sem categoria') ?> · Unid: <b><?= Security::e($p['unidade']) ?></b>
                                </div>
                            </td>

                            <td>
                                <?php if ($aptoFiscal): ?>
                                    <div><b>NCM: <?= Security::e($p['ncm']) ?></b></div>
                                    <div class="kl">CFOP <?= Security::e($p['cfop_interno']) ?> · CSOSN/CST <?= Security::e($p['csosn_cst']) ?></div>
                                <?php else: ?>
                                    <span class="bd" style="background:var(--asoft);color:var(--atxt)" title="Informe o NCM de 8 dígitos para emitir NF-e">
                                        NCM Pendente
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div><?= Security::formatMoney($p['preco_custo']) ?></div>
                                <div class="kl" style="color:#0E9F6E">+<?= number_format((float)$p['margem_lucro'], 1, ',', '.') ?>%</div>
                            </td>

                            <td>
                                <b style="color:#0E9F6E;font-size:14px"><?= Security::formatMoney($p['preco_venda']) ?></b>
                            </td>

                            <td>
                                <div>
                                    <b><?= number_format($estAtual, 0, ',', '.') ?></b> <?= Security::e($p['unidade']) ?>
                                    <span class="kl">(Mín: <?= number_format($estMin, 0, ',', '.') ?>)</span>
                                </div>
                                <span class="bd" style="background:<?= $corEstoque ?>;color:<?= $txtEstoque ?>;margin-top:3px">
                                    <?= $labelEstoque ?>
                                </span>
                            </td>

                            <td>
                                <?php if ((int)$p['ativo'] === 1): ?>
                                    <span class="bd" style="background:var(--osoft);color:var(--otxt)">Ativo</span>
                                <?php else: ?>
                                    <span class="bd" style="background:var(--rsoft);color:var(--rtxt)">Inativo</span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align:right;white-space:nowrap">
                                <button type="button" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px" onclick='editarProduto(<?= $jsonProd ?>)'>
                                    Editar
                                </button>

                                <form method="post" action="<?= BASE_URL ?>/modules/cadastros/produtos.php" style="display:inline" onsubmit="return confirm('Deseja alterar o status deste produto?');">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="acao" value="alternar_status">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px">
                                        <?= (int)$p['ativo'] === 1 ? 'Inativar' : 'Ativar' ?>
                                    </button>
                                </form>

                                <form method="post" action="<?= BASE_URL ?>/modules/cadastros/produtos.php" style="display:inline" onsubmit="return confirm('Tem certeza que deseja excluir este produto?');">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px;color:var(--rtxt)">
                                        Excluir
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <?php if ($totalPaginas > 1): ?>
        <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <div class="kl">Página <?= $paginaAtual ?> de <?= $totalPaginas ?> (<?= $totalRegistros ?> registros)</div>
            <div style="display:flex;gap:6px">
                <?php for ($p = 1; $p <= $totalPaginas; $p++):
                    $q = $_GET;
                    $q['pagina'] = $p;
                ?>
                    <a href="?<?= Security::e(http_build_query($q)) ?>"
                       class="btn-theme"
                       style="min-height:36px;padding:0 12px;text-decoration:none;<?= $p === $paginaAtual ? 'background:#0E9F6E;color:#fff;border-color:#0E9F6E' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ===================================================================== -->
<!-- MODAL DE CADASTRO / EDIÇÃO DE PRODUTO (Padrão Visual LivreOS)          -->
<!-- ===================================================================== -->
<div id="modalProduto" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;overflow-y:auto;padding:24px 14px">
    <div class="card" style="max-width:900px;margin:20px auto;padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:18px">
            <div>
                <span class="ct" id="modalProdTitulo" style="font-size:18px">Novo Produto</span>
                <div class="kl">Dados comerciais, formação de preço, estoque e tributação SEFAZ para NF-e / NFC-e</div>
            </div>
            <button type="button" class="btn-theme" style="min-height:36px;padding:0 12px" onclick="fecharModalProduto()">✕ Fechar</button>
        </div>

        <form method="post" action="<?= BASE_URL ?>/modules/cadastros/produtos.php" id="formProduto">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" id="prd_id" value="0">

            <!-- Seção 1: Identificação Comercial -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px">1. Identificação do Produto</div>
            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-3">
                    <label class="form-label-os" for="prd_codigo_sku">Código SKU *</label>
                    <input type="text" name="codigo_sku" id="prd_codigo_sku" class="form-control-os" placeholder="Ex: PRD-001" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_codigo_barras">EAN / GTIN (Barras)</label>
                    <input type="text" name="codigo_barras" id="prd_codigo_barras" class="form-control-os" value="SEM GTIN" placeholder="789... ou SEM GTIN">
                </div>

                <div class="col-md-6">
                    <label class="form-label-os" for="prd_nome">Descrição do Produto (Sairá na NF-e) *</label>
                    <input type="text" name="nome" id="prd_nome" class="form-control-os" placeholder="Nome completo da mercadoria" required>
                </div>

                <div class="col-md-5">
                    <label class="form-label-os" for="prd_categoria">Categoria / Grupo</label>
                    <input type="text" name="categoria" id="prd_categoria" class="form-control-os" placeholder="Ex: Equipamentos, Peças, Insumos…">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="prd_unidade">Unidade Comercial / Tributável *</label>
                    <select name="unidade" id="prd_unidade" class="form-select-os">
                        <?php foreach ($unidadesMedida as $sigla => $desc): ?>
                            <option value="<?= $sigla ?>"><?= Security::e($desc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_ativo">Situação</label>
                    <select name="ativo" id="prd_ativo" class="form-select-os">
                        <option value="1">Ativo</option>
                        <option value="0">Inativo</option>
                    </select>
                </div>
            </div>

            <!-- Seção 2: Formação de Preço e Estoque -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px;border-top:1px solid var(--border);padding-top:14px">
                2. Preços, Margem e Estoque
            </div>
            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-3">
                    <label class="form-label-os" for="prd_preco_custo">Preço de Custo (R$)</label>
                    <input type="text" name="preco_custo" id="prd_preco_custo" class="form-control-os" data-mask="money" value="0,00" oninput="recalcularPrecoVenda()">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_margem_lucro">Margem de Lucro (%)</label>
                    <input type="text" name="margem_lucro" id="prd_margem_lucro" class="form-control-os" data-mask="money" value="0,00" oninput="recalcularPrecoVenda()">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_preco_venda">Preço de Venda (R$) *</label>
                    <input type="text" name="preco_venda" id="prd_preco_venda" class="form-control-os" data-mask="money" value="0,00" oninput="recalcularMargemLucro()" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_estoque_atual">Estoque Atual</label>
                    <input type="number" name="estoque_atual" id="prd_estoque_atual" class="form-control-os" min="0" step="0.01" value="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="prd_estoque_minimo">Estoque Mínimo (Alerta)</label>
                    <input type="number" name="estoque_minimo" id="prd_estoque_minimo" class="form-control-os" min="0" step="0.01" value="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="prd_peso_liquido">Peso Líquido (kg - p/ NF-e)</label>
                    <input type="text" name="peso_liquido" id="prd_peso_liquido" class="form-control-os" data-mask="money" value="0,00">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="prd_peso_bruto">Peso Bruto (kg - p/ NF-e)</label>
                    <input type="text" name="peso_bruto" id="prd_peso_bruto" class="form-control-os" data-mask="money" value="0,00">
                </div>
            </div>

            <!-- Seção 3: Tributação Fiscal (NF-e / NFC-e) -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px;border-top:1px solid var(--border);padding-top:14px">
                3. Dados Fiscais e Tributação (NF-e / NFC-e)
            </div>
            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-3">
                    <label class="form-label-os" for="prd_ncm">NCM (8 dígitos) *</label>
                    <input type="text" name="ncm" id="prd_ncm" class="form-control-os" maxlength="8" placeholder="Ex: 84713012">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_cest">CEST (7 dígitos - se ST)</label>
                    <input type="text" name="cest" id="prd_cest" class="form-control-os" maxlength="7" placeholder="Opcional">
                </div>

                <div class="col-md-6">
                    <label class="form-label-os" for="prd_origem">Origem da Mercadoria (ICMS) *</label>
                    <select name="origem" id="prd_origem" class="form-select-os">
                        <option value="0">0 - Nacional</option>
                        <option value="1">1 - Estrangeira (Importação direta)</option>
                        <option value="2">2 - Estrangeira (Adquirida no mercado interno)</option>
                        <option value="3">3 - Nacional com conteúdo de importação &gt; 40%</option>
                        <option value="4">4 - Nacional (Processos produtivos básicos)</option>
                        <option value="5">5 - Nacional com conteúdo de importação &lt;= 40%</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_cfop_interno">CFOP Estadual *</label>
                    <input type="text" name="cfop_interno" id="prd_cfop_interno" class="form-control-os" maxlength="4" value="5102">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_cfop_externo">CFOP Interestadual *</label>
                    <input type="text" name="cfop_externo" id="prd_cfop_externo" class="form-control-os" maxlength="4" value="6102">
                </div>

                <div class="col-md-6">
                    <label class="form-label-os" for="prd_csosn_cst">CSOSN (Simples Nacional) / CST ICMS *</label>
                    <select name="csosn_cst" id="prd_csosn_cst" class="form-select-os">
                        <option value="102">102 - Tributada pelo Simples Nacional sem permissão de crédito</option>
                        <option value="101">101 - Tributada pelo Simples Nacional com permissão de crédito</option>
                        <option value="500">500 - ICMS cobrado anteriormente por Substituição Tributária (ST)</option>
                        <option value="400">400 - Não tributada pelo Simples Nacional</option>
                        <option value="00">00 - (Regime Normal) Tributada integralmente</option>
                        <option value="60">60 - (Regime Normal) ICMS cobrado anteriormente por ST</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_cst_pis">CST PIS</label>
                    <select name="cst_pis" id="prd_cst_pis" class="form-select-os">
                        <option value="07">07 - Operação Isenta</option>
                        <option value="01">01 - Alíquota Básica</option>
                        <option value="49">49 - Outras Operações Saída</option>
                        <option value="99">99 - Outras Operações</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_aliq_pis">Alíquota PIS (%)</label>
                    <input type="text" name="aliq_pis" id="prd_aliq_pis" class="form-control-os" data-mask="money" value="0,00">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_cst_cofins">CST COFINS</label>
                    <select name="cst_cofins" id="prd_cst_cofins" class="form-select-os">
                        <option value="07">07 - Operação Isenta</option>
                        <option value="01">01 - Alíquota Básica</option>
                        <option value="49">49 - Outras Operações Saída</option>
                        <option value="99">99 - Outras Operações</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_aliq_cofins">Alíquota COFINS (%)</label>
                    <input type="text" name="aliq_cofins" id="prd_aliq_cofins" class="form-control-os" data-mask="money" value="0,00">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_aliq_icms">Alíquota ICMS (%)</label>
                    <input type="text" name="aliq_icms" id="prd_aliq_icms" class="form-control-os" data-mask="money" value="0,00">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="prd_aliq_ipi">Alíquota IPI (%)</label>
                    <input type="text" name="aliq_ipi" id="prd_aliq_ipi" class="form-control-os" data-mask="money" value="0,00">
                </div>

                <div class="col-md-6">
                    <label class="form-label-os" for="prd_observacoes">Informações Adicionais do Produto (Sairá na NF-e se preenchido)</label>
                    <input type="text" name="observacoes" id="prd_observacoes" class="form-control-os" placeholder="Ex: Número de série, lote ou garantia…">
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:16px;border-top:1px solid var(--border)">
                <button type="button" class="btn-theme" onclick="fecharModalProduto()">Cancelar</button>
                <button type="submit" class="btn-primary-brand">Salvar Produto</button>
            </div>
        </form>
    </div>
</div>

<script>
function lerMoedaJs(str) {
    if (!str) return 0;
    var limpo = String(str).replace(/\./g, '').replace(',', '.');
    var n = parseFloat(limpo);
    return isNaN(n) ? 0 : n;
}

function formatarMoedaJs(num) {
    return (Number(num) || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function recalcularPrecoVenda() {
    var custo  = lerMoedaJs(document.getElementById('prd_preco_custo').value);
    var margem = lerMoedaJs(document.getElementById('prd_margem_lucro').value);
    if (custo > 0) {
        var venda = custo * (1 + (margem / 100));
        document.getElementById('prd_preco_venda').value = formatarMoedaJs(venda);
    }
}

function recalcularMargemLucro() {
    var custo = lerMoedaJs(document.getElementById('prd_preco_custo').value);
    var venda = lerMoedaJs(document.getElementById('prd_preco_venda').value);
    if (custo > 0 && venda >= custo) {
        var margem = ((venda - custo) / custo) * 100;
        document.getElementById('prd_margem_lucro').value = formatarMoedaJs(margem);
    } else {
        document.getElementById('prd_margem_lucro').value = '0,00';
    }
}

function abrirModalProduto() {
    document.getElementById('formProduto').reset();
    document.getElementById('prd_id').value = '0';
    document.getElementById('prd_codigo_sku').value = 'PRD-' + Math.floor(100 + Math.random() * 900);
    document.getElementById('prd_codigo_barras').value = 'SEM GTIN';
    document.getElementById('prd_preco_custo').value = '0,00';
    document.getElementById('prd_margem_lucro').value = '0,00';
    document.getElementById('prd_preco_venda').value = '0,00';
    document.getElementById('prd_estoque_atual').value = '0';
    document.getElementById('prd_estoque_minimo').value = '0';
    document.getElementById('prd_peso_liquido').value = '0,00';
    document.getElementById('prd_peso_bruto').value = '0,00';
    document.getElementById('prd_cfop_interno').value = '5102';
    document.getElementById('prd_cfop_externo').value = '6102';
    document.getElementById('prd_csosn_cst').value = '102';
    document.getElementById('modalProdTitulo').textContent = 'Novo Produto';
    document.getElementById('modalProduto').style.display = 'block';
}

function editarProduto(p) {
    document.getElementById('prd_id').value = p.id || 0;
    document.getElementById('prd_codigo_sku').value = p.codigo_sku || '';
    document.getElementById('prd_codigo_barras').value = p.codigo_barras || 'SEM GTIN';
    document.getElementById('prd_nome').value = p.nome || '';
    document.getElementById('prd_categoria').value = p.categoria || '';
    document.getElementById('prd_unidade').value = p.unidade || 'UN';
    document.getElementById('prd_ativo').value = (p.ativo !== undefined) ? String(p.ativo) : '1';

    document.getElementById('prd_preco_custo').value = formatarMoedaJs(p.preco_custo);
    document.getElementById('prd_margem_lucro').value = formatarMoedaJs(p.margem_lucro);
    document.getElementById('prd_preco_venda').value = formatarMoedaJs(p.preco_venda);
    document.getElementById('prd_estoque_atual').value = parseFloat(p.estoque_atual || 0);
    document.getElementById('prd_estoque_minimo').value = parseFloat(p.estoque_minimo || 0);
    document.getElementById('prd_peso_liquido').value = formatarMoedaJs(p.peso_liquido);
    document.getElementById('prd_peso_bruto').value = formatarMoedaJs(p.peso_bruto);

    document.getElementById('prd_ncm').value = p.ncm || '';
    document.getElementById('prd_cest').value = p.cest || '';
    document.getElementById('prd_origem').value = (p.origem !== undefined) ? String(p.origem) : '0';
    document.getElementById('prd_cfop_interno').value = p.cfop_interno || '5102';
    document.getElementById('prd_cfop_externo').value = p.cfop_externo || '6102';
    document.getElementById('prd_csosn_cst').value = p.csosn_cst || '102';
    document.getElementById('prd_cst_pis').value = p.cst_pis || '07';
    document.getElementById('prd_aliq_pis').value = formatarMoedaJs(p.aliq_pis);
    document.getElementById('prd_cst_cofins').value = p.cst_cofins || '07';
    document.getElementById('prd_aliq_cofins').value = formatarMoedaJs(p.aliq_cofins);
    document.getElementById('prd_aliq_icms').value = formatarMoedaJs(p.aliq_icms);
    document.getElementById('prd_aliq_ipi').value = formatarMoedaJs(p.aliq_ipi);
    document.getElementById('prd_observacoes').value = p.observacoes || '';

    document.getElementById('modalProdTitulo').textContent = 'Editar Produto #' + p.id;
    document.getElementById('modalProduto').style.display = 'block';
}

function fecharModalProduto() {
    document.getElementById('modalProduto').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
