<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use DateTimeImmutable;

final class MovimentoCaixa
{
    public function __construct(
        public readonly int $id,
        public readonly TipoMovimento $tipo,
        public readonly string $categoria,
        public readonly float $valor,
        public readonly ?string $descricao,
        public readonly ?int $pagamentoId,
        public readonly DateTimeImmutable $ocorridoEm,
    ) {
    }
}
