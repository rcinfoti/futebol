<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\AcaoAplicarMulta;
use RcInfoti\Pelada\Rodada\AcaoPromover;
use RcInfoti\Pelada\Rodada\EstadoRodada;
use RcInfoti\Pelada\Rodada\Inscricao;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Rodada\StatusInscricao;
use RcInfoti\Pelada\Rodada\Tipo;

final class ServicoRegrasRodadaTest extends TestCase
{
    private function servico(): ServicoRegrasRodada
    {
        return new ServicoRegrasRodada();
    }

    public function test_promove_por_ordem_de_chegada_antes_da_virada(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00'); // antes da virada
        $estado = new EstadoRodada(
            viraRegraEm: new DateTimeImmutable('2026-01-07 12:00'),
            prazoMultaEm: new DateTimeImmutable('2026-01-08 16:00'),
            limiteLinha: 2,
            limiteGoleiro: 1,
            inscricoes: [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 3, pagou: true),
                new Inscricao(3, Tipo::Linha, StatusInscricao::Espera, 2, pagou: false),
            ],
        );

        // 1 vaga (limite 2, 1 confirmado). FIFO -> menor ordem = jogador 3 (ordem 2),
        // mesmo sem ter pago. O jogador 2 (ordem 3) fica.
        $this->assertEquals([new AcaoPromover(3)], $this->servico()->decidir($estado, $agora));
    }

    public function test_nao_promove_quando_nao_ha_vaga(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            1,
            1,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 2),
            ],
        );

        $this->assertSame([], $this->servico()->decidir($estado, $agora));
    }

    public function test_promove_todos_quando_vagas_sobram(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            5, // muitas vagas
            1,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Espera, 2),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 1),
            ],
        );

        // 5 vagas, 2 na espera -> promove os dois, em ordem de chegada.
        $this->assertEquals(
            [new AcaoPromover(2), new AcaoPromover(1)],
            $this->servico()->decidir($estado, $agora),
        );
    }

    public function test_apos_virada_promove_apenas_quem_pagou(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 13:00'); // após a virada
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            2,
            1,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 2, pagou: false),
                new Inscricao(3, Tipo::Linha, StatusInscricao::Espera, 3, pagou: true),
            ],
        );

        // 1 vaga. Ignora jogador 2 (não pagou) mesmo tendo ordem menor;
        // promove jogador 3 (pagou).
        $this->assertEquals([new AcaoPromover(3)], $this->servico()->decidir($estado, $agora));
    }

    public function test_no_instante_exato_da_virada_ja_vale_pago_primeiro(): void
    {
        $vira = new DateTimeImmutable('2026-01-07 12:00');
        $estado = new EstadoRodada(
            $vira,
            new DateTimeImmutable('2026-01-08 16:00'),
            2,
            1,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 2, pagou: false),
            ],
        );

        // agora == viraRegraEm -> já é pago primeiro; ninguém pagou -> nada.
        $this->assertSame([], $this->servico()->decidir($estado, $vira));
    }

    public function test_pago_primeiro_respeita_ordem_entre_quem_pagou(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 13:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            2,
            1,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 5, pagou: true),
                new Inscricao(3, Tipo::Linha, StatusInscricao::Espera, 4, pagou: true),
            ],
        );

        // 1 vaga; entre os que pagaram, menor ordem = jogador 3 (ordem 4).
        $this->assertEquals([new AcaoPromover(3)], $this->servico()->decidir($estado, $agora));
    }

    public function test_listas_de_linha_e_goleiro_sao_independentes(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            limiteLinha: 1,   // linha cheia
            limiteGoleiro: 1, // goleiro com vaga
            inscricoes: [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 2),
                new Inscricao(3, Tipo::Goleiro, StatusInscricao::Espera, 1),
            ],
        );

        // Linha cheia -> jogador 2 não sobe. Goleiro com vaga -> promove jogador 3.
        $this->assertEquals([new AcaoPromover(3)], $this->servico()->decidir($estado, $agora));
    }

    public function test_promove_em_ambos_os_tipos_linha_antes_de_goleiro(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            limiteLinha: 1,
            limiteGoleiro: 1,
            inscricoes: [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Espera, 1),
                new Inscricao(2, Tipo::Goleiro, StatusInscricao::Espera, 1),
            ],
        );

        // Uma vaga em cada tipo; resultado traz linha primeiro, depois goleiro.
        $this->assertEquals(
            [new AcaoPromover(1), new AcaoPromover(2)],
            $this->servico()->decidir($estado, $agora),
        );
    }

    public function test_aplica_multa_para_desistencia_apos_prazo(): void
    {
        $agora = new DateTimeImmutable('2026-01-08 18:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            prazoMultaEm: new DateTimeImmutable('2026-01-08 16:00'),
            limiteLinha: 20,
            limiteGoleiro: 4,
            inscricoes: [
                new Inscricao(
                    1,
                    Tipo::Linha,
                    StatusInscricao::Desistiu,
                    1,
                    desistiuEm: new DateTimeImmutable('2026-01-08 17:00'),
                ),
            ],
        );

        $this->assertEquals([new AcaoAplicarMulta(1)], $this->servico()->decidir($estado, $agora));
    }

    public function test_nao_aplica_multa_para_desistencia_antes_do_prazo(): void
    {
        $agora = new DateTimeImmutable('2026-01-08 12:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            20,
            4,
            [
                new Inscricao(
                    1,
                    Tipo::Linha,
                    StatusInscricao::Desistiu,
                    1,
                    desistiuEm: new DateTimeImmutable('2026-01-08 10:00'),
                ),
            ],
        );

        $this->assertSame([], $this->servico()->decidir($estado, $agora));
    }

    public function test_multa_no_instante_exato_do_prazo(): void
    {
        $prazo = new DateTimeImmutable('2026-01-08 16:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            $prazo,
            20,
            4,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Desistiu, 1, desistiuEm: $prazo),
            ],
        );

        // desistiuEm == prazoMultaEm -> multa (limite inclusivo).
        $this->assertEquals(
            [new AcaoAplicarMulta(1)],
            $this->servico()->decidir($estado, new DateTimeImmutable('2026-01-08 16:30')),
        );
    }

    public function test_nao_reaplica_multa_ja_aplicada(): void
    {
        $agora = new DateTimeImmutable('2026-01-08 18:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            20,
            4,
            [
                new Inscricao(
                    1,
                    Tipo::Linha,
                    StatusInscricao::Desistiu,
                    1,
                    desistiuEm: new DateTimeImmutable('2026-01-08 17:00'),
                    multaAplicada: true,
                ),
            ],
        );

        $this->assertSame([], $this->servico()->decidir($estado, $agora));
    }
}
