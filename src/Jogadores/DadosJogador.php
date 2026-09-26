<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Jogadores;

use RcInfoti\Pelada\Rodada\Tipo;

final class DadosJogador
{
    private function __construct(
        public readonly string $nome,
        public readonly Tipo $tipo,
        public readonly ?string $email,
        public readonly ?string $telefone,
    ) {
    }

    /** @param array<string,mixed> $f campos crus do formulário */
    public static function deFormulario(array $f): self
    {
        $erros = [];

        $nome = preg_replace('/\s+/u', ' ', trim((string) ($f['nome'] ?? ''))) ?? '';
        $tam = mb_strlen($nome);
        if ($tam < 2 || $tam > 120) {
            $erros['nome'] = 'Nome precisa ter entre 2 e 120 letras.';
        }

        $tipo = match ((string) ($f['tipo'] ?? '')) {
            'linha' => Tipo::Linha,
            'goleiro' => Tipo::Goleiro,
            default => null,
        };
        if ($tipo === null) {
            $erros['tipo'] = 'Escolha linha ou goleiro.';
        }

        $email = mb_strtolower(trim((string) ($f['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erros['email'] = 'E-mail inválido.';
        }

        $telefone = trim((string) ($f['telefone'] ?? ''));
        if (mb_strlen($telefone) > 40) {
            $erros['telefone'] = 'Telefone muito longo.';
        }

        if ($erros !== []) {
            throw new DadosInvalidos($erros);
        }

        return new self($nome, $tipo, $email === '' ? null : $email, $telefone === '' ? null : $telefone);
    }
}
