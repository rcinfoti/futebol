<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Acesso\Autorizacao;
use RcInfoti\Pelada\Acesso\Papel;
use RcInfoti\Pelada\Acesso\ServicoAcessoOrganizador;
use RcInfoti\Pelada\Financeiro\RelatorioFinanceiroPdo;
use RcInfoti\Pelada\Financeiro\RepositorioCaixaPdo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;
use RcInfoti\Pelada\Web\AdminFinanceiroController;
use RcInfoti\Pelada\Web\Csrf;
use RcInfoti\Pelada\Web\Request;

final class AdminFinanceiroControllerTest extends TestCase
{
    private PDO $pdo;
    private SessaoMemoria $sessao;
    private Csrf $csrf;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta'), (2, 'Sabado', 'sabado')");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha')");
        $org = (new ServicoAcessoOrganizador($this->pdo))->criar('Org', 'o@x.com', 'senha-forte-2', Papel::Organizador);
        (new Autorizacao($this->pdo))->vincular(1, $org);
        $this->sessao = new SessaoMemoria();
        $this->sessao->set('usuario_id', $org);
        $this->csrf = new Csrf($this->sessao);
    }

    private function c(): AdminFinanceiroController
    {
        return new AdminFinanceiroController(
            $this->sessao, $this->csrf, new ServicoAcessoOrganizador($this->pdo), new Autorizacao($this->pdo), '',
            new RepositorioCaixaPdo($this->pdo), new RelatorioFinanceiroPdo($this->pdo),
            new \RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo($this->pdo),
            new \RcInfoti\Pelada\Financeiro\ArmazemComprovantes($this->dir()),
        );
    }

    private ?string $dirComp = null;

    private function dir(): string
    {
        return $this->dirComp ??= sys_get_temp_dir() . '/compadm_' . uniqid();
    }

    /** @param array<string,string> $d */
    private function post(array $d): Request
    {
        return new Request('POST', '/x', post: $d + ['_csrf' => $this->csrf->token()]);
    }

    private function saldo(int $pelada = 1): float
    {
        return (new RepositorioCaixaPdo($this->pdo))->saldo($pelada);
    }

    public function test_lanca_gasto_com_valor_em_formato_brasileiro(): void
    {
        $hoje = (new DateTimeImmutable('now'))->format('Y-m-d');
        $r = $this->c()->lancar($this->post(['tipo' => 'saida', 'categoria' => 'campo', 'valor' => '1.250,50', 'data' => $hoje, 'descricao' => '']), 1);

        $this->assertSame(302, $r->status);
        $this->assertSame(-1250.5, $this->saldo());
        $this->assertSame('Aluguel do campo', $this->pdo->query('SELECT descricao FROM movimentos_caixa')->fetchColumn()); // descrição padrão
    }

    public function test_lanca_entrada_avulsa(): void
    {
        $this->c()->lancar($this->post(['tipo' => 'entrada', 'categoria' => 'ajuste', 'valor' => '300', 'data' => '2026-01-01', 'descricao' => 'Saldo que veio do caderno']), 1);
        $this->assertSame(300.0, $this->saldo());
    }

    public function test_recusa_categoria_reservada_valor_ruim_e_data_futura(): void
    {
        $amanha = (new DateTimeImmutable('+1 day'))->format('Y-m-d');
        foreach ([
            ['tipo' => 'entrada', 'categoria' => 'pagamento', 'valor' => '10', 'data' => '2026-01-01'], // origem própria
            ['tipo' => 'saida', 'categoria' => 'multa', 'valor' => '10', 'data' => '2026-01-01'],
            ['tipo' => 'saida', 'categoria' => 'bola', 'valor' => '-5', 'data' => '2026-01-01'],
            ['tipo' => 'saida', 'categoria' => 'bola', 'valor' => '10', 'data' => $amanha],
            ['tipo' => 'saida', 'categoria' => 'bola', 'valor' => '10', 'data' => '31/01/2026'],
        ] as $d) {
            $this->c()->lancar($this->post($d), 1);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM movimentos_caixa')->fetchColumn());
    }

    public function test_nao_lanca_nem_ve_caixa_de_outra_pelada(): void
    {
        $this->assertSame(403, $this->c()->caixa(new Request('GET', '/x'), 2)->status);
        $this->assertSame(403, $this->c()->lancar($this->post(['tipo' => 'saida', 'categoria' => 'bola', 'valor' => '10', 'data' => '2026-01-01']), 2)->status);
        $this->assertSame(403, $this->c()->relatorioCsv(new Request('GET', '/x'), 2)->status);
    }

    public function test_extrato_do_mes_e_exclusao(): void
    {
        $caixa = new RepositorioCaixaPdo($this->pdo);
        $t = new DateTimeImmutable('2026-01-15 12:00:00');
        $id = $caixa->lancarSaida(1, 40, 'bola', 'Bola nova', $t, $t);
        $caixa->lancarSaida(1, 99, 'bola', 'Outra do mês seguinte', $t->modify('+1 month'), $t);

        $r = $this->c()->caixa(new Request('GET', '/x', query: ['mes' => '2026-01']), 1);
        $this->assertStringContainsString('Bola nova', $r->corpo);
        $this->assertStringNotContainsString('Outra do mês seguinte', $r->corpo);

        $this->c()->excluir($this->post(['mes' => '2026-01']), 1, $id);
        $this->assertSame(-99.0, $this->saldo());
    }

    public function test_relatorio_e_csv(): void
    {
        $this->pdo->exec("INSERT INTO pagamentos (pelada_id, jogador_id, categoria, escopo, valor, forma, confirmado, criado_em)
            VALUES (1, 10, 'futebol', 'semana', 15, 'pix', 1, '2026-01-06 10:00:00')");

        $tela = $this->c()->relatorio(new Request('GET', '/x', query: ['mes' => '2026-01']), 1);
        $this->assertStringContainsString('Ana', $tela->corpo);
        $this->assertStringContainsString('15,00', $tela->corpo);

        $csv = $this->c()->relatorioCsv(new Request('GET', '/x', query: ['mes' => '2026-01']), 1);
        $this->assertStringContainsString('text/csv', $csv->cabecalhos['Content-Type']);
        $this->assertStringContainsString('pelada-1-2026-01.csv', $csv->cabecalhos['Content-Disposition']);
        $this->assertStringContainsString('Ana;15,00;0,00;15,00;0,00', $csv->corpo);
    }

    public function test_organizador_ve_comprovante_da_sua_pelada(): void
    {
        mkdir($this->dir());
        $nome = str_repeat('ab', 16) . '.pdf';
        file_put_contents($this->dir() . '/' . $nome, '%PDF-1.4 teste');
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (20, 2, 'Zeca', 'linha')");
        $this->pdo->exec("INSERT INTO pagamentos (id, pelada_id, jogador_id, categoria, escopo, valor, forma, comprovante_arquivo, confirmado, criado_em) VALUES
            (7, 1, 10, 'futebol', 'semana', 15, 'pix', '{$nome}', 0, '2026-01-06'),
            (8, 2, 20, 'futebol', 'semana', 15, 'pix', '{$nome}', 0, '2026-01-06')");

        $r = $this->c()->comprovante(new Request('GET', '/x'), 1, 7);
        $this->assertSame(200, $r->status);
        $this->assertSame('application/pdf', $r->cabecalhos['Content-Type']);
        $this->assertSame('nosniff', $r->cabecalhos['X-Content-Type-Options']);
        $this->assertSame('%PDF-1.4 teste', $r->corpo);

        // pagamento da pelada 2 pela URL da pelada 1
        $this->assertSame(404, $this->c()->comprovante(new Request('GET', '/x'), 1, 8)->status);
        // pelada que não é dele
        $this->assertSame(403, $this->c()->comprovante(new Request('GET', '/x'), 2, 8)->status);
    }

    public function test_nome_adulterado_no_banco_nao_le_arquivo_fora(): void
    {
        $this->pdo->exec("INSERT INTO pagamentos (id, pelada_id, jogador_id, categoria, escopo, valor, forma, comprovante_arquivo, confirmado, criado_em)
            VALUES (9, 1, 10, 'futebol', 'semana', 15, 'pix', '../../config/config.local.php', 0, '2026-01-06')");

        $this->assertSame(404, $this->c()->comprovante(new Request('GET', '/x'), 1, 9)->status);
    }
}
