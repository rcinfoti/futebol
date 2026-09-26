<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

interface Sessao
{
    public function get(string $chave): mixed;

    public function set(string $chave, mixed $valor): void;

    public function remove(string $chave): void;

    public function regenerar(): void;
}
