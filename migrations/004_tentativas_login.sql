-- Rate limit do login por PIN (spec §5). MySQL 8 / cPanel.
-- Um PIN de 4 dígitos sem limite cai em força bruta; bloqueia o jogador por um tempo
-- após N falhas seguidas. Acerto zera o contador.
CREATE TABLE tentativas_login (
    jogador_id INT UNSIGNED NOT NULL PRIMARY KEY,
    falhas INT NOT NULL DEFAULT 0,
    bloqueado_ate DATETIME NULL,
    CONSTRAINT fk_tentativas_jogador FOREIGN KEY (jogador_id) REFERENCES jogadores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
