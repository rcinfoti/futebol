<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class CalendarioRodada
{
    public function proximaRodada(AgendaPelada $agenda, DateTimeImmutable $agora): DatasRodada
    {
        $jogo = $this->proximoDiaHora($agenda->diaJogo, $agenda->horaJogo, $agora);

        return new DatasRodada(
            $jogo,
            $this->recuarAte($jogo, $agenda->abreDia, $agenda->abreHora),
            $this->recuarAte($jogo, $agenda->viraRegraDia, $agenda->viraRegraHora),
            $this->recuarAte($jogo, $agenda->prazoMultaDia, $agenda->prazoMultaHora),
        );
    }

    /** Próximo datetime cujo dia-da-semana = $dia e hora = $hora, com valor >= $agora. */
    private function proximoDiaHora(int $dia, string $hora, DateTimeImmutable $agora): DateTimeImmutable
    {
        $hoje = $agora->setTime(0, 0, 0);
        $wd = (int) $agora->format('N');
        $delta = ($dia - $wd + 7) % 7;

        $cand = $this->comHora($hoje->modify("+{$delta} days"), $hora);
        if ($cand < $agora) {
            $cand = $this->comHora($hoje->modify('+' . ($delta + 7) . ' days'), $hora);
        }

        return $cand;
    }

    /** Recua do jogo até o dia-da-semana $dia (na janela de 7 dias que termina no jogo). */
    private function recuarAte(DateTimeImmutable $jogo, int $dia, string $hora): DateTimeImmutable
    {
        $wdJogo = (int) $jogo->format('N');
        $delta = ($wdJogo - $dia + 7) % 7;

        return $this->comHora($jogo->modify("-{$delta} days"), $hora);
    }

    private function comHora(DateTimeImmutable $d, string $hora): DateTimeImmutable
    {
        [$h, $m, $s] = array_pad(explode(':', $hora), 3, '0');

        return $d->setTime((int) $h, (int) $m, (int) $s);
    }
}
