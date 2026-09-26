<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Presenca;

use DateTimeImmutable;
use PDO;
use RcInfoti\Pelada\Email\EnviadorEmail;

/**
 * Spec §6: e-mail "confirmação de vaga" pra quem subiu da espera (automático ou pelo
 * organizador). Enviado pelo cron — a requisição web só marca promovido_em, sem
 * depender do mail() do servidor. Idempotente via promocao_notificada.
 */
final class NotificadorPromocao
{
    private const DIAS = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly EnviadorEmail $email,
    ) {
    }

    public function notificarPendentes(int $peladaId): int
    {
        // só quem AINDA está confirmado numa rodada aberta: se subiu e desistiu antes do cron, não avisa
        $sel = $this->pdo->prepare(
            "SELECT i.id, j.nome, j.email, r.data_jogo, p.nome AS pelada
             FROM inscricoes i
             JOIN rodadas r ON r.id = i.rodada_id
             JOIN jogadores j ON j.id = i.jogador_id
             JOIN peladas p ON p.id = r.pelada_id
             WHERE r.pelada_id = ? AND r.status = 'aberta'
               AND i.promovido_em IS NOT NULL AND i.promocao_notificada = 0 AND i.status = 'confirmado'
             ORDER BY i.id"
        );
        $sel->execute([$peladaId]);
        $marca = $this->pdo->prepare('UPDATE inscricoes SET promocao_notificada = 1 WHERE id = ?');

        $enviados = 0;
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $i) {
            if ($i['email'] === null || $i['email'] === '') {
                continue; // sem e-mail: tenta de novo quando for cadastrado
            }
            $jogo = new DateTimeImmutable((string) $i['data_jogo']);
            $quando = self::DIAS[(int) $jogo->format('N')] . ', ' . $jogo->format('d/m');
            $this->email->enviar(
                (string) $i['email'],
                'Abriu vaga: você está confirmado!',
                sprintf(
                    "Olá %s,\n\nAbriu uma vaga e você saiu da lista de espera: está CONFIRMADO na %s de %s.\n"
                    . "Se não puder ir, entre no link da pelada e toque em \"Não vou\" pra liberar a vaga pro próximo.\n\nAbraço.",
                    $i['nome'],
                    $i['pelada'],
                    $quando,
                ),
            );
            $marca->execute([(int) $i['id']]);
            $enviados++;
        }

        return $enviados;
    }
}
