<?php
// Caminho: /config/modulos.php
// Fonte única do menu: módulos (sidebar) e abas (barra superior).
// Para criar/alterar abas de um módulo, edite só este arquivo.
declare(strict_types=1);

if (!function_exists('livreos_icone')) {
    /** Retorna o desenho (path SVG) de um ícone pelo nome. */
    function livreos_icone(string $nome): string
    {
        static $icones = [
            'home'     => 'M3 11l9-8 9 8v10H3z',
            'plus'     => 'M12 5v14M5 12h14',
            'list'     => 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
            'chart'    => 'M5 20V10M12 20V4M19 20v-7',
            'doc'      => 'M6 3h8l4 4v14H6z',
            'box'      => 'M3 7l9-4 9 4v10l-9 4-9-4z',
            'swap'     => 'M7 7h13l-3-3M17 17H4l3 3',
            'clip'     => 'M9 4h6v3H9zM6 5h3M15 5h3v16H6V5',
            'users'    => 'M4 20c0-4 4-6 8-6s8 2 8 6M12 4a4 4 0 100 8 4 4 0 000-8',
            'truck'    => 'M3 6h11v10H3zM14 9h4l3 3v4h-7M7 19h.01M17 19h.01',
            'tag'      => 'M3 12V3h9l9 9-9 9z',
            'cog'      => 'M12 8a4 4 0 100 8 4 4 0 000-8M12 2v3M12 19v3M2 12h3M19 12h3',
            'card'     => 'M3 7h18v12H3zM3 11h18',
            'bag'      => 'M5 8h14l-1 13H6zM9 8a3 3 0 016 0',
            'cart'     => 'M3 4h2l2 12h11l2-8H6',
            'wrench'   => 'M14 6l4 4-8 8H6v-4z',
            'car'      => 'M5 16v-5l2-5h10l2 5v5M3 16h18M7 19v-3M17 19v-3',
            'search'   => 'M11 4a7 7 0 100 14 7 7 0 000-14M21 21l-5-5',
            'rh'       => 'M3 5h18v14H3zM13 10h5M13 14h5',
            'monitor'  => 'M3 4h18v12H3zM8 21h8',
        ];
        return $icones[$nome] ?? $icones['doc'];
    }
}

if (!function_exists('livreos_svg')) {
    /** Monta o <svg> completo de um ícone. */
    function livreos_svg(string $nome, int $tam = 18): string
    {
        return '<svg viewBox="0 0 24 24" width="' . $tam . '" height="' . $tam . '" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . '<path d="' . livreos_icone($nome) . '"></path></svg>';
    }
}

