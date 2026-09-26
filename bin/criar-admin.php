<?php

declare(strict_types=1);

// Cria um usuário da área do organizador (o primeiro super admin nasce aqui).
//
//   php bin/criar-admin.php --nome="Rogério" --email=rogerschagas@gmail.com --papel=super_admin
//   php bin/criar-admin.php --nome="Fulano" --email=f@x.com --papel=organizador --pelada=quinta
//   php bin/criar-admin.php --email=f@x.com --nova-senha            (troca a senha)
//
// A senha é pedida no terminal (sem eco). Para automação: variável de ambiente PELADA_SENHA.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use RcInfoti\Pelada\Acesso\Autorizacao;
use RcInfoti\Pelada\Acesso\Papel;
use RcInfoti\Pelada\Acesso\ServicoAcessoOrganizador;
use RcInfoti\Pelada\Infra\Database;

$op = getopt('', ['nome:', 'email:', 'papel:', 'pelada:', 'nova-senha']);
$email = (string) ($op['email'] ?? '');
if ($email === '') {
    fwrite(STDERR, "Uso: php bin/criar-admin.php --nome=... --email=... [--papel=super_admin|organizador] [--pelada=slug]\n");
    exit(1);
}

$lerSenha = static function (): string {
    $env = getenv('PELADA_SENHA');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    fwrite(STDOUT, 'Senha (mín. ' . ServicoAcessoOrganizador::SENHA_MINIMA . '): ');
    @shell_exec('stty -echo');
    $s = trim((string) fgets(STDIN));
    @shell_exec('stty echo');
    fwrite(STDOUT, "\nRepita: ");
    @shell_exec('stty -echo');
    $r = trim((string) fgets(STDIN));
    @shell_exec('stty echo');
    fwrite(STDOUT, "\n");
    if ($s !== $r) {
        fwrite(STDERR, "As senhas não conferem.\n");
        exit(1);
    }

    return $s;
};

$config = require __DIR__ . '/../config/config.local.php';
$pdo = Database::fromConfig($config['db'])->pdo();
$acesso = new ServicoAcessoOrganizador($pdo);

try {
    if (isset($op['nova-senha'])) {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $stmt->execute([mb_strtolower(trim($email))]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            fwrite(STDERR, "Usuário não encontrado.\n");
            exit(1);
        }
        $acesso->alterarSenha((int) $id, $lerSenha());
        fwrite(STDOUT, "Senha alterada.\n");
        exit(0);
    }

    $papel = Papel::tryFrom((string) ($op['papel'] ?? 'organizador'));
    if ($papel === null) {
        fwrite(STDERR, "Papel inválido: use super_admin ou organizador.\n");
        exit(1);
    }

    $id = $acesso->criar((string) ($op['nome'] ?? ''), $email, $lerSenha(), $papel);
    fwrite(STDOUT, "Usuário #{$id} criado ({$papel->value}).\n");

    if (isset($op['pelada'])) {
        $stmt = $pdo->prepare('SELECT id FROM peladas WHERE slug = ?');
        $stmt->execute([(string) $op['pelada']]);
        $peladaId = $stmt->fetchColumn();
        if ($peladaId === false) {
            fwrite(STDERR, "Pelada '{$op['pelada']}' não existe — usuário criado sem vínculo.\n");
            exit(1);
        }
        (new Autorizacao($pdo))->vincular((int) $peladaId, $id);
        fwrite(STDOUT, "Vinculado à pelada '{$op['pelada']}'.\n");
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
