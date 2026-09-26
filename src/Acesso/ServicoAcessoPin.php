<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Acesso;

use PDO;

final class ServicoAcessoPin
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function definirPin(int $jogadorId, string $pin): void
    {
        $this->pdo->prepare('UPDATE jogadores SET pin_hash = ? WHERE id = ?')
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $jogadorId]);
    }

    public function autenticar(int $jogadorId, string $pin): bool
    {
        $stmt = $this->pdo->prepare('SELECT pin_hash FROM jogadores WHERE id = ?');
        $stmt->execute([$jogadorId]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) && $hash !== '' && password_verify($pin, $hash);
    }
}
