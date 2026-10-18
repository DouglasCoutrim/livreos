<?php
// Caminho: /modules/compras/notas_compra.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('compras.ver') && !Auth::hasPermission('cadastros.ver')) {
    Session::setFlash('error', 'Você não tem permissão para acessar o módulo de Compras.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();
Session::start();

// =========================================================================
// 1. RECEBE O XML E FAZ O PARSE PARA A TELA DE CONFERÊNCIA
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['xml_file'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        Session::setFlash('error', 'Token de segurança inválido. Tente novamente.');
        header('Location: ' . BASE_URL . '/modules/compras/notas_compra.php');
        exit;
    }

    $file = $_FILES['xml_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        Session::setFlash('error', 'Erro ao carregar o arquivo XML.');
        header('Location: ' . BASE_URL . '/modules/compras/notas_compra.php');
        exit;
    }

    $xmlContent = file_get_contents($file['tmp_name']);
    $xmlContentClean = preg_replace('/(xmlns:[\w"]+)=("[^"]*")/i', '', $xmlContent);
    $xmlContentClean = preg_replace('/(xmlns)=("[^"]*")/i', '', $xmlContentClean);

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContentClean);

    if ($xml === false) {
        Session::setFlash('error', 'O arquivo enviado não é um XML de NF-e válido.');
        header('Location: ' . BASE_URL . '/modules/compras/notas_compra.php');
        exit;
    }

    $infNFe = null;
    if (isset($xml->NFe->infNFe)) {
        $infNFe = $xml->NFe->infNFe;
    } elseif (isset($xml->infNFe)) {
        $infNFe = $xml->infNFe;
    }

    if (!$infNFe) {
        Session::setFlash('error', 'Estrutura da NF-e não encontrada no arquivo XML.');
        header('Location: ' . BASE_URL . '/modules/compras/notas_compra.php');
        exit;
    }

    $chaveAcesso = '';
    if (isset($xml->protNFe->infProt->chNFe)) {
        $chaveAcesso = (string)$xml->protNFe->infProt->chNFe;
    } elseif (isset($infNFe['Id'])) {
        $chaveAcesso = str_replace('NFe', '', (string)$infNFe['Id']);
    }

    // Verifica duplicidade
    $stmtChk = $db->prepare("SELECT id FROM notas_compra WHERE empresa_id = :emp AND chave_acesso = :ch LIMIT 1");
    $stmtChk->execute([':emp' => $empresaId, ':ch' => $chaveAcesso]);
    if ($stmtChk->fetch()) {
        Session::setFlash('error', 'Esta Nota Fiscal (Chave: ' . $chaveAcesso . ') já foi importada anteriormente.');
        header('Location: ' . BASE_URL . '/modules/compras/notas_compra.php');
        exit;
    }

    // Extrai dados do Emitente (Fornecedor)
    $emit = $infNFe->emit;
    $emitCnpj = preg_replace('/\D/', '', (string)$emit->CNPJ);
    $emitNome = (string)$emit->xNome;
    $emitFantasia = isset($emit->xFant) ? (string)$emit->xFant : null;
    $emitIe = isset($emit->IE) ? (string)$emit->IE : null;
    $emitEnder = $emit->enderEmit;

    $cnpjFmt = strlen($emitCnpj) === 14 ? substr($emitCnpj,0,2).'.'.substr($emitCnpj,2,3).'.'.substr($emitCnpj,5,3).'/'.substr($emitCnpj,8,4).'-'.substr($emitCnpj,12,2) : $emitCnpj;

    // Verifica se Fornecedor já existe
    $stmtForn = $db->prepare("SELECT id, nome_razao FROM fornecedores WHERE empresa_id = :emp AND cpf_cnpj = :doc LIMIT 1");
    $stmtForn->execute([':emp' => $empresaId, ':doc' => $cnpjFmt]);
    $fornRow = $stmtForn->fetch();

    // Extrai dados da Transportadora
    $transp = $infNFe->transp;
    $transpNome = null;
    $transpCnpj = null;
    $transpId = null;
    if (isset($transp->transporta)) {
        $t = $transp->transporta;
        if (isset($t->xNome)) $transpNome = (string)$t->xNome;
        if (isset($t->CNPJ)) {
            $transpCnpj = preg_replace('/\D/', '', (string)$t->CNPJ);
            $transpCnpjFmt = strlen($transpCnpj) === 14 ? substr($transpCnpj,0,2).'.'.substr($transpCnpj,2,3).'.'.substr($transpCnpj,5,3).'/'.substr($transpCnpj,8,4).'-'.substr($transpCnpj,12,2) : $transpCnpj;
            
            $stmtTr = $db->prepare("SELECT id FROM transportadoras WHERE empresa_id = :emp AND cpf_cnpj = :doc LIMIT 1");
            $stmtTr->execute([':emp' => $empresaId, ':doc' => $transpCnpjFmt]);
            $trRow = $stmtTr->fetch();
            if ($trRow) {
                $transpId = (int)$trRow['id'];
            }
        }
    }

    // Extrai Itens
    $itensPre = [];
    foreach ($infNFe->det as $det) {
        $prod = $det->prod;
        $cProd = (string)$prod->cProd;
        $cEan = isset($prod->cEAN) && (string)$prod->cEAN !== 'SEM GTIN' ? (string)$prod->cEAN : null;
        $xProd = (string)$prod->xProd;
        $ncm = isset($prod->NCM) ? (string)$prod->NCM : null;
        $uCom = isset($prod->uCom) ? (string)$prod->uCom : 'UN';
        $qCom = (float)$prod->qCom;
        $vUnCom = (float)$prod->vUnCom;
        $vProd = (float)$prod->vProd;

        $produtoExistente = null;
        if ($cEan) {
            $stmtP = $db->prepare("SELECT id, nome, estoque_atual FROM produtos WHERE empresa_id = :emp AND codigo_barras = :ean LIMIT 1");
            $stmtP->execute([':emp' => $empresaId, ':ean' => $cEan]);
            $produtoExistente = $stmtP->fetch();
        }
        if (!$produtoExistente) {
            $stmtP2 = $db->prepare("SELECT id, nome, estoque_atual FROM produtos WHERE empresa_id = :emp AND codigo_sku = :sku LIMIT 1");
            $stmtP2->execute([':emp' => $empresaId, ':sku' => $cProd]);
            $produtoExistente = $stmtP2->fetch();
        }

        $itensPre[] = [
            'codigo'    => $cProd,
            'ean'       => $cEan,
            'descricao' => $xProd,
            'ncm'       => $ncm,
            'unidade'   => $uCom,
            'qtd'       => $qCom,
            'v_unit'    => $vUnCom,
            'v_total'   => $vProd,
            'ja_existe' => $produtoExistente ? true : false,
            'prod_id'   => $produtoExistente ? (int)$produtoExistente['id'] : null,
            'est_atual' => $produtoExistente ? (float)$produtoExistente['estoque_atual'] : 0,
        ];
    }

    $ide = $infNFe->ide;
    $total = $infNFe->total->ICMSTot;

    $_SESSION['xml_pendente'] = [
        'xml_content'   => $xmlContent,
        'chave'         => $chaveAcesso,
        'modelo'        => isset($ide->mod) ? (string)$ide->mod : '55',
        'serie'         => isset($ide->serie) ? (string)$ide->serie : '1',
        'numero'        => isset($ide->nNF) ? (string)$ide->nNF : '0',
        'data_emissao'  => isset($ide->dhEmi) ? (string)$ide->dhEmi : date('Y-m-d'),
        'v_produtos'    => (float)$total->vProd,
        'v_frete'       => (float)$total->vFrete,
        'v_desconto'    => (float)$total->vDesc,
        'v_total'       => (float)$total->vNF,
        'fornecedor'    => [
            'cnpj'      => $cnpjFmt,
            'nome'      => $emitNome,
            'fantasia'  => $emitFantasia,
            'ie'        => $emitIe,
            'cep'       => isset($emitEnder->CEP) ? (string)$emitEnder->CEP : null,
            'logr'      => isset($emitEnder->xLgr) ? (string)$emitEnder->xLgr : null,
            'nro'       => isset($emitEnder->nro) ? (string)$emitEnder->nro : null,
            'bairro'    => isset($emitEnder->xBairro) ? (string)$emitEnder->xBairro : null,
            'mun'       => isset($emitEnder->xMun) ? (string)$emitEnder->xMun : null,
            'uf'        => isset($emitEnder->UF) ? (string)$emitEnder->UF : null,
            'ibge'      => isset($emitEnder->cMun) ? (string)$emitEnder->cMun : null,
            'ja_existe' => $fornRow ? true : false,
            'id_exist'  => $fornRow ? (int)$fornRow['id'] : null,
        ],
        'transportadora' => [
            'nome'      => $transpNome,
            'cnpj'      => $transpCnpjFmt ?? null,
            'ja_existe' => $transpId ? true : false,
            'id_exist'  => $transpId,
        ],
        'itens'         => $itensPre,
    ];

    header('Location: ' . BASE_URL . '/modules/compras/notas_compra.php?conferir=1');
    exit;
}

