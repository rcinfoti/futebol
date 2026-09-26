<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Web\Response;

final class ResponseTest extends TestCase
{
    public function test_html_tem_status_200_e_corpo(): void
    {
        $r = Response::html('<h1>oi</h1>');
        $this->assertSame(200, $r->status);
        $this->assertSame('<h1>oi</h1>', $r->corpo);
    }

    public function test_redirecionar_usa_location_e_302(): void
    {
        $r = Response::redirecionar('/entrar');
        $this->assertSame(302, $r->status);
        $this->assertSame('/entrar', $r->cabecalhos['Location']);
    }
}
