<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Multa;

use DateTimeImmutable;
use PDO;

final class RepositorioMultaPdo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function quitar(int $multaId, DateTimeImmutable $agora): void
    {
        $this->pdo->beginTransaction();
        try {
            // Reivindica a multa de forma atômica; sem linhas afetadas (já paga ou
            // inexistente) => no-op idempotente.
            $marca = $this->pdo->prepare(
                "UPDATE multas SET status = 'paga', quitado_em = ? WHERE id = ? AND status = 'pendente'"
            );
            $marca->execute([$agora->format('Y-m-d H:i:s'), $multaId]);
            if ($marca->rowCount() === 0) {
                $this->pdo->commit();

                return;
            }

            $busca = $this->pdo->prepare('SELECT jogador_id, valor FROM multas WHERE id = ?');
            $busca->execute([$multaId]);
            $m = $busca->fetch(PDO::FETCH_ASSOC);

            // Reduz o saldo pendente sem deixar negativo (clamp em PHP: GREATEST não
            // existe no SQLite e MAX(2 args) é agregação no MySQL).
            $saldoStmt = $this->pdo->prepare('SELECT saldo_pendente FROM jogadores WHERE id = ?');
            $saldoStmt->execute([(int) $m['jogador_id']]);
            $saldoAtual = (float) $saldoStmt->fetchColumn();
            $novo = max(0.0, $saldoAtual - (float) $m['valor']);

            $this->pdo->prepare('UPDATE jogadores SET saldo_pendente = ? WHERE id = ?')
                ->execute([$novo, (int) $m['jogador_id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
