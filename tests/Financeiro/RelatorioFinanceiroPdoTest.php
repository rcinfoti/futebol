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
use RcInfoti\Pelada\Financeiro\RelatorioFinanceiroPdo;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Financeiro\ResumoMensalJogador;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RelatorioFinanceiroPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo, saldo_pendente) VALUES
            (10, 1, 'Ana', 'linha', 0),
            (11, 1, 'Bia', 'linha', 20.00)");
    }

    private function pagarConfirmado(int $jogadorId, CategoriaPagamento $cat, EscopoPagamento $esc, float $valor, string $quando): void
    {
        $repo = new RepositorioPagamentoPdo($this->pdo);
        $agora = new DateTimeImmutable($quando);
        $id = $repo->registrar(new Pagamento(1, $jogadorId, $cat, $esc, $valor, FormaPagamento::Pix), $agora);
        $repo->confirmar($id, $agora);
    }

    /** @return array<int,ResumoMensalJogador> indexado por jogadorId */
    private function porJogador(array $lista): array
    {
        $out = [];
        foreach ($lista as $r) {
            $out[$r->jogadorId] = $r;
        }

        return $out;
    }

    public function test_soma_futebol_e_festa_confirmados_do_mes(): void
    {
        $this->pagarConfirmado(10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, '2026-01-06 09:00:00');
        $this->pagarConfirmado(10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, '2026-01-13 09:00:00');
        $this->pagarConfirmado(10, CategoriaPagamento::Festa, EscopoPagamento::Semana, 5.00, '2026-01-13 09:00:00');

        $resumo = $this->porJogador((new RelatorioFinanceiroPdo($this->pdo))->resumoMensalJogador(1, 2026, 1));

        $this->assertSame(30.0, $resumo[10]->pagoFutebol);
        $this->assertSame(5.0, $resumo[10]->pagoFesta);
        $this->assertSame('Ana', $resumo[10]->nome);
    }

    public function test_jogador_sem_pagamento_aparece_com_zeros_e_pendencia(): void
    {
        $resumo = $this->porJogador((new RelatorioFinanceiroPdo($this->pdo))->resumoMensalJogador(1, 2026, 1));

        $this->assertCount(2, $resumo);
        $this->assertSame(0.0, $resumo[11]->pagoFutebol);
        $this->assertSame(0.0, $resumo[11]->pagoFesta);
        $this->assertSame(20.0, $resumo[11]->pendente); // saldo_pendente do cadastro
    }

    public function test_resumo_ignora_pagamento_nao_confirmado(): void
    {
        // registrar SEM confirmar => não conta
        (new RepositorioPagamentoPdo($this->pdo))->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-01-06 09:00:00'),
        );

        $resumo = $this->porJogador((new RelatorioFinanceiroPdo($this->pdo))->resumoMensalJogador(1, 2026, 1));

        $this->assertSame(0.0, $resumo[10]->pagoFutebol);
    }

    public function test_resumo_ignora_pagamento_de_outro_mes(): void
    {
        $this->pagarConfirmado(10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, '2026-02-03 09:00:00');

        $resumo = $this->porJogador((new RelatorioFinanceiroPdo($this->pdo))->resumoMensalJogador(1, 2026, 1));

        $this->assertSame(0.0, $resumo[10]->pagoFutebol); // pago em fevereiro, não em janeiro
    }
}
