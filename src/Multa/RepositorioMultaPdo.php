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

            // Reduz o saldo pendente de forma atômica e sem deixar negativo. O CASE
            // (portável MySQL/SQLite) faz a subtração e o clamp na própria escrita,
            // como o `saldo_pendente = saldo_pendente + ?` de aplicarMulta — assim
            // dois quitar concorrentes do mesmo jogador não sobrescrevem um ao outro.
            $this->pdo->prepare(
                'UPDATE jogadores
                    SET saldo_pendente = CASE WHEN saldo_pendente < ? THEN 0 ELSE saldo_pendente - ? END
                  WHERE id = ?'
            )->execute([(float) $m['valor'], (float) $m['valor'], (int) $m['jogador_id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
