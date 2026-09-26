<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\StatusInscricao;
use RcInfoti\Pelada\Rodada\Tipo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioRodadaPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro, valor_futebol, valor_festa_semana)
            VALUES (1, 'Quinta', 'quinta', 2, 1, 15.00, 5.00)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo, saldo_pendente, festa_quitada_ano)
            VALUES (10, 1, 'Ana', 'linha', 0, NULL), (11, 1, 'Bia', 'linha', 0, NULL), (12, 1, 'Cadu', 'goleiro', 0, NULL)");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
    }

    private function repo(): RepositorioRodadaPdo
    {
        return new RepositorioRodadaPdo($this->pdo);
    }

    private function inscrever(int $jogadorId, string $tipo, string $status, int $ordem): void
    {
        $this->pdo->prepare('INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES (5, ?, ?, ?, ?)')
            ->execute([$jogadorId, $tipo, $status, $ordem]);
    }

    public function test_carrega_estado_sem_inscricoes(): void
    {
        $estado = $this->repo()->carregarEstado(5);

        $this->assertSame(2, $estado->limiteLinha);
        $this->assertSame(1, $estado->limiteGoleiro);
        $this->assertEquals(new DateTimeImmutable('2026-01-07 12:00:00'), $estado->viraRegraEm);
        $this->assertSame([], $estado->inscricoes);
    }

    public function test_carrega_inscricoes_ordenadas_com_tipo_e_status(): void
    {
        $this->inscrever(11, 'linha', 'espera', 2);
        $this->inscrever(10, 'linha', 'confirmado', 1);

        $estado = $this->repo()->carregarEstado(5);

        $this->assertCount(2, $estado->inscricoes);
        $this->assertSame(10, $estado->inscricoes[0]->jogadorId); // ordem 1 primeiro
        $this->assertSame(Tipo::Linha, $estado->inscricoes[0]->tipo);
        $this->assertSame(StatusInscricao::Confirmado, $estado->inscricoes[0]->status);
        $this->assertSame(StatusInscricao::Espera, $estado->inscricoes[1]->status);
    }

    public function test_pagou_so_com_pagamento_confirmado(): void
    {
        $this->inscrever(10, 'linha', 'espera', 1);
        $this->inscrever(11, 'linha', 'espera', 2);
        // Ana (10): pagamento confirmado. Bia (11): pagamento não confirmado.
        $this->pdo->exec("INSERT INTO pagamentos (pelada_id, jogador_id, rodada_id, categoria, escopo, valor, forma, confirmado, criado_em)
            VALUES (1, 10, 5, 'futebol', 'semana', 15, 'pix', 1, '2026-01-06 10:00:00'),
                   (1, 11, 5, 'futebol', 'semana', 15, 'pix', 0, '2026-01-06 10:00:00')");

        $estado = $this->repo()->carregarEstado(5);
        $porId = [];
        foreach ($estado->inscricoes as $i) {
            $porId[$i->jogadorId] = $i;
        }

        $this->assertTrue($porId[10]->pagou);
        $this->assertFalse($porId[11]->pagou);
    }

    public function test_promover_confirma_a_inscricao(): void
    {
        $this->inscrever(11, 'linha', 'espera', 2);

        $this->repo()->promover(5, 11, new DateTimeImmutable('2026-01-07 09:00:00'));

        $status = $this->pdo->query('SELECT status FROM inscricoes WHERE jogador_id = 11')->fetchColumn();
        $this->assertSame('confirmado', $status);
    }

    public function test_aplicar_multa_persiste_tudo(): void
    {
        $this->inscrever(10, 'linha', 'desistiu', 1);

        $this->repo()->aplicarMulta(5, 10, new DateTimeImmutable('2026-01-08 17:00:00'));

        // multa gravada com o devido de linha (15 + 5 = 20)
        $multa = $this->pdo->query('SELECT valor, status, jogador_id, rodada_id FROM multas')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(20.0, (float) $multa['valor']);
        $this->assertSame('pendente', $multa['status']);
        $this->assertSame('10', (string) $multa['jogador_id']);
        // saldo_pendente incrementado
        $saldo = $this->pdo->query('SELECT saldo_pendente FROM jogadores WHERE id = 10')->fetchColumn();
        $this->assertSame(20.0, (float) $saldo);
        // flag marcada
        $flag = $this->pdo->query('SELECT multa_aplicada FROM inscricoes WHERE jogador_id = 10')->fetchColumn();
        $this->assertSame('1', (string) $flag);
    }

    public function test_multa_ignora_festa_quitada_de_outro_ano(): void
    {
        // Cadu (goleiro) quitou a festa em 2025, mas a rodada é de 2026 -> festa ainda devida (5).
        $this->pdo->exec('UPDATE jogadores SET festa_quitada_ano = 2025 WHERE id = 12');
        $this->inscrever(12, 'goleiro', 'desistiu', 1);

        $this->repo()->aplicarMulta(5, 12, new DateTimeImmutable('2026-01-08 17:00:00'));

        $valor = $this->pdo->query('SELECT valor FROM multas WHERE jogador_id = 12')->fetchColumn();
        $this->assertSame(5.0, (float) $valor); // goleiro paga só a festa da semana
    }
}
