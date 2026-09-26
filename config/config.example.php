<?php

declare(strict_types=1);

// Copie para config/config.local.php (fora do versionamento) e preencha.
// No servidor: /home1/rcinfoti/public_html/futebol/config/config.local.php
return [
    // Subdiretorio publico. "/futebol" em www.rcinfoti.com.br/futebol; "" se servir na raiz.
    'base' => '/futebol',

    'db' => [
        'host' => 'localhost',
        'porta' => 3306,
        'banco' => 'rcinfoti_pelada',
        'usuario' => 'rcinfoti_pelada',
        'senha' => '',
    ],

    // Remetente dos e-mails (multa, aviso de vaga) disparados pelo cron.
    'email_de' => 'pelada@rcinfoti.com.br',

    // Pasta dos comprovantes de pagamento. Fica fora de public/ (nao acessivel por URL).
    'comprovantes_dir' => __DIR__ . '/../uploads',
];
