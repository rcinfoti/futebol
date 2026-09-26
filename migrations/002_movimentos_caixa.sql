-- Caixa: fonte única da verdade do saldo (MySQL 8 / cPanel).
-- Entradas nascem de pagamentos confirmados (pagamento_id UNIQUE => sem dupla contagem);
-- saídas (gastos) têm pagamento_id NULL.
CREATE TABLE movimentos_caixa (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pelada_id INT UNSIGNED NOT NULL,
    tipo ENUM('entrada','saida') NOT NULL,
    categoria VARCHAR(60) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    descricao VARCHAR(255) NULL,
    pagamento_id INT UNSIGNED NULL,
    ocorrido_em DATETIME NOT NULL,
    criado_em DATETIME NOT NULL,
    UNIQUE KEY uk_mov_pagamento (pagamento_id),
    KEY idx_mov_pelada (pelada_id),
    CONSTRAINT fk_mov_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id),
    CONSTRAINT fk_mov_pagamento FOREIGN KEY (pagamento_id) REFERENCES pagamentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
