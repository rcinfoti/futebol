<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Admin;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Admin\GestaoRodada;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class GestaoRodadaTest extends TestCase
{
    private PDO $pdo;
    private DateTimeImmutable $antes;   // janela FIFO
    private DateTimeImmutable $depois;  // após o prazo da multa

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro) VALUES (1, 'Quinta', 'quinta', 1, 1), (2, 'Sabado', 'sabado', 20, 4)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo, ativo) VALUES
            (10, 1, 'Ana', 'linha', 1), (11, 1, 'Bia', 'linha', 1), (12, 1, 'Caio', 'linha', 0), (20, 2, 'Zeca', 'linha', 1)");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em) VALUES
            (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00'),
            (6, 2, '2026-01-10', 'aberta', '2026-01-09 12:00:00', '2026-01-10 16:00:00')");
        $this->antes = new DateTimeImmutable('2026-01-05 10:00:00');
        $this->depois = new DateTimeImmutable('2026-01-08 17:00:00');
    }

    private function g(): GestaoRodada
    {
        return new GestaoRodada(
            $this->pdo,
            new ServicoPresenca($this->pdo),
            new ProcessadorRodada(new RepositorioRodadaPdo($this->pdo), new ServicoRegrasRodada()),
        );
    }

    private function situacao(int $jogadorId): string|false
    {
        return $this->pdo->query("SELECT status FROM inscricoes WHERE rodada_id = 5 AND jogador_id = {$jogadorId}")->fetchColumn();
    }

    public function test_adicionar_confirma_em_nome_do_jogador(): void
    {
        $this->assertTrue($this->g()->adicionar(1, 5, 10, $this->antes));
        $this->assertSame('confirmado', $this->situacao(10));

        $this->assertTrue($this->g()->adicionar(1, 5, 11, $this->antes)); // lotado → espera
        $this->assertSame('espera', $this->situacao(11));
    }

    public function test_adicionar_recusa_fora_do_escopo(): void
    {
        $this->assertFalse($this->g()->adicionar(1, 6, 10, $this->antes));  // rodada de outra pelada
        $this->assertFalse($this->g()->adicionar(1, 5, 20, $this->antes));  // jogador de outra pelada
        $this->assertFalse($this->g()->adicionar(1, 5, 12, $this->antes));  // jogador inativo
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inscricoes')->fetchColumn());
    }

    public function test_adicionar_recusa_rodada_fechada(): void
    {
        $this->pdo->exec("UPDATE rodadas SET status = 'fechada' WHERE id = 5");
        $this->assertFalse($this->g()->adicionar(1, 5, 10, $this->antes));
    }

    public function test_promover_passa_por_cima_do_limite(): void
    {
        $this->g()->adicionar(1, 5, 10, $this->antes);
        $this->g()->adicionar(1, 5, 11, $this->antes); // espera (limite 1)

        $this->assertTrue($this->g()->promover(1, 5, 11, $this->antes)); // organizador decide abrir vaga extra

        $this->assertSame('confirmado', $this->situacao(10));
        $this->assertSame('confirmado', $this->situacao(11));
    }

    public function test_promover_so_quem_esta_na_espera(): void
    {
        $this->g()->adicionar(1, 5, 10, $this->antes);
        $this->assertFalse($this->g()->promover(1, 5, 10, $this->antes)); // já confirmado
        $this->assertFalse($this->g()->promover(1, 5, 11, $this->antes)); // nem inscrito
    }

    public function test_desistir_libera_vaga_e_sobe_o_proximo(): void
    {
        $this->g()->adicionar(1, 5, 10, $this->antes);
        $this->g()->adicionar(1, 5, 11, $this->antes); // espera

        $this->assertTrue($this->g()->desistir(1, 5, 10, $this->antes));

        $this->assertSame('desistiu', $this->situacao(10));
        $this->assertSame('confirmado', $this->situacao(11)); // FIFO na hora
    }

    public function test_desistir_depois_do_prazo_gera_multa(): void
    {
        $this->g()->adicionar(1, 5, 10, $this->antes);

        $this->g()->desistir(1, 5, 10, $this->depois);

        $this->assertSame('pendente', $this->pdo->query('SELECT status FROM multas WHERE jogador_id = 10')->fetchColumn());
    }

    public function test_desistir_fora_do_escopo(): void
    {
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES (6, 20, 'linha', 'confirmado', 1)");
        $this->assertFalse($this->g()->desistir(1, 6, 20, $this->antes));
        $this->assertSame('confirmado', $this->pdo->query('SELECT status FROM inscricoes WHERE jogador_id = 20')->fetchColumn());
    }

    public function test_jogadores_fora_da_rodada_para_o_seletor(): void
    {
        $this->g()->adicionar(1, 5, 10, $this->antes);

        $this->assertSame(['Bia'], array_column($this->g()->jogadoresForaDaRodada(1, 5), 'nome')); // Ana já está, Caio inativo
    }
}
