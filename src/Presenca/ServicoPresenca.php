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
            $this->travarRodada($rodadaId);
            $ctx = $this->contexto($rodadaId, $jogadorId);
            $atual = $this->statusAtual($rodadaId, $jogadorId);

            if ($atual === 'confirmado' || $atual === 'espera') {
                $this->pdo->commit();

                return; // já está dentro
            }

            $status = $this->temVaga($rodadaId, $ctx['tipo'], $ctx['limite']) ? 'confirmado' : 'espera';
            $quando = $agora->format('Y-m-d H:i:s');
            // Ordem de chegada: quem volta depois de desistir entra no fim da fila,
            // senão furaria a espera com a ordem antiga.
            $ordem = $this->proximaOrdem($rodadaId);

            if ($atual === 'desistiu') {
                $this->pdo->prepare(
                    'UPDATE inscricoes SET status = ?, ordem = ?, confirmado_em = ?, desistiu_em = NULL, promovido_em = NULL, promocao_notificada = 0
                     WHERE rodada_id = ? AND jogador_id = ?'
                )->execute([$status, $ordem, $quando, $rodadaId, $jogadorId]);
            } else {
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
        // Quem estava só na espera nunca teve vaga: sai da fila, sem virar "desistência"
        // (spec §3.3 — multa é para confirmado que desiste). Se voltar, entra no fim da fila.
        if ($this->statusAtual($rodadaId, $jogadorId) === 'espera') {
            $this->pdo->prepare("DELETE FROM inscricoes WHERE rodada_id = ? AND jogador_id = ? AND status = 'espera'")
                ->execute([$rodadaId, $jogadorId]);

            return;
        }

        $this->pdo->prepare(
            "UPDATE inscricoes SET status = 'desistiu', desistiu_em = ?
             WHERE rodada_id = ? AND jogador_id = ? AND status = 'confirmado'"
        )->execute([$agora->format('Y-m-d H:i:s'), $rodadaId, $jogadorId]);
    }

    /**
     * Serializa confirmações concorrentes da mesma rodada (MySQL/InnoDB): sem isso,
     * duas requisições simultâneas contam a mesma vaga livre e estouram o limite.
     * No SQLite (testes) a transação já é serializada pelo lock do arquivo.
     */
    private function travarRodada(int $rodadaId): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->pdo->prepare('SELECT id FROM rodadas WHERE id = ? FOR UPDATE')->execute([$rodadaId]);
        }
    }

    private function proximaOrdem(int $rodadaId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM inscricoes WHERE rodada_id = ?');
        $stmt->execute([$rodadaId]);

        return (int) $stmt->fetchColumn();
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
