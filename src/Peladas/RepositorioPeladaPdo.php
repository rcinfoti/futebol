<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Peladas;

use PDO;
use RcInfoti\Pelada\Financeiro\TemporadaFesta;
use RcInfoti\Pelada\Jogadores\DadosInvalidos;

final class RepositorioPeladaPdo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function criar(DadosPelada $d): int
    {
        $this->garantirSlugLivre($d->slug, null);
        $c = $d->colunas();
        $this->pdo->prepare(
            'INSERT INTO peladas (' . implode(', ', array_keys($c)) . ') VALUES (' . implode(', ', array_fill(0, count($c), '?')) . ')'
        )->execute(array_values($c));

        return (int) $this->pdo->lastInsertId();
    }

    public function atualizar(int $peladaId, DadosPelada $d): void
    {
        $this->garantirSlugLivre($d->slug, $peladaId);
        $c = $d->colunas();
        $sets = implode(', ', array_map(static fn (string $k): string => "{$k} = ?", array_keys($c)));
        $this->pdo->prepare("UPDATE peladas SET {$sets} WHERE id = ?")->execute([...array_values($c), $peladaId]);
    }

    /** @return array<string,mixed>|null linha completa (festa_inicio/fim em "MM-DD") */
    public function buscar(int $peladaId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM peladas WHERE id = ?');
        $stmt->execute([$peladaId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        $r['festa_inicio'] = TemporadaFesta::mmdd($r['festa_inicio'] ?? null) ?? TemporadaFesta::INICIO_PADRAO;
        $r['festa_fim'] = TemporadaFesta::mmdd($r['festa_fim'] ?? null) ?? TemporadaFesta::FIM_PADRAO;

        return $r;
    }

    public function definirAtiva(int $peladaId, bool $ativa): void
    {
        $this->pdo->prepare('UPDATE peladas SET ativa = ? WHERE id = ?')->execute([$ativa ? 1 : 0, $peladaId]);
    }

    private function garantirSlugLivre(string $slug, ?int $exceto): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM peladas WHERE slug = ?');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        if ($id !== false && (int) $id !== $exceto) {
            throw new DadosInvalidos(['slug' => 'Já existe uma pelada com esse link.']);
        }
    }
}
