<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Email;

final class EnviadorEmailMail implements EnviadorEmail
{
    public function __construct(private readonly string $de)
    {
    }

    public function enviar(string $para, string $assunto, string $corpo): void
    {
        $cabecalhos = 'From: ' . $this->de . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n";

        mail($para, $assunto, $corpo, $cabecalhos);
    }
}
