<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Painel\ConsultaPainelJogador;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
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

    private function controller(): PainelJogadorController
    {
        return new PainelJogadorController(
            $this->sessao,
            $this->csrf,
            new ServicoAcessoPin($this->pdo),
            new ServicoPresenca($this->pdo),
            new ConsultaPainelJogador($this->pdo),
            new RepositorioPagamentoPdo($this->pdo),
        );
    }

    public function test_login_com_pin_correto_cria_sessao_e_redireciona(): void
    {
        $req = new Request('POST', '/entrar', post: ['jogador_id' => '10', 'pin' => '1234', '_csrf' => $this->csrf->token()]);
        $resp = $this->controller()->autenticar($req);

        $this->assertSame(302, $resp->status);
        $this->assertSame('/', $resp->cabecalhos['Location']);
        $this->assertSame(10, $this->sessao->get('jogador_id'));
    }

    public function test_login_com_pin_errado_nao_autentica(): void
    {
        $req = new Request('POST', '/entrar', post: ['jogador_id' => '10', 'pin' => '0000', '_csrf' => $this->csrf->token()]);
        $resp = $this->controller()->autenticar($req);

        $this->assertSame(302, $resp->status);
        $this->assertSame('/entrar?erro=1', $resp->cabecalhos['Location']);
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
}
