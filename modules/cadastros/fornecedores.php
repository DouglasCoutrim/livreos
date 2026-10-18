<?php
// Caminho: /modules/cadastros/fornecedores.php
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
 * Validador de CPF ou CNPJ
 */
function validarCpfCnpjForn(string $doc): bool
{
    $num = preg_replace('/\D/', '', $doc);
    if (strlen($num) === 11) {
        if (preg_match('/^(\d)\1{10}$/', $num)) return false;
        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) $d += (int)$num[$c] * (($t + 1) - $c);
            $d = ((10 * $d) % 11) % 10;
            if ((int)$num[$c] !== $d) return false;
        }
        return true;
    }
    if (strlen($num) === 14) {
        if (preg_match('/^(\d)\1{13}$/', $num)) return false;
        $p1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $p2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $s = 0; for ($i = 0; $i < 12; $i++) $s += (int)$num[$i] * $p1[$i];
        $r = $s % 11; $d1 = ($r < 2) ? 0 : 11 - $r;
        if ((int)$num[12] !== $d1) return false;
        $s = 0; for ($i = 0; $i < 13; $i++) $s += (int)$num[$i] * $p2[$i];
        $r = $s % 11; $d2 = ($r < 2) ? 0 : 11 - $r;
        return ((int)$num[13] === $d2);
    }
    return false;
}

// =========================================================================
// PROCESSAMENTO DE FORMULÁRIOS (POST)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        Session::setFlash('error', 'Token de segurança inválido. Recarregue a página.');
        header('Location: ' . BASE_URL . '/modules/cadastros/fornecedores.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';

    // 1. SALVAR FORNECEDOR
    if ($acao === 'salvar') {
        $id                 = (int)($_POST['id'] ?? 0);
        $tipoPessoa         = ($_POST['tipo_pessoa'] ?? 'PJ') === 'PF' ? 'PF' : 'PJ';
        $cpfCnpj            = trim($_POST['cpf_cnpj'] ?? '');
        $nomeRazao          = trim($_POST['nome_razao'] ?? '');
        $nomeFantasia       = trim($_POST['nome_fantasia'] ?? '') ?: null;
        $indicadorIe        = (int)($_POST['indicador_ie'] ?? 1);
        $inscricaoEstadual  = strtoupper(trim($_POST['inscricao_estadual'] ?? '')) ?: null;
        $email              = strtolower(trim($_POST['email'] ?? '')) ?: null;
        $telefone           = trim($_POST['telefone'] ?? '') ?: null;
        $celular            = trim($_POST['celular'] ?? '') ?: null;
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

        $erros = [];
        if ($nomeRazao === '' || mb_strlen($nomeRazao) < 3) {
            $erros[] = 'Informe a Razão Social ou Nome do fornecedor.';
        }
        if ($cpfCnpj === '' || !validarCpfCnpjForn($cpfCnpj)) {
            $erros[] = 'O CPF ou CNPJ informado é inválido.';
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'O e-mail informado é inválido.';
        }

        if (empty($erros)) {
            $stmtDup = $db->prepare("SELECT id FROM fornecedores WHERE empresa_id = :emp AND cpf_cnpj = :doc AND id != :id LIMIT 1");
            $stmtDup->execute([':emp' => $empresaId, ':doc' => $cpfCnpj, ':id' => $id]);
            if ($stmtDup->fetch()) {
                $erros[] = 'Já existe um fornecedor cadastrado com este CPF/CNPJ.';
            }
        }

        if (!empty($erros)) {
            Session::setFlash('error', implode(' ', $erros));
            header('Location: ' . BASE_URL . '/modules/cadastros/fornecedores.php');
            exit;
        }

        $params = [
            ':empresa_id'         => $empresaId,
            ':tipo_pessoa'        => $tipoPessoa,
            ':cpf_cnpj'           => $cpfCnpj,
            ':nome_razao'         => $nomeRazao,
            ':nome_fantasia'      => $nomeFantasia,
            ':indicador_ie'       => $indicadorIe,
            ':inscricao_estadual' => $inscricaoEstadual,
            ':email'              => $email,
            ':telefone'           => $telefone,
            ':celular'            => $celular,
            ':cep'                => $cep,
            ':logradouro'         => $logradouro,
            ':numero'             => $numero,
            ':complemento'        => $complemento,
            ':bairro'             => $bairro,
            ':cidade'             => $cidade,
            ':uf'                => $uf,
            ':codigo_ibge'        => $codigoIbge,
            ':observacoes'        => $observacoes,
            ':ativo'              => $ativo,
        ];

        if ($id > 0) {
            $params[':id'] = $id;
            $sql = "UPDATE fornecedores SET tipo_pessoa=:tipo_pessoa, cpf_cnpj=:cpf_cnpj, nome_razao=:nome_razao, nome_fantasia=:nome_fantasia, indicador_ie=:indicador_ie, inscricao_estadual=:inscricao_estadual, email=:email, telefone=:telefone, celular=:celular, cep=:cep, logradouro=:logradouro, numero=:numero, complemento=:complemento, bairro=:bairro, cidade=:cidade, uf=:uf, codigo_ibge=:codigo_ibge, observacoes=:observacoes, ativo=:ativo WHERE id=:id AND empresa_id=:empresa_id";
            $db->prepare($sql)->execute($params);
            AuditLogger::log('FORNECEDOR_EDITAR', 'Cadastros', "Editou fornecedor #{$id} ({$nomeRazao})");
            Session::setFlash('success', 'Fornecedor atualizado com sucesso!');
        } else {
            $sql = "INSERT INTO fornecedores (empresa_id, tipo_pessoa, cpf_cnpj, nome_razao, nome_fantasia, indicador_ie, inscricao_estadual, email, telefone, celular, cep, logradouro, numero, complemento, bairro, cidade, uf, codigo_ibge, observacoes, ativo) VALUES (:empresa_id, :tipo_pessoa, :cpf_cnpj, :nome_razao, :nome_fantasia, :indicador_ie, :inscricao_estadual, :email, :telefone, :celular, :cep, :logradouro, :numero, :complemento, :bairro, :cidade, :uf, :codigo_ibge, :observacoes, :ativo)";
            $db->prepare($sql)->execute($params);
            $novoId = (int)$db->lastInsertId();
            AuditLogger::log('FORNECEDOR_CRIAR', 'Cadastros', "Cadastrou fornecedor #{$novoId} ({$nomeRazao})");
            Session::setFlash('success', 'Fornecedor cadastrado com sucesso!');
        }

        header('Location: ' . BASE_URL . '/modules/cadastros/fornecedores.php');
        exit;
    }

    // 2. ALTERNAR STATUS
    if ($acao === 'alternar_status') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE fornecedores SET ativo = IF(ativo = 1, 0, 1) WHERE id = :id AND empresa_id = :emp")->execute([':id' => $id, ':emp' => $empresaId]);
        Session::setFlash('success', 'Status do fornecedor atualizado.');
        header('Location: ' . BASE_URL . '/modules/cadastros/fornecedores.php');
        exit;
    }

    // 3. EXCLUIR
    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM fornecedores WHERE id = :id AND empresa_id = :emp")->execute([':id' => $id, ':emp' => $empresaId]);
        Session::setFlash('success', 'Fornecedor excluído com sucesso.');
        header('Location: ' . BASE_URL . '/modules/cadastros/fornecedores.php');
        exit;
    }
}

