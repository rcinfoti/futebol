-- Ciclo da rodada + notificação/quitação de multas (MySQL 8 / cPanel).
ALTER TABLE rodadas
    ADD UNIQUE KEY uk_rodada_pelada_data (pelada_id, data_jogo);

ALTER TABLE multas
    ADD COLUMN email_enviado TINYINT(1) NOT NULL DEFAULT 0 AFTER quitado_em;
