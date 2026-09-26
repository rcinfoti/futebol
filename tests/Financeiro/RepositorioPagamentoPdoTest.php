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
}
