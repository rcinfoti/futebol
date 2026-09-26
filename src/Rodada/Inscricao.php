<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class Inscricao
{
    public function __construct(
        public readonly int $jogadorId,
        public readonly Tipo $tipo,
        public readonly StatusInscricao $status,
        public readonly int $ordem,
        public readonly bool $pagou = false,
        public readonly ?DateTimeImmutable $desistiuEm = null,
        public readonly bool $multaAplicada = false,
    ) {
    }
}
