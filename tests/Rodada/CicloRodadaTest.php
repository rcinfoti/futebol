<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Multa\NotificadorMulta;
use RcInfoti\Pelada\Rodada\AgendaPelada;
use RcInfoti\Pelada\Rodada\CalendarioRodada;
use RcInfoti\Pelada\Rodada\CicloRodada;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioCicloRodadaPdo;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Tests\Email\EnviadorEmailFake;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class CicloRodadaTest extends TestCase
{
    private PDO $pdo;
    private EnviadorEmailFake $email;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->email = new EnviadorEmailFake();

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro)
            VALUES (1, 'Quinta', 'quinta', 1, 1)");
    }

    private function agenda(): AgendaPelada
    {
        return new AgendaPelada(4, '20:00:00', 7, '08:00:00', 3, '12:00:00', 4, '16:00:00');
    }

    private function ciclo(): CicloRodada
    {
        $calendario = new CalendarioRodada();
        $cicloRepo = new RepositorioCicloRodadaPdo($this->pdo, $calendario);
        $processador = new ProcessadorRodada(new RepositorioRodadaPdo($this->pdo), new ServicoRegrasRodada());
        $notificador = new NotificadorMulta($this->pdo, $this->email);

        return new CicloRodada($cicloRepo, $processador, $notificador);
    }

    public function test_cria_a_rodada_quando_a_abertura_chegou(): void
    {
        // segunda 2026-01-05 09:00: abertura (domingo) já passou
        $this->ciclo()->executar(1, $this->agenda(), new DateTimeImmutable('2026-01-05 09:00:00'));

        $row = $this->pdo->query("SELECT * FROM rodadas WHERE pelada_id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('2026-01-08', $row['data_jogo']);
        $this->assertSame('aberta', $row['status']);
    }

    public function test_processa_multa_e_notifica_na_rodada_existente(): void
    {
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, email, tipo) VALUES
            (10, 1, 'Ana', 'ana@example.com', 'linha'),
            (11, 1, 'Bia', 'bia@example.com', 'linha')");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, abre_em, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-04 08:00:00', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        // Ana confirmada e já desistiu às 16:30 (após o prazo 16:00) => multa
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem, desistiu_em)
            VALUES (5, 10, 'linha', 'desistiu', 1, '2026-01-08 16:30:00')");
        // Bia na espera
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem)
            VALUES (5, 11, 'linha', 'espera', 2)");

        // $agora após o prazo: aplica multa da Ana; sem promoção (jogo iminente)
        $this->ciclo()->executar(1, $this->agenda(), new DateTimeImmutable('2026-01-08 17:00:00'));

        // multa aplicada e persistida
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM multas WHERE jogador_id = 10')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT multa_aplicada FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 10')->fetchColumn());
        // e-mail de multa enviado para Ana
        $this->assertCount(1, $this->email->enviados);
        $this->assertSame('ana@example.com', $this->email->enviados[0]['para']);
        // Bia continua na espera (sem promoção após o prazo)
        $this->assertSame('espera', $this->pdo->query('SELECT status FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 11')->fetchColumn());
    }

    public function test_fecha_rodada_antiga_no_ciclo(): void
    {
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (9, 1, '2026-01-01', 'aberta', '2025-12-31 12:00:00', '2026-01-01 16:00:00')");

        $this->ciclo()->executar(1, $this->agenda(), new DateTimeImmutable('2026-01-05 09:00:00'));

        $this->assertSame('fechada', $this->pdo->query('SELECT status FROM rodadas WHERE id = 9')->fetchColumn());
    }
}
