USE livreos_erp;

-- Tabela Mestre: Cabeçalho de Orçamentos e Pedidos
CREATE TABLE IF NOT EXISTS vendas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    cliente_id INT UNSIGNED NOT NULL,
    vendedor_id INT UNSIGNED NULL,
    tipo ENUM('orcamento', 'pedido') NOT NULL DEFAULT 'pedido',
    status ENUM('rascunho', 'pendente', 'aprovado', 'faturado', 'cancelado') NOT NULL DEFAULT 'pendente',
    data_emissao DATE NOT NULL,
    data_validade DATE NULL,
    valor_bruto DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    valor_desconto DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    valor_liquido DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    condicao_pagamento VARCHAR(100) NULL,
    observacoes TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE RESTRICT,
    FOREIGN KEY (vendedor_id) REFERENCES vendedores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela Detalhe: Itens do Pedido/Orçamento
CREATE TABLE IF NOT EXISTS vendas_itens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venda_id INT UNSIGNED NOT NULL,
    produto_id INT UNSIGNED NOT NULL,
    quantidade DECIMAL(10,2) NOT NULL,
    valor_unitario DECIMAL(15,2) NOT NULL,
    subtotal DECIMAL(15,2) NOT NULL,
    FOREIGN KEY (venda_id) REFERENCES vendas(id) ON DELETE CASCADE,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões
INSERT IGNORE INTO permissoes (modulo, acao, descricao) VALUES
('vendas', 'ver', 'Visualizar vendas e orçamentos'),
('vendas', 'criar', 'Criar e editar vendas'),
('vendas', 'excluir', 'Excluir ou cancelar vendas'),
('vendas', 'faturar', 'Faturar pedido (gera financeiro e baixa estoque)');

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT 1, id FROM permissoes WHERE modulo = 'vendas';