<?php

declare(strict_types=1);

// Copie para config/config.local.php (fora do versionamento) e preencha.
return [
    'db' => [
        'host' => 'localhost',
        'porta' => 3306,
        'banco' => 'rcinfoti_pelada',
        'usuario' => 'rcinfoti_pelada',
        'senha' => '',
    ],
    // Remetente dos e-mails de multa disparados pelo cron (cron/processar.php).
    'email_de' => 'pelada@rcinfoti.com.br',
];
