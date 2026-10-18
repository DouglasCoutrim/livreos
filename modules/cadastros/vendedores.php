<?php
// Caminho: /modules/cadastros/vendedores.php
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

// Função auxiliar para converter valores monetários BR
function parseDec(string $val): float {
    $limpo = str_replace(['R$', '%', ' ', '.'], '', trim($val));
    $limpo = str_replace(',', '.', $limpo);
    return is_numeric($limpo) ? (float)$limpo : 0.0;
}

// Processamento do Formulário (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($csrf)) {
        Session::setFlash('error', 'Token de segurança inválido. Tente novamente.');
        header('Location: ' . BASE_URL . '/modules/cadastros/vendedores.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $id             = (int)($_POST['id'] ?? 0);
        $nomeFantasia   = trim($_POST['nome_fantasia'] ?? '');
        $cpf            = trim($_POST['cpf'] ?? '') ?: null;
        $tipoPessoa     = isset($_POST['tipo_pessoa']) ? 'PF' : 'PJ';

        $cep            = trim($_POST['cep'] ?? '') ?: null;
        $rua            = trim($_POST['rua'] ?? '') ?: null;
        $numero         = trim($_POST['numero'] ?? '') ?: null;
        $complemento    = trim($_POST['complemento'] ?? '') ?: null;
        $bairro         = trim($_POST['bairro'] ?? '') ?: null;
        $estado         = strtoupper(trim($_POST['estado'] ?? '')) ?: null;
        $cidade         = trim($_POST['cidade'] ?? '') ?: null;
        $pais           = trim($_POST['pais'] ?? 'Brasil');

        $foneComercial  = trim($_POST['fone_comercial'] ?? '') ?: null;
        $telefone       = trim($_POST['telefone'] ?? '') ?: null;
        $site           = trim($_POST['site'] ?? '') ?: null;
        $email          = strtolower(trim($_POST['email'] ?? '')) ?: null;
        $emailComercial = strtolower(trim($_POST['email_comercial'] ?? '')) ?: null;
        $emailFinanceiro= strtolower(trim($_POST['email_financeiro'] ?? '')) ?: null;
        $contato        = trim($_POST['contato'] ?? '') ?: null;
        $contato2       = trim($_POST['contato_2'] ?? '') ?: null;
        $contato3       = trim($_POST['contato_3'] ?? '') ?: null;

        $tipoComissao   = trim($_POST['tipo_comissao'] ?? '') ?: null;
        $percentual     = parseDec($_POST['percentual'] ?? '0');
        $valor          = parseDec($_POST['valor'] ?? '0');
        $diaComissao    = trim($_POST['dia_comissao'] ?? '') ?: null;
        $metaDiaria     = parseDec($_POST['meta_diaria'] ?? '0');
        $metaMensal     = parseDec($_POST['meta_mensal'] ?? '0');
        $ativo          = isset($_POST['ativo']) && (int)$_POST['ativo'] === 0 ? 0 : 1;

        if ($nomeFantasia === '') {
            Session::setFlash('error', 'O campo Nome / Nome Fantasia é obrigatório.');
            header('Location: ' . BASE_URL . '/modules/cadastros/vendedores.php');
            exit;
        }

        $params = [
            ':empresa_id'      => $empresaId,
            ':nome_fantasia'   => $nomeFantasia,
            ':cpf'             => $cpf,
            ':tipo_pessoa'     => $tipoPessoa,
            ':cep'             => $cep,
            ':rua'             => $rua,
            ':numero'          => $numero,
            ':complemento'     => $complemento,
            ':bairro'          => $bairro,
            ':estado'          => $estado,
            ':cidade'          => $cidade,
            ':pais'            => $pais,
            ':fone_comercial'  => $foneComercial,
            ':telefone'        => $telefone,
            ':site'            => $site,
            ':email'           => $email,
            ':email_comercial' => $emailComercial,
            ':email_financeiro'=> $emailFinanceiro,
            ':contato'         => $contato,
            ':contato_2'       => $contato2,
            ':contato_3'       => $contato3,
            ':tipo_comissao'   => $tipoComissao,
            ':percentual'      => $percentual,
            ':valor'           => $valor,
            ':dia_comissao'    => $diaComissao,
            ':meta_diaria'     => $metaDiaria,
            ':meta_mensal'     => $metaMensal,
            ':ativo'           => $ativo,
        ];

        if ($id > 0) {
            $params[':id'] = $id;
            $sql = "UPDATE vendedores SET nome_fantasia=:nome_fantasia, cpf=:cpf, tipo_pessoa=:tipo_pessoa, cep=:cep, rua=:rua, numero=:numero, complemento=:complemento, bairro=:bairro, estado=:estado, cidade=:cidade, pais=:pais, fone_comercial=:fone_comercial, telefone=:telefone, site=:site, email=:email, email_comercial=:email_comercial, email_financeiro=:email_financeiro, contato=:contato, contato_2=:contato_2, contato_3=:contato_3, tipo_comissao=:tipo_comissao, percentual=:percentual, valor=:valor, dia_comissao=:dia_comissao, meta_diaria=:meta_diaria, meta_mensal=:meta_mensal, ativo=:ativo WHERE id=:id AND empresa_id=:empresa_id";
            $db->prepare($sql)->execute($params);
            Session::setFlash('success', 'Vendedor atualizado com sucesso!');
        } else {
            $sql = "INSERT INTO vendedores (empresa_id, nome_fantasia, cpf, tipo_pessoa, cep, rua, numero, complemento, bairro, estado, cidade, pais, fone_comercial, telefone, site, email, email_comercial, email_financeiro, contato, contato_2, contato_3, tipo_comissao, percentual, valor, dia_comissao, meta_diaria, meta_mensal, ativo) VALUES (:empresa_id, :nome_fantasia, :cpf, :tipo_pessoa, :cep, :rua, :numero, :complemento, :bairro, :estado, :cidade, :pais, :fone_comercial, :telefone, :site, :email, :email_comercial, :email_financeiro, :contato, :contato_2, :contato_3, :tipo_comissao, :percentual, :valor, :dia_comissao, :meta_diaria, :meta_mensal, :ativo)";
            $db->prepare($sql)->execute($params);
            Session::setFlash('success', 'Vendedor cadastrado com sucesso!');
        }

        header('Location: ' . BASE_URL . '/modules/cadastros/vendedores.php');
        exit;
    }

    if ($acao === 'alternar_status') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE vendedores SET ativo = IF(ativo = 1, 0, 1) WHERE id = :id AND empresa_id = :emp")->execute([':id' => $id, ':emp' => $empresaId]);
        Session::setFlash('success', 'Status atualizado.');
        header('Location: ' . BASE_URL . '/modules/cadastros/vendedores.php');
        exit;
    }

    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM vendedores WHERE id = :id AND empresa_id = :emp")->execute([':id' => $id, ':emp' => $empresaId]);
        Session::setFlash('success', 'Vendedor excluído.');
        header('Location: ' . BASE_URL . '/modules/cadastros/vendedores.php');
        exit;
    }
}

