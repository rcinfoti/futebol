<?php

declare(strict_types=1);

// Cron Job do cPanel: chamar a cada 5–10 min.
//   php /home1/rcinfoti/public_html/futebol/cron/processar.php

require __DIR__ . '/../vendor/autoload.php';

use RcInfoti\Pelada\Email\EnviadorEmailMail;
use RcInfoti\Pelada\Infra\Database;
use RcInfoti\Pelada\Multa\NotificadorMulta;
use RcInfoti\Pelada\Rodada\AgendaPelada;
use RcInfoti\Pelada\Rodada\CalendarioRodada;
use RcInfoti\Pelada\Rodada\CicloRodada;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioCicloRodadaPdo;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;

/** @var array{db: array{host:string,porta:int,banco:string,usuario:string,senha:string}, email_de: string} $config */
$config = require __DIR__ . '/../config/config.local.php';

$pdo = Database::fromConfig($config['db'])->pdo();
$agora = new DateTimeImmutable('now');

$ciclo = new CicloRodada(
    new RepositorioCicloRodadaPdo($pdo, new CalendarioRodada()),
    new ProcessadorRodada(new RepositorioRodadaPdo($pdo), new ServicoRegrasRodada()),
    new NotificadorMulta($pdo, new EnviadorEmailMail($config['email_de'])),
);

$peladas = $pdo->query('SELECT * FROM peladas WHERE ativa = 1')->fetchAll(PDO::FETCH_ASSOC);
foreach ($peladas as $pelada) {
    $ciclo->executar((int) $pelada['id'], AgendaPelada::deArray($pelada), $agora);
}
