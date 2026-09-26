<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Admin;

use DateTimeImmutable;
use PDO;
use RcInfoti\Pelada\Financeiro\CategoriaPagamento;
use RcInfoti\Pelada\Financeiro\EscopoPagamento;
use RcInfoti\Pelada\Financeiro\FormaPagamento;
use RcInfoti\Pelada\Financeiro\Pagamento;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Jogadores\DadosInvalidos;

/**
 * Organizador registra dinheiro/Pix que recebeu direto (spec §3.2: "registro manual pelo
 * organizador"). Como quem recebeu é o próprio organizador, o pagamento já nasce confirmado
 * — e a confirmação gera a entrada no caixa (idempotente, via RepositorioPagamentoPdo).
 */
final class RecebimentoPagamento
{
    public const VALOR_MAXIMO = 1000.0;

    /** tipo do formulário => [categoria, escopo, coluna do valor padrão] */
    private const TIPOS = [
        'futebol' => [CategoriaPagamento::Futebol, EscopoPagamento::Semana, 'valor_futebol'],
        'festa_semana' => [CategoriaPagamento::Festa, EscopoPagamento::Semana, 'valor_festa_semana'],
        'festa_ano' => [CategoriaPagamento::Festa, EscopoPagamento::Ano, 'valor_festa_ano'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly RepositorioPagamentoPdo $pagamentos,
    ) {
    }

    /** @param float|null $valor null = valor padrão configurado na pelada */
    public function receber(int $peladaId, int $jogadorId, string $tipo, string $forma, ?float $valor, DateTimeImmutable $agora): int
    {
        $erros = [];
        if (!isset(self::TIPOS[$tipo])) {
            $erros['tipo'] = 'Escolha o que foi pago.';
        }
        $formaEnum = match ($forma) {
            'pix' => FormaPagamento::Pix,
            'dinheiro' => FormaPagamento::Dinheiro,
            default => null,
        };
        if ($formaEnum === null) {
            $erros['forma'] = 'Escolha Pix ou dinheiro.';
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.valor_futebol, p.valor_festa_semana, p.valor_festa_ano
             FROM jogadores j JOIN peladas p ON p.id = j.pelada_id WHERE j.id = ? AND j.pelada_id = ?'
        );
        $stmt->execute([$jogadorId, $peladaId]);
        $pl = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pl === false) {
            $erros['jogador'] = 'Jogador não é dessa pelada.';
        }

        if ($erros === [] && $valor === null) {
            $valor = (float) $pl[self::TIPOS[$tipo][2]];
        }
        if ($valor !== null && ($valor <= 0 || $valor > self::VALOR_MAXIMO)) {
            $erros['valor'] = 'Valor precisa ser maior que zero e até R$ ' . number_format(self::VALOR_MAXIMO, 2, ',', '.') . '.';
        }
        if ($erros !== []) {
            throw new DadosInvalidos($erros);
        }

        [$categoria, $escopo] = self::TIPOS[$tipo];
        $rodadaId = $escopo === EscopoPagamento::Semana ? $this->rodadaAberta($peladaId) : null;

        $id = $this->pagamentos->registrar(
            new Pagamento($peladaId, $jogadorId, $categoria, $escopo, round((float) $valor, 2), $formaEnum, rodadaId: $rodadaId),
            $agora,
        );
        $this->pagamentos->confirmar($id, $agora);

        return $id;
    }

    private function rodadaAberta(int $peladaId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM rodadas WHERE pelada_id = ? AND status = 'aberta' ORDER BY data_jogo LIMIT 1");
        $stmt->execute([$peladaId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
