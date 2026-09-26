<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

final class AgendaPelada
{
    public function __construct(
        public readonly int $diaJogo,
        public readonly string $horaJogo,
        public readonly int $abreDia,
        public readonly string $abreHora,
        public readonly int $viraRegraDia,
        public readonly string $viraRegraHora,
        public readonly int $prazoMultaDia,
        public readonly string $prazoMultaHora,
    ) {
    }

    /** @param array<string,mixed> $r linha de `peladas` */
    public static function deArray(array $r): self
    {
        return new self(
            (int) $r['dia_jogo'],
            (string) $r['hora_jogo'],
            (int) $r['abre_dia'],
            (string) $r['abre_hora'],
            (int) $r['vira_regra_dia'],
            (string) $r['vira_regra_hora'],
            (int) $r['prazo_multa_dia'],
            (string) $r['prazo_multa_hora'],
        );
    }
}
