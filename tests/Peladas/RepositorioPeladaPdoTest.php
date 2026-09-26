<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Peladas;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Jogadores\DadosInvalidos;
use RcInfoti\Pelada\Peladas\DadosPelada;
use RcInfoti\Pelada\Peladas\RepositorioPeladaPdo;
use RcInfoti\Pelada\Rodada\AgendaPelada;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioPeladaPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
    }

    private function dados(string $nome, string $slug = ''): DadosPelada
    {
        return DadosPelada::deFormulario([
            'nome' => $nome, 'slug' => $slug,
            'dia_jogo' => '6', 'hora_jogo' => '09:00', 'abre_dia' => '1', 'abre_hora' => '08:00',
            'vira_regra_dia' => '5', 'vira_regra_hora' => '12:00', 'prazo_multa_dia' => '5', 'prazo_multa_hora' => '20:00',
            'limite_linha' => '14', 'limite_goleiro' => '2', 'valor_futebol' => '20', 'valor_festa_semana' => '0',
            'valor_festa_ano' => '0', 'festa_inicio' => '01/03', 'festa_fim' => '31/10',
        ]);
    }

    public function test_cria_e_a_agenda_serve_pro_cron(): void
    {
        $repo = new RepositorioPeladaPdo($this->pdo);
        $id = $repo->criar($this->dados('Pelada de Sábado'));

        $row = $repo->buscar($id);
        $this->assertSame('pelada-de-sabado', $row['slug']);
        $this->assertSame(14, (int) $row['limite_linha']);
        $this->assertSame('03-01', $row['festa_inicio']);

        // a mesma linha alimenta AgendaPelada::deArray usada no cron
        $agenda = AgendaPelada::deArray($row);
        $this->assertSame([6, '09:00:00', 5, '20:00:00'], [$agenda->diaJogo, $agenda->horaJogo, $agenda->prazoMultaDia, $agenda->prazoMultaHora]);
    }

    public function test_slug_duplicado_e_recusado(): void
    {
        $repo = new RepositorioPeladaPdo($this->pdo);
        $repo->criar($this->dados('Quinta'));

        $this->expectException(DadosInvalidos::class);
        $repo->criar($this->dados('Outra', 'quinta'));
    }

    public function test_atualiza_mantendo_o_proprio_slug(): void
    {
        $repo = new RepositorioPeladaPdo($this->pdo);
        $id = $repo->criar($this->dados('Quinta'));
        $repo->criar($this->dados('Sábado'));

        $repo->atualizar($id, $this->dados('Quinta Renovada', 'quinta'));
        $this->assertSame('Quinta Renovada', $repo->buscar($id)['nome']);

        $this->expectException(DadosInvalidos::class);
        $repo->atualizar($id, $this->dados('Quinta', 'sabado'));
    }

    public function test_ativar_desativar(): void
    {
        $repo = new RepositorioPeladaPdo($this->pdo);
        $id = $repo->criar($this->dados('Quinta'));

        $repo->definirAtiva($id, false);
        $this->assertSame(0, (int) $repo->buscar($id)['ativa']);
        $this->assertNull($repo->buscar(999));
    }
}