$stmtLista = $db->prepare("SELECT * FROM vendedores WHERE empresa_id = :emp ORDER BY nome_fantasia ASC");
$stmtLista->execute([':emp' => $empresaId]);
$vendedores = $stmtLista->fetchAll();

$ufsBrasil = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

$pageTitle    = 'Vendedores';
$activeModule = 'cadastros';
$activeSub    = 'vendedores';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
        <div class="ct" style="font-size:20px">Vendedores</div>
        <div class="kl" style="margin-top:2px">Gerenciamento da equipe de vendas e comissões</div>
    </div>
    <button type="button" class="btn-primary-brand" onclick="abrirModalVend()">+ Novo Vendedor</button>
</div>

<!-- Tabela de Listagem -->
<div class="card" style="padding:0;overflow:hidden;margin-top:20px">
    <div class="table-responsive-os">
        <table class="table-os">
            <thead>
                <tr>
                    <th>Nome / Nome Fantasia</th>
                    <th>CPF</th>
                    <th>Contato</th>
                    <th>Comissão Padrão</th>
                    <th>Meta Mensal</th>
                    <th>Situação</th>
                    <th style="text-align:right">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendedores)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted)">Nenhum vendedor cadastrado.</td></tr>
                <?php else: foreach ($vendedores as $v): 
                    $jsonV = htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                    <tr>
                        <td><b><?= Security::e($v['nome_fantasia']) ?></b></td>
                        <td><?= Security::e($v['cpf'] ?: '—') ?></td>
                        <td><?= Security::e($v['telefone'] ?: ($v['fone_comercial'] ?: '—')) ?><div class="kl"><?= Security::e($v['email'] ?: 'Sem e-mail') ?></div></td>
                        <td><b><?= number_format((float)$v['percentual'], 2, ',', '.') ?>%</b></td>
                        <td><b><?= Security::formatMoney($v['meta_mensal']) ?></b></td>
                        <td>
                            <?php if((int)$v['ativo'] === 1): ?>
                                <span class="bd" style="background:var(--osoft);color:var(--otxt)">Ativo</span>
                            <?php else: ?>
                                <span class="bd" style="background:var(--rsoft);color:var(--rtxt)">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <button type="button" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px" onclick='editarVend(<?= $jsonV ?>)'>Editar</button>
                            <form method="post" style="display:inline" onsubmit="return confirm('Alterar status?');">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="acao" value="alternar_status">
                                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                <button type="submit" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px">Status</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('Excluir vendedor?');">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                <button type="submit" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px;color:var(--rtxt)">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL DE CADASTRO / EDIÇÃO DE VENDEDOR (Idêntico à referência)        -->
