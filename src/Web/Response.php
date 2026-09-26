<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Response
{
    /** @param array<string,string> $cabecalhos */
    public function __construct(
        public readonly int $status,
        public readonly string $corpo,
        public readonly array $cabecalhos = [],
    ) {
    }

    public static function html(string $corpo, int $status = 200): self
    {
        return new self($status, $corpo, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirecionar(string $para, int $status = 302): self
    {
        return new self($status, '', ['Location' => $para]);
    }
}
