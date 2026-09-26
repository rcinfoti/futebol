<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Acesso;

enum Papel: string
{
    case SuperAdmin = 'super_admin';
    case Organizador = 'organizador';
}
