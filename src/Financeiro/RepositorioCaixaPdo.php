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

    /** Entrada avulsa (saldo inicial, doação, ajuste) — sem pagamento de origem. */
    public function lancarEntrada(
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
             VALUES (?, 'entrada', ?, ?, ?, NULL, ?, ?)"
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

    /**
     * Apaga um lançamento feito à mão (gasto ou entrada avulsa) digitado errado.
     * Entradas que nascem de pagamento confirmado ou de multa recebida NÃO são apagáveis
     * aqui: elas têm origem própria e apagar só o caixa deixaria os registros incoerentes.
     */
    public function excluirManual(int $peladaId, int $movimentoId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM movimentos_caixa
             WHERE id = ? AND pelada_id = ? AND pagamento_id IS NULL AND categoria NOT IN ('pagamento', 'multa')"
        );
        $stmt->execute([$movimentoId, $peladaId]);

        return $stmt->rowCount() > 0;
    }

    public function saldo(int $peladaId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN tipo = 'saida'   THEN valor ELSE 0 END), 0) AS saldo
             FROM movimentos_caixa
             WHERE pelada_id = ?"
        );
        $stmt->execute([$peladaId]);

        return (float) $stmt->fetchColumn();
    }

    /** @return list<MovimentoCaixa> */
    public function extrato(int $peladaId, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tipo, categoria, valor, descricao, pagamento_id, ocorrido_em
             FROM movimentos_caixa
             WHERE pelada_id = ? AND ocorrido_em >= ? AND ocorrido_em <= ?
             ORDER BY ocorrido_em, id'
        );
        $stmt->execute([
            $peladaId,
            $inicio->format('Y-m-d H:i:s'),
            $fim->format('Y-m-d H:i:s'),
        ]);

        $movimentos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $movimentos[] = new MovimentoCaixa(
                (int) $r['id'],
                $r['tipo'] === 'entrada' ? TipoMovimento::Entrada : TipoMovimento::Saida,
                (string) $r['categoria'],
                (float) $r['valor'],
                $r['descricao'] !== null ? (string) $r['descricao'] : null,
                $r['pagamento_id'] !== null ? (int) $r['pagamento_id'] : null,
                new DateTimeImmutable($r['ocorrido_em']),
            );
        }

        return $movimentos;
    }
}
