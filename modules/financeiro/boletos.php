<?php
// Caminho: /modules/financeiro/boletos.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
Auth::requireLogin();

if (!Auth::hasPermission('financeiro.ver')) {
    Session::setFlash('error', 'Sem permissão.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

function parseDecBol(string $val): float {
    $limpo = str_replace(['R$', '%', ' ', '.'], '', trim($val));
    $limpo = str_replace(',', '.', $limpo);
    return is_numeric($limpo) ? (float)$limpo : 0.0;
}

$db = Database::getConnection();
$empresaId = Auth::empresaId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (Security::validateCsrf($csrf)) {
        $acao = $_POST['acao'] ?? '';
        if ($acao === 'salvar') {
            $banco        = trim($_POST['banco'] ?? '');
            $empresaEmiss = trim($_POST['empresa'] ?? '');
            $cliente      = trim($_POST['cliente'] ?? '');
            $cpfCnpj      = trim($_POST['cpf_cnpj'] ?? '');
            $nomeSacado   = trim($_POST['nome_sacado'] ?? '');
            $observacoes  = trim($_POST['observacoes'] ?? '') ?: null;
            $logradouro   = trim($_POST['logradouro'] ?? '');
            $numero       = trim($_POST['numero'] ?? '');
            $bairro       = trim($_POST['bairro'] ?? '');
            $cidade       = trim($_POST['cidade'] ?? '');
            $uf           = strtoupper(trim($_POST['uf'] ?? ''));
            $cep          = trim($_POST['cep'] ?? '');

            $dtEmissao    = trim($_POST['data_emissao'] ?? date('Y-m-d'));
            $dtVenc       = trim($_POST['data_vencimento'] ?? date('Y-m-d'));
            $valorBoleto  = parseDecBol($_POST['valor_boleto'] ?? '0');
            $nossoNum     = trim($_POST['nosso_numero'] ?? '') ?: '1';
            $numDoc       = trim($_POST['numero_documento'] ?? '') ?: null;

            $dtDesc       = trim($_POST['data_desconto'] ?? '') ?: null;
            $valDesc      = parseDecBol($_POST['valor_desconto'] ?? '0');
            $dtMulta      = trim($_POST['data_multa'] ?? '') ?: null;
            $valMulta     = parseDecBol($_POST['valor_multa'] ?? '0');
            $pMulta       = parseDecBol($_POST['percentual_multa'] ?? '0');
            $dtJuros      = trim($_POST['data_juros'] ?? '') ?: null;
            $valJuros     = parseDecBol($_POST['valor_juros'] ?? '0');
            $pJuros       = parseDecBol($_POST['percentual_juros'] ?? '0');

            if ($banco !== '' && $cliente !== '' && $valorBoleto > 0) {
                $stmt = $db->prepare("
                    INSERT INTO boletos (
                        empresa_id, banco, empresa_emissora, cliente_nome, cpf_cnpj, nome_sacado, observacoes,
                        logradouro, numero, bairro, cidade, uf, cep, data_emissao, data_vencimento, valor_boleto,
                        nosso_numero, numero_documento, data_desconto, valor_desconto, data_multa, valor_multa,
                        percentual_multa, data_juros, valor_juros, percentual_juros, status
                    ) VALUES (
                        :emp, :banco, :emp_emiss, :cli, :doc, :sacado, :obs, :logr, :num, :bairro, :cid, :uf, :cep,
                        :demissao, :dvenc, :vbol, :nosso, :ndoc, :ddesc, :vdesc, :dmulta, :vmulta, :pmulta,
                        :djuros, :vjuros, :pjuros, 'gerado'
                    )
                ");
                $stmt->execute([
                    ':emp'       => $empresaId,
                    ':banco'     => $banco,
                    ':emp_emiss' => $empresaEmiss,
                    ':cli'       => $cliente,
                    ':doc'       => $cpfCnpj,
                    ':sacado'    => $nomeSacado,
                    ':obs'       => $observacoes,
                    ':logr'      => $logradouro,
                    ':num'       => $numero,
                    ':bairro'    => $bairro,
                    ':cid'       => $cidade,
                    ':uf'        => $uf,
                    ':cep'       => $cep,
                    ':demissao'  => $dtEmissao,
                    ':dvenc'     => $dtVenc,
                    ':vbol'      => $valorBoleto,
                    ':nosso'     => $nossoNum,
                    ':ndoc'      => $numDoc,
                    ':ddesc'     => $dtDesc,
                    ':vdesc'     => $valDesc,
                    ':dmulta'    => $dtMulta,
                    ':vmulta'    => $valMulta,
                    ':pmulta'    => $pMulta,
                    ':djuros'    => $dtJuros,
                    ':vjuros'    => $valJuros,
                    ':pjuros'    => $pJuros,
                ]);
                Session::setFlash('success', 'Boleto cadastrado com sucesso!');
                header('Location: ' . BASE_URL . '/modules/financeiro/boletos.php');
                exit;
            }
        } elseif ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM boletos WHERE id = :id AND empresa_id = :emp")->execute([':id' => $id, ':emp' => $empresaId]);
            Session::setFlash('success', 'Boleto excluído.');
            header('Location: ' . BASE_URL . '/modules/financeiro/boletos.php');
            exit;
        }
    }
}

$stmtLista = $db->prepare("SELECT * FROM boletos WHERE empresa_id = :emp ORDER BY id DESC");
$stmtLista->execute([':emp' => $empresaId]);
$boletos = $stmtLista->fetchAll();

$ufsBrasil = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

$pageTitle = 'Boletos';
$activeModule = 'financeiro';
$activeSub = 'boletos';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div>
        <div class="ct" style="font-size:20px">Emissão de Boletos</div>
        <div class="kl" style="margin-top:2px">Gestão e emissão de boletos bancários</div>
    </div>
    <button type="button" class="btn-primary-brand" onclick="abrirModalBoleto()">+ Novo Boleto</button>
</div>

<div class="card" style="padding:0;overflow:hidden">
    <div class="table-responsive-os">
        <table class="table-os">
            <thead>
                <tr>
                    <th>Nosso Número</th>
                    <th>Sacado / Cliente</th>
                    <th>Vencimento</th>
                    <th>Valor do Boleto</th>
                    <th>Banco</th>
                    <th>Status</th>
                    <th style="text-align:right">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($boletos)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted)">Nenhum boleto cadastrado.</td></tr>
                <?php else: foreach ($boletos as $b): ?>
                    <tr>
                        <td><b><?= Security::e($b['nosso_numero']) ?></b></td>
                        <td><?= Security::e($b['nome_sacado']) ?><div class="kl"><?= Security::e($b['cpf_cnpj']) ?></div></td>
                        <td><?= Security::formatDate($b['data_vencimento']) ?></td>
                        <td><b style="color:var(--otxt)"><?= Security::formatMoney($b['valor_boleto']) ?></b></td>
                        <td><?= Security::e($b['banco']) ?></td>
                        <td><span class="bd" style="background:var(--osoft);color:var(--otxt)"><?= ucfirst($b['status']) ?></span></td>
                        <td style="text-align:right">
                            <form method="post" style="display:inline" onsubmit="return confirm('Excluir boleto?');">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                <button type="submit" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px;color:var(--rtxt)">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal idêntico à imagem de referência -->