if (!function_exists('livreos_modulos')) {
    /**
     * Estrutura: 'modulo' => [label, icone, home (aba inicial), items[chave => [rótulo, url, ícone]]]
     */
    function livreos_modulos(): array
    {
        return [
            'financeiro' => [
                'label' => 'Financeiro', 'icone' => 'chart', 'home' => 'lancamentos',
                'items' => [
                    'novo_lancamento' => ['Novo Lançamento',          '/modules/financeiro/novo_lancamento.php', 'plus'],
                    'lancamentos'     => ['Lançamentos Financeiros',  '/modules/financeiro/lancamentos.php',     'list'],
                    'dre'             => ['DRE Gerencial',            '/modules/financeiro/dre.php',             'chart'],
                    'boletos'         => ['Boletos',                  '/modules/financeiro/boletos.php',         'doc'],
                    'contratos'       => ['Contratos',                '/modules/financeiro/contratos.php',       'clip'],
                    'transferencias'  => ['Transferências Bancárias', '/modules/financeiro/transferencias.php',  'swap'],
                ],
            ],
            'vendas' => [
                'label' => 'Vendas', 'icone' => 'cart',
                'items' => [
                    'pedidos'    => ['Pedidos de Venda', '/modules/vendas/pedidos.php',    'cart'],
                    'orcamentos' => ['Orçamentos',       '/modules/vendas/orcamentos.php', 'doc'],
                ],
            ],
            'servicos' => [
                'label' => 'Serviços', 'icone' => 'wrench',
                'items' => [
                    'ordens_servico' => ['Ordens de Serviço', '/modules/servicos/ordens_servico.php', 'wrench'],
                    'contratos'      => ['Contratos',         '/modules/servicos/contratos.php',      'clip'],
                ],
            ],
            'fiscal' => [
                'label' => 'Fiscal', 'icone' => 'doc',
                'items' => [
                    'notas_fiscais' => ['Notas Fiscais',        '/modules/fiscal/notas_fiscais.php', 'doc'],
                    'config_fiscal' => ['Configuração Fiscal',  '/modules/fiscal/configuracao.php',  'cog'],
                ],
            ],
            'estoque' => [
                'label' => 'Estoque', 'icone' => 'box',
                'items' => [
                    'estoques'      => ['Estoques',                  '/modules/estoque/estoques.php',      'box'],
                    'movimentacoes' => ['Movimentações de Estoque',  '/modules/estoque/movimentacoes.php', 'swap'],
                    'inventario'    => ['Inventário',                '/modules/estoque/inventario.php',    'clip'],
                ],
            ],
            'crm' => [
                'label' => 'CRM', 'icone' => 'users',
                'items' => [
                    'funil'         => ['Funil de Vendas', '/modules/crm/funil.php',         'chart'],
                    'oportunidades' => ['Oportunidades',   '/modules/crm/oportunidades.php', 'tag'],
                ],
            ],
            'producao' => [
                'label' => 'Produção', 'icone' => 'cog',
                'items' => [
                    'ordens_producao' => ['Ordens de Produção', '/modules/producao/ordens.php', 'cog'],
                    'fichas_tecnicas' => ['Fichas Técnicas',    '/modules/producao/fichas.php', 'clip'],
                ],
            ],
            'compras' => [
                'label' => 'Compras', 'icone' => 'bag',
                'items' => [
                    'notas_compra'  => ['Notas de Compra',       '/modules/compras/notas_compra.php',  'doc'],
                    'ordens_compra' => ['Ordens de Compra',      '/modules/compras/ordens_compra.php', 'bag'],
                    'consulta_cnpj' => ['Consulta Notas CNPJ',   '/modules/compras/consulta_cnpj.php', 'search'],
                ],
            ],
            'rh' => [
                'label' => 'RH', 'icone' => 'rh',
                'items' => [
                    'colaboradores' => ['Colaboradores',        '/modules/rh/colaboradores.php', 'users'],
                    'folha'         => ['Folha de Pagamento',   '/modules/rh/folha.php',         'card'],
                ],
            ],
            'cadastros' => [
                'label' => 'Cadastros', 'icone' => 'monitor', 'home' => 'clientes',
                'items' => [
                    'clientes'        => ['Clientes',        '/modules/cadastros/clientes.php',        'users'],
                    'produtos'        => ['Produtos',        '/modules/cadastros/produtos.php',        'box'],
                    'fornecedores'    => ['Fornecedores',    '/modules/cadastros/fornecedores.php',    'truck'],
                    'transportadoras' => ['Transportadoras', '/modules/cadastros/transportadoras.php', 'truck'],
                    'vendedores'      => ['Vendedores',      '/modules/cadastros/vendedores.php',      'tag'],
                    'veiculos'        => ['Veículos',        '/modules/cadastros/veiculos.php',        'car'],
                ],
            ],
        ];
    }
}

if (!function_exists('livreos_modulo_url')) {
    /** URL de entrada de um módulo (aba "home" ou a primeira aba). */
    function livreos_modulo_url(array $modulo): string
    {
        $chave = $modulo['home'] ?? array_key_first($modulo['items']);
        return $modulo['items'][$chave][1] ?? '/dashboard.php';
    }
}