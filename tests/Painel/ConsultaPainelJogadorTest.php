<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Painel;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Painel\ConsultaPainelJogador;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class ConsultaPainelJogadorTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, valor_futebol, valor_festa_semana) VALUES (1, 'Quinta', 'quinta', 15.00, 5.00)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo, saldo_pendente) VALUES (10, 1, 'Ana', 'linha', 30.00)");
    }

    private function consulta(): ConsultaPainelJogador
    {
        return new ConsultaPainelJogador($this->pdo);
    }

    public function test_sem_rodada_aberta(): void
    {
        $p = $this->consulta()->montar(10, new DateTimeImmutable('2026-01-05 10:00:00'));

        $this->assertSame('Ana', $p->nome);
        $this->assertSame('sem_rodada', $p->situacao);
        $this->assertNull($p->rodadaId);
        $this->assertSame(30.0, $p->saldoPendente);
    }

    public function test_fora_quando_ha_rodada_mas_nao_inscrito(): void
    {
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");

        $p = $this->consulta()->montar(10, new DateTimeImmutable('2026-01-05 10:00:00'));

        $this->assertSame('fora', $p->situacao);
        $this->assertSame(5, $p->rodadaId);
        $this->assertSame('2026-01-08', $p->dataJogo);
        $this->assertSame(20.0, $p->devidoSemana); // linha: 15 + 5
    }

    public function test_confirmado_reflete_inscricao(): void
    {
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES (5, 10, 'linha', 'confirmado', 1)");

        $p = $this->consulta()->montar(10, new DateTimeImmutable('2026-01-05 10:00:00'));

        $this->assertSame('confirmado', $p->situacao);
    }
}
