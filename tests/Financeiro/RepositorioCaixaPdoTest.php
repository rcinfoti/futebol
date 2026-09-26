<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Financeiro;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Financeiro\RepositorioCaixaPdo;
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
}