// =========================================================================
// CONSULTAS E LISTAGEM
// =========================================================================
$stmtKpi = $db->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN ativo=1 THEN 1 ELSE 0 END) AS ativos FROM fornecedores WHERE empresa_id = :emp");
$stmtKpi->execute([':emp' => $empresaId]);
$kpis = $stmtKpi->fetch();

$busca  = trim($_GET['busca'] ?? '');
$status = trim($_GET['status'] ?? '');

$where = ["empresa_id = :emp"];
$bind  = [':emp' => $empresaId];

if ($busca !== '') {
    $where[] = "(nome_razao LIKE :b1 OR cpf_cnpj LIKE :b2 OR cidade LIKE :b3)";
    $bind[':b1'] = "%{$busca}%";
    $bind[':b2'] = "%{$busca}%";
    $bind[':b3'] = "%{$busca}%";
}
if ($status === '1' || $status === '0') {
    $where[] = "ativo = :ativo";
    $bind[':ativo'] = (int)$status;
}

$whereSql = implode(' AND ', $where);
$stmtLista = $db->prepare("SELECT * FROM fornecedores WHERE {$whereSql} ORDER BY nome_razao ASC");
$stmtLista->execute($bind);
$fornecedores = $stmtLista->fetchAll();

$ufsBrasil = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

