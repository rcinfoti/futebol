<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

final class Pagamento
{
    public function __construct(
        public readonly int $peladaId,
        public readonly int $jogadorId,
        public readonly CategoriaPagamento $categoria,
        public readonly EscopoPagamento $escopo,
        public readonly float $valor,
        public readonly FormaPagamento $forma,
        public readonly ?int $rodadaId = null,
        public readonly ?string $comprovanteArquivo = null,
    ) {
    }
}
