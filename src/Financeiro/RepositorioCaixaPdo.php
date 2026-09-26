<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use DateTimeImmutable;
use PDO;

final class RepositorioCaixaPdo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lancarSaida(
        int $peladaId,
        float $valor,
        string $categoria,
        string $descricao,
        DateTimeImmutable $ocorridoEm,
        DateTimeImmutable $agora,
    ): int {
        $this->pdo->prepare(
            "INSERT INTO movimentos_caixa
                (pelada_id, tipo, categoria, valor, descricao, pagamento_id, ocorrido_em, criado_em)
             VALUES (?, 'saida', ?, ?, ?, NULL, ?, ?)"
        )->execute([
            $peladaId,
            $categoria,
            $valor,
            $descricao,
            $ocorridoEm->format('Y-m-d H:i:s'),
            $agora->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
