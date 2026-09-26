<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

interface RepositorioRodada
{
    public function carregarEstado(int $rodadaId): EstadoRodada;

    public function promover(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void;

    public function aplicarMulta(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void;
}
