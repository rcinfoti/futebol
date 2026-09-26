<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RcInfoti\Pelada\Acesso\Autorizacao;
use RcInfoti\Pelada\Acesso\ServicoAcessoOrganizador;
use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Admin\ConsultaPainelPelada;
use RcInfoti\Pelada\Admin\GestaoRodada;
use RcInfoti\Pelada\Admin\RecebimentoPagamento;
use RcInfoti\Pelada\Financeiro\ArmazemComprovantes;
use RcInfoti\Pelada\Financeiro\RelatorioFinanceiroPdo;
use RcInfoti\Pelada\Financeiro\RepositorioCaixaPdo;
use RcInfoti\Pelada\Multa\RepositorioMultaPdo;
use RcInfoti\Pelada\Peladas\RepositorioPeladaPdo;
use RcInfoti\Pelada\Jogadores\RepositorioJogadorPdo;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Infra\Database;
use RcInfoti\Pelada\Painel\ConsultaPainelJogador;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Web\AdminController;
use RcInfoti\Pelada\Web\AdminFinanceiroController;
use RcInfoti\Pelada\Web\AdminPeladasController;
use RcInfoti\Pelada\Web\Csrf;
use RcInfoti\Pelada\Web\PainelJogadorController;
use RcInfoti\Pelada\Web\Request;
use RcInfoti\Pelada\Web\Roteador;
use RcInfoti\Pelada\Web\SessaoPhp;

date_default_timezone_set('America/Sao_Paulo');

$config = require __DIR__ . '/../config/config.local.php';
// Subdiretório público (ex.: "/futebol" em www.rcinfoti.com.br/futebol); "" quando servido na raiz.
$base = rtrim((string) ($config['base'] ?? ''), '/');

$https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
session_name('pelada');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 60, // 60 dias: jogador não precisa digitar PIN toda semana
    'path' => $base === '' ? '/' : $base . '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 60));
ini_set('session.use_strict_mode', '1');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$pdo = Database::fromConfig($config['db'])->pdo();

// Comprovantes fora da raiz web (uploads/ é bloqueado no .htaccess e fica fora do git/rsync)
$comprovantes = new ArmazemComprovantes((string) ($config['comprovantes_dir'] ?? __DIR__ . '/../uploads/comprovantes'));

$sessao = new SessaoPhp();
$csrf = new Csrf($sessao);
$controller = new PainelJogadorController(
    $sessao,
    $csrf,
    new ServicoAcessoPin($pdo),
    new ServicoPresenca($pdo),
    new ConsultaPainelJogador($pdo),
    new RepositorioPagamentoPdo($pdo),
    new ProcessadorRodada(new RepositorioRodadaPdo($pdo), new ServicoRegrasRodada()),
    $base,
    $comprovantes,
);

$r = new Roteador();
$r->get('/entrar', fn (Request $req, array $p) => $controller->entrar($req));
$r->get('/p/{slug}/entrar', fn (Request $req, array $p) => $controller->entrar($req, $p['slug']));
$r->post('/p/{slug}/entrar', fn (Request $req, array $p) => $controller->autenticar($req, $p['slug']));
$r->get('/', fn (Request $req, array $p) => $controller->painel($req));
$r->post('/confirmar', fn (Request $req, array $p) => $controller->confirmar($req));
$r->post('/nao-vou', fn (Request $req, array $p) => $controller->naoVou($req));
$r->post('/avisar-pagamento', fn (Request $req, array $p) => $controller->avisarPagamento($req));
$r->post('/sair', fn (Request $req, array $p) => $controller->sair($req));

// --- área do organizador ---
$processador = new ProcessadorRodada(new RepositorioRodadaPdo($pdo), new ServicoRegrasRodada());
$acessoOrg = new ServicoAcessoOrganizador($pdo);
$autorizacao = new Autorizacao($pdo);
$pagamentos = new RepositorioPagamentoPdo($pdo);
$admin = new AdminController(
    $sessao,
    $csrf,
    $acessoOrg,
    $autorizacao,
    $base,
    jogadores: new RepositorioJogadorPdo($pdo),
    pins: new ServicoAcessoPin($pdo),
    consulta: new ConsultaPainelPelada($pdo),
    pagamentos: $pagamentos,
    gestao: new GestaoRodada($pdo, new ServicoPresenca($pdo), $processador),
    multas: new RepositorioMultaPdo($pdo),
    recebimento: new RecebimentoPagamento($pdo, $pagamentos),
);
$financeiro = new AdminFinanceiroController(
    $sessao,
    $csrf,
    $acessoOrg,
    $autorizacao,
    $base,
    caixa: new RepositorioCaixaPdo($pdo),
    relatorio: new RelatorioFinanceiroPdo($pdo),
    pagamentos: $pagamentos,
    comprovantes: $comprovantes,
);
$peladasCtl = new AdminPeladasController($sessao, $csrf, $acessoOrg, $autorizacao, $base, peladas: new RepositorioPeladaPdo($pdo));
$id = static fn (array $p, string $k): int => ctype_digit($p[$k]) ? (int) $p[$k] : 0; // não-numérico → 0 → 403/404

