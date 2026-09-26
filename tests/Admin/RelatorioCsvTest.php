<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Admin\RelatorioCsv;
use RcInfoti\Pelada\Financeiro\ResumoMensalJogador;

final class RelatorioCsvTest extends TestCase
{
    public function test_csv_pt_br_para_excel(): void
    {
        $csv = RelatorioCsv::mensal([
            new ResumoMensalJogador(10, 'Ana', 60.0, 20.0, 0.0),
            new ResumoMensalJogador(11, 'Bia; "Bi"', 15.5, 0.0, 20.0),
        ]);

        $this->assertStringStartsWith("\u{FEFF}", $csv); // BOM: Excel abre UTF-8 com acento certo
        $linhas = explode("\r\n", trim(substr($csv, 3)));
        $this->assertSame('Jogador;Futebol;Festa;Total pago;Pendente', $linhas[0]);
        $this->assertSame('Ana;60,00;20,00;80,00;0,00', $linhas[1]);
        $this->assertSame('"Bia; ""Bi""";15,50;0,00;15,50;20,00', $linhas[2]);
        $this->assertSame('Total;75,50;20,00;95,50;20,00', $linhas[3]);
    }

    public function test_neutraliza_formula_no_nome(): void
    {
        $csv = RelatorioCsv::mensal([new ResumoMensalJogador(10, '=HYPERLINK("http://x")', 0.0, 0.0, 0.0)]);

        $this->assertStringContainsString("\"'=HYPERLINK", $csv);
    }
}
