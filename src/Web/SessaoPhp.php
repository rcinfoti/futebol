<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class SessaoPhp implements Sessao
{
    public function get(string $chave): mixed
    {
        return $_SESSION[$chave] ?? null;
    }

    public function set(string $chave, mixed $valor): void
    {
        $_SESSION[$chave] = $valor;
    }

    public function remove(string $chave): void
    {
        unset($_SESSION[$chave]);
    }

    public function regenerar(): void
    {
        session_regenerate_id(true);
    }
}
