-- Multa indevida pode ser anulada pelo organizador (spec §8.4 "estornar multa"). MySQL 8 / cPanel.
-- Só multa pendente é cancelada; a pendência correspondente sai do saldo do jogador.
ALTER TABLE multas
    MODIFY status ENUM('pendente','paga','cancelada') NOT NULL DEFAULT 'pendente';
