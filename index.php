<?php
// Caminho: /index.php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

Session::start();
if (Auth::check()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}
header('Location: ' . BASE_URL . '/login.php');
exit;