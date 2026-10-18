<?php
// Caminho: /modules/cadastros/transportadoras.php
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

// Processamento de Formulários (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        Session::setFlash('error', 'Token de segurança inválido. Tente novamente.');
        header('Location: ' . BASE_URL . '/modules/cadastros/transportadoras.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $id                 = (int)($_POST['id'] ?? 0);
        $tipoPessoa         = ($_POST['tipo_pessoa'] ?? 'PJ') === 'PF' ? 'PF' : 'PJ';
        $cpfCnpj            = trim($_POST['cpf_cnpj'] ?? '');
        $nomeRazao          = trim($_POST['nome_razao'] ?? '');
        $nomeFantasia       = trim($_POST['nome_fantasia'] ?? '') ?: null;
        $inscricaoEstadual  = strtoupper(trim($_POST['inscricao_estadual'] ?? '')) ?: null;
        $email              = strtolower(trim($_POST['email'] ?? '')) ?: null;
        $telefone           = trim($_POST['telefone'] ?? '') ?: null;
        $celular            = trim($_POST['celular'] ?? '') ?: null;
        $cep                = trim($_POST['cep'] ?? '') ?: null;
        $logradouro         = trim($_POST['logradouro'] ?? '') ?: null;
        $numero             = trim($_POST['numero'] ?? '') ?: null;
        $bairro             = trim($_POST['bairro'] ?? '') ?: null;
        $cidade             = trim($_POST['cidade'] ?? '') ?: null;
        $uf                 = strtoupper(trim($_POST['uf'] ?? '')) ?: null;
        $ativo              = isset($_POST['ativo']) && (int)$_POST['ativo'] === 0 ? 0 : 1;

        $erros = [];
        if ($nomeRazao === '') $erros[] = 'Informe a Razão Social ou Nome da transportadora.';
        if ($cpfCnpj === '') $erros[] = 'Informe o CPF ou CNPJ.';

        if (!empty($erros)) {
            Session::setFlash('error', implode(' ', $erros));
            header('Location: ' . BASE_URL . '/modules/cadastros/transportadoras.php');
            exit;
        }

        $params = [
            ':empresa_id'         => $empresaId,
            ':tipo_pessoa'        => $tipoPessoa,
            ':cpf_cnpj'           => $cpfCnpj,
            ':nome_razao'         => $nomeRazao,
            ':nome_fantasia'      => $nomeFantasia,
            ':inscricao_estadual' => $inscricaoEstadual,
            ':email'              => $email,
            ':telefone'           => $telefone,
            ':celular'            => $celular,
            ':cep'                => $cep,
            ':logradouro'         => $logradouro,
            ':numero'             => $numero,
            ':bairro'             => $bairro,
            ':cidade'             => $cidade,
            ':uf'                 => $uf,
            ':ativo'              => $ativo,
        ];

        if ($id > 0) {
            $params[':id'] = $id;
            $sql = "UPDATE transportadoras SET tipo_pessoa=:tipo_pessoa, cpf_cnpj=:cpf_cnpj, nome_razao=:nome_razao, nome_fantasia=:nome_fantasia, inscricao_estadual=:inscricao_estadual, email=:email, telefone=:telefone, celular=:celular, cep=:cep, logradouro=:logradouro, numero=:numero, bairro=:bairro, cidade=:cidade, uf=:uf, ativo=:ativo WHERE id=:id AND empresa_id=:empresa_id";
            $db->prepare($sql)->execute($params);
            Session::setFlash('success', 'Transportadora atualizada com sucesso!');
        } else {
            $sql = "INSERT INTO transportadoras (empresa_id, tipo_pessoa, cpf_cnpj, nome_razao, nome_fantasia, inscricao_estadual, email, telefone, celular, cep, logradouro, numero, bairro, cidade, uf, ativo) VALUES (:empresa_id, :tipo_pessoa, :cpf_cnpj, :nome_razao, :nome_fantasia, :inscricao_estadual, :email, :telefone, :celular, :cep, :logradouro, :numero, :bairro, :cidade, :uf, :ativo)";
            $db->prepare($sql)->execute($params);
            Session::setFlash('success', 'Transportadora cadastrada com sucesso!');
        }

        header('Location: ' . BASE_URL . '/modules/cadastros/transportadoras.php');
        exit;
    }

    if ($acao === 'alternar_status') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE transportadoras SET ativo = IF(ativo = 1, 0, 1) WHERE id = :id AND empresa_id = :emp")->execute([':id' => $id, ':emp' => $empresaId]);
        Session::setFlash('success', 'Status atualizado.');
        header('Location: ' . BASE_URL . '/modules/cadastros/transportadoras.php');
        exit;
    }

    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM transportadoras WHERE id = :id AND empresa_id = :emp")->execute([':id' => $id, ':emp' => $empresaId]);
        Session::setFlash('success', 'Transportadora excluída.');
        header('Location: ' . BASE_URL . '/modules/cadastros/transportadoras.php');
        exit;
    }
}

