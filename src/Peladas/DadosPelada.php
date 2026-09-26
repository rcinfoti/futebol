<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Peladas;

use RcInfoti\Pelada\Financeiro\TemporadaFesta;
use RcInfoti\Pelada\Jogadores\DadosInvalidos;

/**
 * Configuração de uma pelada (spec §3, §4), validada. Dias da semana em ISO-8601
 * (1=segunda … 7=domingo), a mesma convenção do CalendarioRodada.
 */
final class DadosPelada
{
    public const DIAS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    private function __construct(
        public readonly string $nome,
        public readonly string $slug,
        public readonly int $diaJogo,
        public readonly string $horaJogo,
        public readonly int $abreDia,
        public readonly string $abreHora,
        public readonly int $viraRegraDia,
        public readonly string $viraRegraHora,
        public readonly int $prazoMultaDia,
        public readonly string $prazoMultaHora,
        public readonly int $limiteLinha,
        public readonly int $limiteGoleiro,
        public readonly float $valorFutebol,
        public readonly float $valorFestaSemana,
        public readonly float $valorFestaAno,
        public readonly string $festaInicio,
        public readonly string $festaFim,
    ) {
    }

    /** @param array<string,mixed> $f */
    public static function deFormulario(array $f): self
    {
        $e = [];
        $txt = static fn (string $k): string => trim((string) ($f[$k] ?? ''));

        $nome = preg_replace('/\s+/u', ' ', $txt('nome')) ?? '';
        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 120) {
            $e['nome'] = 'Nome precisa ter entre 3 e 120 letras.';
        }
        $slug = $txt('slug') === '' ? self::slugDe($nome) : mb_strtolower($txt('slug'));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 60) {
            $e['slug'] = 'Link: só letras minúsculas, números e hífen (ex.: pelada-de-quinta).';
        }

        $dia = static function (string $k) use ($txt, &$e): int {
            $v = $txt($k);
            if (!ctype_digit($v) || (int) $v < 1 || (int) $v > 7) {
                $e[$k] = 'Escolha o dia.';

                return 0;
            }

            return (int) $v;
        };
        $hora = static function (string $k) use ($txt, &$e): string {
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::00)?$/', $txt($k), $m) !== 1) {
                $e[$k] = 'Hora inválida (use HH:MM).';

                return '00:00:00';
            }

            return "{$m[1]}:{$m[2]}:00";
        };
        $inteiro = static function (string $k, int $min, int $max) use ($txt, &$e): int {
            $v = $txt($k);
            if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
                $e[$k] = "Use um número de {$min} a {$max}.";

                return $min;
            }

            return (int) $v;
        };
        $dinheiro = static function (string $k) use ($txt, &$e): float {
            $v = $txt($k);
            $v = str_contains($v, ',') ? str_replace(['.', ','], ['', '.'], $v) : $v;
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > 10000) {
                $e[$k] = 'Valor inválido.';

                return 0.0;
            }

            return round((float) $v, 2);
        };
        $diaMes = static function (string $k) use ($txt, &$e): string {
            // formulário usa DD/MM; guarda MM-DD
            $v = $txt($k);
            $mmdd = preg_match('#^(\d{1,2})/(\d{1,2})$#', $v, $m) === 1
                ? TemporadaFesta::mmdd(sprintf('%02d-%02d', (int) $m[2], (int) $m[1]))
                : null;
            if ($mmdd === null) {
                $e[$k] = 'Data inválida (use DD/MM).';

                return '01-01';
            }

            return $mmdd;
        };

        $d = new self(
            $nome, $slug,
            $dia('dia_jogo'), $hora('hora_jogo'),
            $dia('abre_dia'), $hora('abre_hora'),
            $dia('vira_regra_dia'), $hora('vira_regra_hora'),
            $dia('prazo_multa_dia'), $hora('prazo_multa_hora'),
            $inteiro('limite_linha', 1, 100), $inteiro('limite_goleiro', 0, 20),
            $dinheiro('valor_futebol'), $dinheiro('valor_festa_semana'), $dinheiro('valor_festa_ano'),
            $diaMes('festa_inicio'), $diaMes('festa_fim'),
        );

        if (!array_intersect_key($e, array_flip(['dia_jogo', 'hora_jogo', 'abre_dia', 'abre_hora', 'vira_regra_dia', 'vira_regra_hora', 'prazo_multa_dia', 'prazo_multa_hora']))) {
            $e += $d->errosDaAgenda();
        }
        if ($e !== []) {
            throw new DadosInvalidos($e);
        }

        return $d;
    }

    /**
     * Os marcos são recuados a partir do jogo dentro da semana que termina nele (CalendarioRodada),
     * então comparo "minutos antes do jogo": precisa valer abre > vira > prazo ≥ 0.
     *
     * @return array<string,string>
     */
    private function errosDaAgenda(): array
    {
        $antes = fn (int $dia, string $hora): int
            => (($this->diaJogo - $dia + 7) % 7) * 1440 + self::minutos($this->horaJogo) - self::minutos($hora);

        $abre = $antes($this->abreDia, $this->abreHora);
        $vira = $antes($this->viraRegraDia, $this->viraRegraHora);
        $prazo = $antes($this->prazoMultaDia, $this->prazoMultaHora);

        return match (true) {
            $abre < 0 => ['abre_hora' => 'A abertura não pode ser depois do jogo.'],
            $prazo < 0 => ['prazo_multa_hora' => 'O prazo da multa não pode ser depois do jogo.'],
            $vira >= $abre => ['vira_regra_hora' => 'A virada "pago primeiro" tem que ser depois da abertura.'],
            $prazo >= $vira => ['prazo_multa_hora' => 'O prazo da multa tem que ser depois da virada "pago primeiro".'],
            default => [],
        };
    }

    private static function minutos(string $hora): int
    {
        [$h, $m] = explode(':', $hora);

        return (int) $h * 60 + (int) $m;
    }

    public static function slugDe(string $nome): string
    {
        $s = mb_strtolower($nome);
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';

        return trim(substr($s, 0, 60), '-');
    }

    /** @return array<string,mixed> colunas de `peladas` */
    public function colunas(): array
    {
        return [
            'nome' => $this->nome, 'slug' => $this->slug,
            'dia_jogo' => $this->diaJogo, 'hora_jogo' => $this->horaJogo,
            'abre_dia' => $this->abreDia, 'abre_hora' => $this->abreHora,
            'vira_regra_dia' => $this->viraRegraDia, 'vira_regra_hora' => $this->viraRegraHora,
            'prazo_multa_dia' => $this->prazoMultaDia, 'prazo_multa_hora' => $this->prazoMultaHora,
            'limite_linha' => $this->limiteLinha, 'limite_goleiro' => $this->limiteGoleiro,
            'valor_futebol' => $this->valorFutebol, 'valor_festa_semana' => $this->valorFestaSemana,
            'valor_festa_ano' => $this->valorFestaAno,
            // coluna DATE no MySQL: ano fixo (2000 é bissexto, aceita 29/02); só mês/dia importam
            'festa_inicio' => '2000-' . $this->festaInicio, 'festa_fim' => '2000-' . $this->festaFim,
        ];
    }

    /**
     * Linha do banco → valores do formulário (HH:MM, vírgula decimal, DD/MM).
     *
     * @param array<string,mixed> $r
     * @return array<string,string>
     */
    public static function paraFormulario(array $r): array
    {
        $hm = static fn ($v): string => substr((string) $v, 0, 5);
        $rs = static fn ($v): string => number_format((float) $v, 2, ',', '');
        $dm = static function ($v, string $padrao): string {
            [$mes, $d] = explode('-', TemporadaFesta::mmdd($v === null ? null : (string) $v) ?? $padrao);

            return "{$d}/{$mes}";
        };

        return [
            'nome' => (string) $r['nome'], 'slug' => (string) $r['slug'],
            'dia_jogo' => (string) $r['dia_jogo'], 'hora_jogo' => $hm($r['hora_jogo']),
            'abre_dia' => (string) $r['abre_dia'], 'abre_hora' => $hm($r['abre_hora']),
            'vira_regra_dia' => (string) $r['vira_regra_dia'], 'vira_regra_hora' => $hm($r['vira_regra_hora']),
            'prazo_multa_dia' => (string) $r['prazo_multa_dia'], 'prazo_multa_hora' => $hm($r['prazo_multa_hora']),
            'limite_linha' => (string) $r['limite_linha'], 'limite_goleiro' => (string) $r['limite_goleiro'],
            'valor_futebol' => $rs($r['valor_futebol']), 'valor_festa_semana' => $rs($r['valor_festa_semana']),
            'valor_festa_ano' => $rs($r['valor_festa_ano']),
            'festa_inicio' => $dm($r['festa_inicio'], TemporadaFesta::INICIO_PADRAO),
            'festa_fim' => $dm($r['festa_fim'], TemporadaFesta::FIM_PADRAO),
        ];
    }
}
