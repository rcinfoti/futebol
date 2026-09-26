<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Presenca;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class ServicoPresencaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        // limite de linha = 1 para exercitar a espera
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro) VALUES (1, 'Quinta', 'quinta', 1, 1)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES
            (10, 1, 'Ana', 'linha'), (11, 1, 'Bia', 'linha'), (12, 1, 'Cadu', 'goleiro')");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
    }

    private function servico(): ServicoPresenca
    {
        return new ServicoPresenca($this->pdo);
    }

    private function inscricao(int $jogadorId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inscricoes WHERE rodada_id = 5 AND jogador_id = ?');
        $stmt->execute([$jogadorId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function test_primeiro_confirmado_entra_como_confirmado(): void
    {
        $this->servico()->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00'));

        $i = $this->inscricao(10);
        $this->assertSame('confirmado', $i['status']);
        $this->assertSame('linha', $i['tipo']);
        $this->assertSame(1, (int) $i['ordem']);
    }

    public function test_confirma_como_espera_quando_lotado(): void
    {
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00')); // ocupa a única vaga de linha
        $s->confirmar(5, 11, new DateTimeImmutable('2026-01-05 10:05:00')); // lotado → espera

        $this->assertSame('confirmado', $this->inscricao(10)['status']);
        $this->assertSame('espera', $this->inscricao(11)['status']);
        $this->assertSame(2, (int) $this->inscricao(11)['ordem']);
    }

    public function test_goleiro_tem_limite_proprio(): void
    {
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00')); // linha ocupa vaga de linha
        $s->confirmar(5, 12, new DateTimeImmutable('2026-01-05 10:05:00')); // goleiro tem sua própria vaga

        $this->assertSame('confirmado', $this->inscricao(12)['status']);
    }

    public function test_confirmar_e_idempotente(): void
    {
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00'));
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:10:00'));

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 10')->fetchColumn());
    }

    public function test_desistir_marca_status_e_data(): void
    {
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00'));
        $s->desistir(5, 10, new DateTimeImmutable('2026-01-08 17:00:00'));

        $i = $this->inscricao(10);
        $this->assertSame('desistiu', $i['status']);
        $this->assertSame('2026-01-08 17:00:00', $i['desistiu_em']);
    }

    public function test_reconfirmar_apos_desistir(): void
    {
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00'));
        $s->desistir(5, 10, new DateTimeImmutable('2026-01-06 09:00:00'));
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-06 10:00:00'));

        $i = $this->inscricao(10);
        $this->assertSame('confirmado', $i['status']);
        $this->assertNull($i['desistiu_em']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 10')->fetchColumn());
    }

    public function test_quem_desiste_e_volta_vai_pro_fim_da_fila(): void
    {
        // limite de linha = 1: Ana confirmada, Bia na espera
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (13, 1, 'Duda', 'linha')");
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00')); // Ana: confirmada, ordem 1
        $s->confirmar(5, 11, new DateTimeImmutable('2026-01-05 10:05:00')); // Bia: espera, ordem 2
        $s->confirmar(5, 13, new DateTimeImmutable('2026-01-05 10:10:00')); // Duda: espera, ordem 3

        // Bia sai da espera e volta: não pode manter a ordem 2 e passar na frente da Duda
        $s->desistir(5, 11, new DateTimeImmutable('2026-01-05 11:00:00'));
        $s->confirmar(5, 11, new DateTimeImmutable('2026-01-05 11:05:00'));

        $this->assertSame('espera', $this->inscricao(11)['status']);
        $this->assertGreaterThan((int) $this->inscricao(13)['ordem'], (int) $this->inscricao(11)['ordem']);
    }

    public function test_desistir_da_espera_sai_da_fila_sem_virar_desistencia(): void
    {
        // Spec §3.3: multa é pra CONFIRMADO que desiste. Quem só estava na espera nunca teve vaga.
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00')); // confirmada
        $s->confirmar(5, 11, new DateTimeImmutable('2026-01-05 10:05:00')); // espera

        $s->desistir(5, 11, new DateTimeImmutable('2026-01-08 17:00:00')); // depois do prazo da multa

        $this->assertSame([], $this->inscricao(11)); // saiu da fila
        $this->assertSame('confirmado', $this->inscricao(10)['status']);
    }
}
