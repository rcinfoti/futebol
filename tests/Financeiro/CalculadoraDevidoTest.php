<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Financeiro;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Financeiro\CalculadoraDevido;
use RcInfoti\Pelada\Rodada\Tipo;

final class CalculadoraDevidoTest extends TestCase
{
    private function calc(): CalculadoraDevido
    {
        return new CalculadoraDevido(15.00, 5.00);
    }

    public function test_linha_paga_futebol_mais_festa(): void
    {
        $this->assertSame(20.00, $this->calc()->devidoSemanal(Tipo::Linha, festaQuitada: false));
    }

    public function test_goleiro_paga_so_festa(): void
    {
        $this->assertSame(5.00, $this->calc()->devidoSemanal(Tipo::Goleiro, festaQuitada: false));
    }

    public function test_festa_quitada_zera_a_parte_da_festa(): void
    {
        $this->assertSame(15.00, $this->calc()->devidoSemanal(Tipo::Linha, festaQuitada: true));
        $this->assertSame(0.00, $this->calc()->devidoSemanal(Tipo::Goleiro, festaQuitada: true));
    }
}
