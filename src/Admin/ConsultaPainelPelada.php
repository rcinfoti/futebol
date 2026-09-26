<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Admin;

use PDO;
use RcInfoti\Pelada\Financeiro\RepositorioCaixaPdo;

final class ConsultaPainelPelada
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function montar(int $peladaId): PainelPelada
    {
        $stmt = $this->pdo->prepare('SELECT id, nome, slug, limite_linha, limite_goleiro FROM peladas WHERE id = ?');
        $stmt->execute([$peladaId]);
        $pl = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pl === false) {
            throw new \RuntimeException("Pelada {$peladaId} não encontrada.");
        }

        $rod = $this->pdo->prepare("SELECT id, data_jogo FROM rodadas WHERE pelada_id = ? AND status = 'aberta' ORDER BY data_jogo LIMIT 1");
        $rod->execute([$peladaId]);
        $r = $rod->fetch(PDO::FETCH_ASSOC);
        $rodadaId = $r === false ? null : (int) $r['id'];

        $listas = ['linha:confirmado' => [], 'linha:espera' => [], 'goleiro:confirmado' => [], 'goleiro:espera' => [], 'desistiu' => []];
        if ($rodadaId !== null) {
            // "pago" = existe pagamento de futebol/festa da rodada JÁ CONFIRMADO pelo organizador
            $ins = $this->pdo->prepare(
                'SELECT i.tipo, i.status, j.id, j.nome,
                        EXISTS (SELECT 1 FROM pagamentos pg WHERE pg.rodada_id = i.rodada_id
                                AND pg.jogador_id = i.jogador_id AND pg.confirmado = 1) AS pago
                 FROM inscricoes i JOIN jogadores j ON j.id = i.jogador_id
                 WHERE i.rodada_id = ? ORDER BY i.ordem'
            );
            $ins->execute([$rodadaId]);
            foreach ($ins->fetchAll(PDO::FETCH_ASSOC) as $i) {
                $chave = $i['status'] === 'desistiu' ? 'desistiu' : $i['tipo'] . ':' . $i['status'];
                $listas[$chave][] = ['id' => (int) $i['id'], 'nome' => (string) $i['nome'], 'pago' => (int) $i['pago'] === 1];
            }
        }

        $pend = $this->pdo->prepare('SELECT id, nome, saldo_pendente FROM jogadores WHERE pelada_id = ? AND saldo_pendente > 0 ORDER BY saldo_pendente DESC, nome');
        $pend->execute([$peladaId]);

        $pag = $this->pdo->prepare(
            'SELECT p.id, j.nome AS jogador, p.categoria, p.escopo, p.valor, p.forma, p.criado_em, p.comprovante_arquivo
             FROM pagamentos p JOIN jogadores j ON j.id = p.jogador_id
             WHERE p.pelada_id = ? AND p.confirmado = 0 ORDER BY p.criado_em'
        );
        $pag->execute([$peladaId]);

        return new PainelPelada(
            (int) $pl['id'],
            (string) $pl['nome'],
            (string) $pl['slug'],
            (int) $pl['limite_linha'],
            (int) $pl['limite_goleiro'],
            $rodadaId,
            $r === false ? null : (string) $r['data_jogo'],
            $listas['linha:confirmado'],
            $listas['linha:espera'],
            $listas['goleiro:confirmado'],
            $listas['goleiro:espera'],
            $listas['desistiu'],
            (new RepositorioCaixaPdo($this->pdo))->saldo($peladaId),
            array_map(static fn (array $x): array => ['id' => (int) $x['id'], 'nome' => (string) $x['nome'], 'saldo' => (float) $x['saldo_pendente']],
                $pend->fetchAll(PDO::FETCH_ASSOC)),
            array_map(static fn (array $x): array => [
                'id' => (int) $x['id'], 'jogador' => (string) $x['jogador'], 'categoria' => (string) $x['categoria'],
                'escopo' => (string) $x['escopo'], 'valor' => (float) $x['valor'], 'forma' => (string) $x['forma'],
                'criado_em' => (string) $x['criado_em'],
                'comprovante' => $x['comprovante_arquivo'] !== null && $x['comprovante_arquivo'] !== '',
            ], $pag->fetchAll(PDO::FETCH_ASSOC)),
        );
    }

    public function pagamentoDaPelada(int $pagamentoId, int $peladaId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM pagamentos WHERE id = ? AND pelada_id = ?');
        $stmt->execute([$pagamentoId, $peladaId]);

        return $stmt->fetchColumn() !== false;
    }
}