$pageTitle    = 'Fornecedores';
$activeModule = 'cadastros';
$activeSub    = 'fornecedores';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
        <div class="ct" style="font-size:20px">Fornecedores</div>
        <div class="kl" style="margin-top:2px">Gerenciamento de fornecedores e parceiros comerciais para integração com notas de compra (XML)</div>
    </div>
    <button type="button" class="btn-primary-brand" onclick="abrirModalForn()">+ Novo fornecedor</button>
</div>

<!-- KPIs -->
<div class="kpi-grid">
    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Total de fornecedores</div>
            <div class="kv"><?= (int)($kpis['total'] ?? 0) ?></div>
        </div>
        <div class="ib" style="background:var(--bsoft2);color:var(--btxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 8h14l-1 13H6zM9 8a3 3 0 016 0"></path></svg>
        </div>
    </div>
    <div class="card" style="display:flex;justify-content:space-between;gap:10px">
        <div>
            <div class="kl">Fornecedores ativos</div>
            <div class="kv"><?= (int)($kpis['ativos'] ?? 0) ?></div>
        </div>
        <div class="ib" style="background:var(--osoft);color:var(--otxt)">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"></path></svg>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="card">
    <form method="get" action="" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end">
        <div style="grid-column:span 2">
            <label class="form-label-os">Buscar</label>
            <input type="text" name="busca" class="form-control-os" placeholder="Razão social, CNPJ ou cidade…" value="<?= Security::e($busca) ?>">
        </div>
        <div>
            <label class="form-label-os">Status</label>
            <select name="status" class="form-select-os">
                <option value="">Todos</option>
                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Ativos</option>
                <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inativos</option>
            </select>
        </div>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn-primary-brand" style="flex:1">Filtrar</button>
            <a href="?" class="btn-theme" style="text-decoration:none;justify-content:center">Limpar</a>
        </div>
    </form>
</div>

