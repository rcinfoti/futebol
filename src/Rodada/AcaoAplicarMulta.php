<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

final class AcaoAplicarMulta implements Acao
{
    public function __construct(public readonly int $jogadorId)
    {
    }
}
