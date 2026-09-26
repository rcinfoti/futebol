<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Jogadores;

/** Erros de validação por campo, prontos para reexibir no formulário. */
final class DadosInvalidos extends \InvalidArgumentException
{
    /** @param array<string,string> $erros campo => mensagem */
    public function __construct(public readonly array $erros)
    {
        parent::__construct(implode(' ', $erros));
    }
}
