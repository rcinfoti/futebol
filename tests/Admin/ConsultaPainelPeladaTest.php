<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Admin;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Admin\ConsultaPainelPelada;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class ConsultaPainelPeladaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro) VALUES (1, 'Quinta', 'quinta', 2, 1), (2, 'Sabado', 'sabado', 20, 4)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo, saldo_pendente) VALUES
            (10, 1, 'Ana', 'linha', 0), (11, 1, 'Bia', 'linha', 20), (12, 1, 'Caio', 'linha', 0),
            (13, 1, 'Duda', 'goleiro', 0), (14, 1, 'Edu', 'linha', 0), (20, 2, 'Zeca', 'linha', 50)");
    }

    private function c(): ConsultaPainelPelada
    {
        return new ConsultaPainelPelada($this->pdo);
    }

    public function test_sem_rodada(): void
    {
        $p = $this->c()->montar(1);

        $this->assertSame('Quinta', $p->nome);
        $this->assertSame('quinta', $p->slug);
        $this->assertNull($p->rodadaId);
        $this->assertSame([], $p->confirmadosLinha);
    }

    public function test_rodada_com_listas_por_tipo_em_ordem_de_chegada(): void
    {
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES
            (5, 12, 'linha', 'confirmado', 2), (5, 10, 'linha', 'confirmado', 1), (5, 11, 'linha', 'espera', 3),
            (5, 13, 'goleiro', 'confirmado', 4), (5, 14, 'linha', 'desistiu', 5)");

        $p = $this->c()->montar(1);

        $this->assertSame(5, $p->rodadaId);
        $this->assertSame('2026-01-08', $p->dataJogo);
        $this->assertSame(['Ana', 'Caio'], array_column($p->confirmadosLinha, 'nome'));
        $this->assertSame(['Bia'], array_column($p->esperaLinha, 'nome'));
        $this->assertSame(['Duda'], array_column($p->confirmadosGoleiro, 'nome'));
        $this->assertSame([], $p->esperaGoleiro);
        $this->assertSame(['Edu'], array_column($p->desistiram, 'nome'));
        $this->assertSame(2, $p->limiteLinha);
        $this->assertSame(1, $p->limiteGoleiro);
    }

    public function test_confirmado_mostra_se_ja_pagou_a_rodada(): void
    {
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES
            (5, 10, 'linha', 'confirmado', 1), (5, 12, 'linha', 'confirmado', 2)");
        $this->pdo->exec("INSERT INTO pagamentos (pelada_id, jogador_id, rodada_id, categoria, escopo, valor, forma, confirmado, criado_em)
            VALUES (1, 10, 5, 'futebol', 'semana', 15, 'pix', 1, '2026-01-06 10:00:00'),
                   (1, 12, 5, 'futebol', 'semana', 15, 'pix', 0, '2026-01-06 10:00:00')");

        $p = $this->c()->montar(1);
        $porNome = array_column($p->confirmadosLinha, 'pago', 'nome');

        $this->assertTrue($porNome['Ana']);
        $this->assertFalse($porNome['Caio']); // só avisou, ainda não confirmado
    }

    public function test_caixa_pendencias_e_pagamentos_a_confirmar_da_pelada(): void
    {
        $this->pdo->exec("INSERT INTO movimentos_caixa (pelada_id, tipo, categoria, valor, ocorrido_em, criado_em) VALUES
            (1, 'entrada', 'pagamento', 100, '2026-01-01', '2026-01-01'), (1, 'saida', 'gasto', 30, '2026-01-02', '2026-01-02'),
            (2, 'entrada', 'pagamento', 999, '2026-01-01', '2026-01-01')");
        $this->pdo->exec("INSERT INTO pagamentos (id, pelada_id, jogador_id, categoria, escopo, valor, forma, confirmado, criado_em) VALUES
            (1, 1, 10, 'festa', 'ano', 220, 'pix', 0, '2026-01-06 10:00:00'),
            (2, 1, 12, 'futebol', 'semana', 15, 'dinheiro', 1, '2026-01-06 11:00:00'),
            (3, 2, 20, 'futebol', 'semana', 15, 'pix', 0, '2026-01-06 12:00:00')");

        $p = $this->c()->montar(1);

        $this->assertSame(70.0, $p->saldoCaixa);
        $this->assertSame([['id' => 11, 'nome' => 'Bia', 'saldo' => 20.0]], $p->pendencias);
        $this->assertCount(1, $p->pagamentosAConfirmar);
        $this->assertSame(1, $p->pagamentosAConfirmar[0]['id']);
        $this->assertSame('Ana', $p->pagamentosAConfirmar[0]['jogador']);
        $this->assertSame(220.0, $p->pagamentosAConfirmar[0]['valor']);
        $this->assertFalse($p->pagamentosAConfirmar[0]['comprovante']);
    }

    public function test_pagamento_pertence_a_pelada(): void
    {
        $this->pdo->exec("INSERT INTO pagamentos (id, pelada_id, jogador_id, categoria, escopo, valor, forma, confirmado, criado_em)
            VALUES (3, 2, 20, 'futebol', 'semana', 15, 'pix', 0, '2026-01-06 12:00:00')");

        $this->assertTrue($this->c()->pagamentoDaPelada(3, 2));
        $this->assertFalse($this->c()->pagamentoDaPelada(3, 1));
    }
}
