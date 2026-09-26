<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Painel;

final class PainelJogador
{
    public function __construct(
        public readonly string $nome,
        public readonly string $tipo,
        public readonly ?int $rodadaId,
        public readonly ?string $dataJogo,
        public readonly string $situacao,
        public readonly float $devidoSemana,
        public readonly float $saldoPendente,
    ) {
    }
}
