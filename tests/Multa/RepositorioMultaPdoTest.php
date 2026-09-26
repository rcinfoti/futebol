<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Multa;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Multa\RepositorioMultaPdo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioMultaPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
    }

    private function jogadorComSaldo(int $id, float $saldo): void
    {
        $this->pdo->prepare("INSERT INTO jogadores (id, pelada_id, nome, tipo, saldo_pendente) VALUES (?, 1, 'J', 'linha', ?)")
            ->execute([$id, $saldo]);
    }

    private function multaPendente(int $id, int $jogadorId, float $valor): void
    {
        $this->pdo->prepare(
            "INSERT INTO multas (id, pelada_id, jogador_id, rodada_id, valor, status, criado_em)
             VALUES (?, 1, ?, 5, ?, 'pendente', '2026-01-08 16:00:00')"
        )->execute([$id, $jogadorId, $valor]);
    }

    private function repo(): RepositorioMultaPdo
    {
        return new RepositorioMultaPdo($this->pdo);
    }

    public function test_quitar_marca_paga_e_reduz_saldo(): void
    {
        $this->jogadorComSaldo(10, 20.00);
        $this->multaPendente(100, 10, 20.00);

        $this->repo()->quitar(100, new DateTimeImmutable('2026-01-10 09:00:00'));

        $m = $this->pdo->query('SELECT status, quitado_em FROM multas WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('paga', $m['status']);
        $this->assertSame('2026-01-10 09:00:00', $m['quitado_em']);
        $this->assertSame(0.0, (float) $this->pdo->query('SELECT saldo_pendente FROM jogadores WHERE id = 10')->fetchColumn());
    }

    public function test_quitar_e_idempotente(): void
    {
        $this->jogadorComSaldo(10, 20.00);
        $this->multaPendente(100, 10, 20.00);
        $repo = $this->repo();

        $repo->quitar(100, new DateTimeImmutable('2026-01-10 09:00:00'));
        $repo->quitar(100, new DateTimeImmutable('2026-01-10 09:05:00')); // segunda vez: no-op

        // saldo não é reduzido duas vezes
        $this->assertSame(0.0, (float) $this->pdo->query('SELECT saldo_pendente FROM jogadores WHERE id = 10')->fetchColumn());
    }

    public function test_saldo_nao_fica_negativo(): void
    {
        $this->jogadorComSaldo(10, 5.00);   // saldo menor que a multa
        $this->multaPendente(100, 10, 20.00);

        $this->repo()->quitar(100, new DateTimeImmutable('2026-01-10 09:00:00'));

        $this->assertSame(0.0, (float) $this->pdo->query('SELECT saldo_pendente FROM jogadores WHERE id = 10')->fetchColumn());
    }

    public function test_quitar_inexistente_e_noop(): void
    {
        $this->repo()->quitar(999, new DateTimeImmutable('2026-01-10 09:00:00'));
        $this->assertTrue(true); // não lança
    }
}
