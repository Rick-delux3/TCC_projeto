<?php

namespace App\Support;

class CorretorPermissions
{
    public static function all(): array
    {
        return [
            'leads.visualizar' => 'Visualizar leads/clientes',
            'analises.visualizar' => 'Visualizar análises',
            'analises.criar' => 'Solicitar análises',
            'imobiliarias.visualizar' => 'Visualizar imobiliárias',
            'tags.visualizar' => 'Visualizar tags',
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}