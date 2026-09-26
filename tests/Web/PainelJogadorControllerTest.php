<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Painel\ConsultaPainelJogador;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Web\Csrf;
use RcInfoti\Pelada\Web\PainelJogadorController;
use RcInfoti\Pelada\Web\Request;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class PainelJogadorControllerTest extends TestCase
{
    private PDO $pdo;
    private SessaoMemoria $sessao;
    private Csrf $csrf;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro) VALUES (1, 'Quinta', 'quinta', 20, 4)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha')");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        $this->sessao = new SessaoMemoria();
        $this->csrf = new Csrf($this->sessao);
        (new ServicoAcessoPin($this->pdo))->definirPin(10, '1234');
    }

    private ?string $dirComprovantes = null;

    private function armazem(): \RcInfoti\Pelada\Financeiro\ArmazemComprovantes
    {
        $this->dirComprovantes ??= sys_get_temp_dir() . '/comp_' . uniqid();

        return new \RcInfoti\Pelada\Financeiro\ArmazemComprovantes($this->dirComprovantes, static fn (string $a, string $b): bool => rename($a, $b));
    }

    private function controller(string $base = ''): PainelJogadorController
    {
        return new PainelJogadorController(
            $this->sessao,
            $this->csrf,
            new ServicoAcessoPin($this->pdo),
            new ServicoPresenca($this->pdo),
            new ConsultaPainelJogador($this->pdo),
            new RepositorioPagamentoPdo($this->pdo),
            new ProcessadorRodada(new RepositorioRodadaPdo($this->pdo), new ServicoRegrasRodada()),
            $base,
            $this->armazem(),
        );
    }

    private function login(string $jogadorId, string $pin): \RcInfoti\Pelada\Web\Response
    {
        return $this->controller()->autenticar(
            new Request('POST', '/p/quinta/entrar', post: ['jogador_id' => $jogadorId, 'pin' => $pin, '_csrf' => $this->csrf->token()]),
            'quinta',
        );
    }

    public function test_login_com_pin_correto_cria_sessao_e_redireciona(): void
    {
        $resp = $this->login('10', '1234');

        $this->assertSame(302, $resp->status);
        $this->assertSame('/', $resp->cabecalhos['Location']);
        $this->assertSame(10, $this->sessao->get('jogador_id'));
    }

    public function test_login_com_pin_errado_nao_autentica(): void
    {
        $resp = $this->login('10', '0000');

        $this->assertSame(302, $resp->status);
        $this->assertSame('/p/quinta/entrar?erro=1', $resp->cabecalhos['Location']);
        $this->assertNull($this->sessao->get('jogador_id'));
    }

    public function test_painel_sem_sessao_redireciona(): void
    {
        $resp = $this->controller()->painel(new Request('GET', '/'));
        $this->assertSame(302, $resp->status);
        $this->assertSame('/entrar', $resp->cabecalhos['Location']);
    }

    public function test_confirmar_sem_sessao_redireciona(): void
    {
        $resp = $this->controller()->confirmar(new Request('POST', '/confirmar'));
        $this->assertSame('/entrar', $resp->cabecalhos['Location']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inscricoes')->fetchColumn());
    }

    public function test_confirmar_com_csrf_invalido_rejeita(): void
    {
        $this->sessao->set('jogador_id', 10);
        $resp = $this->controller()->confirmar(new Request('POST', '/confirmar', post: ['_csrf' => 'errado']));

        $this->assertSame(400, $resp->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inscricoes')->fetchColumn());
    }

    public function test_confirmar_logado_e_com_csrf_insere_inscricao(): void
    {
        $this->sessao->set('jogador_id', 10);
        $resp = $this->controller()->confirmar(new Request('POST', '/confirmar', post: ['_csrf' => $this->csrf->token()]));

        $this->assertSame(302, $resp->status);
        $this->assertSame('confirmado', $this->pdo->query('SELECT status FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 10')->fetchColumn());
    }

    public function test_avisar_pagamento_registra(): void
    {
        $this->sessao->set('jogador_id', 10);
        $req = new Request('POST', '/avisar-pagamento', post: [
            '_csrf' => $this->csrf->token(),
            'categoria' => 'futebol',
            'forma' => 'pix',
            'valor' => '15',
        ]);

        $resp = $this->controller()->avisarPagamento($req);

        $this->assertSame(302, $resp->status);
        $row = $this->pdo->query('SELECT categoria, valor, confirmado FROM pagamentos WHERE jogador_id = 10')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('futebol', $row['categoria']);
        $this->assertSame(15.0, (float) $row['valor']);
        $this->assertSame(0, (int) $row['confirmado']); // aviso do jogador entra não confirmado
    }

    public function test_login_de_jogador_de_outra_pelada_e_recusado(): void
    {
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (2, 'Sabado', 'sabado')");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (20, 2, 'Zeca', 'linha')");
        (new ServicoAcessoPin($this->pdo))->definirPin(20, '9999');

        $resp = $this->login('20', '9999'); // PIN certo, mas no link da pelada errada

        $this->assertSame('/p/quinta/entrar?erro=1', $resp->cabecalhos['Location']);
        $this->assertNull($this->sessao->get('jogador_id'));
    }

    public function test_login_bloqueado_apos_falhas_avisa(): void
    {
        for ($i = 0; $i < ServicoAcessoPin::MAX_FALHAS; $i++) {
            $this->login('10', '0000');
        }

        $resp = $this->login('10', '1234');

        $this->assertSame('/p/quinta/entrar?bloqueado=1', $resp->cabecalhos['Location']);
        $this->assertNull($this->sessao->get('jogador_id'));
    }

    public function test_entrar_sem_slug_com_uma_pelada_redireciona_pro_link_dela(): void
    {
        $resp = $this->controller()->entrar(new Request('GET', '/entrar'));

        $this->assertSame('/p/quinta/entrar', $resp->cabecalhos['Location']);
    }

    public function test_entrar_sem_slug_com_varias_peladas_mostra_escolha(): void
    {
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (2, 'Sabado', 'sabado')");

        $resp = $this->controller()->entrar(new Request('GET', '/entrar'));

        $this->assertSame(200, $resp->status);
        $this->assertStringContainsString('/p/quinta/entrar', $resp->corpo);
        $this->assertStringContainsString('/p/sabado/entrar', $resp->corpo);
    }

    public function test_entrar_com_slug_inexistente_da_404(): void
    {
        $resp = $this->controller()->entrar(new Request('GET', '/p/xpto/entrar'), 'xpto');

        $this->assertSame(404, $resp->status);
    }

    public function test_tela_de_login_lista_so_jogadores_da_pelada(): void
    {
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (2, 'Sabado', 'sabado')");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (20, 2, 'Zeca', 'linha')");

        $resp = $this->controller()->entrar(new Request('GET', '/p/quinta/entrar'), 'quinta');

        $this->assertStringContainsString('Ana', $resp->corpo);
        $this->assertStringNotContainsString('Zeca', $resp->corpo);
        $this->assertStringContainsString('Quinta', $resp->corpo); // nome real da pelada, não fixo
    }

    public function test_redirecionamentos_respeitam_o_subdiretorio(): void
    {
        $resp = $this->controller('/futebol')->painel(new Request('GET', '/'));

        $this->assertSame('/futebol/entrar', $resp->cabecalhos['Location']);
    }

    public function test_sair_volta_pro_login_da_pelada(): void
    {
        $this->sessao->set('jogador_id', 10);
        $resp = $this->controller()->sair(new Request('POST', '/sair', post: ['_csrf' => $this->csrf->token()]));

        $this->assertSame('/p/quinta/entrar', $resp->cabecalhos['Location']);
        $this->assertNull($this->sessao->get('jogador_id'));
    }

    public function test_sair_exige_csrf(): void
    {
        $this->sessao->set('jogador_id', 10);
        $resp = $this->controller()->sair(new Request('POST', '/sair', post: ['_csrf' => 'errado']));

        $this->assertSame(400, $resp->status);
        $this->assertSame(10, $this->sessao->get('jogador_id'));
    }

    public function test_nao_vou_promove_o_primeiro_da_espera_na_hora(): void
    {
        // rodada no futuro (janela FIFO) e só 1 vaga de linha
        $this->pdo->exec("UPDATE peladas SET limite_linha = 1 WHERE id = 1");
        $this->pdo->exec("UPDATE rodadas SET data_jogo = '2099-01-08', vira_regra_em = '2099-01-07 12:00:00',
            prazo_multa_em = '2099-01-08 16:00:00' WHERE id = 5");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (11, 1, 'Bia', 'linha')");
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem, confirmado_em) VALUES
            (5, 10, 'linha', 'confirmado', 1, '2098-12-31 10:00:00'),
            (5, 11, 'linha', 'espera', 2, '2098-12-31 10:05:00')");

        $this->sessao->set('jogador_id', 10);
        $this->controller()->naoVou(new Request('POST', '/nao-vou', post: ['_csrf' => $this->csrf->token()]));

        // não espera o cron: a Bia sobe na mesma requisição (spec §6, rede de segurança)
        $this->assertSame('confirmado', $this->pdo->query('SELECT status FROM inscricoes WHERE jogador_id = 11')->fetchColumn());
    }

    #[DataProvider('valoresInvalidos')]
    public function test_avisar_pagamento_com_valor_invalido_nao_registra(string $valor): void
    {
        $this->sessao->set('jogador_id', 10);
        $resp = $this->controller()->avisarPagamento(new Request('POST', '/avisar-pagamento', post: [
            '_csrf' => $this->csrf->token(), 'categoria' => 'futebol', 'forma' => 'pix', 'valor' => $valor,
        ]));

        $this->assertSame(302, $resp->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pagamentos')->fetchColumn());
    }

    /** @return array<string,array{string}> */
    public static function valoresInvalidos(): array
    {
        return ['zero' => ['0'], 'negativo' => ['-10'], 'texto' => ['abc'], 'absurdo' => ['99999']];
    }

    /** @return array{name:string,tmp_name:string,size:int,error:int} */
    private function arquivo(string $conteudo, string $nome): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $conteudo);

        return ['name' => $nome, 'tmp_name' => $tmp, 'size' => strlen($conteudo), 'error' => UPLOAD_ERR_OK];
    }

    public function test_avisar_pagamento_com_comprovante(): void
    {
        $this->sessao->set('jogador_id', 10);
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
        $req = new Request('POST', '/avisar-pagamento',
            post: ['_csrf' => $this->csrf->token(), 'categoria' => 'futebol', 'forma' => 'pix', 'valor' => '20'],
            arquivos: ['comprovante' => $this->arquivo($pdf, 'pix.pdf')]);

        $resp = $this->controller()->avisarPagamento($req);

        $this->assertStringEndsWith('?pagamento=avisado', $resp->cabecalhos['Location']);
        $nome = (string) $this->pdo->query('SELECT comprovante_arquivo FROM pagamentos')->fetchColumn();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $nome);
        $this->assertFileExists($this->dirComprovantes . '/' . $nome);
    }

    public function test_comprovante_invalido_nao_registra_pagamento(): void
    {
        $this->sessao->set('jogador_id', 10);
        $req = new Request('POST', '/avisar-pagamento',
            post: ['_csrf' => $this->csrf->token(), 'categoria' => 'futebol', 'forma' => 'pix', 'valor' => '20'],
            arquivos: ['comprovante' => $this->arquivo('<?php echo 1;', 'pix.jpg')]);

        $resp = $this->controller()->avisarPagamento($req);

        $this->assertStringEndsWith('?pagamento=comprovante', $resp->cabecalhos['Location']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pagamentos')->fetchColumn());
    }

    public function test_tela_tem_campo_de_comprovante_e_form_multipart(): void
    {
        $this->sessao->set('jogador_id', 10);
        $this->pdo->exec("UPDATE rodadas SET data_jogo = '2099-01-08' WHERE id = 5");
        $html = $this->controller()->painel(new Request('GET', '/'))->corpo;

        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="comprovante"', $html);
    }
}
