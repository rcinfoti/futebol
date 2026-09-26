-- Schema de produção (MySQL 8 / cPanel). Aplicar via phpMyAdmin ou linha de comando.
CREATE TABLE peladas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    dia_jogo TINYINT NOT NULL,
    hora_jogo TIME NOT NULL,
    abre_dia TINYINT NOT NULL,
    abre_hora TIME NOT NULL,
    vira_regra_dia TINYINT NOT NULL,
    vira_regra_hora TIME NOT NULL,
    prazo_multa_dia TINYINT NOT NULL,
    prazo_multa_hora TIME NOT NULL,
    limite_linha INT NOT NULL DEFAULT 20,
    limite_goleiro INT NOT NULL DEFAULT 4,
    valor_futebol DECIMAL(10,2) NOT NULL DEFAULT 15.00,
    valor_festa_semana DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    valor_festa_ano DECIMAL(10,2) NOT NULL DEFAULT 220.00,
    festa_inicio DATE NULL,
    festa_fim DATE NULL,
    ativa TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_peladas_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE jogadores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pelada_id INT UNSIGNED NOT NULL,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(160) NULL,
    telefone VARCHAR(40) NULL,
    tipo ENUM('linha','goleiro') NOT NULL DEFAULT 'linha',
    pin_hash VARCHAR(255) NULL,
    saldo_pendente DECIMAL(10,2) NOT NULL DEFAULT 0,
    festa_quitada_ano SMALLINT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_jogadores_pelada (pelada_id),
    CONSTRAINT fk_jogadores_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rodadas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pelada_id INT UNSIGNED NOT NULL,
    data_jogo DATE NOT NULL,
    status ENUM('aberta','fechada','encerrada') NOT NULL DEFAULT 'aberta',
    abre_em DATETIME NULL,
    vira_regra_em DATETIME NOT NULL,
    prazo_multa_em DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rodadas_pelada (pelada_id),
    CONSTRAINT fk_rodadas_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inscricoes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rodada_id INT UNSIGNED NOT NULL,
    jogador_id INT UNSIGNED NOT NULL,
    tipo ENUM('linha','goleiro') NOT NULL,
    status ENUM('confirmado','espera','desistiu') NOT NULL,
    ordem INT NOT NULL,
    confirmado_em DATETIME NULL,
    desistiu_em DATETIME NULL,
    multa_aplicada TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_inscricao (rodada_id, jogador_id),
    KEY idx_inscricoes_rodada (rodada_id),
    KEY idx_inscricoes_jogador (jogador_id),
    CONSTRAINT fk_inscricoes_rodada FOREIGN KEY (rodada_id) REFERENCES rodadas(id),
    CONSTRAINT fk_inscricoes_jogador FOREIGN KEY (jogador_id) REFERENCES jogadores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE multas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pelada_id INT UNSIGNED NOT NULL,
    jogador_id INT UNSIGNED NOT NULL,
    rodada_id INT UNSIGNED NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    status ENUM('pendente','paga') NOT NULL DEFAULT 'pendente',
    motivo VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL,
    quitado_em DATETIME NULL,
    KEY idx_multas_jogador (jogador_id),
    CONSTRAINT fk_multas_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id),
    CONSTRAINT fk_multas_jogador FOREIGN KEY (jogador_id) REFERENCES jogadores(id),
    CONSTRAINT fk_multas_rodada FOREIGN KEY (rodada_id) REFERENCES rodadas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pagamentos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pelada_id INT UNSIGNED NOT NULL,
    jogador_id INT UNSIGNED NOT NULL,
    rodada_id INT UNSIGNED NULL,
    categoria ENUM('futebol','festa') NOT NULL,
    escopo ENUM('semana','ano') NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    forma ENUM('pix','dinheiro') NOT NULL,
    comprovante_arquivo VARCHAR(255) NULL,
    confirmado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL,
    KEY idx_pagamentos_jogador (jogador_id),
    KEY idx_pagamentos_rodada (rodada_id),
    CONSTRAINT fk_pagamentos_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id),
    CONSTRAINT fk_pagamentos_jogador FOREIGN KEY (jogador_id) REFERENCES jogadores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
