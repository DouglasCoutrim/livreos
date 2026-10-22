-- =====================================================================
-- Backup / Schema Completo do Banco de Dados - LivreOS ERP
-- =====================================================================

CREATE DATABASE IF NOT EXISTS livreos_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE livreos_erp;

-- 1. Tabela de Empresas (Tenant Principal)
CREATE TABLE IF NOT EXISTS empresas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    razao_social VARCHAR(150) NOT NULL,
    cnpj VARCHAR(18) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresas (id, razao_social, cnpj) VALUES (1, 'Minha Loja de Comércio, Serviços e Distribuição', '00.000.000/0001-00')
ON DUPLICATE KEY UPDATE id=id;

-- 2. Contas Financeiras / Caixas / Bancos
CREATE TABLE IF NOT EXISTS contas_financeiras (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nome VARCHAR(100) NOT NULL,
    saldo_atual DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO contas_financeiras (id, empresa_id, nome, saldo_atual) VALUES 
(1, 1, 'Caixa Geral', 1500.00),
(2, 1, 'Banco Principal (Conta Corrente)', 10000.00)
ON DUPLICATE KEY UPDATE id=id;

-- 3. Categorias Financeiras (Plano de Contas)
CREATE TABLE IF NOT EXISTS categorias_financeiras (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    tipo ENUM('receita', 'despesa') NOT NULL,
    nome VARCHAR(100) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categorias_financeiras (id, empresa_id, tipo, nome) VALUES
(1, 1, 'receita', 'Venda de Mercadorias'),
(2, 1, 'receita', 'Prestação de Serviços'),
(3, 1, 'receita', 'Outras Receitas'),
(4, 1, 'despesa', 'Fornecedores / Insumos'),
(5, 1, 'despesa', 'Despesas Operacionais (Água, Luz, Internet)'),
(6, 1, 'despesa', 'Folha de Pagamento e Encargos'),
(7, 1, 'despesa', 'Impostos e Taxas')
ON DUPLICATE KEY UPDATE id=id;

-- 4. Clientes
CREATE TABLE IF NOT EXISTS clientes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nome_razao VARCHAR(150) NOT NULL,
    cpf_cnpj VARCHAR(18) NULL,
    email VARCHAR(120) NULL,
    telefone VARCHAR(20) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Produtos
CREATE TABLE IF NOT EXISTS produtos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nome VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NULL,
    preco_venda DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    estoque_atual DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Fornecedores
CREATE TABLE IF NOT EXISTS fornecedores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nome_razao VARCHAR(150) NOT NULL,
    cnpj VARCHAR(18) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Transportadoras
CREATE TABLE IF NOT EXISTS transportadoras (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    tipo_pessoa ENUM('PF', 'PJ') NOT NULL DEFAULT 'PJ',
    cpf_cnpj VARCHAR(18) NOT NULL,
    nome_razao VARCHAR(150) NOT NULL,
    nome_fantasia VARCHAR(150) NULL,
    inscricao_estadual VARCHAR(30) NULL,
    email VARCHAR(120) NULL,
    telefone VARCHAR(20) NULL,
    celular VARCHAR(20) NULL,
    cep VARCHAR(10) NULL,
    logradouro VARCHAR(150) NULL,
    numero VARCHAR(20) NULL,
    bairro VARCHAR(80) NULL,
    cidade VARCHAR(100) NULL,
    uf CHAR(2) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Vendedores
CREATE TABLE IF NOT EXISTS vendedores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nome_fantasia VARCHAR(150) NOT NULL,
    cpf VARCHAR(14) NULL,
    tipo_pessoa ENUM('PF', 'PJ') NOT NULL DEFAULT 'PF',
    cep VARCHAR(10) NULL,
    rua VARCHAR(150) NULL,
    numero VARCHAR(20) NULL,
    complemento VARCHAR(80) NULL,
    bairro VARCHAR(80) NULL,
    estado VARCHAR(2) NULL,
    cidade VARCHAR(100) NULL,
    pais VARCHAR(60) NOT NULL DEFAULT 'Brasil',
    fone_comercial VARCHAR(20) NULL,
    telefone VARCHAR(20) NULL,
    site VARCHAR(150) NULL,
    email VARCHAR(120) NULL,
    email_comercial VARCHAR(120) NULL,
    email_financeiro VARCHAR(120) NULL,
    contato VARCHAR(100) NULL,
    contato_2 VARCHAR(100) NULL,
    contato_3 VARCHAR(100) NULL,
    tipo_comissao VARCHAR(50) NULL,
    percentual DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    valor DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    dia_comissao VARCHAR(30) NULL,
    meta_diaria DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    meta_mensal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Veículos
CREATE TABLE IF NOT EXISTS veiculos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    placa VARCHAR(10) NOT NULL,
    marca_modelo VARCHAR(150) NOT NULL,
    ano VARCHAR(4) NULL,
    renavam VARCHAR(20) NULL,
    tipo VARCHAR(50) NULL,
    motorista_padrao VARCHAR(120) NULL,
    observacoes TEXT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Lançamentos Financeiros
CREATE TABLE IF NOT EXISTS lancamentos_financeiros (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    conta_id INT UNSIGNED NOT NULL,
    categoria_id INT UNSIGNED NULL,
    cliente_fornecedor VARCHAR(150) NOT NULL,
    banco VARCHAR(100) NULL,
    tipo ENUM('receita', 'despesa') NOT NULL DEFAULT 'receita',
    valor DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    competencia DATE NOT NULL,
    data_vencimento DATE NOT NULL,
    data_quitacao DATE NULL,
    forma_pagamento VARCHAR(50) NULL,
    centro_custo VARCHAR(100) NULL,
    pago TINYINT(1) NOT NULL DEFAULT 0,
    desconsiderar_multa_juros TINYINT(1) NOT NULL DEFAULT 0,
    documento VARCHAR(50) NULL,
    multa_val DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    juros_val DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    mora_diaria_perc DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    desconto_val DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    desconto_perc DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    a_pagar DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_val DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    observacoes TEXT NULL,
    gerar_recorrencia TINYINT(1) NOT NULL DEFAULT 0,
    gerar_parcelamento TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pendente', 'pago', 'atrasado', 'cancelado') NOT NULL DEFAULT 'pendente',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (conta_id) REFERENCES contas_financeiras(id) ON DELETE RESTRICT,
    FOREIGN KEY (categoria_id) REFERENCES categorias_financeiras(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Boletos
CREATE TABLE IF NOT EXISTS boletos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    banco VARCHAR(100) NOT NULL,
    empresa_emissora VARCHAR(150) NOT NULL,
    cliente_nome VARCHAR(150) NOT NULL,
    cpf_cnpj VARCHAR(18) NOT NULL,
    nome_sacado VARCHAR(150) NOT NULL,
    observacoes TEXT NULL,
    logradouro VARCHAR(150) NOT NULL,
    numero VARCHAR(20) NOT NULL,
    bairro VARCHAR(80) NOT NULL,
    cidade VARCHAR(100) NOT NULL,
    uf CHAR(2) NOT NULL,
    cep VARCHAR(10) NOT NULL,
    data_emissao DATE NOT NULL,
    data_vencimento DATE NOT NULL,
    valor_boleto DECIMAL(15,2) NOT NULL,
    nosso_numero VARCHAR(30) NULL,
    numero_documento VARCHAR(30) NULL,
    data_desconto DATE NULL,
    valor_desconto DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    data_multa DATE NULL,
    valor_multa DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    percentual_multa DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    data_juros DATE NULL,
    valor_juros DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    percentual_juros DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    status ENUM('gerado', 'registrado', 'pago', 'cancelado') NOT NULL DEFAULT 'gerado',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Contratos
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

-- 13. Transferências Bancárias
CREATE TABLE IF NOT EXISTS transferencias_bancarias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    conta_origem_id INT UNSIGNED NOT NULL,
    conta_destino_id INT UNSIGNED NOT NULL,
    valor DECIMAL(15,2) NOT NULL,
    data_transferencia DATE NOT NULL,
    observacoes TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (conta_origem_id) REFERENCES contas_financeiras(id) ON DELETE RESTRICT,
    FOREIGN KEY (conta_destino_id) REFERENCES contas_financeiras(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;