<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\AcaoAplicarMulta;
use RcInfoti\Pelada\Rodada\AcaoPromover;
use RcInfoti\Pelada\Rodada\EstadoRodada;
use RcInfoti\Pelada\Rodada\Inscricao;
use RcInfoti\Pelada\Rodada\StatusInscricao;
use RcInfoti\Pelada\Rodada\Tipo;

final class ValueObjectsTest extends TestCase
{
    public function test_inscricao_guarda_seus_dados(): void
    {
        $i = new Inscricao(7, Tipo::Goleiro, StatusInscricao::Espera, 3, pagou: true);

        $this->assertSame(7, $i->jogadorId);
        $this->assertSame(Tipo::Goleiro, $i->tipo);
        $this->assertSame(StatusInscricao::Espera, $i->status);
        $this->assertSame(3, $i->ordem);
        $this->assertTrue($i->pagou);
        $this->assertNull($i->desistiuEm);
        $this->assertFalse($i->multaAplicada);
    }

    public function test_estado_rodada_guarda_config_e_inscricoes(): void
    {
        $vira = new DateTimeImmutable('2026-01-07 12:00');
        $prazo = new DateTimeImmutable('2026-01-08 16:00');
        $estado = new EstadoRodada($vira, $prazo, 20, 4, [
            new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
        ]);

        $this->assertSame($vira, $estado->viraRegraEm);
        $this->assertSame($prazo, $estado->prazoMultaEm);
        $this->assertSame(20, $estado->limiteLinha);
        $this->assertSame(4, $estado->limiteGoleiro);
        $this->assertCount(1, $estado->inscricoes);
    }

    public function test_acoes_carregam_jogador_e_sao_acao(): void
    {
        $promover = new AcaoPromover(5);
        $multa = new AcaoAplicarMulta(9);

        $this->assertSame(5, $promover->jogadorId);
        $this->assertSame(9, $multa->jogadorId);
        $this->assertInstanceOf(\RcInfoti\Pelada\Rodada\Acao::class, $promover);
        $this->assertInstanceOf(\RcInfoti\Pelada\Rodada\Acao::class, $multa);
    }
}
