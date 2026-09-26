<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Jogadores;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Jogadores\DadosJogador;
use RcInfoti\Pelada\Jogadores\RepositorioJogadorPdo;
use RcInfoti\Pelada\Rodada\Tipo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioJogadorPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta'), (2, 'Sabado', 'sabado')");
    }

    private function repo(): RepositorioJogadorPdo
    {
        return new RepositorioJogadorPdo($this->pdo);
    }

    public function test_dados_validam_e_normalizam(): void
    {
        $d = DadosJogador::deFormulario(['nome' => '  Ana  Souza ', 'tipo' => 'goleiro', 'email' => ' ANA@X.COM ', 'telefone' => ' (31) 9999-0000 ']);

        $this->assertSame('Ana Souza', $d->nome);
        $this->assertSame(Tipo::Goleiro, $d->tipo);
        $this->assertSame('ana@x.com', $d->email);
        $this->assertSame('(31) 9999-0000', $d->telefone);
    }

    public function test_dados_acumulam_erros(): void
    {
        try {
            DadosJogador::deFormulario(['nome' => 'A', 'tipo' => 'zagueiro', 'email' => 'x']);
            $this->fail('deveria rejeitar');
        } catch (\RcInfoti\Pelada\Jogadores\DadosInvalidos $e) {
            $this->assertArrayHasKey('nome', $e->erros);
            $this->assertArrayHasKey('tipo', $e->erros);
            $this->assertArrayHasKey('email', $e->erros);
        }
    }

    public function test_email_e_telefone_opcionais(): void
    {
        $d = DadosJogador::deFormulario(['nome' => 'Bia', 'tipo' => 'linha', 'email' => '', 'telefone' => '']);
        $this->assertNull($d->email);
        $this->assertNull($d->telefone);
    }

    public function test_cria_lista_e_busca_escopado(): void
    {
        $id = $this->repo()->criar(1, DadosJogador::deFormulario(['nome' => 'Bia', 'tipo' => 'linha']));
        $this->repo()->criar(1, DadosJogador::deFormulario(['nome' => 'Ana', 'tipo' => 'goleiro']));
        $this->repo()->criar(2, DadosJogador::deFormulario(['nome' => 'Zeca', 'tipo' => 'linha']));

        $this->assertSame(['Ana', 'Bia'], array_column($this->repo()->listar(1), 'nome'));
        $this->assertSame('Bia', $this->repo()->buscar(1, $id)['nome']);
        $this->assertNull($this->repo()->buscar(2, $id)); // outra pelada não enxerga
    }

    public function test_nome_duplicado_na_mesma_pelada_e_rejeitado(): void
    {
        $this->repo()->criar(1, DadosJogador::deFormulario(['nome' => 'Ana', 'tipo' => 'linha']));
        $this->repo()->criar(2, DadosJogador::deFormulario(['nome' => 'Ana', 'tipo' => 'linha'])); // outra pelada pode

        $this->expectException(\RcInfoti\Pelada\Jogadores\DadosInvalidos::class);
        $this->repo()->criar(1, DadosJogador::deFormulario(['nome' => 'ana', 'tipo' => 'linha'])); // sem diferenciar maiúsculas
    }

    public function test_atualiza(): void
    {
        $id = $this->repo()->criar(1, DadosJogador::deFormulario(['nome' => 'Ana', 'tipo' => 'linha']));
        $this->repo()->atualizar(1, $id, DadosJogador::deFormulario(['nome' => 'Ana Paula', 'tipo' => 'goleiro', 'email' => 'a@x.com']));

        $j = $this->repo()->buscar(1, $id);
        $this->assertSame('Ana Paula', $j['nome']);
        $this->assertSame('goleiro', $j['tipo']);
        $this->assertSame('a@x.com', $j['email']);
    }

    public function test_atualizar_pode_manter_o_proprio_nome_mas_nao_roubar_outro(): void
    {
        $ana = $this->repo()->criar(1, DadosJogador::deFormulario(['nome' => 'Ana', 'tipo' => 'linha']));
        $this->repo()->criar(1, DadosJogador::deFormulario(['nome' => 'Bia', 'tipo' => 'linha']));

        $this->repo()->atualizar(1, $ana, DadosJogador::deFormulario(['nome' => 'Ana', 'tipo' => 'goleiro']));
        $this->expectException(\RcInfoti\Pelada\Jogadores\DadosInvalidos::class);
        $this->repo()->atualizar(1, $ana, DadosJogador::deFormulario(['nome' => 'Bia', 'tipo' => 'linha']));
    }

    public function test_atualizar_de_outra_pelada_falha(): void
    {
        $id = $this->repo()->criar(1, DadosJogador::deFormulario(['nome' => 'Ana', 'tipo' => 'linha']));

        $this->assertFalse($this->repo()->atualizar(2, $id, DadosJogador::deFormulario(['nome' => 'Xande', 'tipo' => 'linha'])));
        $this->assertSame('Ana', $this->repo()->buscar(1, $id)['nome']);
    }

    public function test_desativar_some_da_lista_padrao_e_do_login(): void
    {
        $id = $this->repo()->criar(1, DadosJogador::deFormulario(['nome' => 'Ana', 'tipo' => 'linha']));
        $this->repo()->definirAtivo(1, $id, false);

        $this->assertSame([], $this->repo()->listar(1));
        $todos = $this->repo()->listar(1, incluirInativos: true);
        $this->assertCount(1, $todos);
        $this->assertFalse($todos[0]['ativo']);
    }

    public function test_listar_indica_quem_tem_pin(): void
    {
        $id = $this->repo()->criar(1, DadosJogador::deFormulario(['nome' => 'Ana', 'tipo' => 'linha']));
        $this->assertFalse($this->repo()->listar(1)[0]['tem_pin']);

        $this->pdo->exec("UPDATE jogadores SET pin_hash = 'x' WHERE id = {$id}");
        $this->assertTrue($this->repo()->listar(1)[0]['tem_pin']);
    }
}
