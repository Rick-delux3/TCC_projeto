<?php

namespace App\Support;

class CorretorPermissions
{
    /**
     * Relação única entre as abilities usadas pelos Gates e as permissões
     * persistidas no cadastro do integrante. Permissões de escrita também
     * concedem a leitura necessária para que a ação seja utilizável na UI.
     */
    public static function abilities(): array
    {
        return [
            'view-leads' => ['leads.visualizar', 'leads.editar'],
            'edit-leads' => ['leads.editar'],
            'view-analyses' => ['analises.visualizar', 'analises.criar'],
            'create-analysis' => ['analises.criar'],
            'view-real-estate-companies' => ['imobiliarias.visualizar'],
            'view-tags' => [
                'tags.visualizar',
                'tags.gerenciar',
            ],
            'manage-lead-tags' => [
                'tags.gerenciar',
            ],
        ];
    }

    public static function all(): array
    {
        return [
            'leads.visualizar' => 'Visualizar leads/clientes',
            'leads.editar' => 'Editar dados de leads/clientes',
            'analises.visualizar' => 'Visualizar análises',
            'analises.criar' => 'Solicitar análises',
            'imobiliarias.visualizar' => 'Visualizar imobiliárias',
            'tags.visualizar' => 'Visualizar tags',
            'tags.gerenciar' => 'Gerenciar tags dos leads',
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
