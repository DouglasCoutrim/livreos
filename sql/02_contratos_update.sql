-- Caminho: /sql/02_contratos_update.sql
USE livreos_erp;

CREATE TABLE IF NOT EXISTS contratos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    cliente_nome VARCHAR(120) NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    valor_mensal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    data_inicio DATE NOT NULL,
    data_fim DATE NULL,
    dia_vencimento TINYINT UNSIGNED NOT NULL DEFAULT 10,
    status ENUM('ativo', 'suspenso', 'cancelado', 'encerrado') NOT NULL DEFAULT 'ativo',
    observacoes TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;