<!-- ===================================================================== -->
<div id="modalVend" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;overflow-y:auto;padding:24px 14px">
    <div class="card" style="max-width:960px;margin:20px auto;padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:18px">
            <div>
                <span class="ct" id="modalTituloV" style="font-size:18px">Novo Vendedor</span>
            </div>
            <div style="display:flex;gap:10px">
                <button type="button" class="btn-theme" onclick="fecharModalVend()">Cancelar</button>
                <button type="button" class="btn-primary-brand" onclick="document.getElementById('formVend').requestSubmit()">Salvar</button>
            </div>
        </div>

        <form method="post" id="formVend">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" id="v_id" value="0">

            <!-- Informações Básicas -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px">Informações Básicas</div>
            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-6">
                    <label class="form-label-os">Nome Fantasia *</label>
                    <input type="text" name="nome_fantasia" id="v_nome_fantasia" class="form-control-os" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">CPF</label>
                    <input type="text" name="cpf" id="v_cpf" class="form-control-os" placeholder="000.000.000-00">
                </div>
                <div class="col-md-2" style="display:flex;flex-direction:column;justify-content:flex-end">
                    <label class="form-label-os">Pessoa Física</label>
                    <div style="display:flex;align-items:center;height:44px">
                        <input type="checkbox" name="tipo_pessoa" id="v_tipo_pessoa" value="PF" checked style="width:20px;height:20px;accent-color:#0E9F6E">
                    </div>
                </div>
            </div>

            <!-- Informações de Endereço -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px;border-top:1px solid var(--border);padding-top:14px">Informações de Endereço</div>
            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-3">
                    <label class="form-label-os">CEP</label>
                    <input type="text" name="cep" id="v_cep" class="form-control-os" data-mask="cep" placeholder="00000-000" onblur="buscarCepVendedor(this.value)">
                </div>
                <div class="col-md-5">
                    <label class="form-label-os">Rua</label>
                    <input type="text" name="rua" id="v_rua" class="form-control-os">
                </div>
                <div class="col-md-2">
                    <label class="form-label-os">Número</label>
                    <input type="text" name="numero" id="v_numero" class="form-control-os">
                </div>
                <div class="col-md-2">
                    <label class="form-label-os">Complemento</label>
                    <input type="text" name="complemento" id="v_complemento" class="form-control-os">
                </div>
                <div class="col-md-3">
                    <label class="form-label-os">Bairro</label>
                    <input type="text" name="bairro" id="v_bairro" class="form-control-os">
                </div>
                <div class="col-md-3">
                    <label class="form-label-os">Estado</label>
                    <select name="estado" id="v_estado" class="form-select-os">
                        <option value="">Selecione</option>
                        <?php foreach($ufsBrasil as $u): ?><option value="<?= $u ?>"><?= $u ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Cidade</label>
                    <input type="text" name="cidade" id="v_cidade" class="form-control-os">
                </div>
                <div class="col-md-2">
                    <label class="form-label-os">País</label>
                    <input type="text" name="pais" id="v_pais" class="form-control-os" value="Brasil">
                </div>
            </div>

            <!-- Informações de Contato -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px;border-top:1px solid var(--border);padding-top:14px">Informações de Contato</div>
            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-4">
                    <label class="form-label-os">Fone Comercial</label>
                    <input type="text" name="fone_comercial" id="v_fone_comercial" class="form-control-os" data-mask="phone">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Telefone</label>
                    <input type="text" name="telefone" id="v_telefone" class="form-control-os" data-mask="phone">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Site</label>
                    <input type="text" name="site" id="v_site" class="form-control-os" placeholder="https://...">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">E-mail</label>
                    <input type="email" name="email" id="v_email" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">E-mail Comercial</label>
                    <input type="email" name="email_comercial" id="v_email_comercial" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">E-mail Financeiro</label>
                    <input type="email" name="email_financeiro" id="v_email_financeiro" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Contato</label>
                    <input type="text" name="contato" id="v_contato" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Contato 2</label>
                    <input type="text" name="contato_2" id="v_contato_2" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Contato 3</label>
                    <input type="text" name="contato_3" id="v_contato_3" class="form-control-os">
                </div>
            </div>

            <!-- Detalhes do Vendedor -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px;border-top:1px solid var(--border);padding-top:14px">Detalhes do Vendedor</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label-os">Tipo de Comissão</label>
                    <select name="tipo_comissao" id="v_tipo_comissao" class="form-select-os">
                        <option value="">Selecione</option>
                        <option value="Percentual sobre Venda">Percentual sobre Venda</option>
                        <option value="Valor Fixo">Valor Fixo</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Percentual (%)</label>
                    <input type="text" name="percentual" id="v_percentual" class="form-control-os" data-mask="money" value="0,00">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Valor (R$)</label>
                    <input type="text" name="valor" id="v_valor" class="form-control-os" data-mask="money" value="0,00">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Dia da Comissão</label>
                    <select name="dia_comissao" id="v_dia_comissao" class="form-select-os">
                        <option value="">Selecione</option>
                        <option value="Todo dia 5">Todo dia 5</option>
                        <option value="Todo dia 10">Todo dia 10</option>
                        <option value="Todo dia 15">Todo dia 15</option>
                        <option value="Todo dia 20">Todo dia 20</option>
                        <option value="Todo dia 30">Todo dia 30</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Meta diária (R$)</label>
                    <input type="text" name="meta_diaria" id="v_meta_diaria" class="form-control-os" data-mask="money" value="0,00">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Meta mensal (R$)</label>
                    <input type="text" name="meta_mensal" id="v_meta_mensal" class="form-control-os" data-mask="money" value="0,00">
                </div>
            </div>

            <input type="hidden" name="ativo" id="v_ativo" value="1">
        </form>
    </div>
