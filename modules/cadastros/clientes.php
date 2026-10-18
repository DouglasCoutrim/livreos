<?php
// Caminho: /modules/cadastros/clientes.php
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
 * Valida matematicamente CPF (11 dígitos) ou CNPJ (14 dígitos)
 */
function validarCpfCnpj(string $doc): bool
{
    $num = preg_replace('/\D/', '', $doc);

    if (strlen($num) === 11) {
        if (preg_match('/^(\d)\1{10}$/', $num)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += (int)$num[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ((int)$num[$c] !== $d) {
                return false;
            }
        }
        return true;
    }

    if (strlen($num) === 14) {
        if (preg_match('/^(\d)\1{13}$/', $num)) {
            return false;
        }
        $pesos1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesos2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $soma = 0;
        for ($i = 0; $i < 12; $i++) {
            $soma += (int)$num[$i] * $pesos1[$i];
        }
        $resto = $soma % 11;
        $dv1 = ($resto < 2) ? 0 : 11 - $resto;
        if ((int)$num[12] !== $dv1) {
            return false;
        }

        $soma = 0;
        for ($i = 0; $i < 13; $i++) {
            $soma += (int)$num[$i] * $pesos2[$i];
        }
        $resto = $soma % 11;
        $dv2 = ($resto < 2) ? 0 : 11 - $resto;
        return ((int)$num[13] === $dv2);
    }

    return false;
}

/**
 * Converte string de moeda BR "25.000,50" para float 25000.50
 */
function parseMoedaBr(string $valor): float
{
    $limpo = str_replace(['R$', ' ', '.'], '', trim($valor));
    $limpo = str_replace(',', '.', $limpo);
    return is_numeric($limpo) ? (float)$limpo : 0.00;
}

// =========================================================================
// PROCESSAMENTO DE FORMULÁRIOS (POST: SALVAR, ALTERNAR STATUS, EXCLUIR)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        Session::setFlash('error', 'Token de segurança inválido. Tente novamente.');
        header('Location: ' . BASE_URL . '/modules/cadastros/clientes.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';

    // 1. SALVAR (NOVO OU EDIÇÃO)
    if ($acao === 'salvar') {
        $id                 = (int)($_POST['id'] ?? 0);
        $tipoPessoa         = ($_POST['tipo_pessoa'] ?? 'PJ') === 'PF' ? 'PF' : 'PJ';
        $cpfCnpj            = trim($_POST['cpf_cnpj'] ?? '');
        $nomeRazao          = trim($_POST['nome_razao'] ?? '');
        $nomeFantasia       = trim($_POST['nome_fantasia'] ?? '') ?: null;
        $indicadorIe        = (int)($_POST['indicador_ie'] ?? 9);
        $inscricaoEstadual  = strtoupper(trim($_POST['inscricao_estadual'] ?? '')) ?: null;
        $inscricaoMunicipal = trim($_POST['inscricao_municipal'] ?? '') ?: null;
        $suframa            = trim($_POST['suframa'] ?? '') ?: null;
        $emailNfe           = strtolower(trim($_POST['email_nfe'] ?? '')) ?: null;
        $email              = strtolower(trim($_POST['email'] ?? '')) ?: null;
        $telefone           = trim($_POST['telefone'] ?? '') ?: null;
        $celular            = trim($_POST['celular'] ?? '') ?: null;
        $limiteCredito      = parseMoedaBr($_POST['limite_credito'] ?? '0,00');
        $cep                = trim($_POST['cep'] ?? '') ?: null;
        $logradouro         = trim($_POST['logradouro'] ?? '') ?: null;
        $numero             = trim($_POST['numero'] ?? '') ?: null;
        $complemento        = trim($_POST['complemento'] ?? '') ?: null;
        $bairro             = trim($_POST['bairro'] ?? '') ?: null;
        $cidade             = trim($_POST['cidade'] ?? '') ?: null;
        $uf                 = strtoupper(trim($_POST['uf'] ?? '')) ?: null;
        $codigoIbge         = preg_replace('/\D/', '', $_POST['codigo_ibge'] ?? '') ?: null;
        $observacoes        = trim($_POST['observacoes'] ?? '') ?: null;
        $ativo              = isset($_POST['ativo']) && (int)$_POST['ativo'] === 0 ? 0 : 1;

        if (!in_array($indicadorIe, [1, 2, 9], true)) {
            $indicadorIe = 9;
        }
        if ($indicadorIe === 2 && empty($inscricaoEstadual)) {
            $inscricaoEstadual = 'ISENTO';
        }

        // Validações no Servidor
        $erros = [];
        if ($nomeRazao === '' || mb_strlen($nomeRazao) < 3) {
            $erros[] = 'Informe o Nome Completo ou Razão Social (mínimo 3 caracteres).';
        }
        if ($cpfCnpj === '' || !validarCpfCnpj($cpfCnpj)) {
            $erros[] = 'O CPF ou CNPJ informado é inválido.';
        }
        if ($indicadorIe === 1 && (empty($inscricaoEstadual) || $inscricaoEstadual === 'ISENTO')) {
            $erros[] = 'Para Contribuinte de ICMS (Indicador 1), a Inscrição Estadual é obrigatória.';
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'O e-mail principal informado é inválido.';
        }
        if ($emailNfe !== null && !filter_var($emailNfe, FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'O e-mail de envio da NF-e informado é inválido.';
        }
        if ($codigoIbge !== null && strlen($codigoIbge) !== 7) {
            $erros[] = 'O Código IBGE do município deve conter exatamente 7 dígitos numéricos.';
        }

        // Verifica duplicidade de CPF/CNPJ na mesma empresa
        if (empty($erros)) {
            $stmtDup = $db->prepare("
                SELECT id FROM clientes
                WHERE empresa_id = :emp AND cpf_cnpj = :doc AND id != :id
                LIMIT 1
            ");
            $stmtDup->execute([':emp' => $empresaId, ':doc' => $cpfCnpj, ':id' => $id]);
            if ($stmtDup->fetch()) {
                $erros[] = 'Já existe um cliente cadastrado com este CPF/CNPJ nesta empresa.';
            }
        }

        if (!empty($erros)) {
            Session::setFlash('error', implode(' ', $erros));
            header('Location: ' . BASE_URL . '/modules/cadastros/clientes.php');
            exit;
        }

        $params = [
            ':empresa_id'          => $empresaId,
            ':tipo_pessoa'         => $tipoPessoa,
            ':cpf_cnpj'            => $cpfCnpj,
            ':nome_razao'          => $nomeRazao,
            ':nome_fantasia'       => $nomeFantasia,
            ':indicador_ie'        => $indicadorIe,
            ':inscricao_estadual'  => $inscricaoEstadual,
            ':inscricao_municipal' => $inscricaoMunicipal,
            ':suframa'            => $suframa,
            ':email_nfe'           => $emailNfe,
            ':email'               => $email,
            ':telefone'            => $telefone,
            ':celular'             => $celular,
            ':limite_credito'      => $limiteCredito,
            ':cep'                 => $cep,
            ':logradouro'          => $logradouro,
            ':numero'              => $numero,
            ':complemento'         => $complemento,
            ':bairro'              => $bairro,
            ':cidade'              => $cidade,
            ':uf'                 => $uf,
            ':codigo_ibge'         => $codigoIbge,
            ':observacoes'         => $observacoes,
            ':ativo'               => $ativo,
        ];

        if ($id > 0) {
            $params[':id'] = $id;
            $sql = "
                UPDATE clientes SET
                    tipo_pessoa = :tipo_pessoa,
                    cpf_cnpj = :cpf_cnpj,
                    nome_razao = :nome_razao,
                    nome_fantasia = :nome_fantasia,
                    indicador_ie = :indicador_ie,
                    inscricao_estadual = :inscricao_estadual,
                    inscricao_municipal = :inscricao_municipal,
                    suframa = :suframa,
                    email_nfe = :email_nfe,
                    email = :email,
                    telefone = :telefone,
                    celular = :celular,
                    limite_credito = :limite_credito,
                    cep = :cep,
                    logradouro = :logradouro,
                    numero = :numero,
                    complemento = :complemento,
                    bairro = :bairro,
                    cidade = :cidade,
                    uf = :uf,
                    codigo_ibge = :codigo_ibge,
                    observacoes = :observacoes,
                    ativo = :ativo
                WHERE id = :id AND empresa_id = :empresa_id
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            AuditLogger::log('CLIENTE_EDITAR', 'Cadastros', "Editou o cliente #{$id} ({$nomeRazao})");
            Session::setFlash('success', 'Cliente atualizado com sucesso!');
        } else {
            $sql = "
                INSERT INTO clientes (
                    empresa_id, tipo_pessoa, cpf_cnpj, nome_razao, nome_fantasia,
                    indicador_ie, inscricao_estadual, inscricao_municipal, suframa, email_nfe,
                    email, telefone, celular, limite_credito,
                    cep, logradouro, numero, complemento, bairro, cidade, uf, codigo_ibge,
                    observacoes, ativo
                ) VALUES (
                    :empresa_id, :tipo_pessoa, :cpf_cnpj, :nome_razao, :nome_fantasia,
                    :indicador_ie, :inscricao_estadual, :inscricao_municipal, :suframa, :email_nfe,
                    :email, :telefone, :celular, :limite_credito,
                    :cep, :logradouro, :numero, :complemento, :bairro, :cidade, :uf, :codigo_ibge,
                    :observacoes, :ativo
                )
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $novoId = (int)$db->lastInsertId();

            AuditLogger::log('CLIENTE_CRIAR', 'Cadastros', "Cadastrou o cliente #{$novoId} ({$nomeRazao})");
            Session::setFlash('success', 'Cliente cadastrado com sucesso!');
        }

        header('Location: ' . BASE_URL . '/modules/cadastros/clientes.php');
        exit;
    }

    // 2. ALTERNAR STATUS (ATIVO / INATIVO)
    if ($acao === 'alternar_status') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE clientes SET ativo = IF(ativo = 1, 0, 1) WHERE id = :id AND empresa_id = :emp");
        $stmt->execute([':id' => $id, ':emp' => $empresaId]);

        AuditLogger::log('CLIENTE_STATUS', 'Cadastros', "Alternou status do cliente #{$id}");
        Session::setFlash('success', 'Status do cliente atualizado.');
        header('Location: ' . BASE_URL . '/modules/cadastros/clientes.php');
        exit;
    }

    // 3. EXCLUIR CLIENTE
    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $stmtNome = $db->prepare("SELECT nome_razao FROM clientes WHERE id = :id AND empresa_id = :emp");
        $stmtNome->execute([':id' => $id, ':emp' => $empresaId]);
        $cli = $stmtNome->fetch();

        if ($cli) {
            $stmtDel = $db->prepare("DELETE FROM clientes WHERE id = :id AND empresa_id = :emp");
            $stmtDel->execute([':id' => $id, ':emp' => $empresaId]);
            AuditLogger::log('CLIENTE_EXCLUIR', 'Cadastros', "Excluiu o cliente #{$id} ({$cli['nome_razao']})");
            Session::setFlash('success', 'Cliente removido com sucesso.');
        }

        header('Location: ' . BASE_URL . '/modules/cadastros/clientes.php');
        exit;
    }
}

// =========================================================================
// INDICADORES (KPIs) DE CLIENTES
// =========================================================================
$stmtKpi = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS ativos,
        SUM(CASE WHEN tipo_pessoa = 'PJ' THEN 1 ELSE 0 END) AS total_pj,
        SUM(CASE WHEN tipo_pessoa = 'PF' THEN 1 ELSE 0 END) AS total_pf,
        SUM(CASE WHEN cpf_cnpj != '' AND logradouro IS NOT NULL AND numero IS NOT NULL AND bairro IS NOT NULL AND cidade IS NOT NULL AND uf IS NOT NULL AND LENGTH(codigo_ibge) = 7 THEN 1 ELSE 0 END) AS aptos_nfe
    FROM clientes
    WHERE empresa_id = :emp
");
$stmtKpi->execute([':emp' => $empresaId]);
$kpis = $stmtKpi->fetch();

// =========================================================================
// FILTROS, ORDENAÇÃO E PAGINAÇÃO (GET)
// =========================================================================
$busca  = trim($_GET['busca'] ?? '');
$tipo   = trim($_GET['tipo'] ?? '');
$status = trim($_GET['status'] ?? '');
$ufFiltro = strtoupper(trim($_GET['uf'] ?? ''));

$colunasPermitidas = [
    'nome_razao'     => 'nome_razao',
    'cpf_cnpj'       => 'cpf_cnpj',
    'cidade'         => 'cidade',
    'limite_credito' => 'limite_credito',
    'criado_em'      => 'criado_em',
];
$ordem = $_GET['ordem'] ?? 'nome_razao';
if (!isset($colunasPermitidas[$ordem])) {
    $ordem = 'nome_razao';
}
$dir = (strtoupper($_GET['dir'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';

$paginaAtual = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina   = 10;
$offset      = ($paginaAtual - 1) * $porPagina;

$where = ["empresa_id = :emp"];
$bind  = [':emp' => $empresaId];

if ($busca !== '') {
    $where[] = "(nome_razao LIKE :b1 OR nome_fantasia LIKE :b2 OR cpf_cnpj LIKE :b3 OR email LIKE :b4 OR cidade LIKE :b5)";
    $termo = '%' . $busca . '%';
    $bind[':b1'] = $termo;
    $bind[':b2'] = $termo;
    $bind[':b3'] = $termo;
    $bind[':b4'] = $termo;
    $bind[':b5'] = $termo;
}
if ($tipo === 'PF' || $tipo === 'PJ') {
    $where[] = "tipo_pessoa = :tipo";
    $bind[':tipo'] = $tipo;
}
if ($status === '1' || $status === '0') {
    $where[] = "ativo = :ativo";
    $bind[':ativo'] = (int)$status;
}
if ($ufFiltro !== '' && strlen($ufFiltro) === 2) {
    $where[] = "uf = :uf";
    $bind[':uf'] = $ufFiltro;
}

$whereSql = implode(' AND ', $where);

$stmtCount = $db->prepare("SELECT COUNT(*) FROM clientes WHERE {$whereSql}");
$stmtCount->execute($bind);
$totalRegistros = (int)$stmtCount->fetchColumn();
$totalPaginas   = max(1, (int)ceil($totalRegistros / $porPagina));

$sqlLista = "
    SELECT *
    FROM clientes
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
$clientes = $stmtLista->fetchAll();

// Helper para links de ordenação na tabela
function linkOrdenacao(string $col, string $label, string $ordemAtual, string $dirAtual): string
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

$ufsBrasil = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

$pageTitle    = 'Clientes';
$activeModule = 'cadastros';
$activeSub    = 'clientes';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Cabeçalho da Página -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
        <div class="ct" style="font-size:20px">Clientes</div>
        <div class="kl" style="margin-top:2px">Gerencie clientes Pessoa Física e Jurídica com dados fiscais prontos para NF-e / NFS-e</div>
    </div>
    <button type="button" class="btn-primary-brand" onclick="abrirModalCliente()">
        + Novo cliente
    </button>
</div>

<!-- 1. Cards de Indicadores (KPIs) -->
<div class="kpi-grid">
    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Total de clientes</div>
            <div class="kv"><?= (int)($kpis['total'] ?? 0) ?></div>
        </div>
        <div class="ib" style="background:var(--bsoft2);color:var(--btxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
            </svg>
        </div>
    </div>

    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Clientes ativos</div>
            <div class="kv"><?= (int)($kpis['ativos'] ?? 0) ?></div>
        </div>
        <div class="ib" style="background:var(--osoft);color:var(--otxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6L9 17l-5-5"></path>
            </svg>
        </div>
    </div>

    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Pessoa Jurídica (PJ) / Física (PF)</div>
            <div class="kv"><?= (int)($kpis['total_pj'] ?? 0) ?> <span style="font-size:14px;color:var(--muted);font-weight:600">/ <?= (int)($kpis['total_pf'] ?? 0) ?></span></div>
        </div>
        <div class="ib" style="background:var(--csoft);color:var(--ctxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"></path>
            </svg>
        </div>
    </div>

    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Cadastros aptos p/ NF-e</div>
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
    <form method="get" action="<?= BASE_URL ?>/modules/cadastros/clientes.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end">
        <div style="grid-column:span 2;min-width:220px">
            <label class="form-label-os" for="f_busca">Buscar cliente</label>
            <input type="text" id="f_busca" name="busca" class="form-control-os" placeholder="Nome, Razão Social, CPF/CNPJ, E-mail ou Cidade…" value="<?= Security::e($busca) ?>">
        </div>

        <div>
            <label class="form-label-os" for="f_tipo">Tipo de pessoa</label>
            <select id="f_tipo" name="tipo" class="form-select-os">
                <option value="">Todos (PF e PJ)</option>
                <option value="PJ" <?= $tipo === 'PJ' ? 'selected' : '' ?>>Pessoa Jurídica (PJ)</option>
                <option value="PF" <?= $tipo === 'PF' ? 'selected' : '' ?>>Pessoa Física (PF)</option>
            </select>
        </div>

        <div>
            <label class="form-label-os" for="f_uf">UF</label>
            <select id="f_uf" name="uf" class="form-select-os">
                <option value="">Todas</option>
                <?php foreach ($ufsBrasil as $sigla): ?>
                    <option value="<?= $sigla ?>" <?= $ufFiltro === $sigla ? 'selected' : '' ?>><?= $sigla ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label-os" for="f_status">Status</label>
            <select id="f_status" name="status" class="form-select-os">
                <option value="">Todos</option>
                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Ativos</option>
                <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inativos</option>
            </select>
        </div>

        <div style="display:flex;gap:8px">
            <button type="submit" class="btn-primary-brand" style="flex:1">Filtrar</button>
            <a href="<?= BASE_URL ?>/modules/cadastros/clientes.php" class="btn-theme" style="text-decoration:none;justify-content:center">Limpar</a>
        </div>
    </form>
</div>

<!-- 3. Tabela de Clientes -->
<div class="card" style="padding:0;overflow:hidden">
    <div style="padding:18px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <span class="ct">Lista de Clientes (<?= $totalRegistros ?>)</span>
        <span class="kl">Clique nas colunas para ordenar</span>
    </div>

    <div class="table-responsive-os">
        <table class="table-os">
            <thead>
                <tr>
                    <th><?= linkOrdenacao('nome_razao', 'Cliente / Razão Social', $ordem, $dir) ?></th>
                    <th><?= linkOrdenacao('cpf_cnpj', 'CPF / CNPJ & IE', $ordem, $dir) ?></th>
                    <th>Contato</th>
                    <th><?= linkOrdenacao('cidade', 'Cidade / UF (IBGE)', $ordem, $dir) ?></th>
                    <th>Status NF-e</th>
                    <th><?= linkOrdenacao('limite_credito', 'Limite Crédito', $ordem, $dir) ?></th>
                    <th>Situação</th>
                    <th style="text-align:right">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clientes)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:36px 20px;color:var(--muted)">
                            Nenhum cliente encontrado para os filtros selecionados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($clientes as $c):
                        $aptoNfe = !empty($c['cpf_cnpj'])
                            && !empty($c['logradouro'])
                            && !empty($c['numero'])
                            && !empty($c['bairro'])
                            && !empty($c['cidade'])
                            && !empty($c['uf'])
                            && !empty($c['codigo_ibge'])
                            && strlen((string)$c['codigo_ibge']) === 7;

                        $jsonCliente = htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px">
                                    <span class="bd" style="background:<?= $c['tipo_pessoa'] === 'PJ' ? 'var(--bsoft)' : 'var(--gsoft)' ?>;color:<?= $c['tipo_pessoa'] === 'PJ' ? 'var(--btxt)' : 'var(--gtxt)' ?>">
                                        <?= Security::e($c['tipo_pessoa']) ?>
                                    </span>
                                    <div>
                                        <b style="font-size:13.5px"><?= Security::e($c['nome_razao']) ?></b>
                                        <?php if (!empty($c['nome_fantasia'])): ?>
                                            <div class="kl"><?= Security::e($c['nome_fantasia']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <b><?= Security::e($c['cpf_cnpj']) ?></b>
                                <div class="kl">
                                    <?php if ((int)$c['indicador_ie'] === 1): ?>
                                        IE: <?= Security::e($c['inscricao_estadual'] ?: '—') ?>
                                    <?php elseif ((int)$c['indicador_ie'] === 2): ?>
                                        IE: Isento
                                    <?php else: ?>
                                        Não Contribuinte
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <div><?= Security::e($c['celular'] ?: ($c['telefone'] ?: '—')) ?></div>
                                <div class="kl"><?= Security::e($c['email'] ?: ($c['email_nfe'] ?: 'Sem e-mail')) ?></div>
                            </td>

                            <td>
                                <?php if (!empty($c['cidade'])): ?>
                                    <b><?= Security::e($c['cidade']) ?> / <?= Security::e($c['uf']) ?></b>
                                    <div class="kl">IBGE: <?= Security::e($c['codigo_ibge'] ?: 'Pendente') ?></div>
                                <?php else: ?>
                                    <span class="kl">Não informado</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($aptoNfe): ?>
                                    <span class="bd" style="background:var(--osoft);color:var(--otxt)" title="Endereço, CPF/CNPJ e Código IBGE completos para emissão SEFAZ">
                                        Apto NF-e
                                    </span>
                                <?php else: ?>
                                    <span class="bd" style="background:var(--asoft);color:var(--atxt)" title="Preencha CEP/Endereço e Código IBGE para emitir NF-e">
                                        Incompleto
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <b><?= Security::formatMoney($c['limite_credito']) ?></b>
                            </td>

                            <td>
                                <?php if ((int)$c['ativo'] === 1): ?>
                                    <span class="bd" style="background:var(--osoft);color:var(--otxt)">Ativo</span>
                                <?php else: ?>
                                    <span class="bd" style="background:var(--rsoft);color:var(--rtxt)">Inativo</span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align:right;white-space:nowrap">
                                <button type="button" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px" onclick='editarCliente(<?= $jsonCliente ?>)'>
                                    Editar
                                </button>

                                <form method="post" action="<?= BASE_URL ?>/modules/cadastros/clientes.php" style="display:inline" onsubmit="return confirm('Deseja alterar o status deste cliente?');">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="acao" value="alternar_status">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px">
                                        <?= (int)$c['ativo'] === 1 ? 'Inativar' : 'Ativar' ?>
                                    </button>
                                </form>

                                <form method="post" action="<?= BASE_URL ?>/modules/cadastros/clientes.php" style="display:inline" onsubmit="return confirm('Tem certeza que deseja excluir este cliente permanentemente?');">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
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
<!-- MODAL DE CADASTRO / EDIÇÃO DE CLIENTE (Padrão Visual LivreOS)          -->
<!-- ===================================================================== -->
<div id="modalCliente" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;overflow-y:auto;padding:24px 14px">
    <div class="card" style="max-width:860px;margin:20px auto;padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:18px">
            <div>
                <span class="ct" id="modalTitulo" style="font-size:18px">Novo Cliente</span>
                <div class="kl">Preencha os dados cadastrais, endereço e informações fiscais para emissão de NF-e</div>
            </div>
            <button type="button" class="btn-theme" style="min-height:36px;padding:0 12px" onclick="fecharModalCliente()">✕ Fechar</button>
        </div>

        <form method="post" action="<?= BASE_URL ?>/modules/cadastros/clientes.php" id="formCliente">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" id="cli_id" value="0">

            <!-- Seção 1: Identificação -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px">1. Identificação Principal</div>
            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-3">
                    <label class="form-label-os" for="cli_tipo_pessoa">Tipo de Pessoa *</label>
                    <select name="tipo_pessoa" id="cli_tipo_pessoa" class="form-select-os" onchange="ajustarLabelsTipoPessoa()">
                        <option value="PJ">Pessoa Jurídica (PJ)</option>
                        <option value="PF">Pessoa Física (PF)</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="cli_cpf_cnpj" id="lbl_cpf_cnpj">CNPJ *</label>
                    <input type="text" name="cpf_cnpj" id="cli_cpf_cnpj" class="form-control-os" data-mask="cpfcnpj" placeholder="00.000.000/0000-00" required>
                </div>

                <div class="col-md-5">
                    <label class="form-label-os" for="cli_nome_razao" id="lbl_nome_razao">Razão Social *</label>
                    <input type="text" name="nome_razao" id="cli_nome_razao" class="form-control-os" placeholder="Razão social ou nome completo" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label-os" for="cli_nome_fantasia" id="lbl_nome_fantasia">Nome Fantasia</label>
                    <input type="text" name="nome_fantasia" id="cli_nome_fantasia" class="form-control-os" placeholder="Nome comercial da empresa">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="cli_limite_credito">Limite de Crédito (R$)</label>
                    <input type="text" name="limite_credito" id="cli_limite_credito" class="form-control-os" data-mask="money" value="0,00">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="cli_ativo">Situação do Cadastro</label>
                    <select name="ativo" id="cli_ativo" class="form-select-os">
                        <option value="1">Ativo</option>
                        <option value="0">Inativo</option>
                    </select>
                </div>
            </div>

            <!-- Seção 2: Dados Fiscais (NF-e / NFS-e) -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px;border-top:1px solid var(--border);padding-top:14px">
                2. Dados Fiscais (NF-e / NFC-e / NFS-e)
            </div>
            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-4">
                    <label class="form-label-os" for="cli_indicador_ie">Indicador da Inscrição Estadual *</label>
                    <select name="indicador_ie" id="cli_indicador_ie" class="form-select-os" onchange="ajustarCampoIE()">
                        <option value="9">9 - Não Contribuinte</option>
                        <option value="1">1 - Contribuinte ICMS</option>
                        <option value="2">2 - Contribuinte Isento de IE</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="cli_inscricao_estadual">Inscrição Estadual (IE)</label>
                    <input type="text" name="inscricao_estadual" id="cli_inscricao_estadual" class="form-control-os" placeholder="Número da IE ou ISENTO">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="cli_inscricao_municipal">Inscrição Municipal (NFS-e)</label>
                    <input type="text" name="inscricao_municipal" id="cli_inscricao_municipal" class="form-control-os" placeholder="Opcional (para serviços)">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="cli_suframa">Inscrição SUFRAMA</label>
                    <input type="text" name="suframa" id="cli_suframa" class="form-control-os" placeholder="Opcional">
                </div>

                <div class="col-md-8">
                    <label class="form-label-os" for="cli_email_nfe">E-mail para envio automático de XML / DANFE (NF-e)</label>
                    <input type="email" name="email_nfe" id="cli_email_nfe" class="form-control-os" placeholder="fiscal@cliente.com.br">
                </div>
            </div>

            <!-- Seção 3: Endereço e Código IBGE -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px;border-top:1px solid var(--border);padding-top:14px">
                3. Endereço & Código IBGE (Preenchimento Automático via CEP)
            </div>
            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-3">
                    <label class="form-label-os" for="cli_cep">CEP <span id="statusCep" style="color:#0E9F6E"></span></label>
                    <input type="text" name="cep" id="cli_cep" class="form-control-os" data-mask="cep" placeholder="00000-000" onblur="buscarCepCliente(this.value)">
                </div>

                <div class="col-md-6">
                    <label class="form-label-os" for="cli_logradouro">Logradouro (Rua / Av.)</label>
                    <input type="text" name="logradouro" id="cli_logradouro" class="form-control-os" placeholder="Avenida, Rua, Rodovia…">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="cli_numero">Número</label>
                    <input type="text" name="numero" id="cli_numero" class="form-control-os" placeholder="Ex: 120 ou S/N">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="cli_complemento">Complemento</label>
                    <input type="text" name="complemento" id="cli_complemento" class="form-control-os" placeholder="Sala, Galpão, Bloco…">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="cli_bairro">Bairro / Setor</label>
                    <input type="text" name="bairro" id="cli_bairro" class="form-control-os" placeholder="Bairro">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="cli_cidade">Município</label>
                    <input type="text" name="cidade" id="cli_cidade" class="form-control-os" placeholder="Nome da cidade">
                </div>

                <div class="col-md-3">
                    <label class="form-label-os" for="cli_uf">UF</label>
                    <select name="uf" id="cli_uf" class="form-select-os">
                        <option value="">Selecione</option>
                        <?php foreach ($ufsBrasil as $sigla): ?>
                            <option value="<?= $sigla ?>"><?= $sigla ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="cli_codigo_ibge">Código IBGE do Município (7 dígitos)</label>
                    <input type="text" name="codigo_ibge" id="cli_codigo_ibge" class="form-control-os" maxlength="7" placeholder="Ex: 5206206 (via CEP)">
                </div>
            </div>

            <!-- Seção 4: Contato e Observações -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px;border-top:1px solid var(--border);padding-top:14px">
                4. Contato Comercial e Observações
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label-os" for="cli_email">E-mail Comercial</label>
                    <input type="email" name="email" id="cli_email" class="form-control-os" placeholder="contato@empresa.com.br">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="cli_telefone">Telefone Fixo</label>
                    <input type="text" name="telefone" id="cli_telefone" class="form-control-os" data-mask="phone" placeholder="(00) 0000-0000">
                </div>

                <div class="col-md-4">
                    <label class="form-label-os" for="cli_celular">Celular / WhatsApp</label>
                    <input type="text" name="celular" id="cli_celular" class="form-control-os" data-mask="phone" placeholder="(00) 90000-0000">
                </div>

                <div class="col-12">
                    <label class="form-label-os" for="cli_observacoes">Observações Internas</label>
                    <textarea name="observacoes" id="cli_observacoes" rows="2" class="form-control-os" style="min-height:68px" placeholder="Informações adicionais sobre faturamento, entrega ou histórico…"></textarea>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:16px;border-top:1px solid var(--border)">
                <button type="button" class="btn-theme" onclick="fecharModalCliente()">Cancelar</button>
                <button type="submit" class="btn-primary-brand">Salvar Cliente</button>
            </div>
        </form>
    </div>
</div>

<script>
function ajustarLabelsTipoPessoa() {
    var tipo = document.getElementById('cli_tipo_pessoa').value;
    document.getElementById('lbl_cpf_cnpj').textContent = (tipo === 'PF') ? 'CPF *' : 'CNPJ *';
    document.getElementById('cli_cpf_cnpj').placeholder = (tipo === 'PF') ? '000.000.000-00' : '00.000.000/0000-00';
    document.getElementById('lbl_nome_razao').textContent = (tipo === 'PF') ? 'Nome Completo *' : 'Razão Social *';
    document.getElementById('lbl_nome_fantasia').textContent = (tipo === 'PF') ? 'Apelido (Opcional)' : 'Nome Fantasia';
}

function ajustarCampoIE() {
    var ind = document.getElementById('cli_indicador_ie').value;
    var inputIe = document.getElementById('cli_inscricao_estadual');
    if (ind === '2') {
        inputIe.value = 'ISENTO';
    } else if (ind === '9' && inputIe.value === 'ISENTO') {
        inputIe.value = '';
    }
}

function abrirModalCliente() {
    document.getElementById('formCliente').reset();
    document.getElementById('cli_id').value = '0';
    document.getElementById('cli_limite_credito').value = '0,00';
    document.getElementById('modalTitulo').textContent = 'Novo Cliente';
    ajustarLabelsTipoPessoa();
    document.getElementById('modalCliente').style.display = 'block';
}

function editarCliente(c) {
    document.getElementById('cli_id').value = c.id || 0;
    document.getElementById('cli_tipo_pessoa').value = c.tipo_pessoa || 'PJ';
    ajustarLabelsTipoPessoa();

    document.getElementById('cli_cpf_cnpj').value = c.cpf_cnpj || '';
    document.getElementById('cli_nome_razao').value = c.nome_razao || '';
    document.getElementById('cli_nome_fantasia').value = c.nome_fantasia || '';
    document.getElementById('cli_indicador_ie').value = c.indicador_ie || '9';
    document.getElementById('cli_inscricao_estadual').value = c.inscricao_estadual || '';
    document.getElementById('cli_inscricao_municipal').value = c.inscricao_municipal || '';
    document.getElementById('cli_suframa').value = c.suframa || '';
    document.getElementById('cli_email_nfe').value = c.email_nfe || '';
    document.getElementById('cli_email').value = c.email || '';
    document.getElementById('cli_telefone').value = c.telefone || '';
    document.getElementById('cli_celular').value = c.celular || '';

    var lim = parseFloat(c.limite_credito || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    document.getElementById('cli_limite_credito').value = lim;

    document.getElementById('cli_cep').value = c.cep || '';
    document.getElementById('cli_logradouro').value = c.logradouro || '';
    document.getElementById('cli_numero').value = c.numero || '';
    document.getElementById('cli_complemento').value = c.complemento || '';
    document.getElementById('cli_bairro').value = c.bairro || '';
    document.getElementById('cli_cidade').value = c.cidade || '';
    document.getElementById('cli_uf').value = c.uf || '';
    document.getElementById('cli_codigo_ibge').value = c.codigo_ibge || '';
    document.getElementById('cli_observacoes').value = c.observacoes || '';
    document.getElementById('cli_ativo').value = (c.ativo !== undefined) ? String(c.ativo) : '1';

    document.getElementById('modalTitulo').textContent = 'Editar Cliente #' + c.id;
    document.getElementById('modalCliente').style.display = 'block';
}

function fecharModalCliente() {
    document.getElementById('modalCliente').style.display = 'none';
}

function buscarCepCliente(cepValor) {
    var cep = (cepValor || '').replace(/\D/g, '');
    if (cep.length !== 8) return;

    var statusEl = document.getElementById('statusCep');
    statusEl.textContent = '(buscando…)';

    fetch('https://viacep.com.br/ws/' + cep + '/json/')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            statusEl.textContent = '';
            if (!d.erro) {
                if (d.logradouro) document.getElementById('cli_logradouro').value = d.logradouro;
                if (d.bairro) document.getElementById('cli_bairro').value = d.bairro;
                if (d.localidade) document.getElementById('cli_cidade').value = d.localidade;
                if (d.uf) document.getElementById('cli_uf').value = d.uf;
                if (d.ibge) document.getElementById('cli_codigo_ibge').value = d.ibge;
                document.getElementById('cli_numero').focus();
            } else {
                statusEl.textContent = '(CEP não encontrado)';
            }
        })
        .catch(function () {
            statusEl.textContent = '';
        });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>