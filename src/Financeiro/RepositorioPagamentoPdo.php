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

    public function confirmar(int $pagamentoId, DateTimeImmutable $agora): void
    {
        $this->pdo->beginTransaction();
        try {
            // Reivindica o pagamento de forma atômica: o UPDATE guardado trava a
            // linha e só "vence" se ainda não fora confirmado. Sem linhas afetadas
            // (já confirmado ou inexistente) => no-op idempotente.
            $marca = $this->pdo->prepare(
                'UPDATE pagamentos SET confirmado = 1 WHERE id = ? AND confirmado = 0'
            );
            $marca->execute([$pagamentoId]);
            if ($marca->rowCount() === 0) {
                $this->pdo->commit();

                return;
            }

            $busca = $this->pdo->prepare(
                'SELECT pelada_id, jogador_id, categoria, escopo, valor, criado_em FROM pagamentos WHERE id = ?'
            );
            $busca->execute([$pagamentoId]);
            $p = $busca->fetch(PDO::FETCH_ASSOC);

            // Data econômica do pagamento = quando o jogador pagou (registro), não a
            // hora em que o organizador validou. É ela que datar o caixa e definir o
            // ano da festa, para os dois relatórios reconciliarem e não recobrar quem
            // pagou na virada do ano.
            $pagoEm = new DateTimeImmutable($p['criado_em']);

            // Entrada de caixa vinculada (UNIQUE(pagamento_id) impede dupla contagem).
            // ocorrido_em = data do pagamento; criado_em = quando este movimento foi gravado.
            $this->pdo->prepare(
                "INSERT INTO movimentos_caixa
                    (pelada_id, tipo, categoria, valor, descricao, pagamento_id, ocorrido_em, criado_em)
                 VALUES (?, 'entrada', 'pagamento', ?, NULL, ?, ?, ?)"
            )->execute([
                (int) $p['pelada_id'],
                (float) $p['valor'],
                $pagamentoId,
                $pagoEm->format('Y-m-d H:i:s'),
                $agora->format('Y-m-d H:i:s'),
            ]);

            // Quitar a festa do ano marca o jogador com o ano em que ele pagou.
            if ($p['categoria'] === 'festa' && $p['escopo'] === 'ano') {
                $this->pdo->prepare('UPDATE jogadores SET festa_quitada_ano = ? WHERE id = ?')
                    ->execute([(int) $pagoEm->format('Y'), (int) $p['jogador_id']]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
