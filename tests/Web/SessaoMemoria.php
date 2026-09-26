<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use RcInfoti\Pelada\Web\Sessao;

final class SessaoMemoria implements Sessao
{
    /** @var array<string,mixed> */
    private array $dados = [];

    public function get(string $chave): mixed
    {
        return $this->dados[$chave] ?? null;
    }

    public function set(string $chave, mixed $valor): void
    {
        $this->dados[$chave] = $valor;
    }

    public function remove(string $chave): void
    {
        unset($this->dados[$chave]);
    }

    public function regenerar(): void
    {
        // no-op em memória
    }
}
