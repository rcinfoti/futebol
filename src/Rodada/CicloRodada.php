<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;
use RcInfoti\Pelada\Multa\NotificadorMulta;

final class CicloRodada
{
    public function __construct(
        private readonly RepositorioCicloRodadaPdo $ciclo,
        private readonly ProcessadorRodada $processador,
        private readonly NotificadorMulta $notificador,
    ) {
    }

    public function executar(int $peladaId, AgendaPelada $agenda, DateTimeImmutable $agora): void
    {
        $rodadaId = $this->ciclo->criarRodadaSeAberta($peladaId, $agenda, $agora);

        if ($rodadaId !== null) {
            $this->processador->processar($rodadaId, $agora);
        }

        $this->notificador->notificarPendentes($peladaId, $agora);
        $this->ciclo->fecharRodadasVencidas($peladaId, $agora);
    }
}
