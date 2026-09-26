<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Painel;

use DateTimeImmutable;
use PDO;
use RcInfoti\Pelada\Financeiro\CalculadoraDevido;
use RcInfoti\Pelada\Rodada\Tipo;

final class ConsultaPainelJogador
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function montar(int $jogadorId, DateTimeImmutable $agora): PainelJogador
    {
        $jog = $this->pdo->prepare(
            'SELECT j.nome, j.tipo, j.saldo_pendente, j.festa_quitada_ano, j.pelada_id,
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

        $calc = new CalculadoraDevido((float) $j['valor_futebol'], (float) $j['valor_festa_semana']);
        $tipo = $j['tipo'] === 'goleiro' ? Tipo::Goleiro : Tipo::Linha;

        return new PainelJogador(
            (string) $j['nome'],
            (string) $j['tipo'],
            $rodadaId,
            $dataJogo,
            $situacao,
            $calc->devidoSemanal($tipo, $festaQuitada),
            (float) $j['saldo_pendente'],
        );
    }
}
