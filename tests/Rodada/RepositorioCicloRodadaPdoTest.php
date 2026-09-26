<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\AgendaPelada;
use RcInfoti\Pelada\Rodada\CalendarioRodada;
use RcInfoti\Pelada\Rodada\RepositorioCicloRodadaPdo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioCicloRodadaPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
    }

    private function agenda(): AgendaPelada
    {
        return new AgendaPelada(4, '20:00:00', 7, '08:00:00', 3, '12:00:00', 4, '16:00:00');
    }

    private function repo(): RepositorioCicloRodadaPdo
    {
        return new RepositorioCicloRodadaPdo($this->pdo, new CalendarioRodada());
    }

    public function test_cria_rodada_quando_abertura_ja_passou(): void
    {
        // segunda 2026-01-05 09:00: abertura (domingo 2026-01-04 08:00) já passou
        $id = $this->repo()->criarRodadaSeAberta(1, $this->agenda(), new DateTimeImmutable('2026-01-05 09:00:00'));

        $this->assertNotNull($id);
        $row = $this->pdo->query('SELECT * FROM rodadas WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2026-01-08', $row['data_jogo']);
        $this->assertSame('aberta', $row['status']);
        $this->assertSame('2026-01-04 08:00:00', $row['abre_em']);
        $this->assertSame('2026-01-07 12:00:00', $row['vira_regra_em']);
        $this->assertSame('2026-01-08 16:00:00', $row['prazo_multa_em']);
    }

    public function test_nao_cria_antes_da_abertura(): void
    {
        // sábado 2026-01-03 09:00: abertura é domingo 2026-01-04 08:00 → ainda não abriu
        $id = $this->repo()->criarRodadaSeAberta(1, $this->agenda(), new DateTimeImmutable('2026-01-03 09:00:00'));

        $this->assertNull($id);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rodadas')->fetchColumn());
    }

    public function test_criar_e_idempotente(): void
    {
        $repo = $this->repo();
        $agora = new DateTimeImmutable('2026-01-05 09:00:00');

        $id1 = $repo->criarRodadaSeAberta(1, $this->agenda(), $agora);
        $id2 = $repo->criarRodadaSeAberta(1, $this->agenda(), $agora->modify('+10 minutes'));

        $this->assertSame($id1, $id2);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM rodadas')->fetchColumn());
    }

    private function inserirRodada(string $dataJogo, string $status): int
    {
        $this->pdo->prepare(
            "INSERT INTO rodadas (pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
             VALUES (1, ?, ?, ?, ?)"
        )->execute([$dataJogo, $status, $dataJogo . ' 12:00:00', $dataJogo . ' 16:00:00']);

        return (int) $this->pdo->lastInsertId();
    }

    public function test_fecha_rodada_com_jogo_no_passado(): void
    {
        $antiga = $this->inserirRodada('2026-01-01', 'aberta');

        $fechadas = $this->repo()->fecharRodadasVencidas(1, new DateTimeImmutable('2026-01-05 09:00:00'));

        $this->assertSame(1, $fechadas);
        $status = $this->pdo->query('SELECT status FROM rodadas WHERE id = ' . $antiga)->fetchColumn();
        $this->assertSame('fechada', $status);
    }

    public function test_nao_fecha_rodada_de_hoje_nem_futura(): void
    {
        $hoje = $this->inserirRodada('2026-01-05', 'aberta');
        $futura = $this->inserirRodada('2026-01-12', 'aberta');

        $fechadas = $this->repo()->fecharRodadasVencidas(1, new DateTimeImmutable('2026-01-05 09:00:00'));

        $this->assertSame(0, $fechadas);
        $this->assertSame('aberta', $this->pdo->query('SELECT status FROM rodadas WHERE id = ' . $hoje)->fetchColumn());
        $this->assertSame('aberta', $this->pdo->query('SELECT status FROM rodadas WHERE id = ' . $futura)->fetchColumn());
    }
}
