<?php
// Caminho: /includes/sidebar.php
declare(strict_types=1);

$menus = [
    'financeiro' => [
        'label' => 'Financeiro',
        'svg'   => '<path d="M5 20V10M12 20V4M19 20v-7"></path>',
        'items' => [
            'novo_lancamento'       => ['Novo Lançamento', '/modules/financeiro/novo_lancamento.php'],
            'lancamentos'           => ['Lançamentos Financeiros', '/modules/financeiro/lancamentos.php'],
            'dre'                   => ['DRE Gerencial', '/modules/financeiro/dre.php'],
            'boletos'               => ['Boletos', '/modules/financeiro/boletos.php'],
            'contratos'             => ['Contratos', '/modules/financeiro/contratos.php'],
            'transferencias'        => ['Transferências Bancárias', '/modules/financeiro/transferencias.php'],
        ],
    ],
    'vendas' => [
        'label' => 'Vendas',
        'svg'   => '<path d="M3 4h2l2 12h11l2-8H6"></path>',
        'items' => [
            'pedidos'    => ['Pedidos de Venda', '/modules/vendas/pedidos.php'],
            'orcamentos' => ['Orçamentos', '/modules/vendas/orcamentos.php'],
        ],
    ],
    'servicos' => [
        'label' => 'Serviços',
        'svg'   => '<path d="M14 6l4 4-8 8H6v-4z"></path>',
        'items' => [
            'ordens_servico' => ['Ordens de Serviço', '/modules/servicos/ordens_servico.php'],
            'contratos'      => ['Contratos', '/modules/servicos/contratos.php'],
        ],
    ],
    'fiscal' => [
        'label' => 'Fiscal',
        'svg'   => '<path d="M6 3h8l4 4v14H6z"></path>',
        'items' => [
            'notas_fiscais' => ['Notas Fiscais', '/modules/fiscal/notas_fiscais.php'],
            'config_fiscal' => ['Configuração Fiscal', '/modules/fiscal/configuracao.php'],
        ],
    ],
    'estoque' => [
        'label' => 'Estoque',
        'svg'   => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"></path>',
        'items' => [
            'estoques'       => ['Estoques', '/modules/estoque/estoques.php'],
            'movimentacoes'  => ['Movimentações de Estoque', '/modules/estoque/movimentacoes.php'],
            'inventario'     => ['Inventário', '/modules/estoque/inventario.php'],
        ],
    ],
    'crm' => [
        'label' => 'CRM',
        'svg'   => '<path d="M4 20c0-4 4-6 8-6s8 2 8 6M12 4a4 4 0 100 8 4 4 0 000-8"></path>',
        'items' => [
            'funil'         => ['Funil de Vendas', '/modules/crm/funil.php'],
            'oportunidades' => ['Oportunidades', '/modules/crm/oportunidades.php'],
        ],
    ],
    'producao' => [
        'label' => 'Produção',
        'svg'   => '<path d="M12 8a4 4 0 100 8 4 4 0 000-8M12 2v3M12 19v3M2 12h3M19 12h3"></path>',
        'items' => [
            'ordens_producao' => ['Ordens de Produção', '/modules/producao/ordens.php'],
            'fichas_tecnicas' => ['Fichas Técnicas', '/modules/producao/fichas.php'],
        ],
    ],
    'compras' => [
        'label' => 'Compras',
        'svg'   => '<path d="M5 8h14l-1 13H6zM9 8a3 3 0 016 0"></path>',
        'items' => [
            'notas_compra'  => ['Notas de Compra', '/modules/compras/notas_compra.php'],
            'ordens_compra' => ['Ordens de Compra', '/modules/compras/ordens_compra.php'],
            'consulta_cnpj' => ['Consulta Notas CNPJ', '/modules/compras/consulta_cnpj.php'],
        ],
    ],
    'rh' => [
        'label' => 'RH',
        'svg'   => '<path d="M3 5h18v14H3zM13 10h5M13 14h5"></path>',
        'items' => [
            'colaboradores' => ['Colaboradores', '/modules/rh/colaboradores.php'],
            'folha'         => ['Folha de Pagamento', '/modules/rh/folha.php'],
        ],
    ],
    'cadastros' => [
        'label' => 'Cadastros',
        'svg'   => '<path d="M3 4h18v12H3zM8 21h8"></path>',
        'items' => [
            'clientes'        => ['Clientes', '/modules/cadastros/clientes.php'],
            'produtos'        => ['Produtos', '/modules/cadastros/produtos.php'],
            'fornecedores'    => ['Fornecedores', '/modules/cadastros/fornecedores.php'],
            'transportadoras' => ['Transportadoras', '/modules/cadastros/transportadoras.php'],
            'vendedores'      => ['Vendedores', '/modules/cadastros/vendedores.php'],
            'veiculos'        => ['Veículos', '/modules/cadastros/veiculos.php'],
        ],
    ],
];
?>
<aside id="appSidebar" class="sb">
    <div style="padding:0 10px 18px">
        <a href="<?= BASE_URL ?>/dashboard.php" style="display:inline-block">
            <img src="<?= BASE_URL ?>/assets/livreos-logo.png" alt="LivreOS" class="brand-logo" style="height:56px;width:auto;max-width:100%;object-fit:contain;object-position:left">
        </a>
    </div>

    <a class="ni <?= ($activeModule === 'dashboard') ? 'on' : '' ?>" href="<?= BASE_URL ?>/dashboard.php">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 11l9-8 9 8v10H3z"></path>
        </svg>
        <span style="flex:1">Dashboard</span>
    </a>

    <?php foreach ($menus as $modKey => $modData):
        $isOpen = ($activeModule === $modKey);
    ?>
        <div class="nav-group <?= $isOpen ? 'open' : '' ?>">
            <button type="button" class="ni <?= $isOpen ? 'on' : '' ?>" data-toggle="submenu">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <?= $modData['svg'] ?>
                </svg>
                <span style="flex:1"><?= Security::e($modData['label']) ?></span>
                <svg class="chev" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M6 9l6 6 6-6"></path>
                </svg>
            </button>
            <div class="submenu">
                <?php foreach ($modData['items'] as $subKey => $subInfo): ?>
                    <a href="<?= BASE_URL . $subInfo[1] ?>" class="sub-ni <?= ($isOpen && $activeSub === $subKey) ? 'on' : '' ?>">
                        <?= Security::e($subInfo[0]) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</aside>