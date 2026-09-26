<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

final class AcaoPromover implements Acao
{
    public function __construct(public readonly int $jogadorId)
    {
    }
}
