<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Infra;

use PDO;
use PHPUnit\Framework\TestCase;

final class SchemaSqliteTest extends TestCase
{
    public function test_cria_todas_as_tabelas_do_dominio(): void
    {
        $pdo = new PDO('sqlite::memory:');
        SchemaSqlite::criar($pdo);

        $tabelas = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach (['inscricoes', 'jogadores', 'multas', 'pagamentos', 'peladas', 'rodadas'] as $t) {
            $this->assertContains($t, $tabelas, "faltou a tabela {$t}");
        }
    }

    public function test_inscricoes_tem_as_colunas_usadas_pelas_queries(): void
    {
        $pdo = new PDO('sqlite::memory:');
        SchemaSqlite::criar($pdo);

        $colunas = $pdo->query('PRAGMA table_info(inscricoes)')->fetchAll(PDO::FETCH_ASSOC);
        $nomes = array_column($colunas, 'name');

        foreach (['rodada_id', 'jogador_id', 'tipo', 'status', 'ordem', 'desistiu_em', 'multa_aplicada'] as $c) {
            $this->assertContains($c, $nomes, "faltou a coluna inscricoes.{$c}");
        }
    }

    public function test_movimentos_caixa_existe_com_colunas(): void
    {
        $pdo = new PDO('sqlite::memory:');
        SchemaSqlite::criar($pdo);

        $tabelas = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('movimentos_caixa', $tabelas, 'faltou a tabela movimentos_caixa');

        $colunas = $pdo->query('PRAGMA table_info(movimentos_caixa)')->fetchAll(PDO::FETCH_ASSOC);
        $nomes = array_column($colunas, 'name');
        foreach (['pelada_id', 'tipo', 'categoria', 'valor', 'descricao', 'pagamento_id', 'ocorrido_em', 'criado_em'] as $c) {
            $this->assertContains($c, $nomes, "faltou a coluna movimentos_caixa.{$c}");
        }
    }
}
