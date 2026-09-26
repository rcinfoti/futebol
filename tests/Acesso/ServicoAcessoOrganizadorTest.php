<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Acesso;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Acesso\Papel;
use RcInfoti\Pelada\Acesso\ServicoAcessoOrganizador;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class ServicoAcessoOrganizadorTest extends TestCase
{
    private PDO $pdo;
    private DateTimeImmutable $t;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->t = new DateTimeImmutable('2026-01-05 10:00:00');
    }

    private function s(): ServicoAcessoOrganizador
    {
        return new ServicoAcessoOrganizador($this->pdo);
    }

    public function test_cria_e_autentica(): void
    {
        $id = $this->s()->criar('Rogério', 'Rog@Exemplo.com ', 'senha-forte-1', Papel::SuperAdmin);

        $this->assertSame($id, $this->s()->autenticar('rog@exemplo.com', 'senha-forte-1', $this->t)); // e-mail normalizado
        $this->assertNull($this->s()->autenticar('rog@exemplo.com', 'errada', $this->t));
        $this->assertNotSame('senha-forte-1', $this->pdo->query('SELECT senha_hash FROM usuarios')->fetchColumn());
    }

    public function test_email_inexistente_nao_autentica(): void
    {
        $this->assertNull($this->s()->autenticar('ninguem@x.com', 'qualquer1', $this->t));
    }

    public function test_rejeita_senha_curta_e_email_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->s()->criar('Zé', 'ze@x.com', 'curta', Papel::Organizador);
    }

    public function test_rejeita_email_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->s()->criar('Zé', 'nao-e-email', 'senha-forte-1', Papel::Organizador);
    }

    public function test_rejeita_email_duplicado(): void
    {
        $this->s()->criar('Zé', 'ze@x.com', 'senha-forte-1', Papel::Organizador);
        $this->expectException(\InvalidArgumentException::class);
        $this->s()->criar('Zé 2', 'ZE@x.com', 'senha-forte-2', Papel::Organizador);
    }

    public function test_bloqueia_apos_cinco_falhas_e_expira(): void
    {
        $this->s()->criar('Zé', 'ze@x.com', 'senha-forte-1', Papel::Organizador);
        for ($i = 0; $i < ServicoAcessoOrganizador::MAX_FALHAS; $i++) {
            $this->s()->autenticar('ze@x.com', 'errada', $this->t);
        }

        $this->assertTrue($this->s()->bloqueado('ze@x.com', $this->t));
        $this->assertNull($this->s()->autenticar('ze@x.com', 'senha-forte-1', $this->t));
        $this->assertNotNull($this->s()->autenticar('ze@x.com', 'senha-forte-1', $this->t->modify('+16 minutes')));
    }

    public function test_usuario_inativo_nao_entra(): void
    {
        $this->s()->criar('Zé', 'ze@x.com', 'senha-forte-1', Papel::Organizador);
        $this->pdo->exec('UPDATE usuarios SET ativo = 0');

        $this->assertNull($this->s()->autenticar('ze@x.com', 'senha-forte-1', $this->t));
    }

    public function test_alterar_senha(): void
    {
        $id = $this->s()->criar('Zé', 'ze@x.com', 'senha-forte-1', Papel::Organizador);
        $this->s()->alterarSenha($id, 'outra-senha-9');

        $this->assertNull($this->s()->autenticar('ze@x.com', 'senha-forte-1', $this->t));
        $this->assertSame($id, $this->s()->autenticar('ze@x.com', 'outra-senha-9', $this->t));
    }

    public function test_senha_temporaria_gerada_autentica(): void
    {
        [$id, $senha] = $this->s()->criarComSenhaTemporaria('Zé', 'ze@x.com', Papel::Organizador);

        $this->assertGreaterThanOrEqual(12, strlen($senha));
        $this->assertSame($id, $this->s()->autenticar('ze@x.com', $senha, $this->t));
    }

    public function test_redefinir_senha_temporaria(): void
    {
        [$id, $antiga] = $this->s()->criarComSenhaTemporaria('Zé', 'ze@x.com', Papel::Organizador);
        $nova = $this->s()->redefinirSenhaTemporaria($id);

        $this->assertNotSame($antiga, $nova);
        $this->assertNull($this->s()->autenticar('ze@x.com', $antiga, $this->t));
        $this->assertSame($id, $this->s()->autenticar('ze@x.com', $nova, $this->t));
    }

    public function test_trocar_a_propria_senha_exige_a_atual(): void
    {
        $id = $this->s()->criar('Zé', 'ze@x.com', 'senha-forte-1', Papel::Organizador);

        $this->assertFalse($this->s()->trocarPropriaSenha($id, 'errada', 'nova-senha-123'));
        $this->assertTrue($this->s()->trocarPropriaSenha($id, 'senha-forte-1', 'nova-senha-123'));
        $this->assertSame($id, $this->s()->autenticar('ze@x.com', 'nova-senha-123', $this->t));
    }

    public function test_listar_e_ativar(): void
    {
        $a = $this->s()->criar('Bruno', 'b@x.com', 'senha-forte-1', Papel::Organizador);
        $this->s()->criar('Ana', 'a@x.com', 'senha-forte-1', Papel::SuperAdmin);
        $this->s()->definirAtivo($a, false);

        $lista = $this->s()->listar();
        $this->assertSame(['Ana', 'Bruno'], array_column($lista, 'nome'));
        $this->assertFalse($lista[1]['ativo']);
        $this->assertNull($this->s()->buscar($a)); // buscar só devolve ativo (sessão)
        $this->assertSame('Bruno', $this->s()->buscarQualquer($a)['nome']);
    }
}
