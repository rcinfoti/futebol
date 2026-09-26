<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Acesso\Autorizacao;
use RcInfoti\Pelada\Acesso\Papel;
use RcInfoti\Pelada\Acesso\ServicoAcessoOrganizador;
use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Admin\ConsultaPainelPelada;
use RcInfoti\Pelada\Admin\GestaoRodada;
use RcInfoti\Pelada\Admin\RecebimentoPagamento;
use RcInfoti\Pelada\Multa\RepositorioMultaPdo;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Jogadores\RepositorioJogadorPdo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;
use RcInfoti\Pelada\Web\AdminController;
use RcInfoti\Pelada\Web\Csrf;
use RcInfoti\Pelada\Web\Request;
use RcInfoti\Pelada\Web\Response;

final class AdminControllerTest extends TestCase
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
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha'), (20, 2, 'Zeca', 'linha')");

        $acesso = new ServicoAcessoOrganizador($this->pdo);
        $this->admin = $acesso->criar('Rogério', 'r@x.com', 'senha-forte-1', Papel::SuperAdmin);
        $this->org = $acesso->criar('Org', 'o@x.com', 'senha-forte-2', Papel::Organizador);
        (new Autorizacao($this->pdo))->vincular(1, $this->org); // org só gerencia a Quinta

        $this->sessao = new SessaoMemoria();
        $this->csrf = new Csrf($this->sessao);
    }

    private function c(string $base = ''): AdminController
    {
        return new AdminController(
            $this->sessao,
            $this->csrf,
            new ServicoAcessoOrganizador($this->pdo),
            new Autorizacao($this->pdo),
            $base,
            new RepositorioJogadorPdo($this->pdo),
            new ServicoAcessoPin($this->pdo),
            new ConsultaPainelPelada($this->pdo),
            new RepositorioPagamentoPdo($this->pdo),
            new GestaoRodada(
                $this->pdo,
                new ServicoPresenca($this->pdo),
                new ProcessadorRodada(new RepositorioRodadaPdo($this->pdo), new ServicoRegrasRodada()),
            ),
            new RepositorioMultaPdo($this->pdo),
            new RecebimentoPagamento($this->pdo, new RepositorioPagamentoPdo($this->pdo)),
        );
    }

    /** @param array<string,string> $dados */
    private function post(string $caminho, array $dados = []): Request
    {
        return new Request('POST', $caminho, post: $dados + ['_csrf' => $this->csrf->token()]);
    }

    private function logar(int $usuarioId): void
    {
        $this->sessao->set('usuario_id', $usuarioId);
    }

    private function local(Response $r): string
    {
        return $r->cabecalhos['Location'] ?? '';
    }

    // --- login -------------------------------------------------------------

    public function test_login_ok_cria_sessao(): void
    {
        $r = $this->c()->autenticar($this->post('/admin/entrar', ['email' => 'R@X.com', 'senha' => 'senha-forte-1']));

        $this->assertSame('/admin', $this->local($r));
        $this->assertSame($this->admin, $this->sessao->get('usuario_id'));
    }

    public function test_login_errado(): void
    {
        $r = $this->c()->autenticar($this->post('/admin/entrar', ['email' => 'r@x.com', 'senha' => 'nao']));

        $this->assertSame('/admin/entrar?erro=1', $this->local($r));
        $this->assertNull($this->sessao->get('usuario_id'));
    }

    public function test_login_sem_csrf(): void
    {
        $r = $this->c()->autenticar(new Request('POST', '/admin/entrar', post: ['email' => 'r@x.com', 'senha' => 'senha-forte-1']));
        $this->assertSame(400, $r->status);
    }

    public function test_sessao_de_jogador_nao_abre_admin(): void
    {
        $this->sessao->set('jogador_id', 10);
        $this->assertSame('/admin/entrar', $this->local($this->c()->inicio(new Request('GET', '/admin'))));
    }

    public function test_sair(): void
    {
        $this->logar($this->admin);
        $r = $this->c()->sair($this->post('/admin/sair'));

        $this->assertSame('/admin/entrar', $this->local($r));
        $this->assertNull($this->sessao->get('usuario_id'));
    }

    // --- início / autorização ------------------------------------------------

    public function test_inicio_organizador_com_uma_pelada_vai_direto(): void
    {
        $this->logar($this->org);
        $this->assertSame('/admin/p/1', $this->local($this->c()->inicio(new Request('GET', '/admin'))));
    }

    public function test_inicio_super_admin_lista_todas(): void
    {
        $this->logar($this->admin);
        $r = $this->c('/futebol')->inicio(new Request('GET', '/admin'));

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('/futebol/admin/p/1', $r->corpo);
        $this->assertStringContainsString('/futebol/admin/p/2', $r->corpo);
    }

    public function test_organizador_nao_abre_pelada_alheia(): void
    {
        $this->logar($this->org);

        $this->assertSame(403, $this->c()->painel(new Request('GET', '/admin/p/2'), 2)->status);
        $this->assertSame(403, $this->c()->jogadores(new Request('GET', '/admin/p/2/jogadores'), 2)->status);
        $this->assertSame(403, $this->c()->gerarPin($this->post('/admin/p/2/jogadores/20/pin'), 2, 20)->status);
    }

    public function test_jogador_de_outra_pelada_via_url_da_minha_da_404(): void
    {
        $this->logar($this->org);

        // pelada 1 é minha, mas o jogador 20 é da pelada 2
        $this->assertSame(404, $this->c()->editarJogador(new Request('GET', '/admin/p/1/jogadores/20'), 1, 20)->status);
        $this->assertSame(404, $this->c()->gerarPin($this->post('/admin/p/1/jogadores/20/pin'), 1, 20)->status);
        $this->assertNull($this->pdo->query('SELECT pin_hash FROM jogadores WHERE id = 20')->fetchColumn());
    }

    public function test_painel_mostra_link_da_pelada(): void
    {
        $this->logar($this->org);
        $r = $this->c('/futebol')->painel(new Request('GET', '/admin/p/1'), 1);

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('/futebol/p/quinta/entrar', $r->corpo);
    }

    public function test_nome_da_pelada_no_script_e_seguro(): void
    {
        $this->pdo->exec("UPDATE peladas SET nome = 'Q</script><script>alert(1)</script>' WHERE id = 1");
        $this->logar($this->org);

        $r = $this->c()->painel(new Request('GET', '/admin/p/1'), 1);
        $this->assertStringNotContainsString('</script><script>alert(1)', $r->corpo);
    }

    // --- jogadores -----------------------------------------------------------

    public function test_cria_jogador_e_vai_pra_ficha(): void
    {
        $this->logar($this->org);
        $r = $this->c()->criarJogador($this->post('/admin/p/1/jogadores', ['nome' => 'Bia', 'tipo' => 'goleiro']), 1);

        $id = (int) $this->pdo->query("SELECT id FROM jogadores WHERE nome = 'Bia'")->fetchColumn();
        $this->assertSame("/admin/p/1/jogadores/{$id}", $this->local($r));
        $this->assertSame(1, (int) $this->pdo->query("SELECT pelada_id FROM jogadores WHERE id = {$id}")->fetchColumn());
    }

    public function test_cria_jogador_invalido_reexibe_form_com_erro_e_valores(): void
    {
        $this->logar($this->org);
        $r = $this->c()->criarJogador($this->post('/admin/p/1/jogadores', ['nome' => 'Ana', 'tipo' => 'linha', 'email' => 'bia@x.com']), 1);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Já tem um jogador com esse nome', $r->corpo);
        $this->assertStringContainsString('bia@x.com', $r->corpo); // não perde o que foi digitado
    }

    public function test_edita_jogador(): void
    {
        $this->logar($this->org);
        $r = $this->c()->salvarJogador($this->post('/admin/p/1/jogadores/10', ['nome' => 'Ana Paula', 'tipo' => 'linha']), 1, 10);

        $this->assertSame('/admin/p/1/jogadores/10', $this->local($r));
        $this->assertSame('Ana Paula', $this->pdo->query('SELECT nome FROM jogadores WHERE id = 10')->fetchColumn());
    }

    public function test_gerar_pin_mostra_uma_vez_e_autentica(): void
    {
        $this->logar($this->org);
        $r = $this->c()->gerarPin($this->post('/admin/p/1/jogadores/10/pin'), 1, 10);
        $this->assertSame('/admin/p/1/jogadores/10', $this->local($r));

        $ficha = $this->c()->editarJogador(new Request('GET', '/admin/p/1/jogadores/10'), 1, 10);
        $this->assertSame(1, preg_match('/data-pin="(\d{4})"/', $ficha->corpo, $m));
        $this->assertTrue((new ServicoAcessoPin($this->pdo))->autenticar(10, $m[1]));

        // recarregar a ficha não mostra de novo
        $denovo = $this->c()->editarJogador(new Request('GET', '/admin/p/1/jogadores/10'), 1, 10);
        $this->assertStringNotContainsString('data-pin=', $denovo->corpo);
    }

    public function test_desativar_e_reativar(): void
    {
        $this->logar($this->org);
        $this->c()->alternarAtivo($this->post('/admin/p/1/jogadores/10/ativo', ['ativo' => '0']), 1, 10);
        $this->assertSame(0, (int) $this->pdo->query('SELECT ativo FROM jogadores WHERE id = 10')->fetchColumn());

        $this->c()->alternarAtivo($this->post('/admin/p/1/jogadores/10/ativo', ['ativo' => '1']), 1, 10);
        $this->assertSame(1, (int) $this->pdo->query('SELECT ativo FROM jogadores WHERE id = 10')->fetchColumn());
    }

    public function test_acoes_exigem_csrf(): void
    {
        $this->logar($this->org);
        $r = $this->c()->gerarPin(new Request('POST', '/admin/p/1/jogadores/10/pin', post: ['_csrf' => 'x']), 1, 10);

        $this->assertSame(400, $r->status);
        $this->assertNull($this->pdo->query('SELECT pin_hash FROM jogadores WHERE id = 10')->fetchColumn());
    }

    public function test_nome_do_jogador_e_escapado(): void
    {
        $this->pdo->exec("UPDATE jogadores SET nome = '<script>x</script>' WHERE id = 10");
        $this->logar($this->org);

        $r = $this->c()->jogadores(new Request('GET', '/admin/p/1/jogadores'), 1);
        $this->assertStringNotContainsString('<script>x</script>', $r->corpo);
        $this->assertStringContainsString('&lt;script&gt;', $r->corpo);
    }

    // --- pagamentos ----------------------------------------------------------

    public function test_confirma_pagamento_da_pelada_gera_caixa(): void
    {
        $this->pdo->exec("INSERT INTO pagamentos (id, pelada_id, jogador_id, categoria, escopo, valor, forma, confirmado, criado_em)
            VALUES (7, 1, 10, 'futebol', 'semana', 15, 'pix', 0, '2026-01-06 10:00:00')");
        $this->logar($this->org);

        $r = $this->c()->confirmarPagamento($this->post('/admin/p/1/pagamentos/7/confirmar'), 1, 7);

        $this->assertSame('/admin/p/1', $this->local($r));
        $this->assertSame(1, (int) $this->pdo->query('SELECT confirmado FROM pagamentos WHERE id = 7')->fetchColumn());
        $this->assertSame(15.0, (float) $this->pdo->query('SELECT valor FROM movimentos_caixa WHERE pagamento_id = 7')->fetchColumn());
    }

    public function test_nao_confirma_pagamento_de_outra_pelada_pela_url_da_minha(): void
    {
        $this->pdo->exec("INSERT INTO pagamentos (id, pelada_id, jogador_id, categoria, escopo, valor, forma, confirmado, criado_em)
            VALUES (8, 2, 20, 'futebol', 'semana', 15, 'pix', 0, '2026-01-06 10:00:00')");
        $this->logar($this->org);

        $r = $this->c()->confirmarPagamento($this->post('/admin/p/1/pagamentos/8/confirmar'), 1, 8);

        $this->assertSame(404, $r->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT confirmado FROM pagamentos WHERE id = 8')->fetchColumn());
    }

    // --- rodada manual (Plano 8) ---------------------------------------------

    private function rodadaFutura(): void
    {
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em) VALUES
            (5, 1, '2099-01-08', 'aberta', '2099-01-07 12:00:00', '2099-01-08 16:00:00'),
            (6, 2, '2099-01-10', 'aberta', '2099-01-09 12:00:00', '2099-01-10 16:00:00')");
    }

    public function test_organizador_coloca_jogador_na_rodada_pelo_painel(): void
    {
        $this->rodadaFutura();
        $this->logar($this->org);

        $painel = $this->c()->painel(new Request('GET', '/admin/p/1'), 1);
        $this->assertStringContainsString('name="jogador_id"', $painel->corpo); // seletor de quem está fora

        $r = $this->c()->rodadaAdicionar($this->post('/admin/p/1/rodada/adicionar', ['rodada_id' => '5', 'jogador_id' => '10']), 1);

        $this->assertSame('/admin/p/1', $this->local($r));
        $this->assertSame('confirmado', $this->pdo->query('SELECT status FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 10')->fetchColumn());
    }

    public function test_nao_mexe_em_rodada_de_outra_pelada_pela_url_da_minha(): void
    {
        $this->rodadaFutura();
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES (6, 20, 'linha', 'confirmado', 1)");
        $this->logar($this->org);

        $this->c()->rodadaDesistir($this->post('/admin/p/1/rodada/20/desistir', ['rodada_id' => '6']), 1, 20);
        $this->c()->rodadaAdicionar($this->post('/admin/p/1/rodada/adicionar', ['rodada_id' => '6', 'jogador_id' => '10']), 1);

        $this->assertSame('confirmado', $this->pdo->query('SELECT status FROM inscricoes WHERE jogador_id = 20')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inscricoes')->fetchColumn());
    }

    public function test_promover_e_desistir_pelo_painel(): void
    {
        $this->rodadaFutura();
        $this->pdo->exec("UPDATE peladas SET limite_linha = 1 WHERE id = 1");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (11, 1, 'Bia', 'linha')");
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES
            (5, 10, 'linha', 'confirmado', 1), (5, 11, 'linha', 'espera', 2)");
        $this->logar($this->org);

        $this->c()->rodadaPromover($this->post('/admin/p/1/rodada/11/promover', ['rodada_id' => '5']), 1, 11);
        $this->assertSame('confirmado', $this->pdo->query('SELECT status FROM inscricoes WHERE jogador_id = 11')->fetchColumn());

        $this->c()->rodadaDesistir($this->post('/admin/p/1/rodada/10/desistir', ['rodada_id' => '5']), 1, 10);
        $this->assertSame('desistiu', $this->pdo->query('SELECT status FROM inscricoes WHERE jogador_id = 10')->fetchColumn());
    }

    // --- multas e pagamento recebido (Plano 8) --------------------------------

    public function test_ficha_lista_multas_e_recebe_multa(): void
    {
        $this->pdo->exec("UPDATE jogadores SET saldo_pendente = 20 WHERE id = 10");
        $this->pdo->exec("INSERT INTO multas (id, pelada_id, jogador_id, rodada_id, valor, status, criado_em)
            VALUES (50, 1, 10, 5, 20, 'pendente', '2026-01-08 17:00:00')");
        $this->logar($this->org);

        $ficha = $this->c()->editarJogador(new Request('GET', '/admin/p/1/jogadores/10'), 1, 10);
        $this->assertStringContainsString('/admin/p/1/multas/50/receber', $ficha->corpo);

        $r = $this->c()->multaReceber($this->post('/admin/p/1/multas/50/receber', ['jogador_id' => '10']), 1, 50);

        $this->assertSame('/admin/p/1/jogadores/10', $this->local($r));
        $this->assertSame('paga', $this->pdo->query('SELECT status FROM multas WHERE id = 50')->fetchColumn());
        $this->assertSame(20.0, (float) $this->pdo->query("SELECT valor FROM movimentos_caixa WHERE categoria = 'multa'")->fetchColumn());
    }

    public function test_nao_cancela_multa_de_outra_pelada(): void
    {
        $this->pdo->exec("INSERT INTO multas (id, pelada_id, jogador_id, rodada_id, valor, status, criado_em)
            VALUES (51, 2, 20, 6, 20, 'pendente', '2026-01-08 17:00:00')");
        $this->logar($this->org);

        $this->c()->multaCancelar($this->post('/admin/p/1/multas/51/cancelar'), 1, 51);

        $this->assertSame('pendente', $this->pdo->query('SELECT status FROM multas WHERE id = 51')->fetchColumn());
    }

    public function test_organizador_registra_pagamento_recebido(): void
    {
        $this->logar($this->org);
        $r = $this->c()->receberPagamento($this->post('/admin/p/1/jogadores/10/pagamento', ['tipo' => 'festa_ano', 'forma' => 'dinheiro', 'valor' => '']), 1, 10);

        $this->assertSame('/admin/p/1/jogadores/10', $this->local($r));
        $row = $this->pdo->query('SELECT categoria, escopo, valor, confirmado FROM pagamentos')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['festa', 'ano', 220.0, 1], [$row['categoria'], $row['escopo'], (float) $row['valor'], (int) $row['confirmado']]);
    }

    public function test_pagamento_com_valor_lixo_nao_registra(): void
    {
        $this->logar($this->org);
        $this->c()->receberPagamento($this->post('/admin/p/1/jogadores/10/pagamento', ['tipo' => 'futebol', 'forma' => 'pix', 'valor' => 'abc']), 1, 10);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pagamentos')->fetchColumn());
    }

    public function test_pagamento_pra_jogador_de_outra_pelada_da_404(): void
    {
        $this->logar($this->org);
        $r = $this->c()->receberPagamento($this->post('/admin/p/1/jogadores/20/pagamento', ['tipo' => 'futebol', 'forma' => 'pix']), 1, 20);

        $this->assertSame(404, $r->status);
    }

    public function test_abrir_o_painel_reavalia_a_rodada(): void
    {
        // Spec §6: regras reavaliadas quando organizador OU jogador abre a página.
        $this->rodadaFutura();
        $this->pdo->exec("UPDATE peladas SET limite_linha = 2 WHERE id = 1");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (11, 1, 'Bia', 'linha')");
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES
            (5, 10, 'linha', 'confirmado', 1), (5, 11, 'linha', 'espera', 2)"); // vaga livre + alguém na espera
        $this->logar($this->org);

        $this->c()->painel(new Request('GET', '/admin/p/1'), 1);

        $this->assertSame('confirmado', $this->pdo->query('SELECT status FROM inscricoes WHERE jogador_id = 11')->fetchColumn());
    }
}
