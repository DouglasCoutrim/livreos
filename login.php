<?php
// Caminho: /login.php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

Session::start();
if (Auth::check()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$erro = '';
if (isset($_GET['timeout'])) {
    $erro = 'Sua sessão expirou por inatividade. Faça login novamente.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (!Security::validateCsrf($csrf)) {
        $erro = 'Token de segurança inválido. Recarregue a página.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
        $erro = 'Preencha um e-mail válido e sua senha.';
    } else {
        $res = Auth::attempt($email, $senha);
        if ($res['success']) {
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
        $erro = $res['message'];
    }
}
?>
<!doctype html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0E9F6E">
    <title>LivreOS – Acesso ao Sistema</title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/livreos-icone.png">
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme.css">
    <script src="<?= BASE_URL ?>/assets/js/theme.js"></script>
</head>
<body style="min-height:100vh;display:flex; flex-direction:column; justify-content:center; align-items:center; padding:20px;">

    <div style="position:fixed;top:20px;right:20px">
        <button id="tg" type="button" class="btn-theme">Modo claro/escuro</button>
    </div>

    <div class="card" style="width:100%;max-width:410px;padding:32px 28px">
        <div style="text-align:center;margin-bottom:24px">
            <img src="<?= BASE_URL ?>/assets/livreos-logo.png" alt="LivreOS" class="brand-logo" style="height:56px;width:auto;object-fit:contain">
            <div class="kl" style="margin-top:10px;font-size:13px">Acesse sua conta para gerenciar sua empresa</div>
        </div>

        <?php if ($erro !== ''): ?>
            <div style="background:var(--rsoft);color:var(--rtxt);padding:12px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:18px">
                <?= Security::e($erro) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= BASE_URL ?>/login.php" novalidate>
            <?= Security::csrfField() ?>

            <div style="margin-bottom:16px">
                <label class="form-label-os" for="email">E-mail corporativo</label>
                <input type="email" id="email" name="email" class="form-control-os" placeholder="admin@livreos.com.br" value="<?= Security::e($_POST['email'] ?? 'admin@livreos.com.br') ?>" required autofocus>
            </div>

            <div style="margin-bottom:22px">
                <label class="form-label-os" for="senha">Senha</label>
                <input type="password" id="senha" name="senha" class="form-control-os" placeholder="••••••••" value="Admin@123" required>
            </div>

            <button type="submit" class="btn-primary-brand" style="width:100%">
                Entrar no LivreOS
            </button>
        </form>

        <div class="kl" style="text-align:center;margin-top:20px">
            Ambiente SaaS Multiempresa Protegido · v<?= APP_VERSION ?>
        </div>
    </div>

</body>
</html>