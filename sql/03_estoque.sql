USE livreos_erp;

-- Adiciona campos de custo e alerta na tabela de produtos
ALTER TABLE produtos 
ADD COLUMN IF NOT EXISTS preco_custo DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER sku,
ADD COLUMN IF NOT EXISTS estoque_minimo DECIMAL(10,2) NOT NULL DEFAULT 5.00 AFTER estoque_atual;

-- Cria a tabela de Histórico de Movimentações de Estoque
CREATE TABLE IF NOT EXISTS estoque_movimentacoes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    produto_id INT UNSIGNED NOT NULL,
    tipo ENUM('entrada', 'saida', 'ajuste') NOT NULL,
    quantidade DECIMAL(10,2) NOT NULL,
    custo_unitario DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    motivo VARCHAR(100) NOT NULL,
    observacoes TEXT NULL,
    data_movimentacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões do módulo
INSERT IGNORE INTO permissoes (modulo, acao, descricao) VALUES
('estoque', 'ver', 'Ver posição de estoque'),
('estoque', 'editar', 'Ajustar e movimentar estoque');

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT 1, id FROM permissoes WHERE modulo = 'estoque';