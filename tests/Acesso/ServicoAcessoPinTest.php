<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Acesso;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class ServicoAcessoPinTest extends TestCase
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

    private function servico(): ServicoAcessoPin
    {
        return new ServicoAcessoPin($this->pdo);
    }

    public function test_pin_correto_autentica(): void
    {
        $s = $this->servico();
        $s->definirPin(10, '1234');

        $this->assertTrue($s->autenticar(10, '1234'));
    }

    public function test_pin_errado_falha(): void
    {
        $s = $this->servico();
        $s->definirPin(10, '1234');

        $this->assertFalse($s->autenticar(10, '0000'));
    }

    public function test_jogador_sem_pin_falha(): void
    {
        $this->assertFalse($this->servico()->autenticar(10, '1234'));
    }

    public function test_jogador_inexistente_falha(): void
    {
        $this->assertFalse($this->servico()->autenticar(999, '1234'));
    }

    public function test_pin_nao_e_guardado_em_claro(): void
    {
        $s = $this->servico();
        $s->definirPin(10, '1234');

        $hash = $this->pdo->query('SELECT pin_hash FROM jogadores WHERE id = 10')->fetchColumn();
        $this->assertNotSame('1234', $hash);
        $this->assertTrue(password_verify('1234', (string) $hash));
    }
}
