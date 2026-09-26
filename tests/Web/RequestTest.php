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
}
