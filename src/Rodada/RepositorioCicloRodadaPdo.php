<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;
use PDO;

final class RepositorioCicloRodadaPdo
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CalendarioRodada $calendario,
    ) {
    }

    public function criarRodadaSeAberta(int $peladaId, AgendaPelada $agenda, DateTimeImmutable $agora): ?int
    {
        $datas = $this->calendario->proximaRodada($agenda, $agora);
        $dataJogo = $datas->dataJogo->format('Y-m-d');

        $sel = $this->pdo->prepare('SELECT id FROM rodadas WHERE pelada_id = ? AND data_jogo = ?');
        $sel->execute([$peladaId, $dataJogo]);
        $existente = $sel->fetchColumn();
        if ($existente !== false) {
            return (int) $existente;
        }

        if ($datas->abreEm > $agora) {
            return null; // abertura ainda não chegou
        }

        $ins = $this->pdo->prepare(
            "INSERT INTO rodadas (pelada_id, data_jogo, status, abre_em, vira_regra_em, prazo_multa_em)
             VALUES (?, ?, 'aberta', ?, ?, ?)"
        );
        try {
            $ins->execute([
                $peladaId,
                $dataJogo,
                $datas->abreEm->format('Y-m-d H:i:s'),
                $datas->viraRegraEm->format('Y-m-d H:i:s'),
                $datas->prazoMultaEm->format('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            // Corrida com outra execução do cron: o UNIQUE(pelada_id, data_jogo)
            // barrou o segundo INSERT. Recupera o id já criado em vez de propagar.
            $sel->execute([$peladaId, $dataJogo]);
            $jaCriado = $sel->fetchColumn();
            if ($jaCriado !== false) {
                return (int) $jaCriado;
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function fecharRodadasVencidas(int $peladaId, DateTimeImmutable $agora): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE rodadas SET status = 'fechada'
             WHERE pelada_id = ? AND status = 'aberta' AND data_jogo < ?"
        );
        $stmt->execute([$peladaId, $agora->format('Y-m-d')]);

        return $stmt->rowCount();
    }
}
