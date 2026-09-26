<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use DateTimeImmutable;
use PDO;

final class RelatorioFinanceiroPdo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<ResumoMensalJogador> */
    public function resumoMensalJogador(int $peladaId, int $ano, int $mes): array
    {
        $inicioMes = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $proximoMes = $inicioMes->modify('first day of next month');

        $stmt = $this->pdo->prepare(
            "SELECT j.id, j.nome, j.saldo_pendente,
                    COALESCE(SUM(CASE WHEN pg.categoria = 'futebol' THEN pg.valor ELSE 0 END), 0) AS pago_futebol,
                    COALESCE(SUM(CASE WHEN pg.categoria = 'festa'   THEN pg.valor ELSE 0 END), 0) AS pago_festa
             FROM jogadores j
             LEFT JOIN pagamentos pg
                    ON pg.jogador_id = j.id
                   AND pg.confirmado = 1
                   AND pg.criado_em >= ?
                   AND pg.criado_em <  ?
             WHERE j.pelada_id = ? AND j.ativo = 1
             GROUP BY j.id, j.nome, j.saldo_pendente
             ORDER BY j.nome"
        );
        $stmt->execute([
            $inicioMes->format('Y-m-d H:i:s'),
            $proximoMes->format('Y-m-d H:i:s'),
            $peladaId,
        ]);

        $resumo = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $resumo[] = new ResumoMensalJogador(
                (int) $r['id'],
                (string) $r['nome'],
                (float) $r['pago_futebol'],
                (float) $r['pago_festa'],
                (float) $r['saldo_pendente'],
            );
        }

        return $resumo;
    }
}
