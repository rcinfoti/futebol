<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Web\Request;

final class RequestTest extends TestCase
{
    public function test_entrada_le_do_post_com_fallback_na_query(): void
    {
        $req = new Request('POST', '/entrar', query: ['a' => 'q'], post: ['b' => 'p']);
        $this->assertSame('p', $req->entrada('b'));
        $this->assertSame('q', $req->entrada('a'));
        $this->assertSame('x', $req->entrada('inexistente', 'x'));
    }

    public function test_caminho_relativo_remove_o_subdiretorio(): void
    {
        $this->assertSame('/entrar', Request::caminhoRelativo('/futebol/entrar?x=1', '/futebol'));
        $this->assertSame('/', Request::caminhoRelativo('/futebol', '/futebol'));
        $this->assertSame('/', Request::caminhoRelativo('/futebol/', '/futebol'));
        $this->assertSame('/p/quinta/entrar', Request::caminhoRelativo('/p/quinta/entrar', ''));
        $this->assertSame('/futebolx', Request::caminhoRelativo('/futebolx', '/futebol')); // prefixo só casa em fronteira de segmento
    }

    public function test_arquivo_enviado(): void
    {
        $req = new Request('POST', '/x', arquivos: ['comprovante' => ['name' => 'a.png', 'tmp_name' => '/tmp/x', 'size' => 1, 'error' => 0]]);
        $this->assertSame('a.png', $req->arquivo('comprovante')['name']);
        $this->assertNull($req->arquivo('outro'));
    }
}