<!-- Tabela -->
<div class="card" style="padding:0;overflow:hidden">
    <div class="table-responsive-os">
        <table class="table-os">
            <thead>
                <tr>
                    <th>Razão Social / Nome</th>
                    <th>CNPJ / CPF</th>
                    <th>Contato</th>
                    <th>Cidade / UF</th>
                    <th>Situação</th>
                    <th style="text-align:right">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($fornecedores)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">Nenhum fornecedor cadastrado.</td></tr>
                <?php else: foreach ($fornecedores as $f): 
                    $jsonForn = htmlspecialchars(json_encode($f, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                    <tr>
                        <td>
                            <b><?= Security::e($f['nome_razao']) ?></b>
                            <?php if($f['nome_fantasia']): ?><div class="kl"><?= Security::e($f['nome_fantasia']) ?></div><?php endif; ?>
                        </td>
                        <td><b><?= Security::e($f['cpf_cnpj']) ?></b></td>
                        <td><?= Security::e($f['telefone'] ?: ($f['celular'] ?: '—')) ?><div class="kl"><?= Security::e($f['email'] ?: 'Sem e-mail') ?></div></td>
                        <td><b><?= Security::e($f['cidade'] ?: '—') ?> / <?= Security::e($f['uf'] ?: '—') ?></b></td>
                        <td>
                            <?php if((int)$f['ativo'] === 1): ?>
                                <span class="bd" style="background:var(--osoft);color:var(--otxt)">Ativo</span>
                            <?php else: ?>
                                <span class="bd" style="background:var(--rsoft);color:var(--rtxt)">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <button type="button" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px" onclick='editarForn(<?= $jsonForn ?>)'>Editar</button>
                            <form method="post" style="display:inline" onsubmit="return confirm('Alterar status?');">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="acao" value="alternar_status">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button type="submit" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px">Status</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('Excluir fornecedor permanentemente?');">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button type="submit" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px;color:var(--rtxt)">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div id="modalForn" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;overflow-y:auto;padding:24px 14px">
    <div class="card" style="max-width:800px;margin:20px auto;padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:18px">
            <span class="ct" id="modalTituloForn" style="font-size:18px">Novo Fornecedor</span>
            <button type="button" class="btn-theme" style="min-height:36px;padding:0 12px" onclick="fecharModalForn()">✕</button>
        </div>
        <form method="post" id="formForn">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" id="forn_id" value="0">
            
            <div class="row g-3" style="margin-bottom:14px">
                <div class="col-md-3">
                    <label class="form-label-os">Tipo</label>
                    <select name="tipo_pessoa" id="forn_tipo" class="form-select-os">
                        <option value="PJ">PJ</option>
                        <option value="PF">PF</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">CNPJ / CPF *</label>
                    <input type="text" name="cpf_cnpj" id="forn_doc" class="form-control-os" data-mask="cpfcnpj" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label-os">Razão Social / Nome *</label>
                    <input type="text" name="nome_razao" id="forn_razao" class="form-control-os" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label-os">Nome Fantasia</label>
                    <input type="text" name="nome_fantasia" id="forn_fantasia" class="form-control-os">
                </div>
                <div class="col-md-3">
                    <label class="form-label-os">Inscrição Estadual</label>
                    <input type="text" name="inscricao_estadual" id="forn_ie" class="form-control-os">
                </div>
                <div class="col-md-3">
                    <label class="form-label-os">CEP</label>
                    <input type="text" name="cep" id="forn_cep" class="form-control-os" data-mask="cep" onblur="buscarCepForn(this.value)">
                </div>
                <div class="col-md-6">
                    <label class="form-label-os">Logradouro</label>
                    <input type="text" name="logradouro" id="forn_logradouro" class="form-control-os">
                </div>
                <div class="col-md-2">
                    <label class="form-label-os">Número</label>
                    <input type="text" name="numero" id="forn_numero" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Bairro</label>
                    <input type="text" name="bairro" id="forn_bairro" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Cidade</label>
                    <input type="text" name="cidade" id="forn_cidade" class="form-control-os">
                </div>
                <div class="col-md-2">
                    <label class="form-label-os">UF</label>
                    <select name="uf" id="forn_uf" class="form-select-os">
                        <option value="">--</option>
                        <?php foreach($ufsBrasil as $u): ?><option value="<?= $u ?>"><?= $u ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label-os">IBGE</label>
                    <input type="text" name="codigo_ibge" id="forn_ibge" class="form-control-os" maxlength="7">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">E-mail</label>
                    <input type="email" name="email" id="forn_email" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Telefone</label>
                    <input type="text" name="telefone" id="forn_telefone" class="form-control-os" data-mask="phone">
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;border-top:1px solid var(--border);padding-top:14px">
                <button type="button" class="btn-theme" onclick="fecharModalForn()">Cancelar</button>
                <button type="submit" class="btn-primary-brand">Salvar Fornecedor</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalForn() {
    document.getElementById('formForn').reset();
    document.getElementById('forn_id').value = '0';
    document.getElementById('modalTituloForn').textContent = 'Novo Fornecedor';
    document.getElementById('modalForn').style.display = 'block';
}
function editarForn(f) {
    document.getElementById('forn_id').value = f.id;
    document.getElementById('forn_tipo').value = f.tipo_pessoa || 'PJ';
    document.getElementById('forn_doc').value = f.cpf_cnpj || '';
    document.getElementById('forn_razao').value = f.nome_razao || '';
    document.getElementById('forn_fantasia').value = f.nome_fantasia || '';
    document.getElementById('forn_ie').value = f.inscricao_estadual || '';
    document.getElementById('forn_cep').value = f.cep || '';
    document.getElementById('forn_logradouro').value = f.logradouro || '';
    document.getElementById('forn_numero').value = f.numero || '';
    document.getElementById('forn_bairro').value = f.bairro || '';
    document.getElementById('forn_cidade').value = f.cidade || '';
    document.getElementById('forn_uf').value = f.uf || '';
    document.getElementById('forn_ibge').value = f.codigo_ibge || '';
    document.getElementById('forn_email').value = f.email || '';
    document.getElementById('forn_telefone').value = f.telefone || '';
    document.getElementById('modalTituloForn').textContent = 'Editar Fornecedor #' + f.id;
    document.getElementById('modalForn').style.display = 'block';
}
function fecharModalForn() {
    document.getElementById('modalForn').style.display = 'none';
}
function buscarCepForn(v) {
    var cep = (v||'').replace(/\D/g,'');
    if(cep.length!==8)return;
    fetch('https://viacep.com.br/ws/'+cep+'/json/').then(r=>r.json()).then(d=>{
        if(!d.erro){
            if(d.logradouro)document.getElementById('forn_logradouro').value=d.logradouro;
            if(d.bairro)document.getElementById('forn_bairro').value=d.bairro;
            if(d.localidade)document.getElementById('forn_cidade').value=d.localidade;
            if(d.uf)document.getElementById('forn_uf').value=d.uf;
            if(d.ibge)document.getElementById('forn_ibge').value=d.ibge;
        }
    }).catch(()=>{});
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>