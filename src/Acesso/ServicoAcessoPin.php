<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Acesso;

use DateTimeImmutable;
use PDO;

final class ServicoAcessoPin
{
    /** Falhas seguidas antes de bloquear (spec §5: rate limit básico no login). */
    public const MAX_FALHAS = 5;
    public const MINUTOS_BLOQUEIO = 15;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function definirPin(int $jogadorId, string $pin): void
    {
        $this->pdo->prepare('UPDATE jogadores SET pin_hash = ? WHERE id = ?')
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $jogadorId]);
        $this->zerarFalhas($jogadorId); // PIN novo = recomeça do zero
    }

    /** Sorteia um PIN de 4 dígitos, grava só o hash e devolve o PIN em claro (mostrado uma única vez). */
    public function gerarPin(int $jogadorId): string
    {
        $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $this->definirPin($jogadorId, $pin);

        return $pin;
    }

    public function autenticar(int $jogadorId, string $pin, ?DateTimeImmutable $agora = null): bool
    {
        $agora ??= new DateTimeImmutable('now');
        if ($this->bloqueado($jogadorId, $agora)) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT pin_hash FROM jogadores WHERE id = ?');
        $stmt->execute([$jogadorId]);
        $hash = $stmt->fetchColumn();
        if ($hash === false) {
            return false; // jogador inexistente: nada a contar
        }

        if (is_string($hash) && $hash !== '' && password_verify($pin, $hash)) {
            $this->zerarFalhas($jogadorId);

            return true;
        }

        $this->registrarFalha($jogadorId, $agora);

        return false;
    }

    public function bloqueado(int $jogadorId, DateTimeImmutable $agora): bool
    {
        $stmt = $this->pdo->prepare('SELECT bloqueado_ate FROM tentativas_login WHERE jogador_id = ?');
        $stmt->execute([$jogadorId]);
        $ate = $stmt->fetchColumn();

        return is_string($ate) && $ate !== '' && new DateTimeImmutable($ate) > $agora;
    }

    private function registrarFalha(int $jogadorId, DateTimeImmutable $agora): void
    {
        $stmt = $this->pdo->prepare('SELECT falhas, bloqueado_ate FROM tentativas_login WHERE jogador_id = ?');
        $stmt->execute([$jogadorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Bloqueio anterior já expirou: a contagem recomeça.
        $falhas = $row === false || $row['bloqueado_ate'] !== null ? 1 : (int) $row['falhas'] + 1;
        $bloqueadoAte = $falhas >= self::MAX_FALHAS
            ? $agora->modify('+' . self::MINUTOS_BLOQUEIO . ' minutes')->format('Y-m-d H:i:s')
            : null;

        if ($row === false) {
            $this->pdo->prepare('INSERT INTO tentativas_login (jogador_id, falhas, bloqueado_ate) VALUES (?, ?, ?)')
                ->execute([$jogadorId, $falhas, $bloqueadoAte]);
        } else {
            $this->pdo->prepare('UPDATE tentativas_login SET falhas = ?, bloqueado_ate = ? WHERE jogador_id = ?')
                ->execute([$falhas, $bloqueadoAte, $jogadorId]);
        }
    }

    private function zerarFalhas(int $jogadorId): void
    {
        $this->pdo->prepare('DELETE FROM tentativas_login WHERE jogador_id = ?')->execute([$jogadorId]);
    }
}
