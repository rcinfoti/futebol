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

    public function test_jogadores_para_login_escopa_uma_pelada(): void
    {
        // segunda pelada ativa com outro jogador — não deve vazar no login da primeira
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (2, 'Sabado', 'sabado')");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (20, 2, 'Zeca', 'linha')");

        $lista = $this->consulta()->jogadoresParaLogin(1);
        $nomes = array_column($lista, 'nome');

        $this->assertContains('Ana', $nomes);
        $this->assertNotContains('Zeca', $nomes); // jogador de outra pelada não aparece
    }

    public function test_pelada_por_slug(): void
    {
        $this->assertSame(['id' => 1, 'nome' => 'Quinta', 'slug' => 'quinta'], $this->consulta()->peladaPorSlug('quinta'));
        $this->assertNull($this->consulta()->peladaPorSlug('nao-existe'));
    }

    public function test_pelada_inativa_nao_e_encontrada_por_slug(): void
    {
        $this->pdo->exec('UPDATE peladas SET ativa = 0 WHERE id = 1');
        $this->assertNull($this->consulta()->peladaPorSlug('quinta'));
    }

    public function test_peladas_ativas_lista_para_escolha(): void
    {
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, ativa) VALUES (2, 'Sabado', 'sabado', 1), (3, 'Velha', 'velha', 0)");
        $slugs = array_column($this->consulta()->peladasAtivas(), 'slug');
        $this->assertSame(['quinta', 'sabado'], $slugs);
    }

    public function test_pertence_a_pelada(): void
    {
        $this->assertTrue($this->consulta()->pertenceAPelada(10, 1));
        $this->assertFalse($this->consulta()->pertenceAPelada(10, 2));
    }

    public function test_confirmado_reflete_inscricao(): void
    {
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES (5, 10, 'linha', 'confirmado', 1)");

        $p = $this->consulta()->montar(10, new DateTimeImmutable('2026-01-05 10:00:00'));

        $this->assertSame('confirmado', $p->situacao);
    }

    public function test_dezembro_nao_cobra_festa_semanal(): void
    {
        // Spec §3.2: temporada da festa vai de janeiro ao fim de novembro.
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-12-10', 'aberta', '2026-12-09 12:00:00', '2026-12-10 16:00:00')");

        $p = $this->consulta()->montar(10, new DateTimeImmutable('2026-12-07 10:00:00'));

        $this->assertSame(15.0, $p->devidoSemana); // só o futebol
    }
}
