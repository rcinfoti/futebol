<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,array<string,mixed>> $arquivos formato de $_FILES (um arquivo por campo)
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $arquivos = [],
    ) {
    }

    /** Campo multivalorado (checkboxes "nome[]"). @return list<string> */
    public function lista(string $chave): array
    {
        $v = $this->post[$chave] ?? [];

        return is_array($v) ? array_values(array_map('strval', array_filter($v, 'is_scalar'))) : [];
    }

    /** @return array<string,mixed>|null */
    public function arquivo(string $campo): ?array
    {
        $a = $this->arquivos[$campo] ?? null;

        return is_array($a) && !is_array($a['name'] ?? null) ? $a : null; // ignora envio múltiplo (name[])
    }

    public function entrada(string $chave, ?string $default = null): ?string
    {
        $valor = $this->post[$chave] ?? $this->query[$chave] ?? $default;

        return $valor === null ? null : (string) $valor;
    }

    /** @param string $base subdiretório onde o app está publicado (ex.: "/futebol"); "" na raiz */
    public static function daGlobais(string $base = ''): self
    {
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::caminhoRelativo((string) ($_SERVER['REQUEST_URI'] ?? '/'), $base),
            $_GET,
            $_POST,
            $_FILES,
        );
    }

    /** Caminho da URI sem query e sem o prefixo do subdiretório, sempre começando com "/". */
    public static function caminhoRelativo(string $uri, string $base): string
    {
        $caminho = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
        $base = rtrim($base, '/');

        if ($base !== '' && ($caminho === $base || str_starts_with($caminho, $base . '/'))) {
            $caminho = substr($caminho, strlen($base));
        }

        return $caminho === '' ? '/' : $caminho;
    }
}
