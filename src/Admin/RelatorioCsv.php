<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Admin;

use RcInfoti\Pelada\Financeiro\ResumoMensalJogador;

/** CSV no formato que o Excel pt-BR abre direto: `;`, vírgula decimal, UTF-8 com BOM. */
final class RelatorioCsv
{
    /** @param list<ResumoMensalJogador> $linhas */
    public static function mensal(array $linhas): string
    {
        $out = [self::linha(['Jogador', 'Futebol', 'Festa', 'Total pago', 'Pendente'])];
        $tf = $tfe = $tp = 0.0;
        foreach ($linhas as $r) {
            $out[] = self::linha([
                self::texto($r->nome),
                self::num($r->pagoFutebol),
                self::num($r->pagoFesta),
                self::num($r->pagoFutebol + $r->pagoFesta),
                self::num($r->pendente),
            ]);
            $tf += $r->pagoFutebol;
            $tfe += $r->pagoFesta;
            $tp += $r->pendente;
        }
        $out[] = self::linha(['Total', self::num($tf), self::num($tfe), self::num($tf + $tfe), self::num($tp)]);

        return "\u{FEFF}" . implode("\r\n", $out) . "\r\n";
    }

    /** @param list<string> $campos */
    private static function linha(array $campos): string
    {
        return implode(';', array_map(
            static fn (string $c): string => preg_match('/[;"\r\n]/', $c) === 1 ? '"' . str_replace('"', '""', $c) . '"' : $c,
            $campos,
        ));
    }

    /** Texto livre (nome): começa com = + - @ vira fórmula no Excel → prefixa apóstrofo. */
    private static function texto(string $s): string
    {
        return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'" . $s : $s;
    }

    private static function num(float $v): string
    {
        return number_format($v, 2, ',', '');
    }
}