$stmtLista = $db->prepare("SELECT * FROM transportadoras WHERE empresa_id = :emp ORDER BY nome_razao ASC");
$stmtLista->execute([':emp' => $empresaId]);
$transportadoras = $stmtLista->fetchAll();

$ufsBrasil = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

$pageTitle    = 'Transportadoras';
$activeModule = 'cadastros';
$activeSub    = 'transportadoras';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
        <div class="ct" style="font-size:20px">Transportadoras</div>
        <div class="kl" style="margin-top:2px">Gerencie as empresas de transporte e frete vinculadas às notas fiscais e vendas</div>
    </div>
    <button type="button" class="btn-primary-brand" onclick="abrirModalTransp()">+ Nova transportadora</button>
</div>

<div class="card" style="padding:0;overflow:hidden;margin-top:20px">
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
                <?php if (empty($transportadoras)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">Nenhuma transportadora cadastrada.</td></tr>
                <?php else: foreach ($transportadoras as $t): 
                    $jsonTr = htmlspecialchars(json_encode($t, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                    <tr>
                        <td><b><?= Security::e($t['nome_razao']) ?></b></td>
                        <td><b><?= Security::e($t['cpf_cnpj']) ?></b></td>
                        <td><?= Security::e($t['telefone'] ?: '—') ?><div class="kl"><?= Security::e($t['email'] ?: 'Sem e-mail') ?></div></td>
                        <td><b><?= Security::e($t['cidade'] ?: '—') ?> / <?= Security::e($t['uf'] ?: '—') ?></b></td>
                        <td>
                            <?php if((int)$t['ativo'] === 1): ?>
                                <span class="bd" style="background:var(--osoft);color:var(--otxt)">Ativo</span>
                            <?php else: ?>
                                <span class="bd" style="background:var(--rsoft);color:var(--rtxt)">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <button type="button" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px" onclick='editarTransp(<?= $jsonTr ?>)'>Editar</button>
                            <form method="post" style="display:inline" onsubmit="return confirm('Alterar status?');">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="acao" value="alternar_status">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px">Status</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('Excluir transportadora?');">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
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
<div id="modalTransp" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;overflow-y:auto;padding:24px 14px">
    <div class="card" style="max-width:750px;margin:20px auto;padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:18px">
            <span class="ct" id="modalTituloTr" style="font-size:18px">Nova Transportadora</span>
            <button type="button" class="btn-theme" style="min-height:36px;padding:0 12px" onclick="fecharModalTransp()">✕</button>
        </div>
        <form method="post" id="formTransp">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" id="tr_id" value="0">
            
            <div class="row g-3" style="margin-bottom:14px">
                <div class="col-md-3">
                    <label class="form-label-os">Tipo</label>
                    <select name="tipo_pessoa" id="tr_tipo" class="form-select-os">
                        <option value="PJ">PJ</option>
                        <option value="PF">PF</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">CNPJ / CPF *</label>
                    <input type="text" name="cpf_cnpj" id="tr_doc" class="form-control-os" data-mask="cpfcnpj" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label-os">Razão Social / Nome *</label>
                    <input type="text" name="nome_razao" id="tr_razao" class="form-control-os" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label-os">Nome Fantasia</label>
                    <input type="text" name="nome_fantasia" id="tr_fantasia" class="form-control-os">
                </div>
                <div class="col-md-3">
                    <label class="form-label-os">Inscrição Estadual</label>
                    <input type="text" name="inscricao_estadual" id="tr_ie" class="form-control-os">
                </div>
                <div class="col-md-3">
                    <label class="form-label-os">CEP</label>
                    <input type="text" name="cep" id="tr_cep" class="form-control-os" data-mask="cep" onblur="buscarCepTr(this.value)">
                </div>
                <div class="col-md-6">
                    <label class="form-label-os">Logradouro</label>
                    <input type="text" name="logradouro" id="tr_logradouro" class="form-control-os">
                </div>
                <div class="col-md-2">
                    <label class="form-label-os">Número</label>
                    <input type="text" name="numero" id="tr_numero" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Bairro</label>
                    <input type="text" name="bairro" id="tr_bairro" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Cidade</label>
                    <input type="text" name="cidade" id="tr_cidade" class="form-control-os">
                </div>
                <div class="col-md-2">
                    <label class="form-label-os">UF</label>
                    <select name="uf" id="tr_uf" class="form-select-os">
                        <option value="">--</option>
                        <?php foreach($ufsBrasil as $u): ?><option value="<?= $u ?>"><?= $u ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;border-top:1px solid var(--border);padding-top:14px">
                <button type="button" class="btn-theme" onclick="fecharModalTransp()">Cancelar</button>
                <button type="submit" class="btn-primary-brand">Salvar Transportadora</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalTransp() {
    document.getElementById('formTransp').reset();
    document.getElementById('tr_id').value = '0';
    document.getElementById('modalTituloTr').textContent = 'Nova Transportadora';
    document.getElementById('modalTransp').style.display = 'block';
}
function editarTransp(t) {
    document.getElementById('tr_id').value = t.id;
    document.getElementById('tr_tipo').value = t.tipo_pessoa || 'PJ';
    document.getElementById('tr_doc').value = t.cpf_cnpj || '';
    document.getElementById('tr_razao').value = t.nome_razao || '';
    document.getElementById('tr_fantasia').value = t.nome_fantasia || '';
    document.getElementById('tr_ie').value = t.inscricao_estadual || '';
    document.getElementById('tr_cep').value = t.cep || '';
    document.getElementById('tr_logradouro').value = t.logradouro || '';
    document.getElementById('tr_numero').value = t.numero || '';
    document.getElementById('tr_bairro').value = t.bairro || '';
    document.getElementById('tr_cidade').value = t.cidade || '';
    document.getElementById('tr_uf').value = t.uf || '';
    document.getElementById('modalTituloTr').textContent = 'Editar Transportadora #' + t.id;
    document.getElementById('modalTransp').style.display = 'block';
}
function fecharModalTransp() {
    document.getElementById('modalTransp').style.display = 'none';
}
function buscarCepTr(v) {
    var cep = (v||'').replace(/\D/g,'');
    if(cep.length!==8)return;
    fetch('https://viacep.com.br/ws/'+cep+'/json/').then(r=>r.json()).then(d=>{
        if(!d.erro){
            if(d.logradouro)document.getElementById('tr_logradouro').value=d.logradouro;
            if(d.bairro)document.getElementById('tr_bairro').value=d.bairro;
            if(d.localidade)document.getElementById('tr_cidade').value=d.localidade;
            if(d.uf)document.getElementById('tr_uf').value=d.uf;
        }
    }).catch(()=>{});
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>