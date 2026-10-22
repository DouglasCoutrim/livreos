<?php
// Caminho: /config/config.php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

// Ajuste a URL base caso o projeto rode em subpasta (ex: 'http://localhost/livreos')
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
// Remove subpastas de módulos se chamado de dentro de /modules/...
$basePath = preg_replace('#/modules/.*$#', '', $scriptDir);
$basePath = rtrim($basePath, '/');

define('BASE_URL', $protocol . $host . $basePath);
define('APP_NAME', 'LivreOS');
define('APP_VERSION', '1.0.0');

// Credenciais do Banco de Dados MySQL
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'livreos_erp');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Timeout de sessão em segundos (30 minutos)
define('SESSION_TIMEOUT', 1800);

// Autoloader simples para as classes da pasta /core
spl_autoload_register(function (string $class) {
    $file = __DIR__ . '/../core/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});