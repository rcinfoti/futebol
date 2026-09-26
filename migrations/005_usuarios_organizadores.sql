-- Organizadores e super admin (spec §2 e §4). MySQL 8 / cPanel.
-- Estavam na spec mas faltavam no schema 001.
CREATE TABLE usuarios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    papel ENUM('super_admin','organizador') NOT NULL DEFAULT 'organizador',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    falhas_login INT NOT NULL DEFAULT 0,
    bloqueado_ate DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pelada_organizadores (
    pelada_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (pelada_id, usuario_id),
    KEY idx_org_usuario (usuario_id),
    CONSTRAINT fk_org_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id) ON DELETE CASCADE,
    CONSTRAINT fk_org_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nome único por pelada: o login do jogador é por seletor de nome.
ALTER TABLE jogadores ADD UNIQUE KEY uk_jogador_pelada_nome (pelada_id, nome);
