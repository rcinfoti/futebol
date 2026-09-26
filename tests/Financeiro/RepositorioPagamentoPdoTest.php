<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Financeiro;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Financeiro\CategoriaPagamento;
use RcInfoti\Pelada\Financeiro\EscopoPagamento;
use RcInfoti\Pelada\Financeiro\FormaPagamento;
use RcInfoti\Pelada\Financeiro\Pagamento;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioPagamentoPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha')");
    }

    private function repo(): RepositorioPagamentoPdo
    {
        return new RepositorioPagamentoPdo($this->pdo);
    }

    public function test_registrar_insere_pagamento_nao_confirmado_e_devolve_id(): void
    {
        $id = $this->repo()->registrar(
            new Pagamento(
                peladaId: 1,
                jogadorId: 10,
                categoria: CategoriaPagamento::Futebol,
                escopo: EscopoPagamento::Semana,
                valor: 15.00,
                forma: FormaPagamento::Pix,
                rodadaId: 5,
                comprovanteArquivo: 'comp/abc.jpg',
            ),
            new DateTimeImmutable('2026-01-05 10:00:00'),
        );

        $this->assertGreaterThan(0, $id);

        $row = $this->pdo->query('SELECT * FROM pagamentos WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('futebol', $row['categoria']);
        $this->assertSame('semana', $row['escopo']);
        $this->assertSame('pix', $row['forma']);
        $this->assertSame('comp/abc.jpg', $row['comprovante_arquivo']);
        $this->assertSame(15.0, (float) $row['valor']);
        $this->assertSame(0, (int) $row['confirmado']); // registrar NÃO confirma
        $this->assertSame('2026-01-05 10:00:00', $row['criado_em']);
    }

    public function test_registrar_aceita_dinheiro_festa_ano_sem_rodada_nem_comprovante(): void
    {
        $id = $this->repo()->registrar(
            new Pagamento(
                peladaId: 1,
                jogadorId: 10,
                categoria: CategoriaPagamento::Festa,
                escopo: EscopoPagamento::Ano,
                valor: 220.00,
                forma: FormaPagamento::Dinheiro,
            ),
            new DateTimeImmutable('2026-01-05 10:00:00'),
        );

        $row = $this->pdo->query('SELECT * FROM pagamentos WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('festa', $row['categoria']);
        $this->assertSame('ano', $row['escopo']);
        $this->assertSame('dinheiro', $row['forma']);
        $this->assertNull($row['rodada_id']);
        $this->assertNull($row['comprovante_arquivo']);
    }

    public function test_confirmar_gera_entrada_no_caixa_vinculada(): void
    {
        $repo = $this->repo();
        $id = $repo->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-01-05 10:00:00'),
        );

        $repo->confirmar($id, new DateTimeImmutable('2026-01-06 09:00:00'));

        $pg = $this->pdo->query('SELECT confirmado FROM pagamentos WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $pg['confirmado']);

        $mov = $this->pdo->query('SELECT * FROM movimentos_caixa WHERE pagamento_id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('entrada', $mov['tipo']);
        $this->assertSame('pagamento', $mov['categoria']);
        $this->assertSame(15.0, (float) $mov['valor']);
        $this->assertSame(1, (int) $mov['pelada_id']);
        $this->assertSame('2026-01-06 09:00:00', $mov['ocorrido_em']);
    }

    public function test_registrar_nao_mexe_no_caixa(): void
    {
        $this->repo()->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-01-05 10:00:00'),
        );

        $qtd = (int) $this->pdo->query('SELECT COUNT(*) FROM movimentos_caixa')->fetchColumn();
        $this->assertSame(0, $qtd);
    }

    public function test_confirmar_e_idempotente(): void
    {
        $repo = $this->repo();
        $id = $repo->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-01-05 10:00:00'),
        );

        $repo->confirmar($id, new DateTimeImmutable('2026-01-06 09:00:00'));
        $repo->confirmar($id, new DateTimeImmutable('2026-01-06 09:05:00')); // segunda vez: no-op

        $qtd = (int) $this->pdo->query('SELECT COUNT(*) FROM movimentos_caixa WHERE pagamento_id = ' . $id)->fetchColumn();
        $this->assertSame(1, $qtd); // apenas uma entrada
    }

    public function test_confirmar_pagamento_inexistente_e_noop(): void
    {
        $this->repo()->confirmar(999, new DateTimeImmutable('2026-01-06 09:00:00'));

        $qtd = (int) $this->pdo->query('SELECT COUNT(*) FROM movimentos_caixa')->fetchColumn();
        $this->assertSame(0, $qtd);
    }

    public function test_confirmar_festa_ano_marca_festa_quitada(): void
    {
        $repo = $this->repo();
        $id = $repo->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Festa, EscopoPagamento::Ano, 220.00, FormaPagamento::Dinheiro),
            new DateTimeImmutable('2026-02-01 10:00:00'),
        );

        $repo->confirmar($id, new DateTimeImmutable('2026-02-02 09:00:00'));

        $ano = $this->pdo->query('SELECT festa_quitada_ano FROM jogadores WHERE id = 10')->fetchColumn();
        $this->assertSame(2026, (int) $ano);
    }

    public function test_confirmar_festa_semana_nao_marca(): void
    {
        $repo = $this->repo();
        $id = $repo->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Festa, EscopoPagamento::Semana, 5.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-02-01 10:00:00'),
        );

        $repo->confirmar($id, new DateTimeImmutable('2026-02-02 09:00:00'));

        $ano = $this->pdo->query('SELECT festa_quitada_ano FROM jogadores WHERE id = 10')->fetchColumn();
        $this->assertNull($ano);
    }
}
