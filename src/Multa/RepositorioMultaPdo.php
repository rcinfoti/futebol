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

    /**
     * Baixa a multa sem movimentar dinheiro (usado quando a quitação já foi contabilizada
     * por outro caminho). Para dinheiro recebido pelo organizador, use {@see receber()}.
     */
    public function quitar(int $multaId, DateTimeImmutable $agora): void
    {
        $this->baixar($multaId, null, 'paga', $agora, lancarNoCaixa: false);
    }

    /**
     * Organizador recebeu o valor da multa: quita, reduz a pendência e lança a entrada
     * no caixa — tudo na mesma transação (caixa é a fonte única da verdade, spec §7).
     *
     * @return bool false se não era pendente, não existe ou não é da pelada
     */
    public function receber(int $peladaId, int $multaId, DateTimeImmutable $agora): bool
    {
        return $this->baixar($multaId, $peladaId, 'paga', $agora, lancarNoCaixa: true);
    }

    /** Multa indevida: anula a pendência sem movimentar caixa. Só a partir de "pendente". */
    public function cancelar(int $peladaId, int $multaId, DateTimeImmutable $agora): bool
    {
        return $this->baixar($multaId, $peladaId, 'cancelada', $agora, lancarNoCaixa: false);
    }

    /** @return list<array{id:int,valor:float,status:string,motivo:?string,criado_em:string,data_jogo:?string}> */
    public function listarDoJogador(int $peladaId, int $jogadorId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.valor, m.status, m.motivo, m.criado_em, r.data_jogo
             FROM multas m LEFT JOIN rodadas r ON r.id = m.rodada_id
             WHERE m.pelada_id = ? AND m.jogador_id = ?
             ORDER BY m.criado_em DESC, m.id DESC'
        );
        $stmt->execute([$peladaId, $jogadorId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'valor' => (float) $r['valor'],
            'status' => (string) $r['status'],
            'motivo' => $r['motivo'] !== null ? (string) $r['motivo'] : null,
            'criado_em' => (string) $r['criado_em'],
            'data_jogo' => $r['data_jogo'] !== null ? (string) $r['data_jogo'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function baixar(int $multaId, ?int $peladaId, string $novoStatus, DateTimeImmutable $agora, bool $lancarNoCaixa): bool
    {
        $this->pdo->beginTransaction();
        try {
            // Reivindica a multa de forma atômica: só "vence" quem a encontra pendente
            // (e na pelada certa). Sem linhas afetadas => no-op idempotente, sem caixa duplicado.
            $sql = "UPDATE multas SET status = ?, quitado_em = ? WHERE id = ? AND status = 'pendente'"
                . ($peladaId !== null ? ' AND pelada_id = ?' : '');
            $params = [$novoStatus, $agora->format('Y-m-d H:i:s'), $multaId];
            if ($peladaId !== null) {
                $params[] = $peladaId;
            }
            $marca = $this->pdo->prepare($sql);
            $marca->execute($params);
            if ($marca->rowCount() === 0) {
                $this->pdo->commit();

                return false;
            }

            $busca = $this->pdo->prepare(
                'SELECT m.pelada_id, m.jogador_id, m.valor, j.nome FROM multas m JOIN jogadores j ON j.id = m.jogador_id WHERE m.id = ?'
            );
            $busca->execute([$multaId]);
            $m = $busca->fetch(PDO::FETCH_ASSOC);

            // Reduz o saldo pendente de forma atômica e sem deixar negativo. O CASE
            // (portável MySQL/SQLite) faz a subtração e o clamp na própria escrita.
            $this->pdo->prepare(
                'UPDATE jogadores
                    SET saldo_pendente = CASE WHEN saldo_pendente < ? THEN 0 ELSE saldo_pendente - ? END
                  WHERE id = ?'
            )->execute([(float) $m['valor'], (float) $m['valor'], (int) $m['jogador_id']]);

            if ($lancarNoCaixa) {
                $quando = $agora->format('Y-m-d H:i:s');
                $this->pdo->prepare(
                    "INSERT INTO movimentos_caixa
                        (pelada_id, tipo, categoria, valor, descricao, pagamento_id, ocorrido_em, criado_em)
                     VALUES (?, 'entrada', 'multa', ?, ?, NULL, ?, ?)"
                )->execute([(int) $m['pelada_id'], (float) $m['valor'], 'Multa — ' . $m['nome'], $quando, $quando]);
            }

            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
