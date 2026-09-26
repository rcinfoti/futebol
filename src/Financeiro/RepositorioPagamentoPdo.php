<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use DateTimeImmutable;
use PDO;

final class RepositorioPagamentoPdo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(Pagamento $pagamento, DateTimeImmutable $agora): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pagamentos
                (pelada_id, jogador_id, rodada_id, categoria, escopo, valor, forma, comprovante_arquivo, confirmado, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([
            $pagamento->peladaId,
            $pagamento->jogadorId,
            $pagamento->rodadaId,
            $this->categoriaSql($pagamento->categoria),
            $this->escopoSql($pagamento->escopo),
            $pagamento->valor,
            $this->formaSql($pagamento->forma),
            $pagamento->comprovanteArquivo,
            $agora->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function categoriaSql(CategoriaPagamento $c): string
    {
        return match ($c) {
            CategoriaPagamento::Futebol => 'futebol',
            CategoriaPagamento::Festa => 'festa',
        };
    }

    private function escopoSql(EscopoPagamento $e): string
    {
        return match ($e) {
            EscopoPagamento::Semana => 'semana',
            EscopoPagamento::Ano => 'ano',
        };
    }

    private function formaSql(FormaPagamento $f): string
    {
        return match ($f) {
            FormaPagamento::Pix => 'pix',
            FormaPagamento::Dinheiro => 'dinheiro',
        };
    }
}
