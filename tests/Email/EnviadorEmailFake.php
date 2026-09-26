<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Email;

use RcInfoti\Pelada\Email\EnviadorEmail;

final class EnviadorEmailFake implements EnviadorEmail
{
    /** @var list<array{para:string,assunto:string,corpo:string}> */
    public array $enviados = [];

    public function enviar(string $para, string $assunto, string $corpo): void
    {
        $this->enviados[] = ['para' => $para, 'assunto' => $assunto, 'corpo' => $corpo];
    }
}
