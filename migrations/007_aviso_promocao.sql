-- Spec §6: e-mail de "confirmação de vaga (subiu da espera)". MySQL 8 / cPanel.
-- promovido_em marca quem subiu da espera; o cron envia e marca promocao_notificada.
ALTER TABLE inscricoes
    ADD COLUMN promovido_em DATETIME NULL AFTER desistiu_em,
    ADD COLUMN promocao_notificada TINYINT(1) NOT NULL DEFAULT 0 AFTER promovido_em;
