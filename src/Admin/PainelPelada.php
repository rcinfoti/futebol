<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Admin;

/** Read-model da tela inicial do organizador (spec §8, tela 2). */
final class PainelPelada
{
    /**
     * @param list<array{id:int,nome:string,pago:bool}> $confirmadosLinha
     * @param list<array{id:int,nome:string,pago:bool}> $esperaLinha
     * @param list<array{id:int,nome:string,pago:bool}> $confirmadosGoleiro
     * @param list<array{id:int,nome:string,pago:bool}> $esperaGoleiro
     * @param list<array{id:int,nome:string,pago:bool}> $desistiram
     * @param list<array{id:int,nome:string,saldo:float}> $pendencias
     * @param list<array{id:int,jogador:string,categoria:string,escopo:string,valor:float,forma:string,criado_em:string,comprovante:bool}> $pagamentosAConfirmar
     */
    public function __construct(
        public readonly int $peladaId,
        public readonly string $nome,
        public readonly string $slug,
        public readonly int $limiteLinha,
        public readonly int $limiteGoleiro,
        public readonly ?int $rodadaId,
        public readonly ?string $dataJogo,
        public readonly array $confirmadosLinha,
        public readonly array $esperaLinha,
        public readonly array $confirmadosGoleiro,
        public readonly array $esperaGoleiro,
        public readonly array $desistiram,
        public readonly float $saldoCaixa,
        public readonly array $pendencias,
        public readonly array $pagamentosAConfirmar,
    ) {
    }
}