<div id="modalBoleto" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;overflow-y:auto;padding:24px 14px">
    <div class="card" style="max-width:1000px;margin:20px auto;padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:18px">
            <span class="ct" style="font-size:18px">Novo Boleto</span>
            <div style="display:flex;gap:10px">
                <button type="button" class="btn-theme" onclick="fecharModalBoleto()">Cancelar</button>
                <button type="button" class="btn-primary-brand" onclick="document.getElementById('formBoleto').requestSubmit()">Salvar</button>
            </div>
        </div>

        <form method="post" id="formBoleto">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="salvar">

            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-4">
                    <label class="form-label-os">Banco *</label>
                    <input type="text" name="banco" class="form-control-os" placeholder="Ex: Banco do Brasil, Itaú..." required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Empresa *</label>
                    <input type="text" name="empresa" class="form-control-os" placeholder="Nome da empresa emissora" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Cliente *</label>
                    <input type="text" name="cliente" class="form-control-os" placeholder="Nome do cliente" required>
                </div>
            </div>

            <!-- Dados do Sacado -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px;border-top:1px solid var(--border);padding-top:14px">Dados do Sacado</div>
            <div class="row g-3" style="margin-bottom:18px">
                <div class="col-md-4">
                    <label class="form-label-os">CPF/CNPJ *</label>
                    <input type="text" name="cpf_cnpj" class="form-control-os" data-mask="cpfcnpj" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Nome do Sacado *</label>
                    <input type="text" name="nome_sacado" class="form-control-os" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Observações</label>
                    <input type="text" name="observacoes" class="form-control-os">
                </div>
                <div class="col-md-5">
                    <label class="form-label-os">Logradouro *</label>
                    <input type="text" name="logradouro" id="b_logr" class="form-control-os" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label-os">Número *</label>
                    <input type="text" name="numero" class="form-control-os" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Bairro *</label>
                    <input type="text" name="bairro" id="b_bairro" class="form-control-os" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Cidade *</label>
                    <input type="text" name="cidade" id="b_cidade" class="form-control-os" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label-os">UF *</label>
                    <select name="uf" id="b_uf" class="form-select-os" required>
                        <option value="">--</option>
                        <?php foreach($ufsBrasil as $u): ?><option value="<?= $u ?>"><?= $u ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label-os">CEP *</label>
                    <input type="text" name="cep" class="form-control-os" data-mask="cep" placeholder="00000-000" onblur="buscarCepBoleto(this.value)" required>
                </div>
            </div>

            <!-- Dados do Boleto -->
            <div class="kl" style="color:#0E9F6E;font-weight:800;text-transform:uppercase;margin-bottom:10px;border-top:1px solid var(--border);padding-top:14px">Dados do Boleto</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label-os">Data de Emissão *</label>
                    <input type="date" name="data_emissao" class="form-control-os" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Data de Vencimento *</label>
                    <input type="date" name="data_vencimento" class="form-control-os" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Valor do Boleto (R$) *</label>
                    <input type="text" name="valor_boleto" class="form-control-os" data-mask="money" value="0,00" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Nosso Número</label>
                    <input type="text" name="nosso_numero" class="form-control-os" value="1">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Número do Documento</label>
                    <input type="text" name="numero_documento" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Data para Desconto</label>
                    <input type="date" name="data_desconto" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Valor do Desconto</label>
                    <input type="text" name="valor_desconto" class="form-control-os" data-mask="money" value="0,00">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Data para Multa</label>
                    <input type="date" name="data_multa" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Valor da Multa</label>
                    <input type="text" name="valor_multa" class="form-control-os" data-mask="money" value="0,00">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Percentual da Multa (%)</label>
                    <input type="text" name="percentual_multa" class="form-control-os" data-mask="money" value="0,00">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Data para Juros</label>
                    <input type="date" name="data_juros" class="form-control-os">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Valor Juros</label>
                    <input type="text" name="valor_juros" class="form-control-os" data-mask="money" value="0,00">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Percentual dos Juros (%)</label>
                    <input type="text" name="percentual_juros" class="form-control-os" data-mask="money" value="0,00">
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalBoleto() { document.getElementById('modalBoleto').style.display='block'; }
function fecharModalBoleto() { document.getElementById('modalBoleto').style.display='none'; }
function buscarCepBoleto(v) {
    var cep = (v||'').replace(/\D/g,'');
    if(cep.length!==8)return;
    fetch('https://viacep.com.br/ws/'+cep+'/json/').then(r=>r.json()).then(d=>{
        if(!d.erro){
            if(d.logradouro)document.getElementById('b_logr').value=d.logradouro;
            if(d.bairro)document.getElementById('b_bairro').value=d.bairro;
            if(d.localidade)document.getElementById('b_cidade').value=d.localidade;
            if(d.uf)document.getElementById('b_uf').value=d.uf;
        }
    }).catch(()=>{});
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>