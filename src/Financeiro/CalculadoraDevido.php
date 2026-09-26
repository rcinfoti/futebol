<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use RcInfoti\Pelada\Rodada\Tipo;

final class CalculadoraDevido
{
    public function __construct(
        private readonly float $valorFutebol,
        private readonly float $valorFestaSemana,
    ) {
    }

    public function devidoSemanal(Tipo $tipo, bool $festaQuitada): float
    {
        $futebol = $tipo === Tipo::Linha ? $this->valorFutebol : 0.0;
        $festa = $festaQuitada ? 0.0 : $this->valorFestaSemana;

        return $futebol + $festa;
    }
}
