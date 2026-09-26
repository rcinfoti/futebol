<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Web\Vista;

final class VistaTest extends TestCase
{
    public function test_renderiza_template_com_dados(): void
    {
        $dir = sys_get_temp_dir() . '/vistas_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/oi.php', '<p><?= htmlspecialchars($nome) ?></p>');

        $html = (new Vista($dir))->render('oi', ['nome' => 'Ana & Bia']);

        $this->assertSame('<p>Ana &amp; Bia</p>', $html);
    }
}
