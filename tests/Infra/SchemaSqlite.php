<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Infra;

use PDO;

final class SchemaSqlite
{
    public static function criar(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE peladas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            slug TEXT NOT NULL,
            limite_linha INTEGER NOT NULL DEFAULT 20,
            limite_goleiro INTEGER NOT NULL DEFAULT 4,
            valor_futebol REAL NOT NULL DEFAULT 15.00,
            valor_festa_semana REAL NOT NULL DEFAULT 5.00,
            valor_festa_ano REAL NOT NULL DEFAULT 220.00,
            ativa INTEGER NOT NULL DEFAULT 1
        )');

        $pdo->exec("CREATE TABLE jogadores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pelada_id INTEGER NOT NULL,
            nome TEXT NOT NULL,
            email TEXT,
            telefone TEXT,
            tipo TEXT NOT NULL DEFAULT 'linha',
            pin_hash TEXT,
            saldo_pendente REAL NOT NULL DEFAULT 0,
            festa_quitada_ano INTEGER,
            ativo INTEGER NOT NULL DEFAULT 1
        )");

        $pdo->exec("CREATE TABLE rodadas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pelada_id INTEGER NOT NULL,
            data_jogo TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'aberta',
            abre_em TEXT,
            vira_regra_em TEXT NOT NULL,
            prazo_multa_em TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE inscricoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rodada_id INTEGER NOT NULL,
            jogador_id INTEGER NOT NULL,
            tipo TEXT NOT NULL,
            status TEXT NOT NULL,
            ordem INTEGER NOT NULL,
            confirmado_em TEXT,
            desistiu_em TEXT,
            multa_aplicada INTEGER NOT NULL DEFAULT 0,
            UNIQUE (rodada_id, jogador_id)
        )");

        $pdo->exec("CREATE TABLE multas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pelada_id INTEGER NOT NULL,
            jogador_id INTEGER NOT NULL,
            rodada_id INTEGER NOT NULL,
            valor REAL NOT NULL,
            status TEXT NOT NULL DEFAULT 'pendente',
            motivo TEXT,
            criado_em TEXT NOT NULL,
            quitado_em TEXT,
            UNIQUE (rodada_id, jogador_id)
        )");

        $pdo->exec("CREATE TABLE pagamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pelada_id INTEGER NOT NULL,
            jogador_id INTEGER NOT NULL,
            rodada_id INTEGER,
            categoria TEXT NOT NULL,
            escopo TEXT NOT NULL,
            valor REAL NOT NULL,
            forma TEXT NOT NULL,
            comprovante_arquivo TEXT,
            confirmado INTEGER NOT NULL DEFAULT 0,
            criado_em TEXT NOT NULL
        )");
    }
}
