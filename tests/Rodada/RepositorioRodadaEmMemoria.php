<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use RcInfoti\Pelada\Rodada\EstadoRodada;
use RcInfoti\Pelada\Rodada\RepositorioRodada;

final class RepositorioRodadaEmMemoria implements RepositorioRodada
{
    /** @var list<int> */
    public array $promovidos = [];
    /** @var list<int> */
    public array $multados = [];

    public function __construct(private EstadoRodada $estado)
    {
    }

    public function carregarEstado(int $rodadaId): EstadoRodada
    {
        return $this->estado;
    }

    public function promover(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $this->promovidos[] = $jogadorId;
    }

    public function aplicarMulta(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $this->multados[] = $jogadorId;
    }
}
