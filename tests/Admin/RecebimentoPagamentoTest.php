<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Admin;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Admin\RecebimentoPagamento;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Jogadores\DadosInvalidos;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RecebimentoPagamentoTest extends TestCase
{
    private PDO $pdo;
    private DateTimeImmutable $t;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, valor_futebol, valor_festa_semana, valor_festa_ano) VALUES (1, 'Quinta', 'quinta', 15, 5, 220), (2, 'Sab', 'sab', 15, 5, 220)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha'), (20, 2, 'Zeca', 'linha')");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        $this->t = new DateTimeImmutable('2026-01-06 20:00:00');
    }

    private function r(): RecebimentoPagamento
    {
        return new RecebimentoPagamento($this->pdo, new RepositorioPagamentoPdo($this->pdo));
    }

    /** @return array<string,mixed> */
    private function unico(): array
    {
        return $this->pdo->query('SELECT * FROM pagamentos')->fetch(PDO::FETCH_ASSOC);
    }

    public function test_futebol_da_semana_usa_valor_da_pelada_vincula_rodada_e_ja_confirma(): void
    {
        $id = $this->r()->receber(1, 10, 'futebol', 'dinheiro', null, $this->t);

        $p = $this->unico();
        $this->assertSame($id, (int) $p['id']);
        $this->assertSame(['futebol', 'semana', 15.0, 'dinheiro', 5, 1], [$p['categoria'], $p['escopo'], (float) $p['valor'], $p['forma'], (int) $p['rodada_id'], (int) $p['confirmado']]);
        $this->assertSame(15.0, (float) $this->pdo->query('SELECT SUM(valor) FROM movimentos_caixa')->fetchColumn());
    }

    public function test_festa_do_ano_marca_quitada_e_nao_vincula_rodada(): void
    {
        $this->r()->receber(1, 10, 'festa_ano', 'pix', null, $this->t);

        $p = $this->unico();
        $this->assertSame(['festa', 'ano', 220.0], [$p['categoria'], $p['escopo'], (float) $p['valor']]);
        $this->assertNull($p['rodada_id']);
        $this->assertSame(2026, (int) $this->pdo->query('SELECT festa_quitada_ano FROM jogadores WHERE id = 10')->fetchColumn());
    }

    public function test_valor_informado_sobrescreve_o_padrao(): void
    {
        $this->r()->receber(1, 10, 'futebol', 'pix', 20.0, $this->t); // ex.: pagou futebol + festa junto
        $this->assertSame(20.0, (float) $this->unico()['valor']);
    }

    public function test_recusa_valor_invalido_tipo_invalido_e_jogador_de_outra_pelada(): void
    {
        foreach ([
            fn () => $this->r()->receber(1, 10, 'futebol', 'pix', 0.0, $this->t),
            fn () => $this->r()->receber(1, 10, 'futebol', 'pix', 5000.0, $this->t),
            fn () => $this->r()->receber(1, 10, 'cerveja', 'pix', null, $this->t),
            fn () => $this->r()->receber(1, 10, 'futebol', 'cheque', null, $this->t),
            fn () => $this->r()->receber(1, 20, 'futebol', 'pix', null, $this->t),
        ] as $i => $chamada) {
            try {
                $chamada();
                $this->fail("caso {$i} deveria falhar");
            } catch (DadosInvalidos) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pagamentos')->fetchColumn());
    }
}
