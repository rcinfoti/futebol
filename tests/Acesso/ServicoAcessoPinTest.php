<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Acesso;

use DateTimeImmutable;
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

    public function test_bloqueia_apos_cinco_falhas_seguidas(): void
    {
        $s = $this->servico();
        $s->definirPin(10, '1234');
        $t = new DateTimeImmutable('2026-01-05 10:00:00');

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($s->autenticar(10, '0000', $t));
        }

        $this->assertTrue($s->bloqueado(10, $t));
        $this->assertFalse($s->autenticar(10, '1234', $t)); // PIN certo não passa durante o bloqueio
    }

    public function test_bloqueio_expira(): void
    {
        $s = $this->servico();
        $s->definirPin(10, '1234');
        $t = new DateTimeImmutable('2026-01-05 10:00:00');
        for ($i = 0; $i < 5; $i++) {
            $s->autenticar(10, '0000', $t);
        }

        $depois = $t->modify('+16 minutes');
        $this->assertFalse($s->bloqueado(10, $depois));
        $this->assertTrue($s->autenticar(10, '1234', $depois));
    }

    public function test_acerto_zera_as_falhas(): void
    {
        $s = $this->servico();
        $s->definirPin(10, '1234');
        $t = new DateTimeImmutable('2026-01-05 10:00:00');

        for ($i = 0; $i < 4; $i++) {
            $s->autenticar(10, '0000', $t);
        }
        $this->assertTrue($s->autenticar(10, '1234', $t));
        for ($i = 0; $i < 4; $i++) {
            $s->autenticar(10, '0000', $t);
        }

        $this->assertFalse($s->bloqueado(10, $t)); // 4 + acerto + 4 ≠ 5 seguidas
    }

    public function test_redefinir_pin_libera_bloqueio(): void
    {
        $s = $this->servico();
        $s->definirPin(10, '1234');
        $t = new DateTimeImmutable('2026-01-05 10:00:00');
        for ($i = 0; $i < 5; $i++) {
            $s->autenticar(10, '0000', $t);
        }

        $s->definirPin(10, '5678'); // organizador redefine
        $this->assertTrue($s->autenticar(10, '5678', $t));
    }

    public function test_gerar_pin_sorteia_quatro_digitos_que_autenticam(): void
    {
        $pin = $this->servico()->gerarPin(10);

        $this->assertMatchesRegularExpression('/^\d{4}$/', $pin);
        $this->assertTrue($this->servico()->autenticar(10, $pin));
    }
}
