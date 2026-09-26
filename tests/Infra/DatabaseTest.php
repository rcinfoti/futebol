<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Infra;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Infra\Database;

final class DatabaseTest extends TestCase
{
    public function test_expoe_o_pdo_injetado(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db = new Database($pdo);

        $this->assertSame($pdo, $db->pdo());
    }

    public function test_pdo_executa_consulta_simples(): void
    {
        $db = new Database(new PDO('sqlite::memory:'));

        $valor = $db->pdo()->query('SELECT 1 AS um')->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('1', (string) $valor['um']);
    }
}