$r->get('/admin/entrar', fn (Request $req, array $p) => $admin->entrar($req));
$r->post('/admin/entrar', fn (Request $req, array $p) => $admin->autenticar($req));
$r->post('/admin/sair', fn (Request $req, array $p) => $admin->sair($req));
$r->get('/admin', fn (Request $req, array $p) => $admin->inicio($req));
$r->get('/admin/p/{pelada}', fn (Request $req, array $p) => $admin->painel($req, $id($p, 'pelada')));
$r->get('/admin/p/{pelada}/jogadores', fn (Request $req, array $p) => $admin->jogadores($req, $id($p, 'pelada')));
$r->post('/admin/p/{pelada}/jogadores', fn (Request $req, array $p) => $admin->criarJogador($req, $id($p, 'pelada')));
$r->get('/admin/p/{pelada}/jogadores/novo', fn (Request $req, array $p) => $admin->novoJogador($req, $id($p, 'pelada')));
$r->get('/admin/p/{pelada}/jogadores/{jogador}', fn (Request $req, array $p) => $admin->editarJogador($req, $id($p, 'pelada'), $id($p, 'jogador')));
$r->post('/admin/p/{pelada}/jogadores/{jogador}', fn (Request $req, array $p) => $admin->salvarJogador($req, $id($p, 'pelada'), $id($p, 'jogador')));
$r->post('/admin/p/{pelada}/jogadores/{jogador}/pin', fn (Request $req, array $p) => $admin->gerarPin($req, $id($p, 'pelada'), $id($p, 'jogador')));
$r->post('/admin/p/{pelada}/jogadores/{jogador}/ativo', fn (Request $req, array $p) => $admin->alternarAtivo($req, $id($p, 'pelada'), $id($p, 'jogador')));
$r->post('/admin/p/{pelada}/pagamentos/{pagamento}/confirmar', fn (Request $req, array $p) => $admin->confirmarPagamento($req, $id($p, 'pelada'), $id($p, 'pagamento')));
// Plano 8: rodada manual, multas, pagamento recebido, caixa e relatórios
$r->post('/admin/p/{pelada}/rodada/adicionar', fn (Request $req, array $p) => $admin->rodadaAdicionar($req, $id($p, 'pelada')));
$r->post('/admin/p/{pelada}/rodada/{jogador}/promover', fn (Request $req, array $p) => $admin->rodadaPromover($req, $id($p, 'pelada'), $id($p, 'jogador')));
$r->post('/admin/p/{pelada}/rodada/{jogador}/desistir', fn (Request $req, array $p) => $admin->rodadaDesistir($req, $id($p, 'pelada'), $id($p, 'jogador')));
$r->post('/admin/p/{pelada}/multas/{multa}/receber', fn (Request $req, array $p) => $admin->multaReceber($req, $id($p, 'pelada'), $id($p, 'multa')));
$r->post('/admin/p/{pelada}/multas/{multa}/cancelar', fn (Request $req, array $p) => $admin->multaCancelar($req, $id($p, 'pelada'), $id($p, 'multa')));
$r->post('/admin/p/{pelada}/jogadores/{jogador}/pagamento', fn (Request $req, array $p) => $admin->receberPagamento($req, $id($p, 'pelada'), $id($p, 'jogador')));
$r->get('/admin/p/{pelada}/caixa', fn (Request $req, array $p) => $financeiro->caixa($req, $id($p, 'pelada')));
$r->post('/admin/p/{pelada}/caixa/lancar', fn (Request $req, array $p) => $financeiro->lancar($req, $id($p, 'pelada')));
$r->post('/admin/p/{pelada}/caixa/{movimento}/excluir', fn (Request $req, array $p) => $financeiro->excluir($req, $id($p, 'pelada'), $id($p, 'movimento')));
$r->get('/admin/p/{pelada}/relatorio', fn (Request $req, array $p) => $financeiro->relatorio($req, $id($p, 'pelada')));
$r->get('/admin/p/{pelada}/relatorio.csv', fn (Request $req, array $p) => $financeiro->relatorioCsv($req, $id($p, 'pelada')));
// Plano 9: comprovante, configuração, peladas, organizadores, minha senha
$r->get('/admin/p/{pelada}/pagamentos/{pagamento}/comprovante', fn (Request $req, array $p) => $financeiro->comprovante($req, $id($p, 'pelada'), $id($p, 'pagamento')));
$r->get('/admin/p/{pelada}/config', fn (Request $req, array $p) => $peladasCtl->config($req, $id($p, 'pelada')));
$r->post('/admin/p/{pelada}/config', fn (Request $req, array $p) => $peladasCtl->salvarConfig($req, $id($p, 'pelada')));
$r->post('/admin/p/{pelada}/ativa', fn (Request $req, array $p) => $peladasCtl->alternarPelada($req, $id($p, 'pelada')));
$r->get('/admin/peladas/nova', fn (Request $req, array $p) => $peladasCtl->novaPelada($req));
$r->post('/admin/peladas', fn (Request $req, array $p) => $peladasCtl->criarPelada($req));
$r->get('/admin/organizadores', fn (Request $req, array $p) => $peladasCtl->organizadores($req));
$r->get('/admin/organizadores/novo', fn (Request $req, array $p) => $peladasCtl->novoOrganizador($req));
$r->post('/admin/organizadores', fn (Request $req, array $p) => $peladasCtl->criarOrganizador($req));
$r->get('/admin/organizadores/{usuario}', fn (Request $req, array $p) => $peladasCtl->editarOrganizador($req, $id($p, 'usuario')));
$r->post('/admin/organizadores/{usuario}', fn (Request $req, array $p) => $peladasCtl->salvarOrganizador($req, $id($p, 'usuario')));
$r->post('/admin/organizadores/{usuario}/senha', fn (Request $req, array $p) => $peladasCtl->redefinirSenha($req, $id($p, 'usuario')));
$r->post('/admin/organizadores/{usuario}/ativo', fn (Request $req, array $p) => $peladasCtl->alternarOrganizador($req, $id($p, 'usuario')));
$r->get('/admin/senha', fn (Request $req, array $p) => $peladasCtl->minhaSenha($req));
$r->post('/admin/senha', fn (Request $req, array $p) => $peladasCtl->salvarMinhaSenha($req));

$r->despachar(Request::daGlobais($base))->enviar();
