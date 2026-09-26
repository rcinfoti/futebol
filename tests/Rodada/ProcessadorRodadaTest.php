<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\EstadoRodada;
use RcInfoti\Pelada\Rodada\Inscricao;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Rodada\StatusInscricao;
use RcInfoti\Pelada\Rodada\Tipo;

final class ProcessadorRodadaTest extends TestCase
{
    public function test_aplica_promocao_e_multa_decididas_pelo_servico(): void
    {
        $agora = new DateTimeImmutable('2026-01-08 18:00'); // após prazo -> multa; sem promoção
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
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
        $repo = new RepositorioRodadaEmMemoria($estado);

        (new ProcessadorRodada($repo, new ServicoRegrasRodada()))->processar(99, $agora);

        $this->assertSame([], $repo->promovidos);
        $this->assertSame([1], $repo->multados);
    }

    public function test_promove_da_espera_quando_ha_vaga(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00'); // antes da virada
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            limiteLinha: 2,
            limiteGoleiro: 1,
            inscricoes: [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 2),
            ],
        );
        $repo = new RepositorioRodadaEmMemoria($estado);

        (new ProcessadorRodada($repo, new ServicoRegrasRodada()))->processar(99, $agora);

        $this->assertSame([2], $repo->promovidos);
        $this->assertSame([], $repo->multados);
    }
}