// =========================================================================
// 2. CONFIRMAÇÃO E GRAVAÇÃO FINAL APÓS REVISÃO DO USUÁRIO
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_importacao'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf) || empty($_SESSION['xml_pendente'])) {
        Session::setFlash('error', 'Sessão expirada ou dados inválidos.');
        header('Location: ' . BASE_URL . '/modules/compras/notas_compra.php');
        exit;
    }

    $dados = $_SESSION['xml_pendente'];
    $cadForn = isset($_POST['cadastrar_fornecedor']) ? (int)$_POST['cadastrar_fornecedor'] : 0;
    $cadTransp = isset($_POST['cadastrar_transportadora']) ? (int)$_POST['cadastrar_transportadora'] : 0;

    try {
        $db->beginTransaction();

        $fornecedorId = $dados['fornecedor']['id_exist'];
        if (!$fornecedorId && $cadForn === 1) {
            $f = $dados['fornecedor'];
            $stmtInsForn = $db->prepare("
                INSERT INTO fornecedores (empresa_id, tipo_pessoa, cpf_cnpj, nome_razao, nome_fantasia, inscricao_estadual, cep, logradouro, numero, bairro, cidade, uf, codigo_ibge, ativo)
                VALUES (:emp, 'PJ', :doc, :razao, :fantasia, :ie, :cep, :logr, :nro, :bairro, :mun, :uf, :ibge, 1)
            ");
            $stmtInsForn->execute([
                ':emp'      => $empresaId,
                ':doc'      => $f['cnpj'],
                ':razao'    => $f['nome'],
                ':fantasia' => $f['fantasia'],
                ':ie'       => $f['ie'],
                ':cep'      => $f['cep'],
                ':logr'     => $f['logr'],
                ':nro'      => $f['nro'],
                ':bairro'   => $f['bairro'],
                ':mun'      => $f['mun'],
                ':uf'       => $f['uf'],
                ':ibge'     => $f['ibge'],
            ]);
            $fornecedorId = (int)$db->lastInsertId();
        }

        $transpId = $dados['transportadora']['id_exist'];
        if (!$transpId && $cadTransp === 1 && !empty($dados['transportadora']['nome'])) {
            $t = $dados['transportadora'];
            $stmtInsTr = $db->prepare("
                INSERT INTO transportadoras (empresa_id, cpf_cnpj, nome_razao, ativo)
                VALUES (:emp, :doc, :razao, 1)
            ");
            $stmtInsTr->execute([
                ':emp'   => $empresaId,
                ':doc'   => $t['cnpj'] ?? '00.000.000/0001-00',
                ':razao' => $t['nome'],
            ]);
            $transpId = (int)$db->lastInsertId();
        }

        $stmtNc = $db->prepare("
            INSERT INTO notas_compra (empresa_id, fornecedor_id, chave_acesso, modelo, serie, numero_nf, data_emissao, valor_produtos, valor_frete, valor_desconto, valor_total, xml_importado)
            VALUES (:emp, :forn, :ch, :mod, :ser, :num, :dem, :vprod, :vfrete, :vdesc, :vtot, :xml)
        ");
        $stmtNc->execute([
            ':emp'    => $empresaId,
            ':forn'   => $fornecedorId,
            ':ch'     => $dados['chave'],
            ':mod'    => $dados['modelo'],
            ':ser'    => $dados['serie'],
            ':num'    => $dados['numero'],
            ':dem'    => date('Y-m-d H:i:s', strtotime($dados['data_emissao'])),
            ':vprod'  => $dados['v_produtos'],
            ':vfrete' => $dados['v_frete'],
            ':vdesc'  => $dados['v_desconto'],
            ':vtot'   => $dados['v_total'],
            ':xml'    => $dados['xml_content'],
        ]);
        $notaCompraId = (int)$db->lastInsertId();

        foreach ($dados['itens'] as $idx => $item) {
            $importarItem = isset($_POST['item_inc'][$idx]) ? (int)$_POST['item_inc'][$idx] : 0;
            if ($importarItem !== 1) continue;

            $produtoId = $item['prod_id'];
            if (!$produtoId) {
                $stmtInsProd = $db->prepare("
                    INSERT INTO produtos (empresa_id, codigo_sku, codigo_barras, nome, unidade, preco_custo, margem_lucro, preco_venda, estoque_atual, ncm, ativo)
                    VALUES (:emp, :sku, :ean, :nome, :un, :custo, 50.00, :venda, :qtd, :ncm, 1)
                ");
                $stmtInsProd->execute([
                    ':emp'   => $empresaId,
                    ':sku'   => $item['codigo'],
                    ':ean'   => $item['ean'] ?: 'SEM GTIN',
                    ':nome'  => $item['descricao'],
                    ':un'    => $item['unidade'],
                    ':custo' => $item['v_unit'],
                    ':venda' => round($item['v_unit'] * 1.5, 2),
                    ':qtd'   => $item['qtd'],
                    ':ncm'   => $item['ncm'],
                ]);
                $produtoId = (int)$db->lastInsertId();
            } else {
                $stmtUpEst = $db->prepare("UPDATE produtos SET estoque_atual = estoque_atual + :qtd, preco_custo = :custo WHERE id = :id AND empresa_id = :emp");
                $stmtUpEst->execute([':qtd' => $item['qtd'], ':custo' => $item['v_unit'], ':id' => $produtoId, ':emp' => $empresaId]);
            }

            $stmtItem = $db->prepare("
                INSERT INTO itens_nota_compra (nota_compra_id, produto_id, codigo_produto_fornecedor, ean, descricao, ncm, cfop, unidade, quantidade, valor_unitario, valor_total)
                VALUES (:nc, :prod, :codf, :ean, :desc, :ncm, '5102', :un, :qtd, :vun, :vtot)
            ");
            $stmtItem->execute([
                ':nc'   => $notaCompraId,
                ':prod' => $produtoId,
                ':codf' => $item['codigo'],
                ':ean'  => $item['ean'],
                ':desc' => $item['descricao'],
                ':ncm'  => $item['ncm'],
                ':un'   => $item['unidade'],
                ':qtd'  => $item['qtd'],
                ':vun'  => $item['v_unit'],
                ':vtot' => $item['v_total'],
            ]);
        }

        libxml_use_internal_errors(true);
        $xmlObj = simplexml_load_string($dados['xml_content']);
        $infNFe = $xmlObj->NFe->infNFe ?? $xmlObj->infNFe ?? null;
        if ($infNFe && isset($infNFe->cobr->dup)) {
            $stmtConta = $db->prepare("SELECT id FROM contas_financeiras WHERE empresa_id = :emp AND ativo = 1 LIMIT 1");
            $stmtConta->execute([':emp' => $empresaId]);
            $contaId = $stmtConta->fetchColumn();

            if ($contaId) {
                foreach ($infNFe->cobr->dup as $dup) {
                    $vencDup = (string)$dup->dVenc;
                    $vDup = (float)$dup->vDup;
                    $numDup = isset($dup->nDup) ? (string)$dup->nDup : 'NF ' . $dados['numero'];

                    $stmtFin = $db->prepare("
                        INSERT INTO lancamentos_financeiros (empresa_id, conta_id, pessoa_nome, descricao, tipo, valor, data_vencimento, status)
                        VALUES (:emp, :conta, :pessoa, :desc, 'despesa', :val, :venc, 'pendente')
                    ");
                    $stmtFin->execute([
                        ':emp'    => $empresaId,
                        ':conta'  => $contaId,
                        ':pessoa' => $dados['fornecedor']['nome'],
                        ':desc'   => "NF-e #{$dados['numero']} - Dupl. {$numDup}",
                        ':val'    => $vDup,
                        ':venc'   => $vencDup,
                    ]);
                }
            }
        }

        unset($_SESSION['xml_pendente']);
        $db->commit();

        Session::setFlash('success', "Nota fiscal #{$dados['numero']} importada com sucesso!");
        header('Location: ' . BASE_URL . '/modules/compras/notas_compra.php');
        exit;

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        Session::setFlash('error', 'Erro ao importar: ' . $e->getMessage());
        header('Location: ' . BASE_URL . '/modules/compras/notas_compra.php');
        exit;
    }
}

$modoConferencia = isset($_GET['conferir']) && !empty($_SESSION['xml_pendente']);
$dadosConferencia = $_SESSION['xml_pendente'] ?? null;

$stmtLista = $db->prepare("
    SELECT nc.*, f.nome_razao AS fornecedor_nome
    FROM notas_compra nc
    LEFT JOIN fornecedores f ON f.id = nc.fornecedor_id
    WHERE nc.empresa_id = :emp
    ORDER BY nc.data_emissao DESC
    LIMIT 50
");
$stmtLista->execute([':emp' => $empresaId]);
$notas = $stmtLista->fetchAll();

$pageTitle    = 'Notas de Compra (XML)';
$activeModule = 'compras';
$activeSub    = 'notas_compra';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($modoConferencia && $dadosConferencia): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div>
            <div class="ct" style="font-size:20px">Conferência da Nota Fiscal de Entrada (#<?= Security::e($dadosConferencia['numero']) ?>)</div>
            <div class="kl">Revise as informações extraídas do XML antes de confirmar a importação no sistema</div>
        </div>
        <a href="<?= BASE_URL ?>/modules/compras/notas_compra.php" class="btn-theme" style="text-decoration:none">Cancelar e Voltar</a>
    </div>

    <form method="post" action="<?= BASE_URL ?>/modules/compras/notas_compra.php">
        <?= Security::csrfField() ?>
        <input type="hidden" name="confirmar_importacao" value="1">

        <div class="card" style="margin-bottom:16px">
            <span class="ct" style="color:#0E9F6E">1. Fornecedor (Emitente da Nota)</span>
            <div style="margin-top:10px;font-size:13.5px">
                <b><?= Security::e($dadosConferencia['fornecedor']['nome']) ?></b> (CNPJ: <?= Security::e($dadosConferencia['fornecedor']['cnpj']) ?>)
                <div class="kl" style="margin-top:4px">
                    <?php if ($dadosConferencia['fornecedor']['ja_existe']): ?>
                        <span class="bd" style="background:var(--osoft);color:var(--otxt)">Fornecedor já cadastrado no sistema.</span>
                        <input type="hidden" name="cadastrar_fornecedor" value="0">
                    <?php else: ?>
                        <span class="bd" style="background:var(--asoft);color:var(--atxt)">Fornecedor novo. Deseja cadastrá-lo?</span>
                        <label style="margin-left:12px;font-weight:600">
                            <input type="checkbox" name="cadastrar_fornecedor" value="1" checked> Cadastrar fornecedor automaticamente
                        </label>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:16px">
            <span class="ct" style="color:#0E9F6E">2. Transportadora</span>
            <div style="margin-top:10px;font-size:13.5px">
                <?php if (!empty($dadosConferencia['transportadora']['nome'])): ?>
                    <b><?= Security::e($dadosConferencia['transportadora']['nome']) ?></b>
                    <div class="kl" style="margin-top:4px">
                        <?php if ($dadosConferencia['transportadora']['ja_existe']): ?>
                            <span class="bd" style="background:var(--osoft);color:var(--otxt)">Transportadora já cadastrada.</span>
                            <input type="hidden" name="cadastrar_transportadora" value="0">
                        <?php else: ?>
                            <span class="bd" style="background:var(--asoft);color:var(--atxt)">Transportadora nova informada no XML.</span>
                            <label style="margin-left:12px;font-weight:600">
                                <input type="checkbox" name="cadastrar_transportadora" value="1"> Cadastrar transportadora automaticamente
                            </label>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <span class="kl">Nenhuma transportadora informada neste XML.</span>
                    <input type="hidden" name="cadastrar_transportadora" value="0">
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="padding:0;overflow:hidden;margin-bottom:20px">
            <div style="padding:18px 20px;border-bottom:1px solid var(--border)">
                <span class="ct" style="color:#0E9F6E">3. Produtos da Nota Fiscal (Confirme quais deseja importar/atualizar)</span>
            </div>
            <div class="table-responsive-os">
                <table class="table-os">
                    <thead>
                        <tr>
                            <th style="width:40px;text-align:center">Incluir</th>
                            <th>Código / EAN</th>
                            <th>Descrição do Produto</th>
                            <th>NCM / Unid.</th>
                            <th>Qtd.</th>
                            <th>Valor Unit.</th>
                            <th style="text-align:right">Total Item</th>
                            <th>Status no Sistema</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dadosConferencia['itens'] as $idx => $it): ?>
                            <tr>
                                <td style="text-align:center">
                                    <input type="checkbox" name="item_inc[<?= $idx ?>]" value="1" checked style="width:18px;height:18px;accent-color:#0E9F6E">
                                </td>
                                <td>
                                    <b><?= Security::e($it['codigo']) ?></b>
                                    <div class="kl"><?= Security::e($it['ean'] ?: 'Sem EAN') ?></div>
                                </td>
                                <td><b><?= Security::e($it['descricao']) ?></b></td>
                                <td><?= Security::e($it['ncm']) ?> · <b><?= Security::e($it['unidade']) ?></b></td>
                                <td><b><?= number_format($it['qtd'], 2, ',', '.') ?></b></td>
                                <td><?= Security::formatMoney($it['v_unit']) ?></td>
                                <td style="text-align:right"><b><?= Security::formatMoney($it['v_total']) ?></b></td>
                                <td>
                                    <?php if ($it['ja_existe']): ?>
                                        <span class="bd" style="background:var(--osoft);color:var(--otxt)" title="Produto já cadastrado. A quantidade será somada ao estoque atual.">
                                            Já existe (Estoque atual: <?= number_format($it['est_atual'], 2, ',', '.') ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="bd" style="background:var(--asoft);color:var(--atxt)" title="Produto novo. Será cadastrado automaticamente ao confirmar.">
                                            Novo (Será cadastrado)
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:12px">
            <a href="<?= BASE_URL ?>/modules/compras/notas_compra.php" class="btn-theme" style="text-decoration:none">Cancelar</a>
            <button type="submit" class="btn-primary-brand" style="padding:12px 24px;font-size:15px">✓ Confirmar e Efetivar Importação</button>
        </div>
    </form>

<?php else: ?>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <div class="ct" style="font-size:20px">Notas de Compra (XML)</div>
            <div class="kl" style="margin-top:2px">Importe notas fiscais (NF-e modelo 55) com conferência interativa de fornecedor, transportadora e produtos</div>
        </div>
        <button type="button" class="btn-primary-brand" onclick="abrirModalXml()">
            📁 Importar XML da NF-e
        </button>
    </div>

    <div class="card" style="padding:0;overflow:hidden;margin-top:20px">
        <div style="padding:18px 20px;border-bottom:1px solid var(--border)">
            <span class="ct">Notas Fiscais de Entrada Importadas</span>
        </div>

        <div class="table-responsive-os">
            <table class="table-os">
                <thead>
                    <tr>
                        <th>Número / Série</th>
                        <th>Fornecedor</th>
                        <th>Data Emissão</th>
                        <th>Chave de Acesso</th>
                        <th>Valor Produtos</th>
                        <th style="text-align:right">Valor Total da Nota</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($notas)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:40px;color:var(--muted)">
                                Nenhuma Nota de Compra importada ainda. Clique em "Importar XML da NF-e" para começar.
                            </td>
                        </tr>
                    <?php else: foreach ($notas as $n): ?>
                        <tr>
                            <td>
                                <b>#<?= Security::e($n['numero_nf']) ?></b>
                                <div class="kl">Série: <?= Security::e($n['serie']) ?> · Mod: <?= Security::e($n['modelo']) ?></div>
                            </td>
                            <td>
                                <b><?= Security::e($n['fornecedor_nome'] ?: 'Fornecedor não identificado') ?></b>
                            </td>
                            <td><?= Security::formatDate(substr($n['data_emissao'], 0, 10)) ?></td>
                            <td><code style="font-size:11px"><?= Security::e($n['chave_acesso']) ?></code></td>
                            <td><?= Security::formatMoney($n['valor_produtos']) ?></td>
                            <td style="text-align:right">
                                <b style="color:#0E9F6E;font-size:14px"><?= Security::formatMoney($n['valor_total']) ?></b>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="modalXml" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;overflow-y:auto;padding:24px 14px">
        <div class="card" style="max-width:540px;margin:40px auto;padding:24px">
            <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:18px">
                <span class="ct" style="font-size:18px">Importar XML da NF-e (Modelo 55)</span>
                <button type="button" class="btn-theme" style="min-height:36px;padding:0 12px" onclick="fecharModalXml()">✕</button>
            </div>

            <form method="post" enctype="multipart/form-data" action="<?= BASE_URL ?>/modules/compras/notas_compra.php">
                <?= Security::csrfField() ?>
                
                <div style="margin-bottom:16px">
                    <label class="form-label-os">Selecione o arquivo XML da Nota Fiscal *</label>
                    <input type="file" name="xml_file" accept=".xml" class="form-control-os" required style="padding:8px">
                </div>

                <div style="background:var(--bg);padding:14px;border-radius:10px;font-size:13px;color:var(--text2);margin-bottom:20px;line-height:1.5">
                    <b>Fluxo Assistido:</b>
                    <ul style="margin:6px 0 0 18px;padding:0">
                        <li>O sistema exibirá a conferência do fornecedor e da transportadora.</li>
                        <li>Você revisará a lista de produtos (novos ou existentes para incremento de estoque).</li>
                        <li>Você confirmará a importação antes de gravar no banco de dados.</li>
                    </ul>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:10px">
                    <button type="button" class="btn-theme" onclick="fecharModalXml()">Cancelar</button>
                    <button type="submit" class="btn-primary-brand">Avançar para Conferência</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function abrirModalXml() {
        document.getElementById('modalXml').style.display = 'block';
    }
    function fecharModalXml() {
        document.getElementById('modalXml').style.display = 'none';
    }
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>