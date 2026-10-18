<?php
// Caminho: /modules/cadastros/veiculos.php
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
        header('Location: ' . BASE_URL . '/modules/cadastros/veiculos.php');
        exit;
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $id              = (int)($_POST['id'] ?? 0);
        $placa           = strtoupper(trim($_POST['placa'] ?? ''));
        $marcaModelo     = trim($_POST['marca_modelo'] ?? '');
        $ano             = trim($_POST['ano'] ?? '') ?: null;
        $renavam         = trim($_POST['renavam'] ?? '') ?: null;
        $tipo            = trim($_POST['tipo'] ?? '') ?: null;
        $motoristaPadrao = trim($_POST['motorista_padrao'] ?? '') ?: null;
        $observacoes     = trim($_POST['observacoes'] ?? '') ?: null;
        $ativo           = isset($_POST['ativo']) && (int)$_POST['ativo'] === 0 ? 0 : 1;

        $erros = [];
        if ($placa === '') $erros[] = 'Informe a placa do veículo.';
        if ($marcaModelo === '') $erros[] = 'Informe a marca e modelo do veículo.';

        if (!empty($erros)) {
            Session::setFlash('error', implode(' ', $erros));
            header('Location: ' . BASE_URL . '/modules/cadastros/veiculos.php');
            exit;
        }

        $params = [
            ':empresa_id'      => $empresaId,
            ':placa'           => $placa,
            ':marca_modelo'    => $marcaModelo,
            ':ano'             => $ano,
            ':renavam'         => $renavam,
            ':tipo'            => $tipo,
            ':motorista_padrao'=> $motoristaPadrao,
            ':observacoes'     => $observacoes,
            ':ativo'           => $ativo,
        ];

        if ($id > 0) {
            $params[':id'] = $id;
            $sql = "UPDATE veiculos SET placa=:placa, marca_modelo=:marca_modelo, ano=:ano, renavam=:renavam, tipo=:tipo, motorista_padrao=:motorista_padrao, observacoes=:observacoes, ativo=:ativo WHERE id=:id AND empresa_id=:empresa_id";
            $db->prepare($sql)->execute($params);
            Session::setFlash('success', 'Veículo atualizado com sucesso!');
        } else {
            $sql = "INSERT INTO veiculos (empresa_id, placa, marca_modelo, ano, renavam, tipo, motorista_padrao, observacoes, ativo) VALUES (:empresa_id, :placa, :marca_modelo, :ano, :renavam, :tipo, :motorista_padrao, :observacoes, :ativo)";
            $db->prepare($sql)->execute($params);
            Session::setFlash('success', 'Veículo cadastrado com sucesso!');
        }

        header('Location: ' . BASE_URL . '/modules/cadastros/veiculos.php');
        exit;
    }

    if ($acao === 'alternar_status') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE veiculos SET ativo = IF(ativo = 1, 0, 1) WHERE id = :id AND empresa_id = :emp")->execute([':id' => $id, ':emp' => $empresaId]);
        Session::setFlash('success', 'Status atualizado.');
        header('Location: ' . BASE_URL . '/modules/cadastros/veiculos.php');
        exit;
    }

    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM veiculos WHERE id = :id AND empresa_id = :emp")->execute([':id' => $id, ':emp' => $empresaId]);
        Session::setFlash('success', 'Veículo excluído.');
        header('Location: ' . BASE_URL . '/modules/cadastros/veiculos.php');
        exit;
    }
}

$stmtLista = $db->prepare("SELECT * FROM veiculos WHERE empresa_id = :emp ORDER BY placa ASC");
$stmtLista->execute([':emp' => $empresaId]);
$veiculos = $stmtLista->fetchAll();

$tiposVeiculo = ['Carro', 'Moto', 'Caminhão', 'Van', 'Utilitário', 'Outro'];

$pageTitle    = 'Veículos';
$activeModule = 'cadastros';
$activeSub    = 'veiculos';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
        <div class="ct" style="font-size:20px">Veículos</div>
        <div class="kl" style="margin-top:2px">Gerencie a frota de veículos para entregas, logística e serviços externos</div>
    </div>
    <button type="button" class="btn-primary-brand" onclick="abrirModalVeic()">+ Novo Veículo</button>
</div>

