<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Painel;

use DateTimeImmutable;
use PDO;
use RcInfoti\Pelada\Financeiro\CalculadoraDevido;
use RcInfoti\Pelada\Financeiro\TemporadaFesta;
use RcInfoti\Pelada\Rodada\Tipo;

final class ConsultaPainelJogador
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int,nome:string}> jogadores ativos da pelada, para o seletor de login */
    public function jogadoresParaLogin(int $peladaId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT j.id, j.nome FROM jogadores j
             JOIN peladas p ON p.id = j.pelada_id
             WHERE j.pelada_id = ? AND j.ativo = 1 AND p.ativa = 1
             ORDER BY j.nome"
        );
        $stmt->execute([$peladaId]);

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'nome' => (string) $r['nome']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** @return array{id:int,nome:string,slug:string}|null */
    public function peladaPorSlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nome, slug FROM peladas WHERE slug = ? AND ativa = 1');
        $stmt->execute([$slug]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : self::pelada($r);
    }

    /** @return list<array{id:int,nome:string,slug:string}> */
    public function peladasAtivas(): array
    {
        $rows = $this->pdo->query('SELECT id, nome, slug FROM peladas WHERE ativa = 1 ORDER BY nome')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => self::pelada($r), $rows);
    }

    public function pertenceAPelada(int $jogadorId, int $peladaId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM jogadores WHERE id = ? AND pelada_id = ? AND ativo = 1');
        $stmt->execute([$jogadorId, $peladaId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Slug da pelada do jogador (para voltar ao login certo ao sair). */
    public function slugDoJogador(int $jogadorId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT p.slug FROM jogadores j JOIN peladas p ON p.id = j.pelada_id WHERE j.id = ?');
        $stmt->execute([$jogadorId]);
        $slug = $stmt->fetchColumn();

        return $slug === false ? null : (string) $slug;
    }

    /** @param array<string,mixed> $r @return array{id:int,nome:string,slug:string} */
    private static function pelada(array $r): array
    {
        return ['id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'slug' => (string) $r['slug']];
    }

    public function peladaId(int $jogadorId): int
    {
        $stmt = $this->pdo->prepare('SELECT pelada_id FROM jogadores WHERE id = ?');
        $stmt->execute([$jogadorId]);

        return (int) $stmt->fetchColumn();
    }

    public function montar(int $jogadorId, DateTimeImmutable $agora): PainelJogador
    {
        $jog = $this->pdo->prepare(
            'SELECT j.nome, j.tipo, j.saldo_pendente, j.festa_quitada_ano, j.pelada_id, p.festa_inicio, p.festa_fim,
                    p.valor_futebol, p.valor_festa_semana
             FROM jogadores j JOIN peladas p ON p.id = j.pelada_id
             WHERE j.id = ?'
        );
        $jog->execute([$jogadorId]);
        $j = $jog->fetch(PDO::FETCH_ASSOC);
        if ($j === false) {
            throw new \RuntimeException("Jogador {$jogadorId} não encontrado.");
        }

        // Rodada aberta mais próxima da pelada do jogador.
        $rod = $this->pdo->prepare(
            "SELECT id, data_jogo FROM rodadas
             WHERE pelada_id = ? AND status = 'aberta'
             ORDER BY data_jogo LIMIT 1"
        );
        $rod->execute([(int) $j['pelada_id']]);
        $r = $rod->fetch(PDO::FETCH_ASSOC);

        $rodadaId = $r === false ? null : (int) $r['id'];
        $dataJogo = $r === false ? null : (string) $r['data_jogo'];

        $situacao = 'sem_rodada';
        if ($rodadaId !== null) {
            $ins = $this->pdo->prepare('SELECT status FROM inscricoes WHERE rodada_id = ? AND jogador_id = ?');
            $ins->execute([$rodadaId, $jogadorId]);
            $status = $ins->fetchColumn();
            $situacao = $status === false ? 'fora' : (string) $status;
        }

        $anoJogo = $dataJogo !== null ? (int) (new DateTimeImmutable($dataJogo))->format('Y') : (int) $agora->format('Y');
        $festaQuitada = $j['festa_quitada_ano'] !== null && (int) $j['festa_quitada_ano'] === $anoJogo;
        // fora da temporada (ex.: dezembro) a festa semanal não é cobrada — spec §3.2
        $diaJogo = new DateTimeImmutable($dataJogo ?? $agora->format('Y-m-d'));
        $semFesta = $festaQuitada || !TemporadaFesta::deColunas($j['festa_inicio'], $j['festa_fim'])->cobra($diaJogo);

        $calc = new CalculadoraDevido((float) $j['valor_futebol'], (float) $j['valor_festa_semana']);
        $tipo = $j['tipo'] === 'goleiro' ? Tipo::Goleiro : Tipo::Linha;

        return new PainelJogador(
            (string) $j['nome'],
            (string) $j['tipo'],
            $rodadaId,
            $dataJogo,
            $situacao,
            $calc->devidoSemanal($tipo, $semFesta),
            (float) $j['saldo_pendente'],
        );
    }
}
