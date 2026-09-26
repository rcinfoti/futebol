<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Email;

interface EnviadorEmail
{
    public function enviar(string $para, string $assunto, string $corpo): void;
}
