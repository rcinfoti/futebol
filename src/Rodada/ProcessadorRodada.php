<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class ProcessadorRodada
{
    public function __construct(
        private readonly RepositorioRodada $repo,
        private readonly ServicoRegrasRodada $regras,
    ) {
    }

    public function processar(int $rodadaId, DateTimeImmutable $agora): void
    {
        $estado = $this->repo->carregarEstado($rodadaId);

        foreach ($this->regras->decidir($estado, $agora) as $acao) {
            if ($acao instanceof AcaoPromover) {
                $this->repo->promover($rodadaId, $acao->jogadorId, $agora);
            } elseif ($acao instanceof AcaoAplicarMulta) {
                $this->repo->aplicarMulta($rodadaId, $acao->jogadorId, $agora);
            }
        }
    }
}
