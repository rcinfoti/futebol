<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\AgendaPelada;
use RcInfoti\Pelada\Rodada\CalendarioRodada;

final class CalendarioRodadaTest extends TestCase
{
    private function agenda(): AgendaPelada
    {
        // jogo quinta 20:00; abre domingo 08:00; vira quarta 12:00; prazo quinta 16:00
        return new AgendaPelada(4, '20:00:00', 7, '08:00:00', 3, '12:00:00', 4, '16:00:00');
    }

    public function test_calcula_datas_da_semana_do_jogo(): void
    {
        // segunda-feira 2026-01-05 09:00 → jogo da quinta 2026-01-08
        $datas = (new CalendarioRodada())->proximaRodada($this->agenda(), new DateTimeImmutable('2026-01-05 09:00:00'));

        $this->assertEquals(new DateTimeImmutable('2026-01-08 20:00:00'), $datas->dataJogo);
        $this->assertEquals(new DateTimeImmutable('2026-01-04 08:00:00'), $datas->abreEm);       // domingo antes
        $this->assertEquals(new DateTimeImmutable('2026-01-07 12:00:00'), $datas->viraRegraEm);  // quarta antes
        $this->assertEquals(new DateTimeImmutable('2026-01-08 16:00:00'), $datas->prazoMultaEm); // quinta, antes do jogo
    }

    public function test_no_instante_do_jogo_e_hoje(): void
    {
        // exatamente 2026-01-08 20:00 (quinta) → ainda é o jogo de hoje
        $datas = (new CalendarioRodada())->proximaRodada($this->agenda(), new DateTimeImmutable('2026-01-08 20:00:00'));

        $this->assertEquals(new DateTimeImmutable('2026-01-08 20:00:00'), $datas->dataJogo);
    }

    public function test_apos_o_jogo_vai_para_semana_seguinte(): void
    {
        // 2026-01-08 20:01 (um minuto após o jogo) → próxima quinta 2026-01-15
        $datas = (new CalendarioRodada())->proximaRodada($this->agenda(), new DateTimeImmutable('2026-01-08 20:01:00'));

        $this->assertEquals(new DateTimeImmutable('2026-01-15 20:00:00'), $datas->dataJogo);
        $this->assertEquals(new DateTimeImmutable('2026-01-11 08:00:00'), $datas->abreEm); // domingo 2026-01-11
    }

    public function test_deArray_mapeia_colunas_da_pelada(): void
    {
        $agenda = AgendaPelada::deArray([
            'dia_jogo' => 4, 'hora_jogo' => '20:00:00',
            'abre_dia' => 7, 'abre_hora' => '08:00:00',
            'vira_regra_dia' => 3, 'vira_regra_hora' => '12:00:00',
            'prazo_multa_dia' => 4, 'prazo_multa_hora' => '16:00:00',
        ]);

        $datas = (new CalendarioRodada())->proximaRodada($agenda, new DateTimeImmutable('2026-01-05 09:00:00'));
        $this->assertEquals(new DateTimeImmutable('2026-01-08 20:00:00'), $datas->dataJogo);
    }
}
