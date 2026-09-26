<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;
use PDO;
use RcInfoti\Pelada\Financeiro\CalculadoraDevido;

final class RepositorioRodadaPdo implements RepositorioRodada
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function carregarEstado(int $rodadaId): EstadoRodada
    {
        $cab = $this->pdo->prepare(
            'SELECT r.vira_regra_em, r.prazo_multa_em, p.limite_linha, p.limite_goleiro
             FROM rodadas r JOIN peladas p ON p.id = r.pelada_id
             WHERE r.id = ?'
        );
        $cab->execute([$rodadaId]);
        $r = $cab->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            throw new \RuntimeException("Rodada {$rodadaId} não encontrada.");
        }

        $stmt = $this->pdo->prepare(
            'SELECT i.jogador_id, i.tipo, i.status, i.ordem, i.desistiu_em, i.multa_aplicada,
                    EXISTS(
                        SELECT 1 FROM pagamentos pg
                        WHERE pg.rodada_id = i.rodada_id AND pg.jogador_id = i.jogador_id AND pg.confirmado = 1
                    ) AS pagou
             FROM inscricoes i
             WHERE i.rodada_id = ?
             ORDER BY i.ordem'
        );
        $stmt->execute([$rodadaId]);

        $inscricoes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $inscricoes[] = new Inscricao(
                (int) $row['jogador_id'],
                $this->tipo($row['tipo']),
                $this->status($row['status']),
                (int) $row['ordem'],
                pagou: (bool) $row['pagou'],
                desistiuEm: $row['desistiu_em'] !== null ? new DateTimeImmutable($row['desistiu_em']) : null,
                multaAplicada: (bool) $row['multa_aplicada'],
            );
        }

        return new EstadoRodada(
            new DateTimeImmutable($r['vira_regra_em']),
            new DateTimeImmutable($r['prazo_multa_em']),
            (int) $r['limite_linha'],
            (int) $r['limite_goleiro'],
            $inscricoes,
        );
    }

    public function promover(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE inscricoes SET status = 'confirmado', confirmado_em = ?
             WHERE rodada_id = ? AND jogador_id = ?"
        );
        $stmt->execute([$agora->format('Y-m-d H:i:s'), $rodadaId, $jogadorId]);
    }

    public function aplicarMulta(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $dados = $this->pdo->prepare(
            'SELECT r.pelada_id, r.data_jogo, p.valor_futebol, p.valor_festa_semana,
                    j.tipo, j.festa_quitada_ano
             FROM rodadas r
             JOIN peladas p ON p.id = r.pelada_id
             JOIN jogadores j ON j.id = ?
             WHERE r.id = ?'
        );
        $dados->execute([$jogadorId, $rodadaId]);
        $d = $dados->fetch(PDO::FETCH_ASSOC);
        if ($d === false) {
            throw new \RuntimeException("Dados para multa não encontrados (rodada {$rodadaId}, jogador {$jogadorId}).");
        }

        $anoJogo = (int) (new DateTimeImmutable($d['data_jogo']))->format('Y');
        $festaQuitada = $d['festa_quitada_ano'] !== null && (int) $d['festa_quitada_ano'] === $anoJogo;

        $calc = new CalculadoraDevido((float) $d['valor_futebol'], (float) $d['valor_festa_semana']);
        $valor = $calc->devidoSemanal($this->tipo($d['tipo']), $festaQuitada);

        $this->pdo->beginTransaction();
        try {
            $insere = $this->pdo->prepare(
                "INSERT INTO multas (pelada_id, jogador_id, rodada_id, valor, status, motivo, criado_em)
                 VALUES (?, ?, ?, ?, 'pendente', 'desistência após prazo', ?)"
            );
            $insere->execute([
                (int) $d['pelada_id'],
                $jogadorId,
                $rodadaId,
                $valor,
                $agora->format('Y-m-d H:i:s'),
            ]);

            $this->pdo->prepare('UPDATE jogadores SET saldo_pendente = saldo_pendente + ? WHERE id = ?')
                ->execute([$valor, $jogadorId]);

            $this->pdo->prepare('UPDATE inscricoes SET multa_aplicada = 1 WHERE rodada_id = ? AND jogador_id = ?')
                ->execute([$rodadaId, $jogadorId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function tipo(string $valor): Tipo
    {
        return match ($valor) {
            'linha' => Tipo::Linha,
            'goleiro' => Tipo::Goleiro,
            default => throw new \InvalidArgumentException("Tipo inválido: {$valor}"),
        };
    }

    private function status(string $valor): StatusInscricao
    {
        return match ($valor) {
            'confirmado' => StatusInscricao::Confirmado,
            'espera' => StatusInscricao::Espera,
            'desistiu' => StatusInscricao::Desistiu,
            default => throw new \InvalidArgumentException("Status inválido: {$valor}"),
        };
    }
}
