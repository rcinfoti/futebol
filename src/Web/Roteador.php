<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Roteador
{
    /** @var list<array{metodo:string,regex:string,nomes:list<string>,handler:callable}> */
    private array $rotas = [];

    public function get(string $padrao, callable $handler): void
    {
        $this->registrar('GET', $padrao, $handler);
    }

    public function post(string $padrao, callable $handler): void
    {
        $this->registrar('POST', $padrao, $handler);
    }

    public function despachar(Request $req): Response
    {
        foreach ($this->rotas as $rota) {
            if ($rota['metodo'] !== $req->metodo) {
                continue;
            }
            if (preg_match($rota['regex'], $req->caminho, $m) === 1) {
                $params = [];
                foreach ($rota['nomes'] as $nome) {
                    $params[$nome] = $m[$nome];
                }

                return ($rota['handler'])($req, $params);
            }
        }

        return Response::html('Não encontrado', 404);
    }

    private function registrar(string $metodo, string $padrao, callable $handler): void
    {
        $nomes = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$nomes): string {
            $nomes[] = $m[1];

            return '(?P<' . $m[1] . '>[^/]+)';
        }, $padrao);

        $this->rotas[] = [
            'metodo' => $metodo,
            'regex' => '#^' . $regex . '$#',
            'nomes' => $nomes,
            'handler' => $handler,
        ];
    }
}
