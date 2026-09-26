<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class ProcessarRodadaIntegracaoTest extends TestCase
{
    public function test_processar_e_idempotente(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($pdo);
        $pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro) VALUES (1, 'Quinta', 'quinta', 2, 1)");
        $pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha'), (11, 1, 'Bia', 'linha')");
        $pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        // 1 confirmado + 1 na espera; limite de linha = 2 -> uma vaga.
        $pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem)
            VALUES (5, 10, 'linha', 'confirmado', 1), (5, 11, 'linha', 'espera', 2)");

        $proc = new ProcessadorRodada(new RepositorioRodadaPdo($pdo), new ServicoRegrasRodada());
        $agora = new DateTimeImmutable('2026-01-07 10:00:00'); // antes da virada -> FIFO

        $proc->processar(5, $agora);
        $proc->processar(5, $agora); // segunda passada não deve mudar nada

        $confirmados = (int) $pdo->query("SELECT COUNT(*) FROM inscricoes WHERE rodada_id = 5 AND status = 'confirmado'")->fetchColumn();
        $this->assertSame(2, $confirmados); // Bia subiu uma única vez
    }
}
