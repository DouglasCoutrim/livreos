<?php
// Front Controller (Roteador) para o LivreOS

define('ROOT_PATH', __DIR__);

// Obter a URL solicitada
$request_uri = $_SERVER['REQUEST_URI'];
$request_path = parse_url($request_uri, PHP_URL_PATH);
$request_path = trim($request_path, '/');

// Mapeamento das rotas para os módulos
$routes = [
    '' => 'dashboard/dashboard.php', // Raiz
    'vendas' => 'vendas/vendas.php',
    'vendas/pedidos' => 'vendas/pedidos.php',
    'estoque' => 'estoque/estoques.php',
    'financeiro' => 'financeiro/financeiro.php',
    'cadastros' => 'cadastros/cadastros.php',
    'cadastros/clientes' => 'cadastros/clientes.php',
    'cadastros/produtos' => 'cadastros/produtos.php',
];

// Verificar se a rota existe
if (array_key_exists($request_path, $routes)) {
    // Construir caminho para o arquivo físico
    $physical_path = ROOT_PATH . '/modules/' . $routes[$request_path];
    
    // Verificar se o arquivo existe
    if (file_exists($physical_path)) {
        // Carregar as variáveis globais
        global $pageTitle, $activeModule, $activeSub;
        
        // Incluir o arquivo físico correspondente
        require_once $physical_path;
        exit;
    }
}

// Rota desconhecida - mostra página 404
http_response_code(404);
echo '<!DOCTYPE html>
<html>
<head>
    <title>Página não encontrada</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: sans-serif; padding: 2rem; text-align: center; }
        h1 { color: #dc3545; margin-bottom: 1rem; }
        p { margin-bottom: 2rem; }
        a { color: #0d6efd; text-decoration: none; }
    </style>
</head>
<body>
    <h1>Página não encontrada (404)</h1>
    <p>A URL solicitada não foi encontrada neste sistema.</p>
    <p><a href="/">Voltar para a página principal</a></p>
</body>
</html>';
