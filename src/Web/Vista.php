<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Vista
{
    public function __construct(private readonly string $diretorio)
    {
    }

    /** @param array<string,mixed> $dados */
    public function render(string $arquivo, array $dados = []): string
    {
        $caminho = $this->diretorio . '/' . $arquivo . '.php';
        extract($dados, EXTR_SKIP);
        ob_start();
        require $caminho;

        return (string) ob_get_clean();
    }
}
