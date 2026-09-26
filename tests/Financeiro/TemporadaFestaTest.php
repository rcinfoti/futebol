<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Financeiro;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Financeiro\TemporadaFesta;

final class TemporadaFestaTest extends TestCase
{
    public function test_padrao_janeiro_a_novembro(): void
    {
        $t = TemporadaFesta::deColunas(null, null);

        $this->assertTrue($t->cobra(new DateTimeImmutable('2026-01-01')));
        $this->assertTrue($t->cobra(new DateTimeImmutable('2026-11-30')));
        $this->assertFalse($t->cobra(new DateTimeImmutable('2026-12-01')));
        $this->assertFalse($t->cobra(new DateTimeImmutable('2026-12-31')));
    }

    public function test_usa_so_mes_e_dia_da_coluna_date(): void
    {
        // MySQL guarda DATE; o ano é irrelevante (a temporada se repete todo ano)
        $t = TemporadaFesta::deColunas('2000-03-01', '2000-10-31');

        $this->assertFalse($t->cobra(new DateTimeImmutable('2027-02-28')));
        $this->assertTrue($t->cobra(new DateTimeImmutable('2027-03-01')));
        $this->assertFalse($t->cobra(new DateTimeImmutable('2027-11-01')));
    }

    public function test_temporada_que_vira_o_ano(): void
    {
        $t = TemporadaFesta::deColunas('2000-08-01', '2000-06-30'); // ago → jun do ano seguinte

        $this->assertTrue($t->cobra(new DateTimeImmutable('2026-12-15')));
        $this->assertTrue($t->cobra(new DateTimeImmutable('2027-01-10')));
        $this->assertFalse($t->cobra(new DateTimeImmutable('2027-07-10')));
    }

    public function test_aceita_formato_mm_dd(): void
    {
        $t = TemporadaFesta::deColunas('01-01', '11-30');
        $this->assertSame(['01-01', '11-30'], [$t->inicio, $t->fim]);
    }
}
