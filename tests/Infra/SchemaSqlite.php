<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Infra;

use PDO;

final class SchemaSqlite
{
    public static function criar(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE peladas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            slug TEXT NOT NULL,
            limite_linha INTEGER NOT NULL DEFAULT 20,
            limite_goleiro INTEGER NOT NULL DEFAULT 4,
            valor_futebol REAL NOT NULL DEFAULT 15.00,
            valor_festa_semana REAL NOT NULL DEFAULT 5.00,
            valor_festa_ano REAL NOT NULL DEFAULT 220.00,
            ativa INTEGER NOT NULL DEFAULT 1,
            dia_jogo INTEGER NOT NULL DEFAULT 4,
            hora_jogo TEXT NOT NULL DEFAULT '20:00:00',
            abre_dia INTEGER NOT NULL DEFAULT 7,
            abre_hora TEXT NOT NULL DEFAULT '08:00:00',
            vira_regra_dia INTEGER NOT NULL DEFAULT 3,
            vira_regra_hora TEXT NOT NULL DEFAULT '12:00:00',
            prazo_multa_dia INTEGER NOT NULL DEFAULT 4,
            prazo_multa_hora TEXT NOT NULL DEFAULT '16:00:00',
            festa_inicio TEXT,
            festa_fim TEXT
        )");

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
            prazo_multa_em TEXT NOT NULL,
            UNIQUE (pelada_id, data_jogo)
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
            promovido_em TEXT,
            promocao_notificada INTEGER NOT NULL DEFAULT 0,
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
            email_enviado INTEGER NOT NULL DEFAULT 0,
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

        $pdo->exec("CREATE TABLE movimentos_caixa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pelada_id INTEGER NOT NULL,
            tipo TEXT NOT NULL,
            categoria TEXT NOT NULL,
            valor REAL NOT NULL,
            descricao TEXT,
            pagamento_id INTEGER UNIQUE,
            ocorrido_em TEXT NOT NULL,
            criado_em TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE tentativas_login (
            jogador_id INTEGER PRIMARY KEY,
            falhas INTEGER NOT NULL DEFAULT 0,
            bloqueado_ate TEXT
        )");

        $pdo->exec("CREATE TABLE usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            senha_hash TEXT NOT NULL,
            papel TEXT NOT NULL DEFAULT 'organizador',
            ativo INTEGER NOT NULL DEFAULT 1,
            falhas_login INTEGER NOT NULL DEFAULT 0,
            bloqueado_ate TEXT
        )");

        $pdo->exec("CREATE TABLE pelada_organizadores (
            pelada_id INTEGER NOT NULL,
            usuario_id INTEGER NOT NULL,
            PRIMARY KEY (pelada_id, usuario_id)
        )");

        $pdo->exec('CREATE UNIQUE INDEX uk_jogador_pelada_nome ON jogadores (pelada_id, nome)');
    }
}
