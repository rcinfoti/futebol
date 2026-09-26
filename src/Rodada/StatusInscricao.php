<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

enum StatusInscricao
{
    case Confirmado;
    case Espera;
    case Desistiu;
}