<div class="card" style="padding:0;overflow:hidden;margin-top:20px">
    <div class="table-responsive-os">
        <table class="table-os">
            <thead>
                <tr>
                    <th>Placa</th>
                    <th>Marca / Modelo</th>
                    <th>Tipo / Ano</th>
                    <th>Motorista Padrão</th>
                    <th>Situação</th>
                    <th style="text-align:right">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($veiculos)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted)">Nenhum veículo cadastrado.</td></tr>
                <?php else: foreach ($veiculos as $v): 
                    $jsonV = htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                    <tr>
                        <td><b><span class="bd" style="background:var(--bsoft);color:var(--btxt);font-size:12px"><?= Security::e($v['placa']) ?></span></b></td>
                        <td><b><?= Security::e($v['marca_modelo']) ?></b></td>
                        <td><?= Security::e($v['tipo'] ?: 'Geral') ?> · <?= Security::e($v['ano'] ?: '—') ?></td>
                        <td><?= Security::e($v['motorista_padrao'] ?: 'Não definido') ?></td>
                        <td>
                            <?php if((int)$v['ativo'] === 1): ?>
                                <span class="bd" style="background:var(--osoft);color:var(--otxt)">Ativo</span>
                            <?php else: ?>
                                <span class="bd" style="background:var(--rsoft);color:var(--rtxt)">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <button type="button" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px" onclick='editarVeic(<?= $jsonV ?>)'>Editar</button>
                            <form method="post" style="display:inline" onsubmit="return confirm('Alterar status?');">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="acao" value="alternar_status">
                                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                <button type="submit" class="btn-theme" style="min-height:34px;padding:0 10px;font-size:12px">Status</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('Excluir veículo?');">
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

<!-- Modal -->
<div id="modalVeic" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;overflow-y:auto;padding:24px 14px">
    <div class="card" style="max-width:650px;margin:30px auto;padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:18px">
            <span class="ct" id="modalTituloVeic" style="font-size:18px">Novo Veículo</span>
            <button type="button" class="btn-theme" style="min-height:36px;padding:0 12px" onclick="fecharModalVeic()">✕</button>
        </div>
        <form method="post" id="formVeic">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" id="v_id" value="0">
            
            <div class="row g-3" style="margin-bottom:14px">
                <div class="col-md-4">
                    <label class="form-label-os">Placa *</label>
                    <input type="text" name="placa" id="v_placa" class="form-control-os" placeholder="ABC-1234" required style="text-transform:uppercase">
                </div>
                <div class="col-md-8">
                    <label class="form-label-os">Marca e Modelo *</label>
                    <input type="text" name="marca_modelo" id="v_marca" class="form-control-os" placeholder="Ex: Fiat Fiorino 1.4" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Ano</label>
                    <input type="text" name="ano" id="v_ano" class="form-control-os" maxlength="4" placeholder="2024">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">RENAVAM</label>
                    <input type="text" name="renavam" id="v_renavam" class="form-control-os" placeholder="Opcional">
                </div>
                <div class="col-md-4">
                    <label class="form-label-os">Tipo de Veículo</label>
                    <select name="tipo" id="v_tipo" class="form-select-os">
                        <?php foreach($tiposVeiculo as $tp): ?><option value="<?= $tp ?>"><?= $tp ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label-os">Motorista Padrão / Responsável</label>
                    <input type="text" name="motorista_padrao" id="v_motorista" class="form-control-os" placeholder="Nome do motorista principal">
                </div>
                <div class="col-12">
                    <label class="form-label-os">Observações</label>
                    <textarea name="observacoes" id="v_obs" rows="2" class="form-control-os" placeholder="Seguro, cartão de abastecimento, restrições..."></textarea>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;border-top:1px solid var(--border);padding-top:14px">
                <button type="button" class="btn-theme" onclick="fecharModalVeic()">Cancelar</button>
                <button type="submit" class="btn-primary-brand">Salvar Veículo</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalVeic() {
    document.getElementById('formVeic').reset();
    document.getElementById('v_id').value = '0';
    document.getElementById('modalTituloVeic').textContent = 'Novo Veículo';
    document.getElementById('modalVeic').style.display = 'block';
}
function editarVeic(v) {
    document.getElementById('v_id').value = v.id;
    document.getElementById('v_placa').value = v.placa || '';
    document.getElementById('v_marca').value = v.marca_modelo || '';
    document.getElementById('v_ano').value = v.ano || '';
    document.getElementById('v_renavam').value = v.renavam || '';
    document.getElementById('v_tipo').value = v.tipo || 'Carro';
    document.getElementById('v_motorista').value = v.motorista_padrao || '';
    document.getElementById('v_obs').value = v.observacoes || '';
    document.getElementById('modalTituloVeic').textContent = 'Editar Veículo #' + v.id;
    document.getElementById('modalVeic').style.display = 'block';
}
function fecharModalVeic() {
    document.getElementById('modalVeic').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>