<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Jogadores;

use PDO;
use RcInfoti\Pelada\Rodada\Tipo;

/**
 * Cadastro de jogadores, sempre escopado pela pelada (um organizador nunca toca
 * jogador de outra pelada, mesmo adivinhando o id). Não apaga: só desativa,
 * para preservar o histórico financeiro (spec §4).
 */
final class RepositorioJogadorPdo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int,nome:string,tipo:string,email:?string,telefone:?string,saldo_pendente:float,ativo:bool,tem_pin:bool}> */
    public function listar(int $peladaId, bool $incluirInativos = false): array
    {
        $sql = 'SELECT id, nome, tipo, email, telefone, saldo_pendente, ativo, pin_hash FROM jogadores WHERE pelada_id = ?'
            . ($incluirInativos ? '' : ' AND ativo = 1')
            . ' ORDER BY ativo DESC, nome';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$peladaId]);

        return array_map(self::linha(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{id:int,nome:string,tipo:string,email:?string,telefone:?string,saldo_pendente:float,ativo:bool,tem_pin:bool}|null */
    public function buscar(int $peladaId, int $jogadorId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome, tipo, email, telefone, saldo_pendente, ativo, pin_hash FROM jogadores WHERE pelada_id = ? AND id = ?'
        );
        $stmt->execute([$peladaId, $jogadorId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : self::linha($r);
    }

    public function criar(int $peladaId, DadosJogador $d): int
    {
        $this->garantirNomeLivre($peladaId, $d->nome, null);
        $this->pdo->prepare('INSERT INTO jogadores (pelada_id, nome, tipo, email, telefone) VALUES (?, ?, ?, ?, ?)')
            ->execute([$peladaId, $d->nome, self::tipo($d->tipo), $d->email, $d->telefone]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return bool false se o jogador não é dessa pelada */
    public function atualizar(int $peladaId, int $jogadorId, DadosJogador $d): bool
    {
        if ($this->buscar($peladaId, $jogadorId) === null) {
            return false;
        }
        $this->garantirNomeLivre($peladaId, $d->nome, $jogadorId);
        $this->pdo->prepare('UPDATE jogadores SET nome = ?, tipo = ?, email = ?, telefone = ? WHERE pelada_id = ? AND id = ?')
            ->execute([$d->nome, self::tipo($d->tipo), $d->email, $d->telefone, $peladaId, $jogadorId]);

        return true;
    }

    public function definirAtivo(int $peladaId, int $jogadorId, bool $ativo): bool
    {
        $stmt = $this->pdo->prepare('UPDATE jogadores SET ativo = ? WHERE pelada_id = ? AND id = ?');
        $stmt->execute([$ativo ? 1 : 0, $peladaId, $jogadorId]);

        return $stmt->rowCount() > 0;
    }

    private function garantirNomeLivre(int $peladaId, string $nome, ?int $exceto): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM jogadores WHERE pelada_id = ? AND LOWER(nome) = LOWER(?)');
        $stmt->execute([$peladaId, $nome]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ((int) $id !== $exceto) {
                throw new DadosInvalidos(['nome' => 'Já tem um jogador com esse nome nessa pelada.']);
            }
        }
    }

    private static function tipo(Tipo $t): string
    {
        return $t === Tipo::Goleiro ? 'goleiro' : 'linha';
    }

    /** @param array<string,mixed> $r */
    private static function linha(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'nome' => (string) $r['nome'],
            'tipo' => (string) $r['tipo'],
            'email' => $r['email'] !== null ? (string) $r['email'] : null,
            'telefone' => $r['telefone'] !== null ? (string) $r['telefone'] : null,
            'saldo_pendente' => (float) $r['saldo_pendente'],
            'ativo' => (int) $r['ativo'] === 1,
            'tem_pin' => $r['pin_hash'] !== null && $r['pin_hash'] !== '',
        ];
    }
}
