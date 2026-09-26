<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use DateTimeImmutable;

/**
 * Período do ano em que a festa semanal é cobrada (spec §3.2; default 01/jan–30/nov,
 * dezembro fora). Só mês/dia importam — a temporada se repete todo ano. Suporta
 * temporada que vira o ano (ex.: agosto a junho).
 */
final class TemporadaFesta
{
    public const INICIO_PADRAO = '01-01';
    public const FIM_PADRAO = '11-30';

    private function __construct(
        public readonly string $inicio,
        public readonly string $fim,
    ) {
    }

    /** Aceita as colunas `festa_inicio`/`festa_fim` (DATE "AAAA-MM-DD", "MM-DD" ou null = padrão). */
    public static function deColunas(?string $inicio, ?string $fim): self
    {
        return new self(self::mmdd($inicio) ?? self::INICIO_PADRAO, self::mmdd($fim) ?? self::FIM_PADRAO);
    }

    public function cobra(DateTimeImmutable $data): bool
    {
        $d = $data->format('m-d');

        return $this->inicio <= $this->fim
            ? $d >= $this->inicio && $d <= $this->fim
            : $d >= $this->inicio || $d <= $this->fim; // vira o ano
    }

    /** Normaliza pra "MM-DD"; null se vazio ou inválido. */
    public static function mmdd(?string $v): ?string
    {
        $v = trim((string) $v);
        if (preg_match('/^(?:\d{4}-)?(\d{2})-(\d{2})$/', $v, $m) !== 1 || !checkdate((int) $m[1], (int) $m[2], 2024)) {
            return null; // 2024: bissexto, aceita 29/02
        }

        return $m[1] . '-' . $m[2];
    }
}
