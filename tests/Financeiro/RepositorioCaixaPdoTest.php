<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Financeiro;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Financeiro\CategoriaPagamento;
use RcInfoti\Pelada\Financeiro\EscopoPagamento;
use RcInfoti\Pelada\Financeiro\FormaPagamento;
use RcInfoti\Pelada\Financeiro\MovimentoCaixa;
use RcInfoti\Pelada\Financeiro\Pagamento;
use RcInfoti\Pelada\Financeiro\RepositorioCaixaPdo;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Financeiro\TipoMovimento;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioCaixaPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
    }

    private function repo(): RepositorioCaixaPdo
    {
        return new RepositorioCaixaPdo($this->pdo);
    }

    public function test_lancar_saida_insere_movimento_de_saida(): void
    {
        $id = $this->repo()->lancarSaida(
            peladaId: 1,
            valor: 80.00,
            categoria: 'campo',
            descricao: 'Aluguel do campo',
            ocorridoEm: new DateTimeImmutable('2026-01-08 21:00:00'),
            agora: new DateTimeImmutable('2026-01-09 08:00:00'),
        );

        $this->assertGreaterThan(0, $id);

        $row = $this->pdo->query('SELECT * FROM movimentos_caixa WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('saida', $row['tipo']);
        $this->assertSame('campo', $row['categoria']);
        $this->assertSame(80.0, (float) $row['valor']);
        $this->assertSame('Aluguel do campo', $row['descricao']);
        $this->assertNull($row['pagamento_id']);
        $this->assertSame('2026-01-08 21:00:00', $row['ocorrido_em']);
    }

    private function confirmarPagamento(float $valor, DateTimeImmutable $quando): void
    {
        $this->pdo->exec("INSERT INTO jogadores (pelada_id, nome, tipo) VALUES (1, 'Ana', 'linha')");
        $jogadorId = (int) $this->pdo->lastInsertId();

        $pag = new RepositorioPagamentoPdo($this->pdo);
        $id = $pag->registrar(
            new Pagamento(1, $jogadorId, CategoriaPagamento::Futebol, EscopoPagamento::Semana, $valor, FormaPagamento::Pix),
            $quando,
        );
        $pag->confirmar($id, $quando);
    }

    public function test_saldo_vazio_e_zero(): void
    {
        $this->assertSame(0.0, $this->repo()->saldo(1));
    }

    public function test_saldo_com_so_saidas_fica_negativo(): void
    {
        $this->repo()->lancarSaida(1, 80.00, 'campo', 'Campo', new DateTimeImmutable('2026-01-08 21:00:00'), new DateTimeImmutable('2026-01-09 08:00:00'));

        $this->assertSame(-80.0, $this->repo()->saldo(1));
    }

    public function test_saldo_entradas_menos_saidas(): void
    {
        $this->confirmarPagamento(15.00, new DateTimeImmutable('2026-01-06 09:00:00'));
        $this->confirmarPagamento(15.00, new DateTimeImmutable('2026-01-06 09:10:00'));
        $this->repo()->lancarSaida(1, 20.00, 'bola', 'Bola nova', new DateTimeImmutable('2026-01-07 10:00:00'), new DateTimeImmutable('2026-01-07 10:00:00'));

        $this->assertSame(10.0, $this->repo()->saldo(1)); // 30 - 20
    }

    public function test_saldo_nao_dobra_ao_confirmar_duas_vezes(): void
    {
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha')");
        $pag = new RepositorioPagamentoPdo($this->pdo);
        $id = $pag->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-01-06 09:00:00'),
        );
        $pag->confirmar($id, new DateTimeImmutable('2026-01-06 09:00:00'));
        $pag->confirmar($id, new DateTimeImmutable('2026-01-06 09:30:00'));

        $this->assertSame(15.0, $this->repo()->saldo(1)); // não 30
    }

    public function test_extrato_inclui_os_limites(): void
    {
        $repo = $this->repo();
        $repo->lancarSaida(1, 10.00, 'x', 'início', new DateTimeImmutable('2026-01-01 00:00:00'), new DateTimeImmutable('2026-01-01 00:00:00'));
        $repo->lancarSaida(1, 20.00, 'y', 'meio', new DateTimeImmutable('2026-01-15 12:00:00'), new DateTimeImmutable('2026-01-15 12:00:00'));
        $repo->lancarSaida(1, 30.00, 'z', 'fim', new DateTimeImmutable('2026-01-31 23:59:59'), new DateTimeImmutable('2026-01-31 23:59:59'));
        $repo->lancarSaida(1, 99.00, 'w', 'fora', new DateTimeImmutable('2026-02-01 00:00:00'), new DateTimeImmutable('2026-02-01 00:00:00'));

        $extrato = $repo->extrato(1, new DateTimeImmutable('2026-01-01 00:00:00'), new DateTimeImmutable('2026-01-31 23:59:59'));

        $this->assertCount(3, $extrato);
        $this->assertContainsOnlyInstancesOf(MovimentoCaixa::class, $extrato);
        $this->assertSame('início', $extrato[0]->descricao); // ordenado por ocorrido_em
        $this->assertSame(TipoMovimento::Saida, $extrato[0]->tipo);
        $this->assertSame('fim', $extrato[2]->descricao);
    }

    public function test_extrato_periodo_vazio(): void
    {
        $this->repo()->lancarSaida(1, 10.00, 'x', 'jan', new DateTimeImmutable('2026-01-10 00:00:00'), new DateTimeImmutable('2026-01-10 00:00:00'));

        $extrato = $this->repo()->extrato(1, new DateTimeImmutable('2026-03-01 00:00:00'), new DateTimeImmutable('2026-03-31 23:59:59'));

        $this->assertSame([], $extrato);
    }

    public function test_extrato_traz_entrada_de_pagamento_com_vinculo(): void
    {
        $this->confirmarPagamento(15.00, new DateTimeImmutable('2026-01-06 09:00:00'));

        $extrato = $this->repo()->extrato(1, new DateTimeImmutable('2026-01-01 00:00:00'), new DateTimeImmutable('2026-01-31 23:59:59'));

        $this->assertCount(1, $extrato);
        $this->assertSame(TipoMovimento::Entrada, $extrato[0]->tipo);
        $this->assertSame('pagamento', $extrato[0]->categoria);
        $this->assertNotNull($extrato[0]->pagamentoId);
    }
}
