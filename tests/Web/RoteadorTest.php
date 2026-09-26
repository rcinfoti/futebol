<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Web\Request;
use RcInfoti\Pelada\Web\Response;
use RcInfoti\Pelada\Web\Roteador;

final class RoteadorTest extends TestCase
{
    public function test_casa_rota_get_simples(): void
    {
        $r = new Roteador();
        $r->get('/', fn (Request $req, array $p): Response => Response::html('home'));

        $resp = $r->despachar(new Request('GET', '/'));
        $this->assertSame('home', $resp->corpo);
    }

    public function test_captura_parametro_de_caminho(): void
    {
        $r = new Roteador();
        $r->get('/j/{slug}', fn (Request $req, array $p): Response => Response::html('oi ' . $p['slug']));

        $resp = $r->despachar(new Request('GET', '/j/ana'));
        $this->assertSame('oi ana', $resp->corpo);
    }

    public function test_metodo_diferente_nao_casa(): void
    {
        $r = new Roteador();
        $r->get('/entrar', fn (Request $req, array $p): Response => Response::html('form'));

        $resp = $r->despachar(new Request('POST', '/entrar'));
        $this->assertSame(404, $resp->status);
    }

    public function test_sem_rota_devolve_404(): void
    {
        $resp = (new Roteador())->despachar(new Request('GET', '/nada'));
        $this->assertSame(404, $resp->status);
    }
}
