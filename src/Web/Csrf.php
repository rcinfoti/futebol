<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Csrf
{
    public function __construct(private readonly Sessao $sessao)
    {
    }

    public function token(): string
    {
        $token = $this->sessao->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->sessao->set('_csrf', $token);
        }

        return $token;
    }

    public function valido(?string $enviado): bool
    {
        $token = $this->sessao->get('_csrf');

        return is_string($token) && $enviado !== null && hash_equals($token, $enviado);
    }
}
