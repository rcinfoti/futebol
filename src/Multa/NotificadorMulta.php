<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Multa;

use DateTimeImmutable;
use PDO;
use RcInfoti\Pelada\Email\EnviadorEmail;

final class NotificadorMulta
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EnviadorEmail $email,
    ) {
    }

    public function notificarPendentes(int $peladaId, DateTimeImmutable $agora): int
    {
        $sel = $this->pdo->prepare(
            'SELECT m.id, m.valor, j.nome, j.email
             FROM multas m
             JOIN jogadores j ON j.id = m.jogador_id
             WHERE m.pelada_id = ? AND m.email_enviado = 0
             ORDER BY m.id'
        );
        $sel->execute([$peladaId]);

        $marca = $this->pdo->prepare('UPDATE multas SET email_enviado = 1 WHERE id = ?');

        $enviados = 0;
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $para = $m['email'];
            if ($para === null || $para === '') {
                continue; // sem e-mail: tenta em outra passada, quando for cadastrado
            }

            $this->email->enviar($para, 'Multa da pelada', $this->corpo((string) $m['nome'], (float) $m['valor']));
            $marca->execute([(int) $m['id']]);
            $enviados++;
        }

        return $enviados;
    }

    private function corpo(string $nome, float $valor): string
    {
        return sprintf(
            "Olá %s,\n\nFoi registrada uma multa de R$ %.2f por desistência após o prazo. "
            . "O valor entra como pendência no seu cadastro até a quitação.\n\nAbraço.",
            $nome,
            $valor,
        );
    }
}
