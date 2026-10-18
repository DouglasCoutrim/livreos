<?php
// Caminho: /logout.php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

Session::start();
if (Auth::check()) {
    AuditLogger::log('LOGOUT', 'Autenticação', 'Usuário encerrou a sessão.');
}
Session::destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;