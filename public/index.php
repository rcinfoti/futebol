<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Infra\Database;
use RcInfoti\Pelada\Painel\ConsultaPainelJogador;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Web\Csrf;
use RcInfoti\Pelada\Web\PainelJogadorController;
use RcInfoti\Pelada\Web\Request;
use RcInfoti\Pelada\Web\Roteador;
use RcInfoti\Pelada\Web\SessaoPhp;

date_default_timezone_set('America/Sao_Paulo');
session_start();

$config = require __DIR__ . '/../config/config.local.php';
$pdo = Database::fromConfig($config['db'])->pdo();

$sessao = new SessaoPhp();
$controller = new PainelJogadorController(
    $sessao,
    new Csrf($sessao),
    new ServicoAcessoPin($pdo),
    new ServicoPresenca($pdo),
    new ConsultaPainelJogador($pdo),
    new RepositorioPagamentoPdo($pdo),
);

$r = new Roteador();
$r->get('/entrar', fn (Request $req, array $p) => $controller->entrar($req));
$r->post('/entrar', fn (Request $req, array $p) => $controller->autenticar($req));
$r->get('/', fn (Request $req, array $p) => $controller->painel($req));
$r->post('/confirmar', fn (Request $req, array $p) => $controller->confirmar($req));
$r->post('/nao-vou', fn (Request $req, array $p) => $controller->naoVou($req));
$r->post('/avisar-pagamento', fn (Request $req, array $p) => $controller->avisarPagamento($req));
$r->post('/sair', fn (Request $req, array $p) => $controller->sair($req));

$r->despachar(Request::daGlobais())->enviar();
