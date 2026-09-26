<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Peladas;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Jogadores\DadosInvalidos;
use RcInfoti\Pelada\Peladas\DadosPelada;

final class DadosPeladaTest extends TestCase
{
    /** @return array<string,string> quinta 20h, abre domingo 8h, vira quarta 12h, multa quinta 16h */
    private function valido(array $troca = []): array
    {
        return $troca + [
            'nome' => 'Peladão de Quinta', 'slug' => '',
            'dia_jogo' => '4', 'hora_jogo' => '20:00',
            'abre_dia' => '7', 'abre_hora' => '08:00',
            'vira_regra_dia' => '3', 'vira_regra_hora' => '12:00',
            'prazo_multa_dia' => '4', 'prazo_multa_hora' => '16:00',
            'limite_linha' => '20', 'limite_goleiro' => '4',
            'valor_futebol' => '15', 'valor_festa_semana' => '5', 'valor_festa_ano' => '220,00',
            'festa_inicio' => '01/01', 'festa_fim' => '30/11',
        ];
    }

    /** @return array<string,string> */
    private function erros(array $troca): array
    {
        try {
            DadosPelada::deFormulario($this->valido($troca));
        } catch (DadosInvalidos $e) {
            return $e->erros;
        }
        $this->fail('deveria ser inválido');
    }

    public function test_valido_normaliza(): void
    {
        $d = DadosPelada::deFormulario($this->valido());

        $this->assertSame('peladao-de-quinta', $d->slug); // gerado do nome, sem acento
        $this->assertSame('20:00:00', $d->horaJogo);
        $this->assertSame(220.0, $d->valorFestaAno);
        $this->assertSame(['01-01', '11-30'], [$d->festaInicio, $d->festaFim]);
    }

    public function test_slug_informado_e_validado(): void
    {
        $this->assertSame('quinta', DadosPelada::deFormulario($this->valido(['slug' => 'Quinta']))->slug);
        $this->assertArrayHasKey('slug', $this->erros(['slug' => 'quinta/../x']));
    }

    public function test_dia_e_hora_invalidos(): void
    {
        $this->assertArrayHasKey('dia_jogo', $this->erros(['dia_jogo' => '0'])); // ISO: 1..7
        $this->assertArrayHasKey('hora_jogo', $this->erros(['hora_jogo' => '25:00']));
    }

    public function test_prazo_da_multa_depois_do_jogo(): void
    {
        $this->assertArrayHasKey('prazo_multa_hora', $this->erros(['prazo_multa_hora' => '21:00'])); // quinta 21h > jogo 20h
    }

    public function test_virada_antes_da_abertura(): void
    {
        // abre quarta 13h, vira quarta 12h → vira antes de abrir
        $this->assertArrayHasKey('vira_regra_hora', $this->erros(['abre_dia' => '3', 'abre_hora' => '13:00']));
    }

    public function test_prazo_antes_da_virada(): void
    {
        $this->assertArrayHasKey('prazo_multa_hora', $this->erros(['prazo_multa_dia' => '3', 'prazo_multa_hora' => '11:00']));
    }

    public function test_marcos_no_mesmo_dia_do_jogo_em_ordem_sao_validos(): void
    {
        $d = DadosPelada::deFormulario($this->valido([
            'dia_jogo' => '6', 'hora_jogo' => '18:00',
            'abre_dia' => '6', 'abre_hora' => '08:00',
            'vira_regra_dia' => '6', 'vira_regra_hora' => '12:00',
            'prazo_multa_dia' => '6', 'prazo_multa_hora' => '15:00',
        ]));
        $this->assertSame(6, $d->diaJogo);
    }

    public function test_limites_e_valores(): void
    {
        $e = $this->erros(['limite_linha' => '0', 'limite_goleiro' => '999', 'valor_futebol' => '-1', 'valor_festa_ano' => 'abc']);
        $this->assertArrayHasKey('limite_linha', $e);
        $this->assertArrayHasKey('limite_goleiro', $e);
        $this->assertArrayHasKey('valor_futebol', $e);
        $this->assertArrayHasKey('valor_festa_ano', $e);
    }

    public function test_goleiro_pode_ter_valor_zero(): void
    {
        $this->assertSame(0.0, DadosPelada::deFormulario($this->valido(['valor_festa_semana' => '0']))->valorFestaSemana);
    }

    public function test_temporada_invalida(): void
    {
        $this->assertArrayHasKey('festa_inicio', $this->erros(['festa_inicio' => '31/02']));
    }

    public function test_de_linha_do_banco_para_o_formulario(): void
    {
        $f = DadosPelada::paraFormulario([
            'nome' => 'Q', 'slug' => 'q', 'dia_jogo' => 4, 'hora_jogo' => '20:00:00', 'abre_dia' => 7, 'abre_hora' => '08:00:00',
            'vira_regra_dia' => 3, 'vira_regra_hora' => '12:00:00', 'prazo_multa_dia' => 4, 'prazo_multa_hora' => '16:00:00',
            'limite_linha' => 20, 'limite_goleiro' => 4, 'valor_futebol' => '15.00', 'valor_festa_semana' => '5.00',
            'valor_festa_ano' => '220.00', 'festa_inicio' => null, 'festa_fim' => '2000-11-30',
        ]);
        $this->assertSame(['20:00', '15,00', '01/01', '30/11'], [$f['hora_jogo'], $f['valor_futebol'], $f['festa_inicio'], $f['festa_fim']]);
    }
}
