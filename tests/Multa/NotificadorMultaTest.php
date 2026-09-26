<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Multa;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Multa\NotificadorMulta;
use RcInfoti\Pelada\Tests\Email\EnviadorEmailFake;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class NotificadorMultaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, email, tipo) VALUES
            (10, 1, 'Ana', 'ana@example.com', 'linha'),
            (11, 1, 'Bia', NULL, 'linha')");
    }

    private function inserirMulta(int $id, int $jogadorId, float $valor): void
    {
        $this->pdo->prepare(
            "INSERT INTO multas (id, pelada_id, jogador_id, rodada_id, valor, status, criado_em)
             VALUES (?, 1, ?, 5, ?, 'pendente', '2026-01-08 16:00:00')"
        )->execute([$id, $jogadorId, $valor]);
    }

    public function test_envia_email_e_marca_enviado(): void
    {
        $this->inserirMulta(100, 10, 20.00);
        $fake = new EnviadorEmailFake();

        $enviados = (new NotificadorMulta($this->pdo, $fake))->notificarPendentes(1, new DateTimeImmutable('2026-01-08 16:05:00'));

        $this->assertSame(1, $enviados);
        $this->assertCount(1, $fake->enviados);
        $this->assertSame('ana@example.com', $fake->enviados[0]['para']);
        $this->assertStringContainsString('20', $fake->enviados[0]['corpo']); // valor no corpo
        $this->assertSame(1, (int) $this->pdo->query('SELECT email_enviado FROM multas WHERE id = 100')->fetchColumn());
    }

    public function test_nao_reenvia(): void
    {
        $this->inserirMulta(100, 10, 20.00);
        $fake = new EnviadorEmailFake();
        $notificador = new NotificadorMulta($this->pdo, $fake);

        $notificador->notificarPendentes(1, new DateTimeImmutable('2026-01-08 16:05:00'));
        $segunda = $notificador->notificarPendentes(1, new DateTimeImmutable('2026-01-08 16:10:00'));

        $this->assertSame(0, $segunda);
        $this->assertCount(1, $fake->enviados);
    }

    public function test_pula_sem_email(): void
    {
        $this->inserirMulta(101, 11, 15.00); // Bia não tem e-mail
        $fake = new EnviadorEmailFake();

        $enviados = (new NotificadorMulta($this->pdo, $fake))->notificarPendentes(1, new DateTimeImmutable('2026-01-08 16:05:00'));

        $this->assertSame(0, $enviados);
        $this->assertCount(0, $fake->enviados);
        // permanece 0 para ser tentada quando o e-mail for cadastrado
        $this->assertSame(0, (int) $this->pdo->query('SELECT email_enviado FROM multas WHERE id = 101')->fetchColumn());
    }
}
