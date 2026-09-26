<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Acesso;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Acesso\Autorizacao;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class AutorizacaoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta'), (2, 'Sabado', 'sabado')");
        $this->pdo->exec("INSERT INTO usuarios (id, nome, email, senha_hash, papel) VALUES
            (1, 'Rogerio', 'r@x.com', 'h', 'super_admin'), (2, 'Org', 'o@x.com', 'h', 'organizador')");
    }

    public function test_super_admin_ve_e_gerencia_tudo(): void
    {
        $a = new Autorizacao($this->pdo);

        $this->assertTrue($a->ehSuperAdmin(1));
        $this->assertSame(['quinta', 'sabado'], array_column($a->peladasVisiveis(1), 'slug'));
        $this->assertTrue($a->podeGerir(1, 2));
    }

    public function test_organizador_so_ve_peladas_vinculadas(): void
    {
        $a = new Autorizacao($this->pdo);
        $a->vincular(1, 2);
        $a->vincular(1, 2); // idempotente

        $this->assertFalse($a->ehSuperAdmin(2));
        $this->assertSame(['quinta'], array_column($a->peladasVisiveis(2), 'slug'));
        $this->assertTrue($a->podeGerir(2, 1));
        $this->assertFalse($a->podeGerir(2, 2));
    }

    public function test_usuario_inativo_ou_inexistente_nao_gerencia(): void
    {
        $a = new Autorizacao($this->pdo);
        $this->pdo->exec('UPDATE usuarios SET ativo = 0 WHERE id = 1');

        $this->assertFalse($a->podeGerir(1, 1));
        $this->assertFalse($a->podeGerir(999, 1));
        $this->assertSame([], $a->peladasVisiveis(1));
    }

    public function test_desvincular_e_peladas_do_usuario(): void
    {
        $a = new Autorizacao($this->pdo);
        $a->vincular(1, 2);
        $a->vincular(2, 2);
        $this->assertSame([1, 2], $a->idsPeladasDoUsuario(2));

        $a->desvincular(1, 2);
        $this->assertSame([2], $a->idsPeladasDoUsuario(2));
        $this->assertFalse($a->podeGerir(2, 1));
    }

    public function test_definir_vinculos_substitui_o_conjunto(): void
    {
        $a = new Autorizacao($this->pdo);
        $a->vincular(1, 2);

        $a->definirVinculos(2, [2, 999]); // 999 não existe: ignorado
        $this->assertSame([2], $a->idsPeladasDoUsuario(2));
    }
}
