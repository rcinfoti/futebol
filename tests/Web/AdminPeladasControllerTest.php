<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Acesso\Autorizacao;
use RcInfoti\Pelada\Acesso\Papel;
use RcInfoti\Pelada\Acesso\ServicoAcessoOrganizador;
use RcInfoti\Pelada\Peladas\RepositorioPeladaPdo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;
use RcInfoti\Pelada\Web\AdminPeladasController;
use RcInfoti\Pelada\Web\Csrf;
use RcInfoti\Pelada\Web\Request;
use RcInfoti\Pelada\Web\Response;

final class AdminPeladasControllerTest extends TestCase
{
    private PDO $pdo;
    private SessaoMemoria $sessao;
    private Csrf $csrf;
    private int $admin;
    private int $org;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta'), (2, 'Sabado', 'sabado')");
        $a = new ServicoAcessoOrganizador($this->pdo);
        $this->admin = $a->criar('Rogério', 'r@x.com', 'senha-forte-1', Papel::SuperAdmin);
        $this->org = $a->criar('Org', 'o@x.com', 'senha-forte-2', Papel::Organizador);
        (new Autorizacao($this->pdo))->vincular(1, $this->org);
        $this->sessao = new SessaoMemoria();
        $this->csrf = new Csrf($this->sessao);
    }

    private function c(): AdminPeladasController
    {
        return new AdminPeladasController(
            $this->sessao, $this->csrf, new ServicoAcessoOrganizador($this->pdo), new Autorizacao($this->pdo), '',
            new RepositorioPeladaPdo($this->pdo),
        );
    }

    private function como(int $uid): self
    {
        $this->sessao->set('usuario_id', $uid);

        return $this;
    }

    /** @param array<string,mixed> $d */
    private function post(array $d = []): Request
    {
        return new Request('POST', '/x', post: $d + ['_csrf' => $this->csrf->token()]);
    }

    private function local(Response $r): string
    {
        return $r->cabecalhos['Location'] ?? '';
    }

    /** @return array<string,string> */
    private function config(array $troca = []): array
    {
        return $troca + [
            'nome' => 'Quinta', 'slug' => 'quinta', 'dia_jogo' => '4', 'hora_jogo' => '20:00',
            'abre_dia' => '7', 'abre_hora' => '08:00', 'vira_regra_dia' => '3', 'vira_regra_hora' => '12:00',
            'prazo_multa_dia' => '4', 'prazo_multa_hora' => '16:00', 'limite_linha' => '18', 'limite_goleiro' => '3',
            'valor_futebol' => '17,50', 'valor_festa_semana' => '5', 'valor_festa_ano' => '220',
            'festa_inicio' => '01/01', 'festa_fim' => '30/11',
        ];
    }

    // --- config da pelada (organizador) ----------------------------------------

    public function test_organizador_edita_config_da_sua_pelada(): void
    {
        $this->como($this->org);
        $form = $this->c()->config(new Request('GET', '/x'), 1);
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="valor_festa_ano"', $form->corpo);

        $r = $this->c()->salvarConfig($this->post($this->config()), 1);

        $this->assertSame('/admin/p/1/config', $this->local($r));
        $row = $this->pdo->query('SELECT limite_linha, valor_futebol FROM peladas WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([18, 17.5], [(int) $row['limite_linha'], (float) $row['valor_futebol']]);
    }

    public function test_config_invalida_reexibe_com_erro(): void
    {
        $this->como($this->org);
        $r = $this->c()->salvarConfig($this->post($this->config(['prazo_multa_hora' => '21:00'])), 1);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('prazo da multa não pode ser depois do jogo', $r->corpo);
        $this->assertStringContainsString('value="17,50"', $r->corpo); // mantém o digitado
    }

    public function test_organizador_nao_configura_pelada_alheia(): void
    {
        $this->como($this->org);
        $this->assertSame(403, $this->c()->salvarConfig($this->post($this->config(['nome' => 'Hack'])), 2)->status);
        $this->assertSame('Sabado', $this->pdo->query('SELECT nome FROM peladas WHERE id = 2')->fetchColumn());
    }

    // --- peladas (super admin) ---------------------------------------------------

    public function test_super_admin_cria_pelada(): void
    {
        $this->como($this->admin);
        $r = $this->c()->criarPelada($this->post($this->config(['nome' => 'Pelada de Domingo', 'slug' => ''])));

        $id = (int) $this->pdo->query("SELECT id FROM peladas WHERE slug = 'pelada-de-domingo'")->fetchColumn();
        $this->assertGreaterThan(0, $id);
        $this->assertSame("/admin/p/{$id}", $this->local($r));
    }

    public function test_organizador_nao_cria_pelada_nem_ve_organizadores(): void
    {
        $this->como($this->org);
        $this->assertSame(403, $this->c()->criarPelada($this->post($this->config(['nome' => 'X Pelada', 'slug' => 'x'])))->status);
        $this->assertSame(403, $this->c()->organizadores(new Request('GET', '/x'))->status);
        $this->assertSame(403, $this->c()->criarOrganizador($this->post(['nome' => 'Y', 'email' => 'y@x.com', 'papel' => 'super_admin']))->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE email = 'y@x.com'")->fetchColumn());
    }

    public function test_desativar_pelada(): void
    {
        $this->como($this->admin);
        $this->c()->alternarPelada($this->post(['ativa' => '0']), 2);
        $this->assertSame(0, (int) $this->pdo->query('SELECT ativa FROM peladas WHERE id = 2')->fetchColumn());
    }

    // --- organizadores (super admin) ---------------------------------------------

    public function test_cria_organizador_com_senha_mostrada_uma_vez_e_vinculos(): void
    {
        $this->como($this->admin);
        $r = $this->c()->criarOrganizador($this->post([
            'nome' => 'Novo Org', 'email' => 'novo@x.com', 'papel' => 'organizador', 'peladas' => ['2'],
        ]));
        $id = (int) $this->pdo->query("SELECT id FROM usuarios WHERE email = 'novo@x.com'")->fetchColumn();
        $this->assertSame("/admin/organizadores/{$id}", $this->local($r));

        $tela = $this->c()->editarOrganizador(new Request('GET', '/x'), $id);
        $this->assertSame(1, preg_match('/data-senha="([A-Za-z0-9]{12})"/', $tela->corpo, $m));
        $this->assertSame($id, (new ServicoAcessoOrganizador($this->pdo))->autenticar('novo@x.com', $m[1], new \DateTimeImmutable()));
        $this->assertTrue((new Autorizacao($this->pdo))->podeGerir($id, 2));
        $this->assertStringNotContainsString('data-senha=', $this->c()->editarOrganizador(new Request('GET', '/x'), $id)->corpo);
    }

    public function test_salvar_vinculos_e_redefinir_senha(): void
    {
        $this->como($this->admin);
        $this->c()->salvarOrganizador($this->post(['peladas' => ['2']]), $this->org);
        $a = new Autorizacao($this->pdo);
        $this->assertFalse($a->podeGerir($this->org, 1));
        $this->assertTrue($a->podeGerir($this->org, 2));

        $this->c()->redefinirSenha($this->post(), $this->org);
        $this->assertNull((new ServicoAcessoOrganizador($this->pdo))->autenticar('o@x.com', 'senha-forte-2', new \DateTimeImmutable()));
    }

    public function test_super_admin_nao_se_desativa(): void
    {
        $this->como($this->admin);
        $this->c()->alternarOrganizador($this->post(['ativo' => '0']), $this->admin);
        $this->assertSame(1, (int) $this->pdo->query("SELECT ativo FROM usuarios WHERE id = {$this->admin}")->fetchColumn());

        $this->c()->alternarOrganizador($this->post(['ativo' => '0']), $this->org);
        $this->assertSame(0, (int) $this->pdo->query("SELECT ativo FROM usuarios WHERE id = {$this->org}")->fetchColumn());
    }

    public function test_email_duplicado_reexibe_form(): void
    {
        $this->como($this->admin);
        $r = $this->c()->criarOrganizador($this->post(['nome' => 'Dup', 'email' => 'o@x.com', 'papel' => 'organizador']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Já existe usuário com esse e-mail', $r->corpo);
    }

    // --- minha senha (qualquer usuário) -------------------------------------------

    public function test_trocar_minha_senha(): void
    {
        $this->como($this->org);
        $this->assertSame(200, $this->c()->minhaSenha(new Request('GET', '/x'))->status);

        $errada = $this->c()->salvarMinhaSenha($this->post(['atual' => 'nao', 'nova' => 'nova-senha-123', 'repete' => 'nova-senha-123']));
        $this->assertSame(422, $errada->status);

        $diferente = $this->c()->salvarMinhaSenha($this->post(['atual' => 'senha-forte-2', 'nova' => 'nova-senha-123', 'repete' => 'outra-coisa']));
        $this->assertSame(422, $diferente->status);

        $ok = $this->c()->salvarMinhaSenha($this->post(['atual' => 'senha-forte-2', 'nova' => 'nova-senha-123', 'repete' => 'nova-senha-123']));
        $this->assertSame(302, $ok->status);
        $this->assertSame($this->org, (new ServicoAcessoOrganizador($this->pdo))->autenticar('o@x.com', 'nova-senha-123', new \DateTimeImmutable()));
    }
}
