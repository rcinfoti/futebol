<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        public readonly array $query = [],
        public readonly array $post = [],
    ) {
    }

    public function entrada(string $chave, ?string $default = null): ?string
    {
        $valor = $this->post[$chave] ?? $this->query[$chave] ?? $default;

        return $valor === null ? null : (string) $valor;
    }

    public static function daGlobais(): self
    {
        $caminho = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $caminho,
            $_GET,
            $_POST,
        );
    }
}