</div>

<script>
function fmtMoeda(num) {
    return (Number(num) || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function abrirModalVend() {
    document.getElementById('formVend').reset();
    document.getElementById('v_id').value = '0';
    document.getElementById('v_pais').value = 'Brasil';
    document.getElementById('v_tipo_pessoa').checked = true;
    document.getElementById('modalTituloV').textContent = 'Novo Vendedor';
    document.getElementById('modalVend').style.display = 'block';
}

function editarVend(v) {
    document.getElementById('v_id').value = v.id;
    document.getElementById('v_nome_fantasia').value = v.nome_fantasia || '';
    document.getElementById('v_cpf').value = v.cpf || '';
    document.getElementById('v_tipo_pessoa').checked = (v.tipo_pessoa === 'PF');

    document.getElementById('v_cep').value = v.cep || '';
    document.getElementById('v_rua').value = v.rua || '';
    document.getElementById('v_numero').value = v.numero || '';
    document.getElementById('v_complemento').value = v.complemento || '';
    document.getElementById('v_bairro').value = v.bairro || '';
    document.getElementById('v_estado').value = v.estado || '';
    document.getElementById('v_cidade').value = v.cidade || '';
    document.getElementById('v_pais').value = v.pais || 'Brasil';

    document.getElementById('v_fone_comercial').value = v.fone_comercial || '';
    document.getElementById('v_telefone').value = v.telefone || '';
    document.getElementById('v_site').value = v.site || '';
    document.getElementById('v_email').value = v.email || '';
    document.getElementById('v_email_comercial').value = v.email_comercial || '';
    document.getElementById('v_email_financeiro').value = v.email_financeiro || '';
    document.getElementById('v_contato').value = v.contato || '';
    document.getElementById('v_contato_2').value = v.contato_2 || '';
    document.getElementById('v_contato_3').value = v.contato_3 || '';

    document.getElementById('v_tipo_comissao').value = v.tipo_comissao || '';
    document.getElementById('v_percentual').value = fmtMoeda(v.percentual);
    document.getElementById('v_valor').value = fmtMoeda(v.valor);
    document.getElementById('v_dia_comissao').value = v.dia_comissao || '';
    document.getElementById('v_meta_diaria').value = fmtMoeda(v.meta_diaria);
    document.getElementById('v_meta_mensal').value = fmtMoeda(v.meta_mensal);

    document.getElementById('modalTituloV').textContent = 'Editar Vendedor #' + v.id;
    document.getElementById('modalVend').style.display = 'block';
}

function fecharModalVend() {
    document.getElementById('modalVend').style.display = 'none';
}

function buscarCepVendedor(v) {
    var cep = (v||'').replace(/\D/g,'');
    if(cep.length!==8)return;
    fetch('https://viacep.com.br/ws/'+cep+'/json/').then(r=>r.json()).then(d=>{
        if(!d.erro){
            if(d.logradouro)document.getElementById('v_rua').value=d.logradouro;
            if(d.bairro)document.getElementById('v_bairro').value=d.bairro;
            if(d.localidade)document.getElementById('v_cidade').value=d.localidade;
            if(d.uf)document.getElementById('v_estado').value=d.uf;
        }
    }).catch(()=>{});
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>