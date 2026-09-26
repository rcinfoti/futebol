<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class EstadoRodada
{
    /** @param list<Inscricao> $inscricoes */
    public function __construct(
        public readonly DateTimeImmutable $viraRegraEm,
        public readonly DateTimeImmutable $prazoMultaEm,
        public readonly int $limiteLinha,
        public readonly int $limiteGoleiro,
        public readonly array $inscricoes,
    ) {
    }
}
