-- Caminho: /sql/01_base_schema.sql
CREATE DATABASE IF NOT EXISTS livreos_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE livreos_erp;

-- 1. Tabela de Empresas (SaaS Multiempresa)
CREATE TABLE empresas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    razao_social VARCHAR(150) NOT NULL,
    nome_fantasia VARCHAR(150) NOT NULL,
    cnpj VARCHAR(18) NOT NULL UNIQUE,
    email VARCHAR(120) NULL,
    telefone VARCHAR(20) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Tabela de Perfis de Acesso
CREATE TABLE perfis (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nome VARCHAR(60) NOT NULL,
    descricao VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Tabela de Permissões do Sistema
CREATE TABLE permissoes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(80) NOT NULL UNIQUE,
    modulo VARCHAR(50) NOT NULL,
    descricao VARCHAR(150) NOT NULL
) ENGINE=InnoDB;

-- 4. Relação Perfil x Permissões
CREATE TABLE perfil_permissoes (
    perfil_id INT UNSIGNED NOT NULL,
    permissao_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (perfil_id, permissao_id),
    FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
    FOREIGN KEY (permissao_id) REFERENCES permissoes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Tabela de Usuários
CREATE TABLE usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    perfil_id INT UNSIGNED NOT NULL,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    tentativas_login INT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_ate DATETIME NULL,
    ultimo_login DATETIME NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 6. Tabela de Logs de Auditoria
CREATE TABLE logs_auditoria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NULL,
    acao VARCHAR(80) NOT NULL,
    modulo VARCHAR(50) NOT NULL,
    detalhes TEXT NULL,
    ip VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_empresa_data (empresa_id, criado_em),
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ======================================================================
-- TABELAS BASE PARA ALIMENTAR O DASHBOARD COM DADOS REAIS DO MYSQL
-- (Serão expandidas quando construirmos cada módulo específico)
-- ======================================================================

CREATE TABLE contas_financeiras (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nome VARCHAR(100) NOT NULL,
    saldo_atual DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE lancamentos_financeiros (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    conta_id INT UNSIGNED NOT NULL,
    pessoa_nome VARCHAR(120) NOT NULL,
    descricao VARCHAR(200) NOT NULL,
    tipo ENUM('receita', 'despesa') NOT NULL,
    valor DECIMAL(15,2) NOT NULL,
    data_vencimento DATE NOT NULL,
    data_pagamento DATE NULL,
    status ENUM('pendente', 'pago', 'atrasado', 'cancelado') NOT NULL DEFAULT 'pendente',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fin_empresa_venc (empresa_id, data_vencimento),
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (conta_id) REFERENCES contas_financeiras(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE crm_oportunidades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    cliente_nome VARCHAR(120) NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    valor DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    etapa ENUM('prospeccao', 'negociacao', 'ganha', 'perdida') NOT NULL DEFAULT 'prospeccao',
    data_fechamento DATE NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE vendas_resumo_produtos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    produto_nome VARCHAR(150) NOT NULL,
    qtd_vendida INT UNSIGNED NOT NULL DEFAULT 0,
    valor_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pedidos_venda (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    cliente_nome VARCHAR(150) NOT NULL,
    valor_total DECIMAL(15,2) NOT NULL,
    status ENUM('orcamento', 'faturado', 'cancelado') NOT NULL DEFAULT 'faturado',
    data_pedido DATE NOT NULL,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ======================================================================
-- DADOS INICIAIS (SEED)
-- Senha padrão do admin: Admin@123
-- ======================================================================

INSERT INTO empresas (id, razao_social, nome_fantasia, cnpj, email, telefone) VALUES
(1, 'LivreOS Tecnologia e Gestão Ltda', 'LivreOS Demo', '12.345.678/0001-99', 'contato@livreos.com.br', '(11) 99999-9999');

INSERT INTO perfis (id, empresa_id, nome, descricao) VALUES
(1, 1, 'Administrador', 'Acesso total a todos os módulos do sistema'),
(2, 1, 'Gerente', 'Acesso gerencial a relatórios e operações'),
(3, 1, 'Operador', 'Acesso operacional às rotinas diárias');

INSERT INTO permissoes (id, chave, modulo, descricao) VALUES
(1, 'dashboard.ver', 'Dashboard', 'Visualizar Dashboard'),
(2, 'financeiro.ver', 'Financeiro', 'Visualizar Financeiro'),
(3, 'financeiro.criar', 'Financeiro', 'Criar Lançamentos Financeiros'),
(4, 'vendas.ver', 'Vendas', 'Visualizar Vendas'),
(5, 'estoque.ver', 'Estoque', 'Visualizar Estoque'),
(6, 'cadastros.ver', 'Cadastros', 'Visualizar Cadastros');

INSERT INTO perfil_permissoes (perfil_id, permissao_id) VALUES
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6);

-- Hash bcrypt para "Admin@123"
INSERT INTO usuarios (id, empresa_id, perfil_id, nome, email, senha_hash) VALUES
(1, 1, 1, 'Usuário Admin', 'admin@livreos.com.br', '$2y$10$wK1e2H7vI9n0M2qU5rY4uO6zX8cB3nV1mL9kP4jH2gF6dS0aQ7wE.');

-- Dados espelhando exatamente a referência para validação imediata do Dashboard
INSERT INTO contas_financeiras (empresa_id, nome, saldo_atual) VALUES
(1, 'Banco da Empresa', 159.00);

INSERT INTO lancamentos_financeiros (empresa_id, conta_id, pessoa_nome, descricao, tipo, valor, data_vencimento, data_pagamento, status) VALUES
(1, 1, 'Cliente exemplo', 'Venda de Produtos #101', 'receita', 159.00, '2026-10-16', '2026-10-16', 'pago'),
(1, 1, 'Cliente exemplo 1', 'Fatura Mensal #01', 'receita', 900.00, '2026-12-03', NULL, 'atrasado'),
(1, 1, 'Fornecedor exemplo', 'Compra de Insumos', 'despesa', 500.00, '2026-12-05', NULL, 'atrasado'),
(1, 1, 'Cliente exemplo 2', 'Serviço Avulso', 'receita', 350.00, '2026-12-07', NULL, 'atrasado');

INSERT INTO pedidos_venda (empresa_id, cliente_nome, valor_total, status, data_pedido) VALUES
(1, 'Cliente exemplo', 5500.00, 'faturado', CURDATE()),
(1, 'Cliente exemplo', 101.00, 'faturado', CURDATE());

INSERT INTO crm_oportunidades (empresa_id, cliente_nome, titulo, valor, etapa) VALUES
(1, 'Lead Alpha', 'Implantação ERP', 0.00, 'prospeccao'),
(1, 'Cliente exemplo', 'Expansão Filial', 500.00, 'negociacao'),
(1, 'Cliente Beta', 'Consultoria', 0.00, 'negociacao');

INSERT INTO vendas_resumo_produtos (empresa_id, produto_nome, qtd_vendida, valor_total) VALUES
(1, 'Produto exemplo A', 11, 5500.00),
(1, 'Produto exemplo B', 2, 100.00),
(1, 'Produto exemplo C', 1, 1.00);