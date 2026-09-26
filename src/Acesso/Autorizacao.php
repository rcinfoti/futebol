<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Acesso;

use PDO;

/**
 * Regra de visibilidade (spec §2): super admin vê todas as peladas;
 * organizador só as vinculadas em pelada_organizadores. Usuário inativo não vê nada.
 */
final class Autorizacao
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ehSuperAdmin(int $usuarioId): bool
    {
        return $this->papel($usuarioId) === Papel::SuperAdmin->value;
    }

    public function podeGerir(int $usuarioId, int $peladaId): bool
    {
        $papel = $this->papel($usuarioId);
        if ($papel === null) {
            return false;
        }
        if ($papel === Papel::SuperAdmin->value) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM peladas WHERE id = ?');
            $stmt->execute([$peladaId]);

            return $stmt->fetchColumn() !== false;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM pelada_organizadores WHERE usuario_id = ? AND pelada_id = ?');
        $stmt->execute([$usuarioId, $peladaId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array{id:int,nome:string,slug:string,ativa:bool}> */
    public function peladasVisiveis(int $usuarioId): array
    {
        $papel = $this->papel($usuarioId);
        if ($papel === null) {
            return [];
        }

        if ($papel === Papel::SuperAdmin->value) {
            $stmt = $this->pdo->query('SELECT id, nome, slug, ativa FROM peladas ORDER BY nome');
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT p.id, p.nome, p.slug, p.ativa FROM peladas p
                 JOIN pelada_organizadores o ON o.pelada_id = p.id
                 WHERE o.usuario_id = ? ORDER BY p.nome'
            );
            $stmt->execute([$usuarioId]);
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nome' => (string) $r['nome'],
            'slug' => (string) $r['slug'], 'ativa' => (int) $r['ativa'] === 1,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function vincular(int $peladaId, int $usuarioId): void
    {
        if ($this->vinculado($peladaId, $usuarioId)) {
            return;
        }
        $this->pdo->prepare('INSERT INTO pelada_organizadores (pelada_id, usuario_id) VALUES (?, ?)')
            ->execute([$peladaId, $usuarioId]);
    }

    public function desvincular(int $peladaId, int $usuarioId): void
    {
        $this->pdo->prepare('DELETE FROM pelada_organizadores WHERE pelada_id = ? AND usuario_id = ?')
            ->execute([$peladaId, $usuarioId]);
    }

    /** @return list<int> */
    public function idsPeladasDoUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare('SELECT pelada_id FROM pelada_organizadores WHERE usuario_id = ? ORDER BY pelada_id');
        $stmt->execute([$usuarioId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Substitui o conjunto de peladas do organizador pelo marcado no formulário.
     *
     * @param list<int> $peladaIds ids inexistentes são ignorados
     */
    public function definirVinculos(int $usuarioId, array $peladaIds): void
    {
        $existentes = array_map('intval', $this->pdo->query('SELECT id FROM peladas')->fetchAll(PDO::FETCH_COLUMN));
        $desejados = array_values(array_intersect(array_map('intval', $peladaIds), $existentes));
        $atuais = $this->idsPeladasDoUsuario($usuarioId);

        $this->pdo->beginTransaction();
        try {
            foreach (array_diff($atuais, $desejados) as $id) {
                $this->desvincular($id, $usuarioId);
            }
            foreach (array_diff($desejados, $atuais) as $id) {
                $this->vincular($id, $usuarioId);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function vinculado(int $peladaId, int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM pelada_organizadores WHERE pelada_id = ? AND usuario_id = ?');
        $stmt->execute([$peladaId, $usuarioId]);

        return $stmt->fetchColumn() !== false;
    }

    private function papel(int $usuarioId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT papel FROM usuarios WHERE id = ? AND ativo = 1');
        $stmt->execute([$usuarioId]);
        $p = $stmt->fetchColumn();

        return $p === false ? null : (string) $p;
    }
}
