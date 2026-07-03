<?php

namespace App\Support;

class CorretorPermissions
{
    public static function all(): array
    {
        return [
            'leads.visualizar' => 'Visualizar leads',
            'leads.editar' => 'Editar leads',
            'leads.excluir' => 'Excluir leads',

            'analises.visualizar' => 'Visualizar análises',
            'analises.criar' => 'Realizar análises',
            'analises.reprocessar' => 'Reprocessar análises',

            'imobiliarias.visualizar' => 'Visualizar imobiliárias',
            'imobiliarias.editar' => 'Editar imobiliárias',
            'imobiliarias.configurar' => 'Configurar imobiliárias',

            'tags.visualizar' => 'Visualizar tags LeadLovers',
            'tags.editar' => 'Editar tags LeadLovers',

            'logs.visualizar' => 'Visualizar logs de atividades',
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}