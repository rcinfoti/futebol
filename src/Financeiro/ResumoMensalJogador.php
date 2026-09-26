<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

final class ResumoMensalJogador
{
    public function __construct(
        public readonly int $jogadorId,
        public readonly string $nome,
        public readonly float $pagoFutebol,
        public readonly float $pagoFesta,
        public readonly float $pendente,
    ) {
    }
}
