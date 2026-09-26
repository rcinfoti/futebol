<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Web\Csrf;

final class CsrfTest extends TestCase
{
    public function test_token_e_estavel_na_sessao(): void
    {
        $csrf = new Csrf(new SessaoMemoria());
        $t1 = $csrf->token();
        $t2 = $csrf->token();

        $this->assertNotSame('', $t1);
        $this->assertSame($t1, $t2); // mesmo token durante a sessão
    }

    public function test_valida_token_correto_e_rejeita_o_resto(): void
    {
        $csrf = new Csrf(new SessaoMemoria());
        $token = $csrf->token();

        $this->assertTrue($csrf->valido($token));
        $this->assertFalse($csrf->valido('errado'));
        $this->assertFalse($csrf->valido(null));
    }
}
