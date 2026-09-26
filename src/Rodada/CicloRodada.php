<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;
use RcInfoti\Pelada\Multa\NotificadorMulta;
use RcInfoti\Pelada\Presenca\NotificadorPromocao;

final class CicloRodada
{
    public function __construct(
        private readonly RepositorioCicloRodadaPdo $ciclo,
        private readonly ProcessadorRodada $processador,
        private readonly NotificadorMulta $notificador,
        private readonly ?NotificadorPromocao $promocoes = null,
    ) {
    }

    public function executar(int $peladaId, AgendaPelada $agenda, DateTimeImmutable $agora): void
    {
        $rodadaId = $this->ciclo->criarRodadaSeAberta($peladaId, $agenda, $agora);

        if ($rodadaId !== null) {
            $this->processador->processar($rodadaId, $agora);
        }

        $this->notificador->notificarPendentes($peladaId, $agora);
        $this->promocoes?->notificarPendentes($peladaId);
        $this->ciclo->fecharRodadasVencidas($peladaId, $agora);
    }
}
