<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class DatasRodada
{
    public function __construct(
        public readonly DateTimeImmutable $dataJogo,
        public readonly DateTimeImmutable $abreEm,
        public readonly DateTimeImmutable $viraRegraEm,
        public readonly DateTimeImmutable $prazoMultaEm,
    ) {
    }
}
