<?php

namespace App\Enums;

enum TipoVinculoEmpresa: string
{
    case Titular = 'titular';
    case Socio = 'socio';

    public function label(): string
    {
        return match ($this) {
            self::Titular => 'Titular',
            self::Socio => 'Sócio',
        };
    }
}
