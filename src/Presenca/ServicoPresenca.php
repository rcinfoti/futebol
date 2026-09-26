<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Presenca;

use DateTimeImmutable;
use PDO;

final class ServicoPresenca
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function confirmar(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $this->pdo->beginTransaction();
        try {
            $ctx = $this->contexto($rodadaId, $jogadorId);
            $atual = $this->statusAtual($rodadaId, $jogadorId);

            if ($atual === 'confirmado' || $atual === 'espera') {
                $this->pdo->commit();

                return; // já está dentro
            }

            $status = $this->temVaga($rodadaId, $ctx['tipo'], $ctx['limite']) ? 'confirmado' : 'espera';
            $quando = $agora->format('Y-m-d H:i:s');

            if ($atual === 'desistiu') {
                $this->pdo->prepare(
                    'UPDATE inscricoes SET status = ?, confirmado_em = ?, desistiu_em = NULL
                     WHERE rodada_id = ? AND jogador_id = ?'
                )->execute([$status, $quando, $rodadaId, $jogadorId]);
            } else {
                $ordem = (int) $this->pdo->query(
                    'SELECT COALESCE(MAX(ordem), 0) + 1 FROM inscricoes WHERE rodada_id = ' . $rodadaId
                )->fetchColumn();

                $this->pdo->prepare(
                    'INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem, confirmado_em)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$rodadaId, $jogadorId, $ctx['tipo'], $status, $ordem, $quando]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function desistir(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $this->pdo->prepare(
            "UPDATE inscricoes SET status = 'desistiu', desistiu_em = ?
             WHERE rodada_id = ? AND jogador_id = ?"
        )->execute([$agora->format('Y-m-d H:i:s'), $rodadaId, $jogadorId]);
    }

    /** @return array{tipo:string,limite:int} */
    private function contexto(int $rodadaId, int $jogadorId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.tipo, p.limite_linha, p.limite_goleiro
             FROM rodadas r
             JOIN peladas p ON p.id = r.pelada_id
             JOIN jogadores j ON j.id = ?
             WHERE r.id = ?'
        );
        $stmt->execute([$jogadorId, $rodadaId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            throw new \RuntimeException("Contexto de presença não encontrado (rodada {$rodadaId}, jogador {$jogadorId}).");
        }

        return [
            'tipo' => (string) $r['tipo'],
            'limite' => $r['tipo'] === 'goleiro' ? (int) $r['limite_goleiro'] : (int) $r['limite_linha'],
        ];
    }

    private function statusAtual(int $rodadaId, int $jogadorId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM inscricoes WHERE rodada_id = ? AND jogador_id = ?');
        $stmt->execute([$rodadaId, $jogadorId]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    private function temVaga(int $rodadaId, string $tipo, int $limite): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM inscricoes WHERE rodada_id = ? AND tipo = ? AND status = 'confirmado'"
        );
        $stmt->execute([$rodadaId, $tipo]);

        return (int) $stmt->fetchColumn() < $limite;
    }
}
